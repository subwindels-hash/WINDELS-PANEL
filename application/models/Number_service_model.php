<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Services an OTP can be requested from — WhatsApp, Telegram, ... (§10, §11). */
class Number_service_model extends MY_Model {
    protected $table = 'number_services';

    public function active(){
        return $this->db->where('is_active',1)->order_by('sorting','ASC')
                        ->get($this->table)->result();
    }

    public function all(){
        return $this->db->order_by('sorting','ASC')->get($this->table)->result();
    }

    public function find_by_code($code){
        return $this->db->where('code',strtoupper($code))->get($this->table)->row();
    }
}
