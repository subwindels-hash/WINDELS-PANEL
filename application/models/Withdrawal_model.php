<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Withdrawal queue and guarded lifecycle persistence. */
class Withdrawal_model extends MY_Model {
    protected $table = 'withdrawal_requests';

    public function create(array $fields) {
        $this->db->insert($this->table, $fields);
        return $this->find_by_id($this->db->insert_id());
    }

    public function find_by_idempotency($key) {
        if (!$key) return null;
        return $this->db->where('idempotency_key', $key)->get($this->table)->row();
    }

    /** Internal lifecycle lock. This row includes ciphertext and must not reach a view. */
    public function find_for_update($public_id) {
        return $this->db->query(
            'SELECT * FROM withdrawal_requests WHERE public_id=? FOR UPDATE',
            array((string)$public_id)
        )->row();
    }

    public function find_owned($public_id, $user_id) {
        return $this->db->select($this->safe_projection(), false)
            ->where('public_id', $public_id)->where('user_id', (int)$user_id)
            ->get($this->table)->row();
    }

    public function for_user($user_id, $limit = 20, $offset = 0) {
        return $this->db->select($this->safe_projection(), false)
            ->where('user_id', (int)$user_id)->order_by('created_at', 'DESC')
            ->limit($limit, $offset)->get($this->table)->result();
    }

    public function count_for_user($user_id) {
        return (int)$this->db->where('user_id', (int)$user_id)->count_all_results($this->table);
    }

    public function admin_search(array $filters, $limit = 25, $offset = 0) {
        $this->admin_filters($filters);
        return $this->db->select($this->safe_projection('withdrawal_requests').', users.username, users.email', false)
            ->order_by('withdrawal_requests.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters) {
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    public function admin_find($public_id) {
        return $this->db->select($this->safe_projection('withdrawal_requests').', users.username, users.email', false)
            ->from($this->table)
            ->join('users', 'users.id = withdrawal_requests.user_id', 'left')
            ->where('withdrawal_requests.public_id', $public_id)->get()->row();
    }

    public function admin_totals() {
        $row = $this->db
            ->select('COUNT(*) AS total', false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('PENDING','APPROVED') THEN 1 ELSE 0 END),0) AS open_count", false)
            ->select("COALESCE(SUM(CASE WHEN status IN ('PENDING','APPROVED') THEN payout_amount ELSE 0 END),0) AS open_amount", false)
            ->select("COALESCE(SUM(CASE WHEN status='PAID' THEN payout_amount ELSE 0 END),0) AS paid_amount", false)
            ->get($this->table)->row();
        return array(
            'total' => (int)($row->total ?? 0),
            'open_count' => (int)($row->open_count ?? 0),
            'open_amount' => (string)($row->open_amount ?? '0.00000000'),
            'paid_amount' => (string)($row->paid_amount ?? '0.00000000'),
        );
    }

    /** Compare-and-set one status transition while the service holds a row lock. */
    public function transition($id, $from, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        $this->db->where('id', (int)$id)->where('status', $from)->update($this->table, $fields);
        return $this->db->affected_rows() === 1;
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function event($withdrawal_id, $actor_id, $type, $from = null, $to = null, $note = null) {
        return $this->db->insert('withdrawal_events', array(
            'withdrawal_id' => (int)$withdrawal_id,
            'actor_id' => $actor_id ? (int)$actor_id : null,
            'event_type' => substr((string)$type, 0, 32),
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note === null ? null : mb_substr((string)$note, 0, 500),
            'created_at' => $this->now_utc(),
        ));
    }

    public function events($withdrawal_id) {
        return $this->db->where('withdrawal_id', (int)$withdrawal_id)
            ->order_by('created_at', 'ASC')->get('withdrawal_events')->result();
    }

    public function record_reveal($id, $actor_id) {
        $row = $this->find_by_id($id);
        return $this->db->where('id', (int)$id)->update($this->table, array(
            'reveal_count' => (int)($row->reveal_count ?? 0) + 1,
            'last_revealed_at' => $this->now_utc(),
            'last_revealed_by' => $actor_id ? (int)$actor_id : null,
            'updated_at' => $this->now_utc(),
        ));
    }

    private function admin_filters(array $filters) {
        $this->db->from($this->table)
            ->join('users', 'users.id = withdrawal_requests.user_id', 'left');
        if (!empty($filters['status'])) $this->db->where('withdrawal_requests.status', $filters['status']);
        if (!empty($filters['search'])) {
            $term = trim((string)$filters['search']);
            $this->db->group_start()
                ->like('withdrawal_requests.public_id', $term)
                ->or_like('withdrawal_requests.payout_reference', $term)
                ->or_like('users.username', $term)
                ->or_like('users.email', $term)
                ->group_end();
        }
    }

    /** Every ordinary query deliberately excludes destination_encrypted. */
    private function safe_projection($prefix = null) {
        $columns = array(
            'id', 'public_id', 'user_id', 'wallet_transaction_id', 'refund_wallet_transaction_id',
            'amount', 'fee_amount', 'payout_amount', 'currency', 'status', 'destination_label',
            'idempotency_key', 'payout_reference', 'admin_note', 'approved_at', 'approved_by',
            'paid_at', 'paid_by', 'resolved_at', 'reveal_count', 'last_revealed_at',
            'last_revealed_by', 'created_at', 'updated_at',
        );
        if (!$prefix) return implode(', ', $columns);
        return implode(', ', array_map(function ($column) use ($prefix) {
            return $prefix.'.'.$column;
        }, $columns));
    }
}
