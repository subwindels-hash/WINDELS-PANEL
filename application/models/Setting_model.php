<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Setting_model extends MY_Model {
    protected $table = 'settings';
    protected $primary_key = 'setting_key';

    private static $cache = NULL;

    public function get($key, $default = NULL) {
        $row = $this->db->where('setting_key', $key)->get($this->table)->row();
        if (!$row) return $default;
        $val = json_decode($row->setting_value, TRUE);
        $value = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        return $value === NULL ? $default : $value;
    }

    public function set($key, $value, $category = 'general', $is_public = NULL) {
        $payload = json_encode(array('value' => $value));
        $exists = $this->db->where('setting_key', $key)->get($this->table)->row();
        $data = array('setting_value' => $payload, 'category' => $category);
        if ($is_public !== NULL) $data['is_public'] = (int)$is_public;

        if ($exists) {
            $this->db->where('setting_key', $key)->update($this->table, $data);
        } else {
            $this->db->insert($this->table, array_merge(array('setting_key' => $key), $data));
        }
        self::$cache = NULL;
        return TRUE;
    }

    /** All settings as key => value, cached per request. */
    public function all() {
        if (self::$cache !== NULL) return self::$cache;
        $out = array();
        foreach ($this->db->get($this->table)->result() as $row) {
            $val = json_decode($row->setting_value, TRUE);
            $out[$row->setting_key] = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        }
        return self::$cache = $out;
    }

    public function by_category($category) {
        $out = array();
        foreach ($this->db->where('category', $category)->get($this->table)->result() as $row) {
            $val = json_decode($row->setting_value, TRUE);
            $out[$row->setting_key] = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        }
        return $out;
    }

    /** Only settings marked is_public — safe to expose to the browser. */
    public function public_settings() {
        $out = array();
        foreach ($this->db->where('is_public', 1)->get($this->table)->result() as $row) {
            $val = json_decode($row->setting_value, TRUE);
            $out[$row->setting_key] = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        }
        return $out;
    }
}
