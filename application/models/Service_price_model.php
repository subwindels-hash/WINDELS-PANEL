<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Service_price_model extends MY_Model {
    protected $table = 'service_prices';

    public function for_group($service_id, $price_group_id){
        return $this->db->where(array('service_id'=>$service_id,'price_group_id'=>$price_group_id))->get($this->table)->row();
    }
}
