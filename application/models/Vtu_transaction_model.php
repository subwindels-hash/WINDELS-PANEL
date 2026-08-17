<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** VTU-specific detail; money lives on service_transactions. */
class Vtu_transaction_model extends MY_Model {
    protected $table = 'vtu_transactions';

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_transaction($tx_id){
        return $this->db->where('service_transaction_id',$tx_id)->get($this->table)->row();
    }

    public function update_for_transaction($tx_id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('service_transaction_id',$tx_id)->update($this->table, $fields);
    }
}
