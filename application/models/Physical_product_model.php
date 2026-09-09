<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Physical_product_model — SKU, weight and package dimensions for a
 * PHYSICAL marketplace_listings row (migration 025's `physical_products`
 * table). One row per listing, created/updated from the admin listing form
 * exactly like Digital_product_model is for DIGITAL listings — the schema
 * shipped with migration 025 but had no model or admin form wired to it
 * until this pass, so a physical listing's shipping-relevant attributes
 * could never actually be set from the UI.
 */
class Physical_product_model extends MY_Model {
    protected $table = 'physical_products';

    public function for_listing($listing_id) {
        return $this->db->where('listing_id', (int)$listing_id)->get($this->table)->row();
    }

    public function find_by_sku($sku) {
        return $this->db->where('sku', (string)$sku)->get($this->table)->row();
    }

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function upsert_for_listing($listing_id, array $fields) {
        $existing = $this->for_listing($listing_id);
        $fields['listing_id'] = (int)$listing_id;
        if ($existing) {
            unset($fields['listing_id']); // immutable once created
            $fields['updated_at'] = $this->now_utc();
            $this->db->where('id', $existing->id)->update($this->table, $fields);
            return (int)$existing->id;
        }
        return $this->create($fields);
    }

    public function delete_for_listing($listing_id) {
        return $this->db->where('listing_id', (int)$listing_id)->delete($this->table);
    }
}
