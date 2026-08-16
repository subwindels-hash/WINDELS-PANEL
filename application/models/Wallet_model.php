<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Wallet_model extends MY_Model {
    protected $table = 'wallets';

    /** Wallet for a user, creating it lazily on first access. */
    public function for_user($user_id){
        $row = $this->db->where('user_id',$user_id)->get($this->table)->row();
        if ($row) return $row;
        $this->db->insert($this->table, array(
            'public_id'=>$this->new_public_id(), 'user_id'=>$user_id,
            'balance'=>'0.00000000', 'currency'=>'USD',
            'created_at'=>$this->now_utc(), 'updated_at'=>$this->now_utc(),
        ));
        return $this->find_by_id($this->db->insert_id());
    }
}
