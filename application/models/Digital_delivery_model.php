<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Digital_delivery_model extends MY_Model {
    protected $table = 'digital_deliveries';

    public function for_order_and_product($order_id, $product_id) {
        return $this->db->where('marketplace_order_id', (int)$order_id)
            ->where('digital_product_id', (int)$product_id)
            ->get($this->table)->row();
    }

    /** Every delivery granted for one marketplace order. */
    public function for_order($order_id) {
        return $this->db->where('marketplace_order_id', (int)$order_id)
            ->get($this->table)->result();
    }

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function find_public($public_id) {
        return $this->db
            ->select($this->table.'.*, digital_products.storage_key, digital_products.original_filename, '
                    .'digital_products.mime_type, digital_products.size_bytes, digital_products.download_limit, '
                    .'digital_products.link_ttl_hours, marketplace_listings.title AS listing_title', false)
            ->from($this->table)
            ->join('digital_products', 'digital_products.id = '.$this->table.'.digital_product_id', 'left')
            ->join('marketplace_listings', 'marketplace_listings.id = digital_products.listing_id', 'left')
            ->where($this->table.'.public_id', $public_id)
            ->get()->row();
    }

    /** Every download this user is entitled to, for "My Downloads". */
    /** One page of a customer's downloads (newest first). */
    public function for_user($user_id, $limit = 100, $offset = 0) {
        return $this->db
            ->select($this->table.'.*, marketplace_listings.title AS listing_title, '
                    .'marketplace_listings.image, digital_products.original_filename, '
                    .'digital_products.size_bytes, marketplace_orders.public_id AS order_public_id', false)
            ->from($this->table)
            ->join('digital_products', 'digital_products.id = '.$this->table.'.digital_product_id', 'left')
            ->join('marketplace_listings', 'marketplace_listings.id = digital_products.listing_id', 'left')
            ->join('marketplace_orders', 'marketplace_orders.id = '.$this->table.'.marketplace_order_id', 'left')
            ->where($this->table.'.user_id', (int)$user_id)
            ->order_by($this->table.'.created_at', 'DESC')
            ->limit((int)$limit, (int)$offset)
            ->get()->result();
    }

    public function record_download($id, $ip) {
        $this->db->set('download_count', 'download_count + 1', false)
            ->where('id', (int)$id)
            ->update($this->table, array(
                'last_downloaded_at' => $this->now_utc(),
                'last_download_ip'   => $ip,
                'updated_at'         => $this->now_utc(),
            ));
    }

    public function revoke($id, $actor_id, $reason) {
        return $this->db->where('id', (int)$id)->update($this->table, array(
            'revoked' => 1,
            'revoked_reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'revoked_by' => $actor_id ? (int)$actor_id : null,
            'revoked_at' => $this->now_utc(),
            'updated_at' => $this->now_utc(),
        ));
    }

    public function restore($id) {
        return $this->db->where('id', (int)$id)->update($this->table, array(
            'revoked' => 0, 'revoked_reason' => null, 'revoked_by' => null, 'revoked_at' => null,
            'updated_at' => $this->now_utc(),
        ));
    }
}
