<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shipping_method_model extends MY_Model {
    protected $table = 'shipping_methods';

    public function active() {
        return $this->db->where('is_active', 1)->order_by('sorting', 'ASC')->get($this->table)->result();
    }

    public function find_public($public_id) {
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    /** Active methods priced in the panel's settlement currency. */
    public function active_for_currency($currency) {
        return $this->db->where('is_active', 1)->where('currency', strtoupper((string)$currency))
            ->order_by('sorting', 'ASC')->get($this->table)->result();
    }

    /** A checkout may only quote a currently enabled method. */
    public function find_active_public($public_id) {
        return $this->db->where('public_id', $public_id)->where('is_active', 1)
            ->get($this->table)->row();
    }

    /** The service re-checks the numeric FK immediately before charging. */
    public function find_active($id) {
        return $this->db->where('id', (int)$id)->where('is_active', 1)
            ->get($this->table)->row();
    }

    public function all_rows() {
        return $this->db->order_by('sorting', 'ASC')->get($this->table)->result();
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

    public function delete_row($id) {
        return $this->db->where('id', (int)$id)->delete($this->table);
    }
}
