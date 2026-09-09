<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Feature_flag_model extends MY_Model {
    protected $table = 'feature_flags';

    protected $primary_key = 'flag_key';

    /**
     * Per-request memo of the whole flag table.
     *
     * There are a dozen flags and every page asks about several of them — the
     * navigation alone checks one per module it might render. Each check used
     * to be its own point query: nine per authenticated page load, before any
     * of the page's real work. The table is tiny, cannot change inside a
     * request, and one read answers every question about it.
     */
    private static $memo = null;

    /** Every flag as key => bool, read once per request. */
    public function all_flags(){
        if (self::$memo !== null) return self::$memo;
        $out = array();
        try {
            foreach ($this->db->get($this->table)->result() as $r) $out[$r->flag_key] = (bool)$r->enabled;
        } catch (Throwable $e) {
            // A pre-migration database has no table yet; callers supply their
            // own defaults, exactly as they did when this query failed.
            return array();
        }
        return self::$memo = $out;
    }

    public function enabled($key){
        $all = $this->all_flags();
        return array_key_exists($key, $all) ? (bool)$all[$key] : FALSE;
    }

    /** Drop the memo after a write so the next read is truthful. */
    public static function forget(){ self::$memo = null; }

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
        self::forget();
        return true;
    }
}
