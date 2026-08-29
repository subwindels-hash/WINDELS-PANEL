<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Cart_item_model extends MY_Model {
    protected $table = 'cart_items';

    /** Items in a cart, joined with the live listing so stale prices/removed listings are visible. */
    public function for_cart($cart_id) {
        return $this->db
            ->select('cart_items.*, marketplace_listings.title, marketplace_listings.image, '
                    .'marketplace_listings.price AS current_price, marketplace_listings.promo_price, '
                    .'marketplace_listings.currency, marketplace_listings.stock, '
                    .'marketplace_listings.status AS listing_status, marketplace_listings.product_type, '
                    .'marketplace_listings.public_id AS listing_public_id, '
                    .'physical_products.id AS physical_product_id, physical_products.sku AS physical_sku, '
                    .'physical_products.requires_shipping AS requires_shipping', false)
            ->from($this->table)
            ->join('marketplace_listings', 'marketplace_listings.id = cart_items.listing_id', 'left')
            ->join('physical_products', 'physical_products.listing_id = marketplace_listings.id', 'left')
            ->where('cart_items.cart_id', (int)$cart_id)
            ->order_by('cart_items.created_at', 'ASC')
            ->get()->result();
    }

    public function find_in_cart($cart_id, $listing_id) {
        return $this->db->where('cart_id', (int)$cart_id)->where('listing_id', (int)$listing_id)
            ->get($this->table)->row();
    }

    public function upsert($cart_id, $listing_id, $quantity, $unit_price) {
        $existing = $this->find_in_cart($cart_id, $listing_id);
        if ($existing) {
            $this->db->where('id', $existing->id)->update($this->table, array(
                'quantity'          => (int)$quantity,
                'quoted_unit_price' => $unit_price,
                'updated_at'        => $this->now_utc(),
            ));
            return (int)$existing->id;
        }
        $this->db->insert($this->table, array(
            'cart_id'           => (int)$cart_id,
            'listing_id'        => (int)$listing_id,
            'quantity'          => (int)$quantity,
            'quoted_unit_price' => $unit_price,
            'created_at'        => $this->now_utc(),
            'updated_at'        => $this->now_utc(),
        ));
        return (int)$this->db->insert_id();
    }

    public function remove($cart_id, $listing_id) {
        $this->db->where('cart_id', (int)$cart_id)->where('listing_id', (int)$listing_id)->delete($this->table);
        return $this->db->affected_rows() > 0;
    }

    public function count_for_cart($cart_id) {
        return (int)$this->db->where('cart_id', (int)$cart_id)->count_all_results($this->table);
    }
}
