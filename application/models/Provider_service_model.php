<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Provider_service_model extends MY_Model {
    protected $table = 'provider_services';

    public function for_provider($provider_id){
        return $this->db->where('provider_id',$provider_id)->get($this->table)->result();
    }
}
