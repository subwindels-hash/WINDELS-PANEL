<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ledger_entry_model extends MY_Model {
    protected $table = 'ledger_entries';

    public function for_transaction($wallet_transaction_id){
        return $this->db->where('wallet_transaction_id',$wallet_transaction_id)->get($this->table)->result();
    }
    /** Sum of an account, used by reconciliation reports. */
    public function account_balance($account){
        $row = $this->db->select("SUM(CASE WHEN direction='CREDIT' THEN amount ELSE -amount END) AS total", FALSE)
                        ->where('account',$account)->get($this->table)->row();
        return $row && $row->total !== NULL ? $row->total : '0.00000000';
    }
}
