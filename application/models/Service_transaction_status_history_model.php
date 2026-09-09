<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Append-only status trail for service_transactions (§8 audit trail). */
class Service_transaction_status_history_model extends MY_Model {
    protected $table = 'service_transaction_status_history';

    public function record($tx_id, $from, $to, $source = 'SYSTEM', $reason = null){
        return $this->db->insert($this->table, array(
            'service_transaction_id' => $tx_id,
            'from_status' => $from,
            'to_status'   => $to,
            'source'      => $source,
            'reason'      => $reason,
            'created_at'  => $this->now_utc(),
        ));
    }

    public function for_transaction($tx_id){
        return $this->db->where('service_transaction_id',$tx_id)
                        ->order_by('created_at','ASC')->get($this->table)->result();
    }
}
