<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ActivityFeed — one purchase history across every domain (§20; phase G).
 *
 * The panel sells six different things and records them in two tables: SMM
 * orders in `orders`, and VTU, virtual numbers, identity checks and gift cards
 * in `service_transactions`. That split is deliberate and defended in
 * `AdminStats`, but it leaves customers with a real problem — "what have I
 * bought?" had five different answers on five different pages, and none of
 * them was the whole list. §20 asks for one.
 *
 * This class is the single reader both sides use: the customer's history page
 * and the admin's cross-domain feed. Everything it returns is normalised into
 * one row shape, so a caller never has to know which table a row came from:
 *
 *   domain        SMM|VTU|NUMBER|IDENTITY|GIFTCARD|...
 *   public_id     the reference the customer quotes
 *   label         what was bought, in words
 *   status        the lifecycle status as that domain spells it
 *   amount        what was charged
 *   refunded      how much came back
 *   currency
 *   created_at
 *   url           where to go to see it, or NULL when the caller cannot link
 *
 * Two rules keep it honest:
 *
 *  1. **Read-only, and never a second source of truth.** It re-reads the same
 *     rows the domain screens read. No totals are cached and nothing is
 *     written, so a feed row cannot drift from the transaction it describes.
 *  2. **Paginated by merging two bounded queries.** Both tables are queried
 *     for at most `$limit + $offset` rows, merged, sorted by time, and sliced.
 *     That is more rows than the page needs but a hard bound either way — the
 *     alternative, a UNION across tables whose money columns are spelled
 *     differently, buys accuracy this page does not need at the cost of a
 *     query no index helps.
 */
class ActivityFeed {

    /** Where each domain's detail page lives, for the customer's own rows. */
    private static $customer_routes = array(
        'SMM'      => 'dashboard/orders/',
        'VTU'      => 'dashboard/vtu/',
        'NUMBER'   => 'dashboard/numbers/',
        'IDENTITY' => 'dashboard/identity/',
        'GIFTCARD' => 'dashboard/giftcards/',
    );

    /** ...and where staff go for the same row. */
    private static $admin_routes = array(
        'SMM'      => 'admin/orders/',
        'VTU'      => 'admin/vtu/',
        'NUMBER'   => 'admin/numbers/',
        'IDENTITY' => 'admin/identity/',
        'GIFTCARD' => 'admin/giftcards/',
    );

    /** The permission each admin route is behind. */
    private static $admin_perms = array(
        'SMM'      => 'orders.view',
        'VTU'      => 'vtu.view',
        'NUMBER'   => 'numbers.view',
        'IDENTITY' => 'identity.view',
        'GIFTCARD' => 'giftcards.view',
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Order_model', 'Service_transaction_model'));
    }

    /** Domains a filter dropdown may offer. */
    public static function domains() {
        return array_keys(self::$customer_routes);
    }

    /**
     * One customer's purchases, newest first, across every domain.
     *
     * @param array $filters domain, status
     * @return array{rows:array,total:int}
     */
    public function for_user($user_id, array $filters = array(), $limit = 20, $offset = 0) {
        $domain = strtoupper((string)($filters['domain'] ?? ''));
        $status = (string)($filters['status'] ?? '');
        $window = (int)$limit + (int)$offset;

        $rows = array();
        $total = 0;

        if ($domain === '' || $domain === 'SMM') {
            list($smm, $n) = $this->smm_for_user($user_id, $status, $window);
            $rows = array_merge($rows, $smm);
            $total += $n;
        }
        if ($domain !== 'SMM') {
            list($svc, $n) = $this->services_for_user($user_id, $domain, $status, $window);
            $rows = array_merge($rows, $svc);
            $total += $n;
        }

        return array('rows' => $this->slice($rows, $limit, $offset), 'total' => $total);
    }

    /**
     * The most recent purchases across all customers, for the admin overview.
     *
     * `$permissions` decides which rows get a link, not which rows appear: a
     * staff member without `giftcards.view` still needs to see that gift cards
     * are selling, they just cannot open one. Hiding the row instead would
     * quietly under-report the business to whoever happens to be logged in.
     */
    public function recent(array $permissions = array(), $limit = 10) {
        $rows = array();

        foreach ($this->ci->db
            ->select('orders.public_id, orders.status, orders.charge, orders.refunded_amount,
                      orders.currency, orders.created_at, services.name AS service_name,
                      users.username', false)
            ->from('orders')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->join('users', 'users.id = orders.user_id', 'left')
            ->order_by('orders.created_at', 'DESC')
            ->limit((int)$limit)
            ->get()->result() as $o) {
            $rows[] = $this->row('SMM', $o->public_id,
                $o->service_name ?: 'SMM order', $o->status, $o->charge,
                $o->refunded_amount, $o->currency, $o->created_at,
                self::$admin_routes, $permissions, $o->username);
        }

        foreach ($this->ci->db
            ->select('service_transactions.public_id, service_transactions.service_domain,
                      service_transactions.service_type, service_transactions.status,
                      service_transactions.amount, service_transactions.refunded_amount,
                      service_transactions.currency, service_transactions.created_at,
                      users.username', false)
            ->from('service_transactions')
            ->join('users', 'users.id = service_transactions.user_id', 'left')
            ->order_by('service_transactions.created_at', 'DESC')
            ->limit((int)$limit)
            ->get()->result() as $t) {
            $rows[] = $this->row($t->service_domain, $t->public_id,
                $this->service_label($t), $t->status, $t->amount,
                $t->refunded_amount, $t->currency, $t->created_at,
                self::$admin_routes, $permissions, $t->username);
        }

        return $this->slice($rows, $limit, 0);
    }

    /* ------------------------------------------------------------------ */

    private function smm_for_user($user_id, $status, $window) {
        $this->ci->db->from('orders')->where('user_id', $user_id);
        if ($status !== '') $this->ci->db->where('status', $status);
        $total = (int)$this->ci->db->count_all_results();

        $this->ci->db
            ->select('orders.public_id, orders.status, orders.charge, orders.refunded_amount,
                      orders.currency, orders.created_at, services.name AS service_name', false)
            ->from('orders')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->where('orders.user_id', $user_id);
        if ($status !== '') $this->ci->db->where('orders.status', $status);

        $rows = array();
        foreach ($this->ci->db->order_by('orders.created_at', 'DESC')
            ->limit($window)->get()->result() as $o) {
            $rows[] = $this->row('SMM', $o->public_id, $o->service_name ?: 'SMM order',
                $o->status, $o->charge, $o->refunded_amount, $o->currency, $o->created_at,
                self::$customer_routes);
        }
        return array($rows, $total);
    }

    private function services_for_user($user_id, $domain, $status, $window) {
        $filters = array_filter(array('domain' => $domain, 'status' => $status));
        $total = $this->ci->Service_transaction_model->count_history_for_user($user_id, $filters);

        $rows = array();
        foreach ($this->ci->Service_transaction_model
            ->history_for_user($user_id, $filters, $window, 0) as $t) {
            $rows[] = $this->row($t->service_domain, $t->public_id, $this->service_label($t),
                $t->status, $t->amount, $t->refunded_amount, $t->currency, $t->created_at,
                self::$customer_routes);
        }
        return array($rows, $total);
    }

    /** "Airtime", "Gift card", "NIN check" — what the customer thinks they bought. */
    private function service_label($t) {
        $domain = strtoupper((string)$t->service_domain);
        $type   = ucfirst(strtolower(str_replace('_', ' ', (string)$t->service_type)));

        if ($domain === 'IDENTITY') return strtoupper((string)$t->service_type).' check';
        if ($domain === 'GIFTCARD') return 'Gift card';
        if ($domain === 'NUMBER')   return 'Virtual number';
        return $type !== '' ? $type : $domain;
    }

    /**
     * One normalised row.
     *
     * `url` is NULL rather than absent when the viewer may not open it, so a
     * view renders plain text instead of a dead link — and cannot accidentally
     * offer staff a route their permissions would 404 on.
     */
    private function row($domain, $public_id, $label, $status, $amount, $refunded,
                         $currency, $created_at, array $routes, ?array $permissions = null,
                         $username = null) {
        $domain = strtoupper((string)$domain);
        $url = null;
        if (isset($routes[$domain])) {
            $allowed = true;
            if ($permissions !== null) {
                $perm = self::$admin_perms[$domain] ?? null;
                $allowed = $perm === null
                    || in_array('*', $permissions, true)
                    || in_array($perm, $permissions, true);
            }
            if ($allowed) $url = $routes[$domain].$public_id;
        }

        return array(
            'domain'     => $domain,
            'public_id'  => (string)$public_id,
            'label'      => (string)$label,
            'status'     => (string)$status,
            'amount'     => (string)$amount,
            'refunded'   => (string)($refunded ?? '0'),
            'currency'   => (string)($currency ?: marvy_base_currency()),
            'created_at' => (string)$created_at,
            'username'   => $username,
            'url'        => $url,
        );
    }

    /** Newest first, then the page the caller asked for. */
    private function slice(array $rows, $limit, $offset) {
        usort($rows, function ($a, $b) {
            $cmp = strcmp($b['created_at'], $a['created_at']);
            // Two rows written in the same second still need a stable order,
            // or pagination can show the same row on two pages.
            return $cmp !== 0 ? $cmp : strcmp($b['public_id'], $a['public_id']);
        });
        return array_slice($rows, (int)$offset, (int)$limit);
    }
}
