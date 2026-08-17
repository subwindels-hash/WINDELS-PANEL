<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Payment_event_model extends MY_Model {
    protected $table = 'payment_events';

    public function for_transaction($tx_id) {
        // get() needs the table: without it CI3 builds "SELECT * FROM ()".
        return $this->db->where('payment_transaction_id', $tx_id)
            ->order_by('created_at', 'ASC')->order_by('id', 'ASC')
            ->get($this->table)->result();
    }
}
