<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_service_price_model extends MY_Model {
    protected $table = 'user_service_prices';

    public function for_user($user_id, $service_id){
        return $this->db->where(array('user_id'=>$user_id,'service_id'=>$service_id))->get($this->table)->row();
    }
}
