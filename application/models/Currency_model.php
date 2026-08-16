<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Currency_model extends MY_Model {
    protected $table = 'currencies';

    protected $primary_key = 'code';
    public function active(){ return $this->db->where('is_active',1)->get($this->table)->result(); }
    public function base(){ return $this->db->where('is_base',1)->get($this->table)->row(); }
}
