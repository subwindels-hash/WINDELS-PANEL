<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Refill_model extends MY_Model {
    protected $table = 'refills';

    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db->select('refills.*, orders.public_id AS order_public_id, services.name AS service_name')
            ->from('refills')
            ->join('orders', 'orders.id = refills.order_id', 'inner')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->where('orders.user_id', $user_id)
            ->order_by('refills.requested_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function count_for_user($user_id) {
        return (int)$this->db->from('refills')
            ->join('orders', 'orders.id = refills.order_id', 'inner')
            ->where('orders.user_id', $user_id)->count_all_results();
    }

    public function active_for_order($order_id) {
        return $this->db->where('order_id', $order_id)
            ->where_in('status', array('PENDING','PROCESSING','IN_PROGRESS'))
            ->get($this->table)->row();
    }

    public function find_by_id($id) {
        return $this->db->where('id', $id)->get($this->table)->row();
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->select('refills.*')
            ->from('refills')
            ->join('orders', 'orders.id = refills.order_id', 'inner')
            ->where('refills.public_id', $public_id)
            ->where('orders.user_id', $user_id)->get()->row();
    }

    /* ---------------------------- admin queue ---------------------------- */

    /**
     * Every refill, across all customers.
     *
     * Not user-scoped on purpose — the controller gates it behind
     * `orders.refill`. The order and customer are joined in because a refill
     * row on its own says nothing an operator can act on.
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('refills.*, orders.public_id AS order_public_id, orders.link,
                      orders.status AS order_status, services.name AS service_name,
                      users.username, users.email, users.public_id AS user_public_id', false)
            ->join('orders', 'orders.id = refills.order_id', 'left')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->join('users', 'users.id = orders.user_id', 'left')
            ->order_by('refills.requested_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status'])) $this->db->where('refills.status', strtoupper($f['status']));
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('refills.public_id', $term)
                ->or_like('refills.provider_refill_id', $term)
                ->group_end();
        }
    }

    public function status_counts(){
        $out = array();
        foreach (array('PENDING','PROCESSING','IN_PROGRESS','COMPLETED','FAILED') as $s) {
            $this->admin_filters(array('status' => $s));
            $out[$s] = (int)$this->db->count_all_results();
        }
        return $out;
    }

    public function admin_find($public_id){
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    /** Refills awaiting a provider status poll (cron worker, Session 16). */
    public function pending_provider_sync($limit = 100){
        return $this->db
            ->select('refills.*, orders.provider_id', false)
            ->from($this->table)
            ->join('orders', 'orders.id = refills.order_id', 'left')
            ->where_in('refills.status', array('PENDING','PROCESSING','IN_PROGRESS'))
            ->where('refills.provider_refill_id IS NOT NULL', null, false)
            ->order_by('refills.last_checked_at', 'ASC')
            ->limit($limit)
            ->get()->result();
    }
}
