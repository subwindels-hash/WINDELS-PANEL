<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Product_review_model extends MY_Model {
    protected $table = 'product_reviews';

    /** Whether this buyer already reviewed this specific order (one review per completed purchase). */
    public function for_order($marketplace_order_id) {
        return $this->db->where('marketplace_order_id', (int)$marketplace_order_id)->get($this->table)->row();
    }

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    /** Approved reviews for a listing, newest first. */
    public function approved_for_listing($listing_id, $limit = 20, $offset = 0) {
        return $this->db
            ->select($this->table.'.*, users.username', false)
            ->from($this->table)
            ->join('users', 'users.id = '.$this->table.'.user_id', 'left')
            ->where($this->table.'.listing_id', (int)$listing_id)
            ->where($this->table.'.status', 'APPROVED')
            ->order_by($this->table.'.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function rating_summary($listing_id) {
        $row = $this->db->select('COUNT(*) AS n, COALESCE(AVG(rating), 0) AS avg_rating', false)
            ->where('listing_id', (int)$listing_id)->where('status', 'APPROVED')
            ->get($this->table)->row();
        return array('count' => (int)($row->n ?? 0), 'average' => round((float)($row->avg_rating ?? 0), 1));
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->admin_filters($filters);
        return $this->db
            ->select($this->table.'.*, marketplace_listings.title AS listing_title, users.username', false)
            ->join('marketplace_listings', 'marketplace_listings.id = '.$this->table.'.listing_id', 'left')
            ->join('users', 'users.id = '.$this->table.'.user_id', 'left')
            ->order_by($this->table.'.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters = array()) {
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    public function moderate($id, $status, $actor_id) {
        return $this->db->where('id', (int)$id)->update($this->table, array(
            'status' => $status,
            'moderated_by' => $actor_id ? (int)$actor_id : null,
            'moderated_at' => $this->now_utc(),
            'updated_at' => $this->now_utc(),
        ));
    }

    private function admin_filters(array $f) {
        $this->db->from($this->table);
        if (!empty($f['status'])) $this->db->where($this->table.'.status', strtoupper($f['status']));
    }
}
