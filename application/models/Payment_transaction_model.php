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
    public function find_public_for_user($public_id, $user_id){
        return $this->db->where('public_id',$public_id)->where('user_id',$user_id)->get($this->table)->row();
    }
    public function update_status($id, array $data){ return $this->db->where('id',$id)->update($this->table,$data); }
    public function count_for_user($user_id, $status=null){
        $this->db->where('user_id',$user_id);
        if ($status) $this->db->where('status',$status);
        return (int)$this->db->count_all_results($this->table);
    }
}
