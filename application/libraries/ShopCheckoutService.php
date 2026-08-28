<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ShopCheckoutService — converts a cart into real marketplace orders.
 *
 * Deliberately does not invent a second charge engine. Each cart line becomes
 * its own MarketplaceService::purchase() call, which is the same audited,
 * idempotent, wallet-charging path a direct "Buy now" on a single listing
 * already uses (TransactionEngine underneath). Checkout's job is only to:
 *
 *   - re-validate every line against the live listing (price, stock, status)
 *     at the moment of payment — never trust what the cart page last showed;
 *   - split a cart-level coupon discount across lines proportionally, so the
 *     underlying per-listing purchase() call still charges a server-computed
 *     amount, never a client-submitted total;
 *   - collect a shipping address/method once and attach it to every physical
 *     line's resulting order;
 *   - stop the whole checkout (refunding nothing, since nothing has charged
 *     yet) if any line is no longer valid, rather than silently completing a
 *     different cart than the customer looked at.
 *
 * Digital and gift-card lines fulfil through their own existing systems
 * (secure download issuance, GiftcardService) exactly as a direct purchase
 * of that listing already would — see ShopDeliveryService.
 */
class ShopCheckoutService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Cart_model', 'Cart_item_model', 'Marketplace_listing_model', 'Marketplace_order_model',
            'Coupon_model', 'Shipping_address_model', 'Shipping_method_model', 'Shop_order_shipment_model',
        ));
        $this->ci->load->library(array('MarketplaceService', 'CartService', 'ShopDeliveryService'));
    }

    /**
     * Validate the cart is checkout-ready without charging anything.
     * Used to render checkout and to re-check immediately before charging.
     */
    public function validate($user_id) {
        $view = $this->ci->cartservice->view($user_id);
        if (empty($view['lines'])) return $this->err('EMPTY_CART', 'Your cart is empty.');

        $errors = array();
        foreach ($view['lines'] as $line) {
            if ($line['unavailable']) $errors[] = $line['item']->title.' is no longer available.';
            elseif ($line['out_of_stock']) $errors[] = $line['item']->title.' does not have enough stock.';
        }
        if ($errors) return $this->err('CART_CHANGED', implode(' ', $errors));
        return array('ok' => true, 'view' => $view);
    }

    /**
     * Charge the cart. Requires a shipping address/method only when the cart
     * actually contains a physical line; a purely digital/gift-card cart never
     * asks for one.
     *
     * @return array{ok:bool, orders?:object[], error?:string, code?:string}
     */
    public function checkout($user, array $input) {
        $user_id = is_object($user) ? (int)$user->id : (int)$user;
        $check = $this->validate($user_id);
        if (empty($check['ok'])) return $check;
        $view = $check['view'];

        $shipping_address_id = null;
        $shipping_method = null;
        if ($view['has_physical']) {
            $addr = $this->resolve_shipping_address($user_id, $input);
            if (empty($addr['ok'])) return $addr;
            $shipping_address_id = $addr['address']->id;

            if (!empty($input['shipping_method'])) {
                $shipping_method = $this->ci->Shipping_method_model->find_public($input['shipping_method']);
            }
        }

        $orders = array();
        $idem_root = $input['idempotency_key'] ?? bin2hex(random_bytes(16));

        // Take the coupon slot BEFORE charging anything.
        //
        // It used to be recorded after the first line was charged, which meant
        // the per-customer limit was decided by a COUNT(*) that two
        // simultaneous checkouts both passed — a double-clicked Pay button was
        // enough to use a "one per customer" code twice, and by the time the
        // second row was written the money had already moved. Reserving first
        // means the database's UNIQUE index (migration 030) refuses the loser
        // while nothing has been charged, so the customer gets a clear
        // message instead of an unexpected discount that has to be clawed
        // back. The slot is released below if the checkout does not complete.
        $reservation = null;
        if (!empty($view['coupon'])) {
            $reservation = $this->ci->Coupon_model->reserve_redemption($view['coupon'], $user_id);
            if (empty($reservation['ok'])) {
                return $this->err($reservation['code'] ?? 'COUPON_UNAVAILABLE',
                    $reservation['error'] ?? 'That coupon can no longer be applied to this order.');
            }
        }

        foreach ($view['lines'] as $line) {
            $item = $line['item'];
            // A coupon discount is allocated to each line in proportion to its
            // share of the pre-discount subtotal, so the sum of per-line
            // charges always equals subtotal - discount to the last cent.
            $line_discount = '0';
            if (bccomp($view['subtotal'], '0', 8) > 0 && bccomp($view['discount'], '0', 8) > 0) {
                $share = bcdiv($line['line_total'], $view['subtotal'], 12);
                $line_discount = bcmul($view['discount'], $share, 8);
            }

            $res = $this->ci->marketplaceservice->purchase($user, array(
                'listing' => $item->listing_public_id,
                'quantity' => (int)$item->quantity,
                'discount' => $line_discount,
                'idempotency_key' => 'shop:'.$user_id.':'.$idem_root.':'.$item->listing_id,
                'source' => $input['source'] ?? 'WEB',
            ));

            if (empty($res['ok'])) {
                // Nothing was charged for THIS line. If no line at all got
                // through, the coupon was never actually used: give the slot
                // back rather than burning the customer's only redemption on
                // an order that does not exist.
                if ($reservation && !$orders) {
                    $this->ci->Coupon_model->release_redemption(
                        $reservation['id'], (int)$view['coupon']->id);
                    $reservation = null;
                }
                // Nothing charges twice: every earlier line in this loop was
                // its own independent, already-committed TransactionEngine
                // charge (exactly like buying each one separately), so a later
                // line failing (e.g. it sold out mid-checkout) does not roll
                // those back — it stops the remaining lines and reports
                // clearly which line failed, the same way a real checkout
                // that partially succeeds must behave rather than silently
                // losing track of what was actually charged.
                return array(
                    'ok' => false,
                    'code' => $res['code'] ?? 'CHECKOUT_FAILED',
                    'error' => 'Could not complete "'.$item->title.'": '.($res['error'] ?? 'unknown error'),
                    'orders' => $orders,
                );
            }

            $order = $res['order'];
            $orders[] = $order;

            if (!empty($res['duplicate'])) continue; // already fulfilled by an earlier identical attempt

            if ($item->product_type === 'PHYSICAL' && $shipping_address_id) {
                $this->ci->Shop_order_shipment_model->create(array(
                    'marketplace_order_id' => $order->id,
                    'shipping_address_id'  => $shipping_address_id,
                    'shipping_method_id'   => $shipping_method ? $shipping_method->id : null,
                    'shipping_cost'        => $shipping_method ? $shipping_method->price : '0.00000000',
                    'status'               => 'PENDING',
                ));
            }

            // The reservation is completed — not created — once checkout has
            // actually charged something, against the first order it produced.
            if ($reservation && count($orders) === 1) {
                $this->ci->Coupon_model->attach_redemption(
                    $reservation['id'], $order->id, $view['discount']
                );
            }
        }

        // The cart is only cleared once every line has genuinely settled —
        // an interrupted checkout leaves the cart exactly as it was so the
        // customer can simply try again.
        $cart = $this->ci->Cart_model->for_user($user_id);
        if ($cart) $this->ci->Cart_model->clear($cart->id);

        return array('ok' => true, 'orders' => $orders);
    }

    /* ------------------------------------------------------------------ */

    private function resolve_shipping_address($user_id, array $input) {
        if (!empty($input['shipping_address_id'])) {
            $addr = $this->ci->Shipping_address_model->find_public_for_user($input['shipping_address_id'], $user_id);
            if ($addr) return array('ok' => true, 'address' => $addr);
        }
        // A new address was submitted inline on the checkout form.
        $full_name = trim((string)($input['full_name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $line1 = trim((string)($input['line1'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));
        $country = strtoupper(trim((string)($input['country_code'] ?? '')));

        if ($full_name === '' || $phone === '' || $line1 === '' || $city === '' || strlen($country) !== 2) {
            return $this->err('NO_ADDRESS', 'A shipping address is required for the physical item(s) in your cart.');
        }

        $id = $this->ci->Shipping_address_model->create(array(
            'user_id' => $user_id,
            'full_name' => mb_substr($full_name, 0, 160),
            'phone' => mb_substr($phone, 0, 32),
            'line1' => mb_substr($line1, 0, 255),
            'line2' => mb_substr((string)($input['line2'] ?? ''), 0, 255) ?: null,
            'city' => mb_substr($city, 0, 120),
            'state' => mb_substr((string)($input['state'] ?? ''), 0, 120) ?: null,
            'postal_code' => mb_substr((string)($input['postal_code'] ?? ''), 0, 32) ?: null,
            'country_code' => $country,
            'is_default' => !empty($input['save_address']) ? 1 : 0,
        ));
        return array('ok' => true, 'address' => $this->ci->Shipping_address_model->find_by_id($id));
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
