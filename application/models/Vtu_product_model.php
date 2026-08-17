<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Vtu_product_model extends MY_Model {
    protected $table = 'vtu_products';

    /** Products a customer may buy, for one network and type. */
    public function active_for($network_id, $service_type){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('is_active',1)
                        ->order_by('sorting','ASC')
                        ->get($this->table)->result();
    }

    public function find_active($id){
        return $this->db->where('id',$id)->where('is_active',1)->get($this->table)->row();
    }

    public function find_by_code($network_id, $service_type, $code){
        return $this->db->where('network_id',$network_id)
                        ->where('service_type',$service_type)
                        ->where('code',$code)->get($this->table)->row();
    }
}
