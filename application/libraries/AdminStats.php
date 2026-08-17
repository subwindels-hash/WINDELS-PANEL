<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AdminStats — read-only aggregates for the admin landing page (Session 15).
 *
 * Every method is a single grouped/aggregate query rather than a row scan, so
 * the dashboard stays cheap as the tables grow. Nothing here writes.
 */
class AdminStats {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
    }

    /** Revenue and order counts for the last $days days (UTC). */
    public function revenue($days = 1) {
        $since = gmdate('Y-m-d H:i:s', strtotime('-'.(int)$days.' days'));
        $row = $this->ci->db
            ->select('COUNT(*) AS orders', false)
            ->select('COALESCE(SUM(charge),0) AS gross', false)
            ->select('COALESCE(SUM(refunded_amount),0) AS refunded', false)
            ->where('created_at >=', $since)
            ->get('orders')->row();

        $gross    = (string)($row->gross ?? '0');
        $refunded = (string)($row->refunded ?? '0');
        return array(
            'orders'   => (int)($row->orders ?? 0),
            'gross'    => $gross,
            'refunded' => $refunded,
            'net'      => bcsub($gross, $refunded, 8),
        );
    }

    /** Orders grouped by status, for the queue widget. */
    public function order_status_counts() {
        $rows = $this->ci->db->select('status, COUNT(*) AS c', false)
            ->group_by('status')->get('orders')->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->c;
        return $out;
    }

    /** Things a human needs to act on right now. */
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
