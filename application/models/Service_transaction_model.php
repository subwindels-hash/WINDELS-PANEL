<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Service_transaction_model — the universal transaction record (§19).
 *
 * One row per service purchase in any domain. Money columns are authoritative
 * here; domain tables (vtu_transactions, ...) hold only their own detail.
 */
class Service_transaction_model extends MY_Model {
    protected $table = 'service_transactions';

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id',$id)->update($this->table, $fields);
    }

    public function find_by_idempotency_key($key){
        if (!$key) return null;
        return $this->db->where('idempotency_key',$key)->get($this->table)->row();
    }

    public function find_public_for_user($public_id, $user_id){
        return $this->db->where('public_id',$public_id)->where('user_id',$user_id)
                        ->get($this->table)->row();
    }

    /**
     * Unified history (§20). Filters are optional and combine.
     *
     * @param array $filters domain, type, status, from, to, search
     */
    public function history_for_user($user_id, array $filters = array(), $limit = 25, $offset = 0){
        $this->apply_history_filters($user_id, $filters);
        return $this->db->order_by('created_at','DESC')
                        ->limit($limit, $offset)
                        ->get($this->table)->result();
    }

    public function count_history_for_user($user_id, array $filters = array()){
        $this->apply_history_filters($user_id, $filters);
        return (int)$this->db->count_all_results($this->table);
    }

    private function apply_history_filters($user_id, array $f){
        $this->db->where('user_id',$user_id);
        if (!empty($f['domain'])) $this->db->where('service_domain',$f['domain']);
        if (!empty($f['type']))   $this->db->where('service_type',$f['type']);
        if (!empty($f['status'])) $this->db->where('status',$f['status']);
        if (!empty($f['from']))   $this->db->where('created_at >=',$f['from']);
        if (!empty($f['to']))     $this->db->where('created_at <=',$f['to']);
        if (!empty($f['reference'])) $this->db->where('provider_reference',$f['reference']);
    }

    /** Transactions a status worker should re-check. */
    public function pending_provider_sync($domain, $limit = 100){
        return $this->db->where('service_domain',$domain)
                        ->where('status','PROCESSING')
                        ->where('provider_reference IS NOT NULL', null, false)
                        ->order_by('created_at','ASC')->limit($limit)
                        ->get($this->table)->result();
    }
}
