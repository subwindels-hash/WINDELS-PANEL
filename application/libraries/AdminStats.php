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

    /**
     * Statuses in which money has actually been earned, per table.
     *
     * This constant existed from the start and **was never used by a single
     * query**: every revenue figure counted every row created in the window,
     * so a failed VTU top-up, an order cancelled before it was submitted and a
     * deposit-less PENDING order all reported as revenue. The headline number
     * on the first screen an operator sees was the *volume of attempts*, not
     * income, and it was always too high.
     *
     * What belongs here:
     *
     *  - delivered, or being delivered — the sale stands;
     *  - REFUNDED — the sale happened and the money was given back, so it must
     *    appear in gross AND in refunded, or a goodwill refund would be
     *    invisible in the reporting;
     *
     * and what does not: PENDING (charged but not yet delivered — the action
     * queue's job, not revenue's), FAILED / ERROR / CANCELED / EXPIRED (a wash:
     * charged, then refunded, no income either way).
     */
    private static $earned = array(
        'orders'               => array('COMPLETED', 'PARTIAL', 'IN_PROGRESS', 'PROCESSING', 'REFUNDED'),
        'service_transactions' => array('SUCCESSFUL', 'PROCESSING', 'REFUNDED'),
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
            ->where_in('status', self::$earned['orders'])
            ->get('orders')->row();

        $services = $this->ci->db
            ->select('COUNT(*) AS n', false)
            ->select('COALESCE(SUM(amount),0) AS gross', false)
            ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
            ->where('created_at >=', $since)
            ->where_in('status', self::$earned['service_transactions'])
            ->get('service_transactions')->row();

        // Attempts that earned nothing. Reported, not hidden: an operator who
        // sees 40 sales and 60 attempts knows to look at delivery, and it
        // stops anyone reading a suddenly smaller revenue figure as data loss.
        $unearned = (int)$this->ci->db->where('created_at >=', $since)
                ->where_not_in('status', self::$earned['orders'])
                ->count_all_results('orders')
            + (int)$this->ci->db->where('created_at >=', $since)
                ->where_not_in('status', self::$earned['service_transactions'])
                ->count_all_results('service_transactions');

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
            'unearned' => $unearned,
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
            ->where_in('status', self::$earned['orders'])
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
            ->where_in('status', self::$earned['service_transactions'])
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
    public function domain_health($stuck_after_minutes = 30, $smm_stuck_after_minutes = 1440) {
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

        // SMM comes from `orders` and was missing from this table entirely —
        // the panel's largest domain had a revenue row on this page and no
        // delivery row, so an SMM backlog was the one thing the delivery-health
        // widget could not show. Its own window is far wider on purpose: an SMM
        // order in flight for half an hour is ordinary, while a gift card in
        // that state means a customer has paid and has no code.
        $smm = $this->smm_health($smm_stuck_after_minutes);
        if ($smm !== null) $out = array('SMM' => $smm) + $out;

        return $out;
    }

    /** The `orders` half of domain_health(), in the same shape. */
    private function smm_health($stuck_after_minutes) {
        $row = $this->ci->db
            ->select('COUNT(*) AS n', false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('PENDING','PROCESSING','IN_PROGRESS') THEN 1 ELSE 0 END),0) AS in_flight", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('COMPLETED','PARTIAL') THEN 1 ELSE 0 END),0) AS successful", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('FAILED','ERROR') THEN 1 ELSE 0 END),0) AS failed", false)
            ->select("COALESCE(SUM(CASE WHEN status = 'REFUNDED' THEN 1 ELSE 0 END),0) AS refunded", false)
            ->get('orders')->row();
        if (!$row || (int)$row->n === 0) return null;

        $settled = (int)$row->successful + (int)$row->failed;
        $stuck = (int)$this->ci->db
            ->where_in('status', array('PENDING', 'PROCESSING', 'IN_PROGRESS'))
            ->where('created_at <', gmdate('Y-m-d H:i:s', time() - ((int)$stuck_after_minutes * 60)))
            ->count_all_results('orders');

        return array(
            'total'        => (int)$row->n,
            'in_flight'    => (int)$row->in_flight,
            'successful'   => (int)$row->successful,
            'failed'       => (int)$row->failed,
            'refunded'     => (int)$row->refunded,
            // A cancellation is neither a delivery nor a fault, so it is left
            // out of the denominator exactly as in-flight rows are.
            'success_rate' => $settled > 0 ? round(((int)$row->successful / $settled) * 100, 1) : null,
            'stuck'        => $stuck,
        );
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
     * Aggregated in SQL. It used to `SELECT` every order and every service
     * transaction in the window and add them up in PHP — on a panel doing a
     * few thousand sales a day that is tens of thousands of rows materialised
     * on **every admin page load**, because the landing page draws this chart
     * too. Grouping by the date prefix costs the index on the GROUP BY (it
     * cannot use one either way) but keeps the range scan in the WHERE, and
     * returns at most one row per day per table.
     *
     * `SUBSTR(created_at, 1, 10)` rather than `DATE()`: both MySQL and the
     * SQLite-backed dev database implement it identically for a DATETIME
     * column, and the values are UTC in both, matching the keys built below.
     *
     * It also applies the same earned-status filter as the cards above it. A
     * chart that counted cancelled orders while the summary did not would have
     * the page disagreeing with itself.
     */
    public function revenue_series($days = 14) {
        $days  = max(1, (int)$days);
        $since = gmdate('Y-m-d 00:00:00', strtotime('-'.($days - 1).' days'));

        $series = array();
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', strtotime('-'.$i.' days'))] = array('net' => '0.00000000', 'sales' => 0);
        }

        $sources = array(
            array('orders', 'charge', self::$earned['orders']),
            array('service_transactions', 'amount', self::$earned['service_transactions']),
        );
        foreach ($sources as $source) {
            list($table, $column, $statuses) = $source;
            $rows = $this->ci->db
                ->select('SUBSTR(created_at, 1, 10) AS day', false)
                ->select('COUNT(*) AS sales', false)
                ->select('COALESCE(SUM('.$column.'),0) AS gross', false)
                ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
                ->where('created_at >=', $since)
                ->where_in('status', $statuses)
                ->group_by('day', false)
                ->get($table)->result();
            foreach ($rows as $r) {
                $day = (string)$r->day;
                if (!isset($series[$day])) continue;
                $series[$day]['net'] = bcadd($series[$day]['net'],
                    bcsub($this->money($r->gross), $this->money($r->refunded), 8), 8);
                $series[$day]['sales'] += (int)$r->sales;
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
