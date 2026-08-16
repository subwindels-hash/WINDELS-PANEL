<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Provider_model extends MY_Model {
    protected $table = 'providers';

    public function active(){ return $this->db->where('status','ACTIVE')->get($this->table)->result(); }
    public function due_for_sync(){
        return $this->db->where('status','ACTIVE')
            ->where('(last_successful_sync_at IS NULL OR last_successful_sync_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL sync_interval_minutes MINUTE))', NULL, FALSE)
            ->get($this->table)->result();
    }
}
