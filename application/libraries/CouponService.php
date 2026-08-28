<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CouponService — the coupon's two halves.
 *
 * **Admin half:** create/update/list/activate — the screens under
 * Admin → Shop → Coupons.
 *
 * **Checkout half (module 36):** `quote()` is the one place that decides
 * whether a code can be applied to a purchase right now, and what it takes
 * off. It was extracted from CartService so every purchase domain — the
 * shop cart, an SMM order, VTU, number rentals, identity checks and gift
 * cards — asks exactly the same question and gets exactly the same maths.
 * Before this, only the marketplace checkout could redeem a coupon at all.
 *
 * The redemption *bookkeeping* (the race-safe slot from migration 030)
 * stays in Coupon_model, and each checkout owns its own
 * reserve-before-charge / release-on-failure / attach-on-success sequence,
 * exactly like ShopCheckoutService has always done.
 */
class CouponService {

    const DISCOUNT_TYPES = array('PERCENT', 'FIXED');

    /** Purchase domains a coupon can be redeemed against. */
    const DOMAINS = array('SHOP', 'SMM', 'VTU', 'NUMBER', 'IDENTITY', 'GIFTCARD');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Coupon_model');
    }

    /* --------------------------- checkout side --------------------------- */

    /**
     * Decide whether a code applies to a purchase of $subtotal, and by how
     * much. Every rule the shop cart enforced is repeated here because the
     * other domains deserve the same answers:
     *
     *   - active, inside its date window, under its global usage limit;
     *   - this customer is still under their per-user limit;
     *   - the subtotal meets the minimum spend (if the coupon has one);
     *   - PERCENT maths with the absolute cap, or FIXED maths;
     *   - the discount can never exceed the subtotal — a "₦5,000 off" code
     *     on a ₦2,000 airtime top-up is free airtime, not a ₦3,000 payout.
     *
     * $domain is recorded for the caller's audit context only; a coupon is
     * deliberately not domain-scoped — the operator asked for a site-wide
     * promo code, so one code works wherever it is presented.
     *
     * @return array{ok:bool, coupon?:object, discount?:string, total?:string, code?:string, error?:string}
     */
    public function quote($user, $code, $subtotal, $domain = null) {
        $user_id = is_object($user) ? (int)$user->id : (int)$user;
        $subtotal = $this->money($subtotal);
        $code = strtoupper(trim((string)$code));

        if ($code === '') {
            return array('ok' => false, 'code' => 'NO_CODE', 'error' => 'Enter a coupon code.');
        }
        // Looked up without the user first so the per-user limit gets to give
        // its own, more honest answer ("you already used this") instead of
        // collapsing into "not valid" — the same two-step the cart's apply
        // path has always used.
        $coupon = $this->ci->Coupon_model->find_valid($code);
        if (!$coupon) {
            return array('ok' => false, 'code' => 'INVALID_COUPON',
                'error' => 'That coupon is not valid or has expired.');
        }
        if (!$this->ci->Coupon_model->within_user_limit($coupon, $user_id)) {
            return array('ok' => false, 'code' => 'ALREADY_USED',
                'error' => 'You have already used this coupon.');
        }
        if ($coupon->min_order_amount !== null
            && bccomp($subtotal, $this->money($coupon->min_order_amount), 8) < 0) {
            return array('ok' => false, 'code' => 'BELOW_MINIMUM',
                'error' => 'This coupon requires a subtotal of at least '
                    .marvy_money($coupon->min_order_amount).'.');
        }

        $discount = $this->compute_discount($coupon, $subtotal);
        // A FIXED coupon larger than the purchase is capped at the purchase,
        // not paid out of the platform's pocket.
        if (bccomp($discount, $subtotal, 8) > 0) $discount = $subtotal;

        return array(
            'ok'       => true,
            'coupon'   => $coupon,
            'discount' => $discount,
            'total'    => bcsub($subtotal, $discount, 8),
            'domain'   => in_array((string)$domain, self::DOMAINS, true) ? (string)$domain : null,
        );
    }

    /**
     * Compute the discount a coupon applies to a given subtotal.
     *
     * The single implementation — CartService and every purchase domain
     * funnel through here, so PERCENT maths, the absolute cap and FIXED
     * maths cannot drift between checkouts. The discount is floored at 0
     * and capped at the subtotal: a discount larger than the purchase is
     * free, never a payout.
     */
    public function compute_discount($coupon, $subtotal) {
        $subtotal = $this->money($subtotal);
        if ($coupon->discount_type === 'FIXED') {
            $discount = $this->money($coupon->discount_value);
        } else {
            $discount = bcdiv(bcmul($subtotal, $this->money($coupon->discount_value), 8), '100', 8);
        }
        if ($coupon->max_discount_amount !== null
            && bccomp($discount, $this->money($coupon->max_discount_amount), 8) > 0) {
            $discount = $this->money($coupon->max_discount_amount);
        }
        if (bccomp($discount, '0', 8) < 0) $discount = '0.00000000';
        if (bccomp($discount, $subtotal, 8) > 0) $discount = $subtotal;
        return $discount;
    }

    /* ----------------------------- admin side ---------------------------- */

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
            'min_order_amount' => ($input['min_order_amount'] ?? '') !== '' ? number_format((float)$input['min_order_amount'], 8, '.', '') : null,
            'max_discount_amount' => ($input['max_discount_amount'] ?? '') !== '' ? number_format((float)$input['max_discount_amount'], 8, '.', '') : null,
            'usage_limit' => ($input['usage_limit'] ?? '') !== '' ? max(0, (int)$input['usage_limit']) : null,
            'usage_limit_per_user' => ($input['usage_limit_per_user'] ?? '') !== '' ? max(0, (int)$input['usage_limit_per_user']) : 1,
            'starts_at' => !empty($input['starts_at']) ? gmdate('Y-m-d H:i:s', strtotime($input['starts_at'])) : null,
            'ends_at' => !empty($input['ends_at']) ? gmdate('Y-m-d H:i:s', strtotime($input['ends_at'])) : null,
            'is_active' => !empty($input['is_active']) ? 1 : 0,
            'is_public' => !empty($input['is_public']) ? 1 : 0,
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

    /** Whether a coupon appears in the /cart "available coupons" list. */
    public function set_public($public_id, $public) {
        $coupon = $this->ci->db->where('public_id', $public_id)->get('coupons')->row();
        if (!$coupon) return array('ok' => false, 'error' => 'Coupon not found.');
        $this->ci->Coupon_model->update_fields($coupon->id, array('is_public' => $public ? 1 : 0));
        return array('ok' => true);
    }

    /** Normalise a money-ish value to an 8-dp string, never a float in, float out. */
    private function money($v) {
        if ($v === null || $v === '') return '0.00000000';
        if (!is_numeric($v)) return '0.00000000';
        return number_format((float)$v, 8, '.', '');
    }
}
