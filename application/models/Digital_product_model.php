<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Digital_product_model extends MY_Model {
    protected $table = 'digital_products';

    public function for_listing($listing_id) {
        return $this->db->where('listing_id', (int)$listing_id)->get($this->table)->row();
    }

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function delete_for_listing($listing_id) {
        return $this->db->where('listing_id', (int)$listing_id)->delete($this->table);
    }
}
