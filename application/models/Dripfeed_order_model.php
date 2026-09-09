<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dripfeed_order_model extends MY_Model {
    protected $table = 'dripfeed_orders';

    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db->where('user_id', $user_id)
            ->order_by('created_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function count_for_user($user_id) {
        return (int)$this->db->where('user_id', $user_id)->count_all_results($this->table);
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->where('public_id', $public_id)->where('user_id', $user_id)->get($this->table)->row();
    }

    public function find_by_id($id) {
        return $this->db->where('id', $id)->get($this->table)->row();
    }

    public function due_runs($limit = 100) {
        return $this->db->where('status', 'ACTIVE')
            ->where('next_run_at <=', gmdate('Y-m-d H:i:s'))
            ->order_by('next_run_at', 'ASC')->limit($limit)->get($this->table)->result();
    }

    /* ---------------------------- admin queue ---------------------------- */

    /** Every drip-feed schedule, across all customers, for the admin queue. */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('dripfeed_orders.*, services.name AS service_name,
                      users.username, users.email, users.public_id AS user_public_id', false)
            ->join('services', 'services.id = dripfeed_orders.service_id', 'left')
            ->join('users', 'users.id = dripfeed_orders.user_id', 'left')
            ->order_by('dripfeed_orders.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status']))  $this->db->where('dripfeed_orders.status', strtoupper($f['status']));
        if (!empty($f['user_id'])) $this->db->where('dripfeed_orders.user_id', (int)$f['user_id']);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('dripfeed_orders.public_id', $term)
                ->or_like('dripfeed_orders.link', $term)
                ->group_end();
        }
    }

    public function status_counts(){
        $out = array();
        foreach (array('ACTIVE','PAUSED','CANCELED','COMPLETED') as $s) {
            $this->admin_filters(array('status' => $s));
            $out[$s] = (int)$this->db->count_all_results();
        }
        return $out;
    }

    public function admin_find($public_id){
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }
}
