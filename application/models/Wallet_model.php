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
            'balance'=>'0.00000000', 'currency'=>marvy_base_currency(),
            'created_at'=>$this->now_utc(), 'updated_at'=>$this->now_utc(),
        ));
        return $this->find_by_id($this->db->insert_id());
    }

    /**
     * Float held across every wallet, for the admin wallets view.
     *
     * This is a liability, not revenue: money customers have paid in and not
     * yet spent. Reconciliation starts by comparing it to the bank.
     */
    public function totals(){
        $row = $this->db->select(
            'COALESCE(SUM(balance),0) AS held,
             COALESCE(SUM(total_deposited),0) AS deposited,
             COALESCE(SUM(total_spent),0) AS spent,
             COUNT(*) AS wallets', false)->get($this->table)->row();
        return array(
            'held'      => $row ? $row->held : '0',
            'deposited' => $row ? $row->deposited : '0',
            'spent'     => $row ? $row->spent : '0',
            'wallets'   => $row ? (int)$row->wallets : 0,
        );
    }
}
