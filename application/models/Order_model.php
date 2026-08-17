<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Order_model extends MY_Model {
    protected $table = 'orders';

    public function for_user($user_id, $limit=25, $offset=0, $status=NULL){
        $this->db->where('user_id',$user_id);
        if ($status) $this->db->where('status',$status);
        return $this->db->order_by('created_at','DESC')->limit($limit,$offset)->get($this->table)->result();
    }
    public function pending_provider_sync($limit=200){
        return $this->db->where_in('status', array('PROCESSING','IN_PROGRESS'))
                        ->where('provider_order_id IS NOT NULL', NULL, FALSE)
                        ->limit($limit)->get($this->table)->result();
    }
    public function find_by_idempotency_key($key){
        return $this->db->where('idempotency_key',$key)->get($this->table)->row();
    }

    /** A single order for a user by its public id (never exposes internal ids). */
    public function find_public_for_user($public_id, $user_id){
        return $this->db->where('public_id', $public_id)->where('user_id', $user_id)
                        ->get($this->table)->row();
    }

    /** Order with its service name joined (for list/detail views). */
    public function for_user_with_service($user_id, $limit=25, $offset=0, $status=NULL){
        $this->db->select('orders.*, services.name AS service_name, services.slug AS service_slug')
                 ->from('orders')
                 ->join('services', 'services.id = orders.service_id', 'left')
                 ->where('orders.user_id', $user_id);
        if ($status) $this->db->where('orders.status', $status);
        return $this->db->order_by('orders.created_at','DESC')
                        ->limit($limit, $offset)->get()->result();
    }

    public function count_for_user($user_id, $status=NULL){
        $this->db->where('user_id',$user_id);
        if ($status) $this->db->where('status',$status);
        return (int)$this->db->count_all_results($this->table);
    }

    /* ------------------------- admin queries ------------------------- */

    /**
     * Admin order queue. Unlike the per-user methods above this is deliberately
     * unscoped, so it is only ever reachable behind `orders.view`.
     *
     * @param array $filters status|user_id|service_id|provider_id|search|source
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('orders.*, services.name AS service_name, users.username, users.email', false)
            ->join('services', 'services.id = orders.service_id', 'left')
            ->join('users', 'users.id = orders.user_id', 'left')
            ->order_by('orders.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    /** Shared WHERE builder so the list and its count can never disagree. */
    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status']))      $this->db->where('orders.status', $f['status']);
        if (!empty($f['user_id']))     $this->db->where('orders.user_id', (int)$f['user_id']);
        if (!empty($f['service_id']))  $this->db->where('orders.service_id', (int)$f['service_id']);
        if (!empty($f['provider_id'])) $this->db->where('orders.provider_id', (int)$f['provider_id']);
        if (!empty($f['source']))      $this->db->where('orders.source', $f['source']);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('orders.public_id', $term)
                ->or_like('orders.provider_order_id', $term)
                ->or_like('orders.link', $term)
                ->group_end();
        }
    }

    /** Count of orders per status, for the queue header cards. */
    public function status_counts(){
        $rows = $this->db->select('status, COUNT(*) AS c', false)
            ->group_by('status')->get($this->table)->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->c;
        return $out;
    }

    /** One order joined with the context an admin needs, by public id. */
    public function admin_find($public_id){
        return $this->db
            ->select('orders.*, services.name AS service_name, services.slug AS service_slug,
                      users.username, users.email, providers.name AS provider_name', false)
            ->from($this->table)
            ->join('services', 'services.id = orders.service_id', 'left')
            ->join('users', 'users.id = orders.user_id', 'left')
            ->join('providers', 'providers.id = orders.provider_id', 'left')
            ->where('orders.public_id', $public_id)
            ->get()->row();
    }
}
