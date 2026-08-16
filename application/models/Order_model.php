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
}
