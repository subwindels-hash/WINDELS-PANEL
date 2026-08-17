<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Payment_event_model extends MY_Model {
    protected $table = 'payment_events';

    public function for_transaction($tx_id) {
        return $this->db->where('payment_transaction_id', $tx_id)
            ->order_by('created_at', 'ASC')->get()->result();
    }
}
