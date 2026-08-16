<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Service_category_model extends MY_Model {
    protected $table = 'service_categories';

    public function active(){
        return $this->db->where('is_active',1)->order_by('sorting','ASC')->get($this->table)->result();
    }
    public function find_by_slug($slug){ return $this->db->where('slug',$slug)->get($this->table)->row(); }
}
