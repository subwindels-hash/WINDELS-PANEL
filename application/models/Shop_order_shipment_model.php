<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shop_order_shipment_model extends MY_Model {
    protected $table = 'shop_order_shipments';

    const STATUSES = array('PENDING', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'CANCELLED', 'RETURNED');

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_order($marketplace_order_id) {
        return $this->db->where('marketplace_order_id', (int)$marketplace_order_id)->get($this->table)->row();
    }

    public function find_public($public_id) {
        return $this->db
            ->select($this->table.'.*, marketplace_orders.public_id AS order_public_id, '
                    .'marketplace_orders.buyer_id, marketplace_orders.status AS order_status, '
                    .'marketplace_orders.shipping_cost AS order_shipping_cost, marketplace_orders.gross_amount AS order_gross_amount, '
                    .'marketplace_orders.released_at AS order_released_at, '
                    .'marketplace_listings.title AS listing_title, '
                    .'physical_products.sku AS physical_sku, physical_products.weight_grams, '
                    .'physical_products.length_cm, physical_products.width_cm, physical_products.height_cm, '
                    .'shipping_methods.name AS shipping_method_name, shipping_methods.carrier AS method_carrier, '
                    .'shipping_addresses.full_name, shipping_addresses.phone, shipping_addresses.line1, '
                    .'shipping_addresses.line2, shipping_addresses.city, shipping_addresses.state, '
                    .'shipping_addresses.postal_code, shipping_addresses.country_code', false)
            ->from($this->table)
            ->join('marketplace_orders', 'marketplace_orders.id = '.$this->table.'.marketplace_order_id', 'left')
            ->join('marketplace_listings', 'marketplace_listings.id = marketplace_orders.listing_id', 'left')
            ->join('physical_products', 'physical_products.listing_id = marketplace_orders.listing_id', 'left')
            ->join('shipping_methods', 'shipping_methods.id = '.$this->table.'.shipping_method_id', 'left')
            ->join('shipping_addresses', 'shipping_addresses.id = '.$this->table.'.shipping_address_id', 'left')
            ->where($this->table.'.public_id', $public_id)
            ->get()->row();
    }

    /** Compare-and-set shipment status used by ShopShippingService. */
    public function transition($id, $from, $to, array $extra = array()) {
        $from = strtoupper((string)$from);
        $to = strtoupper((string)$to);
        $fields = array_merge($extra, array('status' => $to, 'updated_at' => $this->now_utc()));
        $ok = $this->db->where('id', (int)$id)->where('status', $from)
            ->update($this->table, $fields);
        if (!$ok || $this->db->affected_rows() === 1) return $ok && $this->db->affected_rows() === 1;
        // MySQL reports zero affected rows when a same-state tracking edit
        // writes values identical to the current row. The compare-and-set is
        // still safe: confirm the row exists in the expected state rather than
        // treating every zero-row update as a successful race.
        if ($from !== $to) return false;
        $current = $this->db->where('id', (int)$id)->get($this->table)->row();
        return $current && strtoupper((string)$current->status) === $from;
    }

    /** Used only after the underlying marketplace order has been refunded. */
    public function cancel_after_refund($id) {
        $this->db->where('id', (int)$id)
            ->where('status !=', 'CANCELLED')
            ->update($this->table, array('status' => 'CANCELLED', 'updated_at' => $this->now_utc()));
        return $this->db->affected_rows() === 1;
    }

    public function update_status($id, $status, array $extra = array()) {
        $fields = array_merge($extra, array('status' => $status, 'updated_at' => $this->now_utc()));
        return $this->db->where('id', (int)$id)->update($this->table, $fields);
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->db
            ->select($this->table.'.*, marketplace_orders.public_id AS order_public_id, '
                    .'marketplace_listings.title AS listing_title, users.username', false)
            ->from($this->table)
            ->join('marketplace_orders', 'marketplace_orders.id = '.$this->table.'.marketplace_order_id', 'left')
            ->join('marketplace_listings', 'marketplace_listings.id = marketplace_orders.listing_id', 'left')
            ->join('users', 'users.id = marketplace_orders.buyer_id', 'left');
        $this->admin_filters($filters);
        return $this->db
            ->order_by($this->table.'.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function admin_count(array $filters = array()) {
        $this->db->from($this->table);
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f) {
        if (!empty($f['status'])) $this->db->where($this->table.'.status', strtoupper($f['status']));
    }
}
