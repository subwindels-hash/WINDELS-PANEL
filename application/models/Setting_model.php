<?php
defined('BASEPATH') OR exit('No direct script access allowed');
class Setting_model extends MY_Model {
    protected $table='settings';
    public function get($key){
        $row=$this->db->where('setting_key',$key)->get($this->table)->row();
        if (!$row) return null;
        $val=json_decode($row->setting_value,TRUE);
        return is_array($val) && array_key_exists('value',$val) ? $val['value'] : $val;
    }
    public function set($key,$value,$category='general'){
        $exists=$this->db->where('setting_key',$key)->get($this->table)->row();
        $payload=json_encode(array('value'=>$value));
        if ($exists) $this->db->where('setting_key',$key)->update($this->table,array('setting_value'=>$payload,'category'=>$category));
        else $this->db->insert($this->table,array('setting_key'=>$key,'setting_value'=>$payload,'category'=>$category));
    }
}
