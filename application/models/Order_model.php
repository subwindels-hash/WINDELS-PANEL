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
}
