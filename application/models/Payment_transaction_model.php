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
}
