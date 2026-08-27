<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Setting_model extends MY_Model {
    protected $table = 'settings';
    protected $primary_key = 'setting_key';

    private static $cache = NULL;

    /** Memo for "does the settings table exist yet" (pre-migration installs). */
    private static $table_present = NULL;

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
        // Re-probe rather than trusting the memo: a migration inside this same
        // process (install wizard, `migrate` then `seed`) creates the table
        // after an earlier read cached its absence.
        self::$table_present = NULL;
        if (!$this->settings_table_exists()) return FALSE;
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

    /**
     * All settings as key => value, cached per request.
     *
     * Tolerates the table not existing. Every request path — the maintenance
     * gate, the branded header, MailService — reads settings, so on a database
     * that has not been migrated yet an unguarded read turns the *first* page
     * of a new install into a CodeIgniter database-error page, including the
     * `migrate` command that would have created the table. An empty settings
     * map is the correct answer for "no schema yet": callers already supply
     * defaults.
     */
    public function all() {
        if (self::$cache !== NULL) return self::$cache;
        if (!$this->settings_table_exists()) return self::$cache = array();

        $out = array();
        foreach ($this->db->get($this->table)->result() as $row) {
            $val = json_decode($row->setting_value, TRUE);
            $out[$row->setting_key] = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        }
        return self::$cache = $out;
    }

    /**
     * Whether the settings table is present, memoised for the request.
     *
     * Checked through table_exists() rather than by catching the failure: with
     * db_debug on (development) CI3 renders its own error page and exits, so a
     * try/catch around the query would never run.
     */
    private function settings_table_exists() {
        if (self::$table_present !== NULL) return self::$table_present;
        // Only a driver that can positively report the table is missing may
        // suppress the read. Anything else — a driver without table_exists(),
        // a probe that throws — is treated as "present" so the normal query
        // still runs and reports its own error. Failing open here matters:
        // this guard exists for the pre-migration install, not to silently
        // swallow settings on a working database.
        if (!isset($this->db) || !is_object($this->db) || !method_exists($this->db, 'table_exists')) {
            return self::$table_present = TRUE;
        }
        try {
            return self::$table_present = (bool) $this->db->table_exists($this->table);
        } catch (Throwable $e) {
            return self::$table_present = TRUE;
        }
    }

    /**
     * Drop the memo.
     *
     * set() does this already; this exists for tests and for the cron CLI,
     * where one process can outlive a settings change made elsewhere.
     */
    public static function flush_cache() {
        self::$cache = NULL;
        self::$table_present = NULL;
    }

    public function by_category($category) {
        // Not memoised: category listings are admin-screen only, and caching
        // them per category would just duplicate all().
        if (!$this->settings_table_exists()) return array();
        $out = array();
        foreach ($this->db->where('category', $category)->get($this->table)->result() as $row) {
            $val = json_decode($row->setting_value, TRUE);
            $out[$row->setting_key] = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        }
        return $out;
    }

    /** Only settings marked is_public — safe to expose to the browser. */
    public function public_settings() {
        if (!$this->settings_table_exists()) return array();
        $out = array();
        foreach ($this->db->where('is_public', 1)->get($this->table)->result() as $row) {
            $val = json_decode($row->setting_value, TRUE);
            $out[$row->setting_key] = (is_array($val) && array_key_exists('value', $val)) ? $val['value'] : $val;
        }
        return $out;
    }
}
