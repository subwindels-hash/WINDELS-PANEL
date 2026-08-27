<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Payout_request_model — withdrawal requests against the earnings ledger.
 */
class Payout_request_model extends MY_Model {

    protected $table = 'payout_requests';

    /** States in which a request still has earnings locked against it. */
    const OPEN = array('REQUESTED', 'APPROVED');

    public function create(array $data) {
        $data['public_id']    = $this->new_public_id();
        $data['requested_at'] = $this->now_utc();
        $data['created_at']   = $this->now_utc();
        $data['updated_at']   = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function by_idempotency_key($key) {
        return $this->db->where('idempotency_key', $key)->get($this->table)->row();
    }

    /** Compare-and-set, so two reviewers cannot both action one request. */
    public function transition($id, $from, $to, array $extra = array()) {
        $data = array_merge($extra, array('status' => $to, 'updated_at' => $this->now_utc()));
        $this->db->where('id', (int)$id)->where('status', $from)->update($this->table, $data);
        return $this->db->affected_rows() > 0;
    }

    /** Whether this user already has money locked in an unfinished request. */
    public function has_open($user_id) {
        return (int)$this->db->where('user_id', (int)$user_id)
                             ->where_in('status', self::OPEN)
                             ->count_all_results($this->table) > 0;
    }

    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db->where('user_id', (int)$user_id)
                        ->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->where('public_id', $public_id)->where('user_id', (int)$user_id)
                        ->get($this->table)->row();
    }

    public function admin_find($public_id) {
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

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

    public function admin_totals() {
        $rows = $this->db->select('status, COALESCE(SUM(amount),0) AS total, COUNT(*) AS n', false)
                         ->group_by('status')->get($this->table)->result();
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
        if (!empty($f['status']))  $this->db->where('status', $f['status']);
        if (!empty($f['user_id'])) $this->db->where('user_id', (int)$f['user_id']);
        if (!empty($f['method']))  $this->db->where('method', $f['method']);
    }
}
