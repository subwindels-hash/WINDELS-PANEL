<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet_transaction_model extends MY_Model {
    protected $table = 'wallet_transactions';

    public function for_wallet($wallet_id, $limit=25, $offset=0){
        return $this->db->where('wallet_id',$wallet_id)->order_by('created_at','DESC')
                        ->limit($limit,$offset)->get($this->table)->result();
    }
    public function find_by_idempotency_key($key){
        return $this->db->where('idempotency_key',$key)->get($this->table)->row();
    }
}
