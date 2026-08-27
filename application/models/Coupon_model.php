<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Coupon_model extends MY_Model {
    protected $table = 'coupons';

    /** A coupon that is active, within its date window, and not exhausted globally. */
    public function find_valid($code) {
        $code = strtoupper(trim((string)$code));
        if ($code === '') return null;
        // Escaped inline at the point of interpolation so the fragment can be
        // read (and linted) as safe without chasing a variable up the method.
        $now = $this->now_utc();
        $row = $this->db->where('code', $code)->where('is_active', 1)
            ->where('(starts_at IS NULL OR starts_at <= '.$this->db->escape($now).')', null, false)
            ->where('(ends_at IS NULL OR ends_at >= '.$this->db->escape($now).')', null, false)
            ->get($this->table)->row();
        if (!$row) return null;
        if ($row->usage_limit !== null && (int)$row->times_used >= (int)$row->usage_limit) return null;
        return $row;
    }

    public function find_public($public_id) {
        return $this->db->where('public_id', $public_id)->get($this->table)->row();
    }

    /**
     * Every coupon currently eligible AND flagged for discovery — the cart
     * page's "available coupons" list. Applies the exact same
     * active/date-window/usage-limit rules as find_valid(); listing a coupon
     * that would actually be refused at apply time would be worse than not
     * listing one at all.
     */
    public function public_valid($limit = 10) {
        // Escaped inline at the point of interpolation so the fragment can be
        // read (and linted) as safe without chasing a variable up the method.
        $now = $this->now_utc();
        $rows = $this->db->where('is_active', 1)->where('is_public', 1)
            ->where('(starts_at IS NULL OR starts_at <= '.$this->db->escape($now).')', null, false)
            ->where('(ends_at IS NULL OR ends_at >= '.$this->db->escape($now).')', null, false)
            ->order_by('created_at', 'DESC')->limit($limit)
            ->get($this->table)->result();
        return array_values(array_filter($rows, function ($row) {
            return $row->usage_limit === null || (int)$row->times_used < (int)$row->usage_limit;
        }));
    }

    /** How many times this user has already redeemed this coupon. */
    public function redemptions_by_user($coupon_id, $user_id) {
        return (int)$this->db->where('coupon_id', (int)$coupon_id)->where('user_id', (int)$user_id)
            ->count_all_results('coupon_redemptions');
    }

    public function record_redemption($coupon_id, $user_id, $order_id, $discount_amount) {
        $this->db->insert('coupon_redemptions', array(
            'coupon_id' => (int)$coupon_id,
            'user_id' => (int)$user_id,
            'marketplace_order_id' => $order_id ? (int)$order_id : null,
            'discount_amount' => $discount_amount,
            'created_at' => $this->now_utc(),
        ));
        // Atomic increment, not read-modify-write, so concurrent redemptions
        // near a usage_limit cannot both slip in over the cap.
        $this->db->set('times_used', 'times_used + 1', false)
            ->where('id', (int)$coupon_id)->update($this->table);
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

    public function admin_search($limit = 50, $offset = 0) {
        return $this->db->order_by('created_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function admin_count() {
        return (int)$this->db->count_all($this->table);
    }
}
