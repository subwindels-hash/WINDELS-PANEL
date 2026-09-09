<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Earning_model — the earnings ledger.
 *
 * Balances are always computed here with SQL SUMs rather than cached on the
 * user row, so there is no second number that can disagree with the ledger.
 */
class Earning_model extends MY_Model {

    protected $table = 'earnings';

    public function by_idempotency_key($key) {
        return $this->db->where('idempotency_key', $key)->get($this->table)->row();
    }

    public function insert_row(array $row) {
        $this->db->insert($this->table, $row);
        return (int)$this->db->insert_id();
    }

    /**
     * Move an earning between states, only if it is still in the state we saw.
     *
     * The `where status = $from` is the concurrency control: two overlapping
     * cron runs or a double-clicked admin action both try the update, and only
     * the first one matches. Returns whether this caller was the one that moved
     * it, which is what lets callers count real transitions.
     */
    public function transition($id, $from, $to, array $extra = array()) {
        $data = array_merge($extra, array(
            'status'     => $to,
            'updated_at' => $this->now_utc(),
        ));

        $this->db->where('id', (int)$id)->where('status', $from)->update($this->table, $data);
        return $this->db->affected_rows() > 0;
    }

    /** Totals per status for one user, as exact decimal strings. */
    public function sums_by_status($user_id) {
        $rows = $this->db->select('status, COALESCE(SUM(amount), 0) AS total', false)
                         ->where('user_id', (int)$user_id)
                         ->group_by('status')
                         ->get($this->table)->result();

        $out = array();
        foreach ($rows as $row) {
            $out[$row->status] = number_format((float)$row->total, 8, '.', '');
        }
        return $out;
    }

    /**
     * Totals per source, counting only money the user still owns or was paid.
     *
     * REVERSED rows are excluded: a reversed referral did not earn anything,
     * and showing it under "referral earnings" would overstate the total.
     */
    public function sums_by_source($user_id) {
        $rows = $this->db->select('source, COALESCE(SUM(amount), 0) AS total', false)
                         ->where('user_id', (int)$user_id)
                         ->where_in('status', array('PENDING', 'AVAILABLE', 'LOCKED', 'PAID'))
                         ->group_by('source')
                         ->get($this->table)->result();

        $out = array();
        foreach ($rows as $row) {
            $out[$row->source] = number_format((float)$row->total, 8, '.', '');
        }
        return $out;
    }

    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db->where('user_id', (int)$user_id)
                        ->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    public function count_for_user($user_id) {
        return (int)$this->db->where('user_id', (int)$user_id)->count_all_results($this->table);
    }

    /** PENDING earnings whose holding period has elapsed. */
    public function due_for_release($now, $limit = 500) {
        return $this->db->where('status', 'PENDING')
                        ->where('available_at IS NOT NULL', null, false)
                        ->where('available_at <=', $now)
                        ->order_by('id', 'ASC')
                        ->limit(max(1, min(2000, (int)$limit)))
                        ->get($this->table)->result();
    }

    /**
     * AVAILABLE earnings for a user, oldest first.
     *
     * Oldest-first matters: earnings are consumed in the order they were
     * earned, so a payout draws down the money that has been sitting longest.
     */
    public function available_for_user($user_id, $limit = 1000) {
        return $this->db->where('user_id', (int)$user_id)
                        ->where('status', 'AVAILABLE')
                        ->order_by('id', 'ASC')
                        ->limit(max(1, min(5000, (int)$limit)))
                        ->get($this->table)->result();
    }

    /** Earnings reserved against one payout request. */
    public function for_payout($payout_request_id) {
        return $this->db->where('payout_request_id', (int)$payout_request_id)
                        ->get($this->table)->result();
    }

    /** Bounded admin grid. */
    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->apply_filters($filters);
        return $this->db->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    public function admin_count(array $filters = array()) {
        $this->apply_filters($filters);
        return (int)$this->db->count_all_results($this->table);
    }

    /** Platform-wide totals for the admin dashboard. */
    public function admin_totals() {
        $rows = $this->db->select('status, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n', false)
                         ->group_by('status')
                         ->get($this->table)->result();
        $out = array();
        foreach ($rows as $row) {
            $out[$row->status] = array(
                'total' => number_format((float)$row->total, 8, '.', ''),
                'count' => (int)$row->n,
            );
        }
        return $out;
    }

    private function apply_filters(array $f) {
        if (!empty($f['user_id'])) $this->db->where('user_id', (int)$f['user_id']);
        if (!empty($f['status']))  $this->db->where('status', $f['status']);
        if (!empty($f['source']))  $this->db->where('source', $f['source']);
        if (!empty($f['campaign_id'])) $this->db->where('campaign_id', (int)$f['campaign_id']);
    }
}
