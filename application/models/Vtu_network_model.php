<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Vtu_network_model extends MY_Model {
    protected $table = 'vtu_networks';

    public function active($service_type = null){
        $this->db->where('is_active',1);
        if ($service_type) $this->db->where('service_type',$service_type);
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    /** Every network, switched on or not — the admin catalogue picker. */
    public function all_networks(){
        return $this->db->order_by('service_type','ASC')->order_by('sorting','ASC')
                        ->get($this->table)->result();
    }

    public function find_by_code($code){
        return $this->db->where('code',$code)->get($this->table)->row();
    }
}
