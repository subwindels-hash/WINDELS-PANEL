<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Managed_page_model — administrator overrides for the static public pages.
 *
 * A row overrides the bundled view for its `page_key`. No row means the shipped
 * view renders, so an install that never opens the admin screens is unaffected
 * and deleting an override restores the original text rather than blanking the
 * page.
 */
class Managed_page_model extends MY_Model {

    protected $table = 'managed_pages';

    /**
     * The pages an administrator may override.
     *
     * key => [label, route, the view rendered when there is no override]
     */
    public static function catalogue() {
        return array(
            'about'           => array('About us',          'about',           'public/about'),
            'terms'           => array('Terms & Conditions', 'terms',          'public/terms'),
            'privacy'         => array('Privacy Policy',    'privacy',         'public/privacy'),
            'refund-policy'   => array('Refund Policy',     'refund-policy',   'public/refund_policy'),
            'acceptable-use'  => array('Acceptable Use',    'acceptable-use',  'public/acceptable_use'),
        );
    }

    public static function is_page($key) {
        return array_key_exists((string)$key, self::catalogue());
    }

    public static function label($key) {
        $c = self::catalogue();
        return isset($c[$key]) ? $c[$key][0] : ucfirst((string)$key);
    }

    /**
     * The published override for a page, or NULL.
     *
     * Tolerates the table not existing so a deployment that has not run
     * migration 021 yet still serves its legal pages from the bundled views
     * instead of erroring.
     */
    public function published($key) {
        if (!$this->table_ready()) return null;
        return $this->db->where('page_key', $key)->where('is_published', 1)
                        ->get($this->table)->row();
    }

    /** Any override for a page, published or not (admin editing). */
    public function find($key) {
        if (!$this->table_ready()) return null;
        return $this->db->where('page_key', $key)->get($this->table)->row();
    }

    /** Every override, keyed by page_key, for the admin index. */
    public function all_by_key() {
        if (!$this->table_ready()) return array();
        $out = array();
        foreach ($this->db->get($this->table)->result() as $row) {
            $out[$row->page_key] = $row;
        }
        return $out;
    }

    /** Insert or update the override for a page. */
    public function store($key, array $data, $actor_id = null) {
        if (!$this->table_ready()) return false;

        $data['updated_by_id'] = $actor_id;
        $data['updated_at'] = $this->now_utc();

        $existing = $this->find($key);
        if ($existing) {
            $this->db->where('id', $existing->id)->update($this->table, $data);
            return (int)$existing->id;
        }

        $data['page_key'] = $key;
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    /** Remove an override, restoring the bundled page. */
    public function clear($key) {
        if (!$this->table_ready()) return false;
        return $this->db->where('page_key', $key)->delete($this->table);
    }

    private function table_ready() {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            return $ready = (bool)$this->db->table_exists($this->table);
        } catch (Exception $e) {
            return $ready = false;
        }
    }
}
