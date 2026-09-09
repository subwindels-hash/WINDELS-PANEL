<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Role_model extends MY_Model {
    protected $table = 'roles';

    public function find_by_name($name){ return $this->db->where('name',$name)->get($this->table)->row(); }
}
