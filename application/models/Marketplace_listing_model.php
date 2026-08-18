<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Marketplace_listing_model extends MY_Model {
    protected $table = 'marketplace_listings';

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function find_id($id) {
        return $this->db->where('id', (int)$id)->get($this->table)->row();
    }

    public function find_public($public_id, $active_only = false) {
        $this->db->where('public_id', $public_id);
        if ($active_only) $this->db->where('status', 'ACTIVE');
        $listing = $this->db->get($this->table)->row();
        if (!$listing) return null;

        $seller = $this->db->where('id', (int)$listing->seller_id)
            ->get('marketplace_sellers')->row();
        if (!$seller || ($active_only && $seller->status !== 'APPROVED')) return null;
        // Match the projection used by catalogue/detail without pulling the
        // encrypted or identity-bearing parts of either row into a list.
        $listing->seller_name = $seller->display_name;
        $listing->seller_user_id = $seller->user_id;
        $listing->seller_status = $seller->status;
        return $listing;
    }

    public function catalogue(array $filters = array(), $limit = 24, $offset = 0) {
        $this->catalogue_filters($filters);
        return $this->db
            ->select('marketplace_listings.*, marketplace_sellers.display_name AS seller_name', false)
            ->order_by('marketplace_listings.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function catalogue_count(array $filters = array()) {
        $this->catalogue_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function catalogue_filters(array $filters) {
        $this->db->from($this->table)
            ->where('marketplace_listings.status', 'ACTIVE')
            ->where('marketplace_sellers.status', 'APPROVED')
            ->join('marketplace_sellers', 'marketplace_sellers.id = marketplace_listings.seller_id', 'inner');
        if (!empty($filters['category'])) {
            $this->db->where('marketplace_listings.category', $filters['category']);
        }
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $this->db->group_start()
                ->like('marketplace_listings.title', $term)
                ->or_like('marketplace_listings.description', $term)
                ->group_end();
        }
    }

    public function for_seller($seller_id, $limit = 50, $offset = 0) {
        return $this->db->where('seller_id', (int)$seller_id)
            ->order_by('created_at', 'DESC')->limit($limit, $offset)
            ->get($this->table)->result();
    }

    public function update_fields($id, array $fields) {
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function decrement_stock($id, $quantity) {
        $listing = $this->find_id($id);
        if (!$listing) return false;
        if ($listing->stock === null) return true;
        if ((int)$listing->stock < (int)$quantity) return false;
        // Optimistic compare-and-swap: two buyers who both saw the last unit
        // cannot both decrement it. This avoids a raw expression while keeping
        // the stock mutation atomic.
        $before = (int)$listing->stock;
        $this->db->where('id', (int)$id)->where('stock', $before)
            ->update($this->table, array(
                'stock' => $before - (int)$quantity,
                'updated_at' => $this->now_utc(),
            ));
        return $this->db->affected_rows() === 1;
    }

    public function restore_stock($id, $quantity) {
        $listing = $this->find_id($id);
        if (!$listing || $listing->stock === null) return true;
        $before = (int)$listing->stock;
        $this->db->where('id', (int)$id)->where('stock', $before)
            ->update($this->table, array(
                'stock' => $before + (int)$quantity,
                'updated_at' => $this->now_utc(),
            ));
        return $this->db->affected_rows() === 1;
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->admin_filters($filters);
        return $this->db
            ->select('marketplace_listings.*, marketplace_sellers.display_name AS seller_name, users.username AS seller_username', false)
            ->join('marketplace_sellers', 'marketplace_sellers.id = marketplace_listings.seller_id', 'left')
            ->join('users', 'users.id = marketplace_sellers.user_id', 'left')
            ->order_by('marketplace_listings.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters = array()) {
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $filters) {
        $this->db->from($this->table);
        if (!empty($filters['status'])) {
            $this->db->where('marketplace_listings.status', strtoupper($filters['status']));
        }
        if (!empty($filters['search'])) {
            $term = trim($filters['search']);
            $this->db->group_start()
                ->like('marketplace_listings.title', $term)
                ->or_like('marketplace_listings.public_id', $term)
                ->group_end();
        }
    }
}
