<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Marketplace categories — managed rows, not free-text slugs. Only ACTIVE
 * categories may hold listings, and slugs are what listings store (uppercase,
 * URL-safe), so renaming a category's label never orphans stock.
 */
class Marketplace_category_model extends MY_Model {

    public function all($limit = 200, $offset = 0) {
        return $this->db->order_by('sort_order', 'ASC')->order_by('name', 'ASC')
            ->limit($limit, $offset)->get('marketplace_categories')->result();
    }

    public function active() {
        return $this->db->where('status', 'ACTIVE')
            ->order_by('sort_order', 'ASC')->order_by('name', 'ASC')
            ->get('marketplace_categories')->result();
    }

    public function find_active($slug) {
        return $this->db->where('slug', strtoupper((string)$slug))->where('status', 'ACTIVE')
            ->get('marketplace_categories')->row();
    }

    public function find_public($public_id) {
        return $this->db->where('public_id', $public_id)->get('marketplace_categories')->row();
    }

    public function create(array $data) {
        $this->db->insert('marketplace_categories', $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->where('id', (int)$id)->update('marketplace_categories', $fields);
    }

    public static function valid_slug($slug) {
        return preg_match('/^[A-Z0-9_-]{2,64}$/', strtoupper((string)$slug)) === 1;
    }
}
