<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Provider_model extends MY_Model {
    protected $table = 'providers';

    public function active(){
        return $this->db->where('status','ACTIVE')->order_by('name','ASC')->get($this->table)->result();
    }

    public function all($status = null){
        if ($status) $this->db->where('status', $status);
        return $this->db->order_by('name','ASC')->get($this->table)->result();
    }

    public function paginated($limit, $offset, $status = null){
        if ($status) $this->db->where('status', $status);
        return $this->db->order_by('name','ASC')->limit($limit,$offset)->get($this->table)->result();
    }

    public function count_all($status = null){
        if ($status) $this->db->where('status', $status);
        return (int)$this->db->count_all_results($this->table);
    }

    public function due_for_sync(){
        return $this->db->where('status','ACTIVE')
            ->where('(last_successful_sync_at IS NULL OR last_successful_sync_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL sync_interval_minutes MINUTE))', NULL, FALSE)
            ->get($this->table)->result();
    }

    public function find_by_public_id($public_id){
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return $this->find_by_id($this->db->insert_id());
    }

    public function update_provider($id, array $data){
        return $this->db->where('id', $id)->update($this->table, $data);
    }

    /** Record a health-check result (also updates the provider summary row). */
    public function record_health($id, $status, $latency_ms = null, $error = null){
        $this->db->insert('provider_health_logs', array(
            'provider_id' => $id,
            'status'      => $status,
            'latency_ms'  => $latency_ms,
            'error'       => $error,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ));
        $this->db->where('id', $id)->update($this->table, array(
            'health_status'        => $status,
            'last_health_check_at' => gmdate('Y-m-d H:i:s'),
            'last_error'           => $status === 'ONLINE' ? null : $error,
        ));
    }

    /** Record a sync result (also updates last_successful_sync_at). */
    public function record_sync($id, $type, $status, $message = null, $items = 0, $duration_ms = null, $metadata = null){
        $this->db->insert('provider_sync_logs', array(
            'provider_id' => $id,
            'type'        => $type,
            'status'      => $status,
            'message'     => $message,
            'items_synced'=> $items,
            'duration_ms' => $duration_ms,
            'metadata'    => $metadata ? json_encode($metadata) : null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ));
        if ($status === 'SUCCESS') {
            $this->db->where('id', $id)->update($this->table, array(
                'last_successful_sync_at' => gmdate('Y-m-d H:i:s'),
                'last_error' => null,
            ));
        }
    }

    public function recent_sync_logs($id, $limit = 20){
        return $this->db->where('provider_id', $id)
            ->order_by('created_at','DESC')->limit($limit)->get('provider_sync_logs')->result();
    }

    public function recent_health_logs($id, $limit = 20){
        return $this->db->where('provider_id', $id)
            ->order_by('created_at','DESC')->limit($limit)->get('provider_health_logs')->result();
    }
}
