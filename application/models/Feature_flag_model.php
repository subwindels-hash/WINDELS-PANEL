<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Feature_flag_model extends MY_Model {
    protected $table = 'feature_flags';

    protected $primary_key = 'flag_key';
    public function enabled($key){
        $row = $this->db->where('flag_key',$key)->get($this->table)->row();
        return $row ? (bool)$row->enabled : FALSE;
    }
    public function all_flags(){
        $out = array();
        foreach ($this->db->get($this->table)->result() as $r) $out[$r->flag_key] = (bool)$r->enabled;
        return $out;
    }
}
