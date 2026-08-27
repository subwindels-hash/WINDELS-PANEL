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

    public function all_rows() {
        return $this->db->order_by('flag_key', 'ASC')->get($this->table)->result();
    }

    public function set_enabled($key, $enabled) {
        $key = substr(preg_replace('/[^a-z0-9_.\\-]/i', '', (string)$key), 0, 128);
        if ($key === '') return false;
        $row = $this->db->where('flag_key', $key)->get($this->table)->row();
        $data = array('enabled' => $enabled ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s'));
        if ($row) {
            $this->db->where('flag_key', $key)->update($this->table, $data);
        } else {
            $data['flag_key'] = $key;
            $this->db->insert($this->table, $data);
        }
        return true;
    }
}
