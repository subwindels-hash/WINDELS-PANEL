<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Countries virtual numbers can be rented in (§10). */
class Number_country_model extends MY_Model {
    protected $table = 'number_countries';

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
