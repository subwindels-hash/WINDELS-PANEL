<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CartService — the shop's multi-item basket.
 *
 * The cart is a scratchpad: it never charges anything and never touches the
 * wallet. Prices shown here are always re-read from `marketplace_listings` at
 * render time (never trusted from the stored `quoted_unit_price`, which
 * exists only so the page renders instantly without a fresh query per row).
 * Checkout (ShopCheckoutService) re-validates every line the same way
 * MarketplaceService::purchase() already validates a single-item buy — the
 * cart does not introduce a second pricing authority.
 *
 * One open cart per user (unique key on shopping_carts.user_id) — there is no
 * multi-cart/save-for-later concept yet, matching the scope actually asked
 * for.
 */
class CartService {

    const MAX_QUANTITY_PER_LINE = 100;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Cart_model', 'Cart_item_model', 'Marketplace_listing_model', 'Coupon_model'));
    }

    /** The user's cart, creating an empty one if none exists yet. */
    public function cart_for($user_id) {
        return $this->ci->Cart_model->find_or_create($user_id);
    }

    /** Full cart contents plus computed totals, ready to render. */
    public function view($user_id) {
        $cart = $this->cart_for($user_id);
        $items = $this->ci->Cart_item_model->for_cart($cart->id);

        $subtotal = '0.00000000';
        $lines = array();
        $has_physical = false;
        $currency = marvy_base_currency();

        foreach ($items as $item) {
            $unavailable = $item->listing_status !== 'ACTIVE';
            $unit_price = $this->effective_price($item);
            $line_total = $unavailable ? '0.00000000' : bcmul($unit_price, (string)$item->quantity, 8);
            if (!$unavailable) $subtotal = bcadd($subtotal, $line_total, 8);
            if ($item->product_type === 'PHYSICAL') $has_physical = true;

            $lines[] = array(
                'item' => $item,
                'unit_price' => $unit_price,
                'line_total' => $line_total,
                'unavailable' => $unavailable,
                'out_of_stock' => $item->stock !== null && (int)$item->stock < (int)$item->quantity,
            );
        }

        $coupon = null;
        $discount = '0.00000000';
        if (!empty($cart->coupon_code)) {
            $res = $this->ci->Coupon_model->find_valid($cart->coupon_code);
            if ($res) {
                $coupon = $res;
                $discount = $this->compute_discount($res, $subtotal);
            }
        }

        $total = bcsub($subtotal, $discount, 8);
        if (bccomp($total, '0', 8) < 0) $total = '0.00000000';

        return array(
            'cart' => $cart,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'coupon' => $coupon,
            'total' => $total,
            'currency' => $currency,
            'has_physical' => $has_physical,
            'count' => count($lines),
        );
    }

    /** How many distinct lines are in the cart — for a header badge. */
    public function count_for($user_id) {
        $cart = $this->ci->Cart_model->for_user($user_id);
        if (!$cart) return 0;
        return $this->ci->Cart_item_model->count_for_cart($cart->id);
    }

    /** Add (or update the quantity of) one listing in the cart. */
    public function add($user_id, $listing_public_id, $quantity = 1) {
        $listing = $this->ci->Marketplace_listing_model->find_public($listing_public_id, true);
        if (!$listing) return $this->err('NO_LISTING', 'That listing is not available.');

        $quantity = max(1, min(self::MAX_QUANTITY_PER_LINE, (int)$quantity));
        if ($listing->stock !== null && $listing->stock < $quantity) {
            return $this->err('OUT_OF_STOCK', 'Only '.(int)$listing->stock.' left in stock.');
        }

        $cart = $this->cart_for($user_id);
        $existing = $this->ci->Cart_item_model->find_in_cart($cart->id, $listing->id);
        $new_quantity = $existing ? min(self::MAX_QUANTITY_PER_LINE, (int)$existing->quantity + $quantity) : $quantity;

        $this->ci->Cart_item_model->upsert($cart->id, $listing->id, $new_quantity, $this->effective_price_of($listing));
        $this->ci->Cart_model->touch($cart->id);
        return array('ok' => true, 'listing' => $listing);
    }

    /** Set an exact quantity (0 removes the line). */
    public function set_quantity($user_id, $listing_public_id, $quantity) {
        $listing = $this->ci->Marketplace_listing_model->find_public($listing_public_id, false);
        if (!$listing) return $this->err('NO_LISTING', 'That listing was not found.');

        $cart = $this->cart_for($user_id);
        $quantity = (int)$quantity;
        if ($quantity <= 0) {
            $this->ci->Cart_item_model->remove($cart->id, $listing->id);
            $this->ci->Cart_model->touch($cart->id);
            return array('ok' => true, 'removed' => true);
        }
        $quantity = min(self::MAX_QUANTITY_PER_LINE, $quantity);
        if ($listing->stock !== null && $listing->stock < $quantity) {
            return $this->err('OUT_OF_STOCK', 'Only '.(int)$listing->stock.' left in stock.');
        }
        $this->ci->Cart_item_model->upsert($cart->id, $listing->id, $quantity, $this->effective_price_of($listing));
        $this->ci->Cart_model->touch($cart->id);
        return array('ok' => true);
    }

    public function remove($user_id, $listing_public_id) {
        $listing = $this->ci->Marketplace_listing_model->find_public($listing_public_id, false);
        if (!$listing) return $this->err('NO_LISTING', 'That listing was not found.');
        $cart = $this->cart_for($user_id);
        $this->ci->Cart_item_model->remove($cart->id, $listing->id);
        $this->ci->Cart_model->touch($cart->id);
        return array('ok' => true);
    }

    /** Apply a coupon code to the cart. Validity is re-checked at checkout too. */
    public function apply_coupon($user_id, $code) {
        $code = strtoupper(trim((string)$code));
        if ($code === '') return $this->err('NO_CODE', 'Enter a coupon code.');
        $coupon = $this->ci->Coupon_model->find_valid($code);
        if (!$coupon) return $this->err('INVALID_COUPON', 'That coupon is not valid or has expired.');

        $view = $this->view($user_id);
        if ($coupon->min_order_amount !== null && bccomp($view['subtotal'], $coupon->min_order_amount, 8) < 0) {
            return $this->err('BELOW_MINIMUM', 'This coupon requires a subtotal of at least '.marvy_money($coupon->min_order_amount).'.');
        }

        $cart = $this->cart_for($user_id);
        $this->ci->Cart_model->set_coupon($cart->id, $code);
        return array('ok' => true);
    }

    public function remove_coupon($user_id) {
        $cart = $this->cart_for($user_id);
        $this->ci->Cart_model->set_coupon($cart->id, null);
        return array('ok' => true);
    }

    /** Compute the discount a coupon applies to a given subtotal. Shared with checkout. */
    public function compute_discount($coupon, $subtotal) {
        if ($coupon->discount_type === 'FIXED') {
            $discount = (string)$coupon->discount_value;
        } else {
            $discount = bcdiv(bcmul($subtotal, (string)$coupon->discount_value, 8), '100', 8);
        }
        if ($coupon->max_discount_amount !== null && bccomp($discount, $coupon->max_discount_amount, 8) > 0) {
            $discount = (string)$coupon->max_discount_amount;
        }
        if (bccomp($discount, $subtotal, 8) > 0) $discount = $subtotal;
        return $discount;
    }

    /** The shelf price right now: a valid promotion wins over the list price. */
    private function effective_price($item) {
        return $this->effective_price_of((object)array(
            'price' => $item->current_price, 'promo_price' => $item->promo_price,
        ));
    }

    private function effective_price_of($listing) {
        $list = (string)$listing->price;
        $promo = isset($listing->promo_price) && $listing->promo_price !== null ? (string)$listing->promo_price : null;
        if ($promo !== null && bccomp($promo, '0', 8) > 0 && bccomp($promo, $list, 8) < 0) return $promo;
        return $list;
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
