<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Setting_model extends MY_Model {
    protected $table = 'settings';
    protected $primary_key = 'setting_key';

    private static $cache = NULL;

    /**
     * A single setting, read through the per-request memo (Session 18).
     *
     * This used to issue its own point query every time. The settings table is
     * a handful of rows and there are ~16 call sites — placing an order or
     * sending mail hits several — so one cached full read is strictly cheaper
     * than N round-trips, and it keeps get()/all() from disagreeing within a
     * request.
     */
    public function get($key, $default = NULL) {
        $all = $this->all();
        if (!array_key_exists($key, $all)) return $default;
        return $all[$key] === NULL ? $default : $all[$key];
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

    /**
     * Drop the memo.
     *
     * set() does this already; this exists for tests and for the cron CLI,
     * where one process can outlive a settings change made elsewhere.
     */
    public static function flush_cache() {
        self::$cache = NULL;
    }

    public function by_category($category) {
        // Not memoised: category listings are admin-screen only, and caching
        // them per category would just duplicate all().
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
