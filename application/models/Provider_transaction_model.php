<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Provider call log shared by every domain (§14). */
class Provider_transaction_model extends MY_Model {
    protected $table = 'provider_transactions';

    public function record(array $data){
        $data['created_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_transaction($tx_id){
        return $this->db->where('service_transaction_id',$tx_id)
                        ->order_by('created_at','ASC')->get($this->table)->result();
    }
}
