<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Payment_transaction_model extends MY_Model {
    protected $table = 'payment_transactions';

    public function for_user($user_id, $limit=25, $offset=0){
        return $this->db->where('user_id',$user_id)->order_by('created_at','DESC')->limit($limit,$offset)->get($this->table)->result();
    }
    public function find_by_provider_tx($provider_tx_id){
        return $this->db->where('provider_tx_id',$provider_tx_id)->get($this->table)->row();
    }
    public function find_by_idempotency_key($key){
        if (!$key) return null;
        return $this->db->where('idempotency_key',$key)->get($this->table)->row();
    }
    public function find_by_id($id){ return $this->db->where('id',$id)->get($this->table)->row(); }
    /**
     * One payment belonging to this customer, by either reference form.
     *
     * Always scoped by user_id: a guessed reference that belongs to someone
     * else must return nothing rather than another customer's payment. Accepts
     * the internal_reference (what the provider and support quote) or the
     * public_id (what older links carry).
     */
    public function for_user_reference($user_id, $reference){
        return $this->db->where('user_id', (int)$user_id)
                        ->group_start()
                            ->where('internal_reference', $reference)
                            ->or_where('public_id', $reference)
                        ->group_end()
                        ->get($this->table)->row();
    }

    public function find_public_for_user($public_id, $user_id){
        return $this->db->where('public_id',$public_id)->where('user_id',$user_id)->get($this->table)->row();
    }
    public function update_status($id, array $data){ return $this->db->where('id',$id)->update($this->table,$data); }
    public function count_for_user($user_id, $status=null){
        $this->db->where('user_id',$user_id);
        if ($status) $this->db->where('status',$status);
        return (int)$this->db->count_all_results($this->table);
    }

    /* ------------------------- admin queries ------------------------- */

    /**
     * Deposit queue for the back office. Unscoped by design — only reachable
     * behind `payments.view`.
     *
     * @param array $filters status|method_id|search
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('payment_transactions.*, users.username, users.email,
                      payment_methods.name AS method_name, payment_methods.type AS method_type', false)
            ->join('users', 'users.id = payment_transactions.user_id', 'left')
            ->join('payment_methods', 'payment_methods.id = payment_transactions.payment_method_id', 'left')
            ->order_by('payment_transactions.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status']))    $this->db->where('payment_transactions.status', $f['status']);
        if (!empty($f['method_id'])) $this->db->where('payment_transactions.payment_method_id', (int)$f['method_id']);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('payment_transactions.public_id', $term)
                ->or_like('payment_transactions.provider_tx_id', $term)
                ->or_like('users.email', $term)
                ->or_like('users.username', $term)
                ->group_end();
        }
    }

    /** One transaction with its user and method, by public id. */
    public function admin_find($public_id){
        return $this->db
            ->select('payment_transactions.*, users.username, users.email,
                      payment_methods.name AS method_name, payment_methods.type AS method_type', false)
            ->from($this->table)
            ->join('users', 'users.id = payment_transactions.user_id', 'left')
            ->join('payment_methods', 'payment_methods.id = payment_transactions.payment_method_id', 'left')
            ->where('payment_transactions.public_id', $public_id)
            ->get()->row();
    }

    /** Totals for the queue header cards. */
    public function admin_totals(){
        $row = $this->db
            ->select("COUNT(*) AS total", false)
            ->select("COALESCE(SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END),0) AS pending_count", false)
            ->select("COALESCE(SUM(CASE WHEN status='PENDING' THEN amount ELSE 0 END),0) AS pending_amount", false)
            ->select("COALESCE(SUM(CASE WHEN status='SUCCESS' THEN credited_amount ELSE 0 END),0) AS credited", false)
            ->get($this->table)->row();
        return array(
            'total'          => (int)($row->total ?? 0),
            'pending_count'  => (int)($row->pending_count ?? 0),
            'pending_amount' => (string)($row->pending_amount ?? '0.00000000'),
            'credited'       => (string)($row->credited ?? '0.00000000'),
        );
    }
}
