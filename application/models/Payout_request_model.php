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

    /** Same as admin_find(), but with the requester's username/email attached for the detail page. */
    public function admin_find_with_user($public_id) {
        return $this->db
            ->select($this->table.'.*, users.username AS user_username, users.email AS user_email, '
                    .'users.public_id AS user_public_id, '
                    .'reviewer.username AS reviewer_username', false)
            ->from($this->table)
            ->join('users', 'users.id = '.$this->table.'.user_id', 'left')
            ->join('users AS reviewer', 'reviewer.id = '.$this->table.'.reviewed_by_id', 'left')
            ->where($this->table.'.public_id', $public_id)
            ->get()->row();
    }

    /**
     * Filtered, searchable admin queue.
     *
     * apply_filters() joins `users` whenever a text search is present so
     * "search users" can match on username/email as well as the request's own
     * public_id — an admin chasing a specific withdrawal rarely has the
     * internal numeric user_id on hand.
     */
    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->db->select($this->table.'.*, users.username AS user_username, users.email AS user_email', false)
                 ->from($this->table)
                 ->join('users', 'users.id = '.$this->table.'.user_id', 'left');
        $this->apply_filters($filters);
        return $this->db->order_by($this->table.'.id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get()->result();
    }

    public function admin_count(array $filters = array()) {
        $this->db->from($this->table)->join('users', 'users.id = '.$this->table.'.user_id', 'left');
        $this->apply_filters($filters);
        return (int)$this->db->count_all_results();
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
        $t = $this->table;
        if (!empty($f['status']))  $this->db->where($t.'.status', $f['status']);
        if (!empty($f['user_id'])) $this->db->where($t.'.user_id', (int)$f['user_id']);
        if (!empty($f['method']))  $this->db->where($t.'.method', $f['method']);

        // Search: username, email or the request's own public reference —
        // whatever an admin is most likely to be holding (a support ticket
        // usually quotes the customer's username/email, not their numeric id).
        if (!empty($f['search'])) {
            $needle = trim((string)$f['search']);
            $this->db->group_start()
                ->like('users.username', $needle)
                ->or_like('users.email', $needle)
                ->or_like($t.'.public_id', $needle)
                ->or_like($t.'.destination', $needle)
                ->group_end();
        }

        if (!empty($f['date_from'])) $this->db->where($t.'.requested_at >=', $f['date_from'].' 00:00:00');
        if (!empty($f['date_to']))   $this->db->where($t.'.requested_at <=', $f['date_to'].' 23:59:59');

        if (!empty($f['amount_min']) && is_numeric($f['amount_min'])) {
            $this->db->where($t.'.amount >=', number_format((float)$f['amount_min'], 8, '.', ''));
        }
        if (!empty($f['amount_max']) && is_numeric($f['amount_max'])) {
            $this->db->where($t.'.amount <=', number_format((float)$f['amount_max'], 8, '.', ''));
        }
    }
}
