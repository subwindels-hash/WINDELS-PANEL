<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cart_model — one open shopping cart per customer.
 *
 * Carts are not append-only: unlike the money ledgers, a cart is a scratchpad
 * the customer edits until checkout replaces it with a real marketplace
 * order. There is exactly one cart row per user (unique key), created lazily
 * on first add-to-cart.
 */
class Cart_model extends MY_Model {
    protected $table = 'shopping_carts';

    public function for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)->get($this->table)->row();
    }

    public function find_or_create($user_id) {
        $cart = $this->for_user($user_id);
        if ($cart) return $cart;
        $this->db->insert($this->table, array(
            'public_id'  => $this->new_public_id(),
            'user_id'    => (int)$user_id,
            'currency'   => marvy_base_currency(),
            'created_at' => $this->now_utc(),
            'updated_at' => $this->now_utc(),
        ));
        return $this->for_user($user_id);
    }

    public function set_coupon($cart_id, $code) {
        $this->db->where('id', (int)$cart_id)->update($this->table, array(
            'coupon_code' => $code,
            'updated_at'  => $this->now_utc(),
        ));
    }

    public function touch($cart_id) {
        $this->db->where('id', (int)$cart_id)->update($this->table, array('updated_at' => $this->now_utc()));
    }

    public function clear($cart_id) {
        $this->db->where('cart_id', (int)$cart_id)->delete('cart_items');
        $this->db->where('id', (int)$cart_id)->update($this->table, array(
            'coupon_code' => null, 'updated_at' => $this->now_utc(),
        ));
    }
}
