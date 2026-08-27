<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CouponService — admin management of shop coupons.
 *
 * Redemption/validation logic itself lives in CartService (applying one to a
 * live cart) so there is exactly one place that decides whether a coupon is
 * usable right now; this class only owns the admin create/update/list surface.
 */
class CouponService {

    const DISCOUNT_TYPES = array('PERCENT', 'FIXED');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Coupon_model');
    }

    public function save($input, $actor_id, $public_id = null) {
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            return array('ok' => false, 'error' => 'Coupon codes may use letters, digits, dashes and underscores (3-32 characters).');
        }
        $type = strtoupper((string)($input['discount_type'] ?? 'PERCENT'));
        if (!in_array($type, self::DISCOUNT_TYPES, true)) $type = 'PERCENT';

        $value = (float)($input['discount_value'] ?? 0);
        if ($value <= 0) return array('ok' => false, 'error' => 'The discount value must be greater than zero.');
        if ($type === 'PERCENT' && $value > 100) return array('ok' => false, 'error' => 'A percentage discount cannot exceed 100.');

        $existing = $public_id ? $this->ci->db->where('public_id', $public_id)->get('coupons')->row() : null;
        if ($public_id && !$existing) return array('ok' => false, 'error' => 'Coupon not found.');

        $dupe = $this->ci->db->where('code', $code)->get('coupons')->row();
        if ($dupe && (!$existing || (int)$dupe->id !== (int)$existing->id)) {
            return array('ok' => false, 'error' => 'That coupon code is already in use.');
        }

        $fields = array(
            'code' => $code,
            'description' => mb_substr(trim((string)($input['description'] ?? '')), 0, 255) ?: null,
            'discount_type' => $type,
            'discount_value' => number_format($value, 8, '.', ''),
            'currency' => $type === 'FIXED' ? marvy_base_currency() : null,
            'min_order_amount' => $input['min_order_amount'] !== '' ? number_format((float)$input['min_order_amount'], 8, '.', '') : null,
            'max_discount_amount' => ($input['max_discount_amount'] ?? '') !== '' ? number_format((float)$input['max_discount_amount'], 8, '.', '') : null,
            'usage_limit' => ($input['usage_limit'] ?? '') !== '' ? max(0, (int)$input['usage_limit']) : null,
            'usage_limit_per_user' => ($input['usage_limit_per_user'] ?? '') !== '' ? max(0, (int)$input['usage_limit_per_user']) : 1,
            'starts_at' => !empty($input['starts_at']) ? gmdate('Y-m-d H:i:s', strtotime($input['starts_at'])) : null,
            'ends_at' => !empty($input['ends_at']) ? gmdate('Y-m-d H:i:s', strtotime($input['ends_at'])) : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        );

        if ($existing) {
            $this->ci->Coupon_model->update_fields($existing->id, $fields);
            $id = $existing->id;
        } else {
            $fields['created_by_id'] = $actor_id;
            $id = $this->ci->Coupon_model->create($fields);
        }
        return array('ok' => true, 'coupon_id' => $id);
    }

    public function set_active($public_id, $active) {
        $coupon = $this->ci->db->where('public_id', $public_id)->get('coupons')->row();
        if (!$coupon) return array('ok' => false, 'error' => 'Coupon not found.');
        $this->ci->Coupon_model->update_fields($coupon->id, array('is_active' => $active ? 1 : 0));
        return array('ok' => true);
    }
}
