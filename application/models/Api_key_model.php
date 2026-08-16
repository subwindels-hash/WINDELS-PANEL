<?php
defined('BASEPATH') OR exit('No direct script access allowed');
class Api_key_model extends MY_Model {
    protected $table='api_keys';
    public function find_valid_by_key($raw){
        $hash=hash('sha256',$raw);
        $row=$this->db->where('key_hash',$hash)->where('revoked_at IS NULL',null,FALSE)->get($this->table)->row();
        if ($row && $row->expires_at && strtotime($row->expires_at) < time()) return null;
        return $row;
    }
}
