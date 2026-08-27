<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AdminStats — read-only aggregates for the admin landing page (Session 15,
 * extended for the service domains in session 28).
 *
 * Every method is a single grouped/aggregate query rather than a row scan, so
 * the dashboard stays cheap as the tables grow. Nothing here writes.
 *
 * **Revenue spans two tables, and that is not an accident of history.** SMM
 * sales live in `orders`; VTU, virtual numbers, identity checks and gift cards
 * live in `service_transactions` (§19's universal record). They were never
 * merged because an SMM order genuinely has columns a NIN lookup does not —
 * quantity, remains, drip-feed schedule — and forcing one table would have
 * meant a dozen nullable columns and a status vocabulary that means different
 * things per row.
 *
 * The cost of that split is exactly this class: any figure that claims to be
 * "revenue" has to read both, or it silently under-reports by however much the
 * newer domains are selling. Between sessions 21 and 27 this file read only
 * `orders`, so the admin landing page showed VTU, numbers, identity and gift
 * card sales as zero — the panel's headline number was wrong and nothing said
 * so. `AdminStatsTest` now asserts every total against both tables.
 */
class AdminStats {

    /** Statuses in which money has actually been earned, per table. */
    private static $earned = array(
        'orders'               => array('COMPLETED', 'PARTIAL', 'IN_PROGRESS', 'PROCESSING'),
        'service_transactions' => array('SUCCESSFUL', 'PROCESSING'),
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
    }

    /**
     * Revenue across every domain the panel sells, for the last $days days.
     *
     * `orders` and `service_transactions` are summed separately and added,
     * rather than UNION-ed, because the two tables spell the money column
     * differently (`charge` vs `amount`) and a UNION would need a subquery per
     * side anyway. Two indexed aggregate queries is the cheaper honest answer.
     */
    public function revenue($days = 1) {
        $since = gmdate('Y-m-d H:i:s', strtotime('-'.(int)$days.' days'));

        $smm = $this->ci->db
            ->select('COUNT(*) AS n', false)
            ->select('COALESCE(SUM(charge),0) AS gross', false)
            ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
            ->where('created_at >=', $since)
            ->get('orders')->row();

        $services = $this->ci->db
            ->select('COUNT(*) AS n', false)
            ->select('COALESCE(SUM(amount),0) AS gross', false)
            ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
            ->where('created_at >=', $since)
            ->get('service_transactions')->row();

        $gross    = bcadd($this->money($smm->gross ?? 0),    $this->money($services->gross ?? 0), 8);
        $refunded = bcadd($this->money($smm->refunded ?? 0), $this->money($services->refunded ?? 0), 8);

        return array(
            // `orders` is kept as the key for backwards compatibility with the
            // existing widget; it now means "sales", of every kind.
            'orders'   => (int)($smm->n ?? 0) + (int)($services->n ?? 0),
            'smm'      => (int)($smm->n ?? 0),
            'services' => (int)($services->n ?? 0),
            'gross'    => $gross,
            'refunded' => $refunded,
            'net'      => bcsub($gross, $refunded, 8),
        );
    }

    /**
     * Sales, revenue and margin broken down by service domain (§25/§26).
     *
     * The number an operator actually runs the business on. Margin is only
     * meaningful where a vendor cost was recorded, and it deliberately is not
     * for every domain — Dojah bills its own prepaid wallet, and a 5sim price
     * that could not be converted to naira is recorded as NULL rather than as
     * a rouble figure. So `costed` reports how many rows the margin is derived
     * from: a margin over 3 of 400 sales is a number to distrust, and hiding
     * that denominator would make it look authoritative.
     *
     * @return array domain => {sales, gross, refunded, net, cost, costed, margin}
     */
    public function revenue_by_domain($days = 30) {
        $since = gmdate('Y-m-d H:i:s', strtotime('-'.(int)$days.' days'));
        $out = array();

        $smm = $this->ci->db
            ->select('COUNT(*) AS n', false)
            ->select('COALESCE(SUM(charge),0) AS gross', false)
            ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
            ->select('COALESCE(SUM(provider_charge),0) AS cost', false)
            ->select('COALESCE(SUM(CASE WHEN provider_charge IS NOT NULL THEN 1 ELSE 0 END),0) AS costed', false)
            ->where('created_at >=', $since)
            ->get('orders')->row();
        if ((int)($smm->n ?? 0) > 0) $out['SMM'] = $this->shape($smm);

        $rows = $this->ci->db
            ->select('service_domain', false)
            ->select('COUNT(*) AS n', false)
            ->select('COALESCE(SUM(amount),0) AS gross', false)
            ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
            ->select('COALESCE(SUM(provider_cost),0) AS cost', false)
            ->select('COALESCE(SUM(CASE WHEN provider_cost IS NOT NULL THEN 1 ELSE 0 END),0) AS costed', false)
            ->where('created_at >=', $since)
            ->group_by('service_domain')
            ->get('service_transactions')->result();
        foreach ($rows as $r) $out[$r->service_domain] = $this->shape($r);

        // Biggest earner first: the ordering an operator reads down.
        uasort($out, function ($a, $b) { return bccomp($b['net'], $a['net'], 8); });
        return $out;
    }

    /**
     * Delivery health per domain — the operational counterpart to revenue.
     *
     * "Stuck" means the customer has paid and is still waiting: PROCESSING
     * past a grace window. Every one of these is money taken for something not
     * yet delivered, which is why it is counted per domain rather than rolled
     * into one figure — a backlog in gift cards and a backlog in airtime need
     * different people to look at them.
     */
    public function domain_health($stuck_after_minutes = 30) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ((int)$stuck_after_minutes * 60));

        $rows = $this->ci->db
            ->select('service_domain', false)
            ->select('COUNT(*) AS n', false)
            ->select("COALESCE(SUM(CASE WHEN status = 'PROCESSING' THEN 1 ELSE 0 END),0) AS in_flight", false)
            ->select("COALESCE(SUM(CASE WHEN status = 'SUCCESSFUL' THEN 1 ELSE 0 END),0) AS successful", false)
            ->select("COALESCE(SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END),0) AS failed", false)
            ->select("COALESCE(SUM(CASE WHEN status = 'REFUNDED' THEN 1 ELSE 0 END),0) AS refunded", false)
            ->group_by('service_domain')
            ->get('service_transactions')->result();

        $out = array();
        foreach ($rows as $r) {
            $settled = (int)$r->successful + (int)$r->failed;
            $out[$r->service_domain] = array(
                'total'      => (int)$r->n,
                'in_flight'  => (int)$r->in_flight,
                'successful' => (int)$r->successful,
                'failed'     => (int)$r->failed,
                'refunded'   => (int)$r->refunded,
                // Of the purchases that reached an outcome, how many worked.
                // Excludes in-flight deliberately: counting them as failures
                // would make every busy minute look like an outage.
                'success_rate' => $settled > 0
                    ? round(((int)$r->successful / $settled) * 100, 1) : null,
                'stuck'      => 0,
            );
        }

        // Stuck rows are a second, narrower query rather than another CASE:
        // it is the one figure that needs the time cutoff, and it is the one
        // an operator acts on.
        $stuck = $this->ci->db
            ->select('service_domain', false)
            ->select('COUNT(*) AS n', false)
            ->where('status', 'PROCESSING')
            ->where('created_at <', $cutoff)
            ->group_by('service_domain')
            ->get('service_transactions')->result();
        foreach ($stuck as $r) {
            if (!isset($out[$r->service_domain])) continue;
            $out[$r->service_domain]['stuck'] = (int)$r->n;
        }

        return $out;
    }

    /**
     * Vendor reliability from the call log, not from our own bookkeeping.
     *
     * `provider_transactions` records what actually happened on the wire —
     * latency and error included — so this answers "is this vendor healthy?"
     * independently of whether we managed to refund the customer afterwards.
     * The health flag on `providers` is a snapshot of the last probe; this is
     * the trend.
     */
    public function provider_performance($days = 7, $limit = 12) {
        $since = gmdate('Y-m-d H:i:s', strtotime('-'.(int)$days.' days'));

        $rows = $this->ci->db
            ->select('providers.name AS provider_name, providers.api_type', false)
            ->select('COUNT(*) AS calls', false)
            ->select("COALESCE(SUM(CASE WHEN provider_transactions.status = 'SUCCESS' THEN 1 ELSE 0 END),0) AS ok", false)
            ->select('COALESCE(SUM(latency_ms),0) AS latency_total', false)
            ->from('provider_transactions')
            ->join('providers', 'providers.id = provider_transactions.provider_id', 'left')
            ->where('provider_transactions.created_at >=', $since)
            ->group_by('provider_transactions.provider_id')
            ->get()->result();

        $out = array();
        foreach ($rows as $r) {
            $calls = (int)$r->calls;
            if ($calls === 0) continue;
            $out[] = array(
                'provider'    => (string)($r->provider_name ?? 'removed provider'),
                'api_type'    => (string)($r->api_type ?? ''),
                'calls'       => $calls,
                'ok'          => (int)$r->ok,
                'failed'      => $calls - (int)$r->ok,
                'success_rate'=> round(((int)$r->ok / $calls) * 100, 1),
                'avg_latency' => (int)round((float)$r->latency_total / $calls),
            );
        }
        // Worst first: this table exists to surface the vendor that is failing.
        usort($out, function ($a, $b) {
            if ($a['success_rate'] === $b['success_rate']) return $b['calls'] - $a['calls'];
            return $a['success_rate'] < $b['success_rate'] ? -1 : 1;
        });
        return array_slice($out, 0, (int)$limit);
    }

    /**
     * Daily revenue for a sparkline, oldest first.
     *
     * Grouped in PHP over two already-filtered queries rather than with a
     * per-day SQL group, because the two tables would each need their own
     * DATE() grouping and then a merge anyway — and a DATE() call on the
     * column defeats the index on `created_at`.
     */
    public function revenue_series($days = 14) {
        $days  = max(1, (int)$days);
        $since = gmdate('Y-m-d 00:00:00', strtotime('-'.($days - 1).' days'));

        $series = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', strtotime('-'.$i.' days'))] = array('net' => '0.00000000', 'sales' => 0);
        }

        $sources = array(
            array('orders', 'charge'),
            array('service_transactions', 'amount'),
        );
        foreach ($sources as $source) {
            list($table, $column) = $source;
            $rows = $this->ci->db
                ->select('created_at, '.$column.' AS amount, refunded_amount', false)
                ->where('created_at >=', $since)
                ->get($table)->result();
            foreach ($rows as $r) {
                $day = substr((string)$r->created_at, 0, 10);
                if (!isset($series[$day])) continue;
                $series[$day]['net'] = bcadd($series[$day]['net'],
                    bcsub($this->money($r->amount), $this->money($r->refunded_amount), 8), 8);
                $series[$day]['sales']++;
            }
        }
        return $series;
    }

    /* ------------------------------------------------------------------ */

    /** One aggregate row → the shape every domain breakdown uses. */
    private function shape($r) {
        $gross    = $this->money($r->gross ?? 0);
        $refunded = $this->money($r->refunded ?? 0);
        $cost     = $this->money($r->cost ?? 0);
        $net      = bcsub($gross, $refunded, 8);
        $costed   = (int)($r->costed ?? 0);

        return array(
            'sales'    => (int)($r->n ?? 0),
            'gross'    => $gross,
            'refunded' => $refunded,
            'net'      => $net,
            'cost'     => $cost,
            'costed'   => $costed,
            // NULL, not zero, when no row carried a cost: "we made 100% margin"
            // and "we do not know the margin" must not render identically.
            'margin'   => $costed > 0 ? bcsub($net, $cost, 8) : null,
        );
    }

    private function money($v) {
        return number_format((float)$v, 8, '.', '');
    }

    /** Orders grouped by status, for the queue widget. */
    public function order_status_counts() {
        $rows = $this->ci->db->select('status, COUNT(*) AS c', false)
            ->group_by('status')->get('orders')->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->c;
        return $out;
    }

    /**
     * Things a human needs to act on right now.
     *
     * `stuck_services` uses a far tighter window than `stuck_orders` (30
     * minutes against 24 hours) because the domains behind it settle in
     * seconds, not days. An SMM order sitting at PROCESSING for an hour is
     * ordinary; a gift card that has been PROCESSING for an hour means a
     * customer paid and has no code, and a virtual number in that state has
     * almost certainly outlived the vendor's hold.
     */
    public function action_queue() {
        return array(
            'deposits' => (int)$this->ci->db->where('status', 'PENDING')
                ->count_all_results('payment_transactions'),
            'tickets'  => (int)$this->ci->db->where_in('status', array('OPEN', 'PENDING'))
                ->count_all_results('tickets'),
            'unassigned_tickets' => (int)$this->ci->db->where('assigned_to_id IS NULL', null, false)
                ->where_in('status', array('OPEN', 'PENDING'))->count_all_results('tickets'),
            'stuck_orders' => (int)$this->ci->db
                ->where_in('status', array('PENDING', 'PROCESSING'))
                ->where('created_at <', gmdate('Y-m-d H:i:s', strtotime('-24 hours')))
                ->count_all_results('orders'),
            'stuck_services' => (int)$this->ci->db
                ->where_in('status', array('PENDING', 'PROCESSING'))
                ->where('created_at <', gmdate('Y-m-d H:i:s', strtotime('-30 minutes')))
                ->count_all_results('service_transactions'),
        );
    }

    /** Headline platform counts for the admin command center. */
    public function platform_overview() {
        $users = $this->ci->db
            ->select('COUNT(*) AS total', false)
            ->select("COALESCE(SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END),0) AS active", false)
            ->select("COALESCE(SUM(CASE WHEN status='SUSPENDED' THEN 1 ELSE 0 END),0) AS suspended", false)
            ->select("COALESCE(SUM(CASE WHEN created_at >= '".gmdate('Y-m-d 00:00:00')."' THEN 1 ELSE 0 END),0) AS new_today", false)
            ->get('users')->row();
        $orders = $this->order_status_counts();
        $order_today = (int)$this->ci->db->where('created_at >=', gmdate('Y-m-d 00:00:00'))->count_all_results('orders');
        $wallet = $this->ci->db->select('COALESCE(SUM(balance),0) AS total', false)->get('wallets')->row();
        $payouts = 0;
        if ($this->ci->db->table_exists('payouts')) {
            $payouts = (int)$this->ci->db->where_in('status', array('PENDING','APPROVED'))->count_all_results('payouts');
        }
        $failed_orders = (int)($orders['FAILED'] ?? 0) + (int)($orders['ERROR'] ?? 0);
        return array(
            'users_total'     => (int)($users->total ?? 0),
            'users_active'    => (int)($users->active ?? 0),
            'users_suspended' => (int)($users->suspended ?? 0),
            'users_today'     => (int)($users->new_today ?? 0),
            'orders_total'    => array_sum($orders),
            'orders_today'    => $order_today,
            'orders_pending'  => (int)($orders['PENDING'] ?? 0),
            'orders_completed'=> (int)($orders['COMPLETED'] ?? 0),
            'orders_failed'   => $failed_orders,
            'wallet_float'    => $this->money($wallet->total ?? 0),
            'payouts_pending' => $payouts,
        );
    }

    /** Customer totals. */
    public function customers() {
        $row = $this->ci->db
            ->select('COUNT(*) AS total', false)
            ->select("COALESCE(SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END),0) AS active", false)
            ->select("COALESCE(SUM(CASE WHEN created_at >= '".gmdate('Y-m-d', strtotime('-30 days'))."' THEN 1 ELSE 0 END),0) AS new_30d", false)
            ->where('role', 'CUSTOMER')
            ->get('users')->row();
        return array(
            'total'   => (int)($row->total ?? 0),
            'active'  => (int)($row->active ?? 0),
            'new_30d' => (int)($row->new_30d ?? 0),
        );
    }

    /** Provider health summary. */
    public function provider_health() {
        $rows = $this->ci->db->select('health_status, COUNT(*) AS c', false)
            ->where('status', 'ACTIVE')->group_by('health_status')
            ->get('providers')->result();
        $out = array('total' => 0);
        foreach ($rows as $r) {
            $out[$r->health_status] = (int)$r->c;
            $out['total'] += (int)$r->c;
        }
        return $out;
    }

    /** Most recent orders across all customers. */
    public function recent_orders($limit = 8) {
        return $this->ci->db
            ->select('orders.public_id, orders.status, orders.charge, orders.created_at,
                      services.name AS service_name, users.username', false)
            ->from('orders')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->join('users', 'users.id = orders.user_id', 'left')
            ->order_by('orders.created_at', 'DESC')
            ->limit((int)$limit)
            ->get()->result();
    }
}
