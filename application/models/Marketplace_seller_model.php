<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marketplace_seller_model extends MY_Model {
    protected $table = 'marketplace_sellers';

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function find_for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)->get($this->table)->row();
    }

    public function find_public($public_id) {
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    public function find_id($id) {
        return $this->db->where('id', (int)$id)->get($this->table)->row();
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->admin_filters($filters);
        return $this->db
            ->select('marketplace_sellers.*, users.username, users.email', false)
            ->join('users', 'users.id = marketplace_sellers.user_id', 'left')
            ->order_by('marketplace_sellers.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters = array()) {
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $filters) {
        $this->db->from($this->table);
        if (!empty($filters['status'])) {
            $this->db->where('marketplace_sellers.status', strtoupper($filters['status']));
        }
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $this->db->group_start()
                ->like('marketplace_sellers.display_name', $term)
                ->or_like('marketplace_sellers.public_id', $term)
                ->group_end();
        }
    }
}
