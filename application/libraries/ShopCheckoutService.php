<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__.'/ShopShippingAllocation.php';

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
 *     line's resulting order, while allocating the carrier fee to the first
 *     physical line so shipping is charged exactly once per checkout;
 *   - stop the whole checkout if any line is no longer valid, and compensate
 *     any earlier line charges if a later independent order fails, rather than
 *     silently completing a different cart or charging a retry twice.
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
            'Coupon_model', 'Shipping_address_model', 'Shipping_method_model',
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
            elseif (!empty($line['physical_details_missing'])) {
                $errors[] = $line['item']->title.' is not ready for physical fulfilment. Ask staff to finish its shipping details.';
            }
        }
        if ($errors) return $this->err('CART_CHANGED', implode(' ', $errors));
        return array('ok' => true, 'view' => $view);
    }

    /**
     * Quote the cart for the checkout screen. Shipping is charged exactly
     * once per checkout even though checkout intentionally creates one
     * marketplace order per cart line. The selected method is always looked up
     * from the active server-side catalogue; a browser can never submit a
     * cheaper price.
     */
    public function quote($user_id, array $input = array()) {
        $check = $this->validate($user_id);
        if (empty($check['ok'])) return $check;
        $view = $check['view'];
        $shipping_method = null;
        $shipping_cost = '0.00000000';

        if (!empty($view['has_physical'])) {
            $selected_method = $input['shipping_method'] ?? null;
            if ($selected_method !== null && $selected_method !== '' && !is_scalar($selected_method)) {
                return $this->err('BAD_SHIPPING_METHOD', 'Choose one shipping method.');
            }
            if ($selected_method !== null && $selected_method !== '') {
                $shipping_method = $this->ci->Shipping_method_model->find_active_public(
                    (string)$selected_method
                );
                if (!$shipping_method) {
                    return $this->err('BAD_SHIPPING_METHOD', 'That shipping method is no longer available.');
                }
            } else {
                $methods = $this->ci->Shipping_method_model->active_for_currency(marvy_base_currency());
                $shipping_method = $methods ? $methods[0] : null;
                if (!$shipping_method) {
                    return $this->err('NO_SHIPPING_METHOD', 'No shipping methods are available for physical items yet.');
                }
            }
            if (strtoupper((string)$shipping_method->currency) !== strtoupper((string)marvy_base_currency())) {
                return $this->err('SHIPPING_CURRENCY_MISMATCH', 'The selected shipping method is not available in the panel currency.');
            }
            // A checkout has one destination and one selected carrier quote.
            // Allocate that quote to the first physical order below; the
            // remaining physical order rows retain the method/address for
            // fulfilment but carry a zero allocation. This prevents a cart
            // with three physical lines from charging the same carrier fee
            // three times.
            $shipping_cost = $this->money($shipping_method->price);
            if (bccomp($shipping_cost, '0', 8) < 0) {
                return $this->err('BAD_SHIPPING_METHOD', 'The selected shipping method has an invalid price.');
            }
        }

        $view['shipping_method'] = $shipping_method;
        $view['shipping_cost'] = $shipping_cost;
        $view['total'] = bcadd($view['total'], $shipping_cost, 8);
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
        $quoted = $this->quote($user_id, $input);
        if (empty($quoted['ok'])) return $quoted;
        $view = $quoted['view'];

        $shipping_address_id = null;
        $shipping_method = $view['shipping_method'] ?? null;
        if (!empty($view['has_physical'])) {
            $addr = $this->resolve_shipping_address($user_id, $input);
            if (empty($addr['ok'])) return $addr;
            $shipping_address_id = $addr['address']->id;
        }

        $orders = array();
        $raw_idem = $input['idempotency_key'] ?? null;
        $provided_idem = is_scalar($raw_idem) ? trim((string)$raw_idem) : '';
        // Store only a bounded, user-scoped digest in each line's unique
        // transaction key. This keeps a browser-supplied token from becoming
        // SQL data of arbitrary length or injecting the line separator.
        $idem_root = $provided_idem === ''
            ? bin2hex(random_bytes(16))
            : substr(hash('sha256', $provided_idem), 0, 48);

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

        $shipping_allocated = false;
        $new_orders = array();
        $reservation_attached = false;
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

            $needs_shipping = !empty($line['requires_shipping']);
            $allocate_shipping = $needs_shipping && !$shipping_allocated;
            if ($needs_shipping) $shipping_allocated = true;
            $res = $this->ci->marketplaceservice->purchase($user, array(
                'listing' => $item->listing_public_id,
                'quantity' => (int)$item->quantity,
                'discount' => $line_discount,
                'shipping_address_id' => $needs_shipping ? $shipping_address_id : null,
                'shipping_method_id' => $needs_shipping && $shipping_method ? $shipping_method->id : null,
                // This object can only be created by server-side PHP; a
                // scalar posted by a browser cannot waive the carrier fee.
                // MarketplaceService still resolves the active method and
                // computes its amount; later lines receive a zero allocation
                // from that same method.
                'shipping_allocation' => $needs_shipping
                    ? ($allocate_shipping ? ShopShippingAllocation::first() : ShopShippingAllocation::subsequent())
                    : null,
                'idempotency_key' => 'shop:'.$user_id.':'.$idem_root.':'.$item->listing_id,
                'source' => isset($input['source']) && is_scalar($input['source'])
                    ? (string)$input['source'] : 'WEB',
            ));

            if (empty($res['ok'])) {
                return $this->checkout_failure($res, $item, $orders, $new_orders, $reservation, $view);
            }

            $order = $res['order'] ?? null;
            if (!$order) {
                return $this->checkout_failure(
                    array('code' => 'CHECKOUT_FAILED', 'error' => 'The order receipt was not created'),
                    $item, $orders, $new_orders, $reservation, $view
                );
            }
            $is_duplicate = !empty($res['duplicate']);
            // TransactionEngine deliberately resolves every idempotency retry
            // to its original row. A previous attempt that was refunded or
            // cancelled must not make a later retry look like a successful
            // checkout and clear the cart without a new order.
            if ($is_duplicate && in_array((string)$order->status, array('REFUNDED', 'CANCELLED'), true)) {
                return $this->checkout_failure(
                    array('code' => 'PREVIOUS_CHECKOUT_FAILED', 'error' => 'This checkout attempt was already cancelled. Start checkout again.'),
                    $item, $orders, $new_orders, $reservation, $view
                );
            }
            $orders[] = $order;

            if ($is_duplicate) continue; // already fulfilled by an earlier identical attempt

            $new_orders[] = $order;

            // MarketplaceService creates the shipment inside its paid-order
            // dispatch. Keeping that write beside the stock/order transition
            // means a failed shipment insert refunds automatically instead of
            // leaving a charged physical order with no fulfilment record.

            // The reservation is completed — not created — once checkout has
            // actually charged something, against the first newly-created order.
            if ($reservation && !$reservation_attached) {
                $this->ci->Coupon_model->attach_redemption(
                    $reservation['id'], $order->id, $view['discount']
                );
                $reservation_attached = true;
            }
        }

        // A replay where every line was already completed has no newly-created
        // order to own this invocation's coupon reservation. Do not leak that
        // temporary slot into the customer's usage count.
        if ($reservation && !$reservation_attached) {
            $this->ci->Coupon_model->release_redemption(
                $reservation['id'], !empty($view['coupon']) ? (int)$view['coupon']->id : null
            );
        }

        // The cart is only cleared once every line has genuinely settled —
        // an interrupted checkout leaves the cart exactly as it was so the
        // customer can simply try again.
        $cart = $this->ci->Cart_model->for_user($user_id);
        if ($cart) $this->ci->Cart_model->clear($cart->id);

        return array('ok' => true, 'orders' => $orders);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Roll back orders created by this checkout when a later line fails.
     * Each MarketplaceService charge is independently committed, so without
     * this compensating path a cart retry could charge the successful prefix
     * again. Idempotent orders from an earlier attempt are deliberately left
     * alone; only rows created during this invocation are refunded.
     */
    private function checkout_failure(array $failure, $item, array $orders, array $new_orders,
                                      $reservation, array $view) {
        for ($i = count($new_orders) - 1; $i >= 0; $i--) {
            $fresh = $this->ci->Marketplace_order_model->find_id($new_orders[$i]->id);
            if (!$fresh || !in_array((string)$fresh->status, array('PAID', 'DELIVERED', 'DISPUTED', 'PARTIALLY_REFUNDED'), true)) {
                continue;
            }
            $rolled = $this->ci->marketplaceservice->refund(
                $fresh, null, 'Checkout did not complete; compensating rollback'
            );
            if (empty($rolled['ok'])) {
                // The original failure is still returned to the customer, but
                // make the stranded-money condition visible to operations.
                log_message('error', 'Shop checkout rollback could not refund order '.$fresh->public_id);
            }
        }
        if ($reservation) {
            $this->ci->Coupon_model->release_redemption(
                $reservation['id'], !empty($view['coupon']) ? (int)$view['coupon']->id : null
            );
        }
        return array(
            'ok' => false,
            'code' => $failure['code'] ?? 'CHECKOUT_FAILED',
            'error' => 'Could not complete "'.$item->title.'": '.($failure['error'] ?? 'unknown error'),
            'orders' => $orders,
        );
    }

    private function resolve_shipping_address($user_id, array $input) {
        $raw_selected = $input['shipping_address_id'] ?? null;
        if ($raw_selected !== null && $raw_selected !== '' && !is_scalar($raw_selected)) {
            return $this->err('BAD_ADDRESS', 'Choose one shipping address.');
        }
        $selected = trim((string)$raw_selected);
        if ($selected !== '') {
            $addr = $this->ci->Shipping_address_model->find_public_for_user($selected, $user_id);
            if ($addr) return array('ok' => true, 'address' => $addr);
            return $this->err('BAD_ADDRESS', 'Choose one of your saved shipping addresses.');
        }
        // A new address was submitted inline on the checkout form.
        $full_name = trim($this->input_string($input, 'full_name'));
        $phone = trim($this->input_string($input, 'phone'));
        $line1 = trim($this->input_string($input, 'line1'));
        $city = trim($this->input_string($input, 'city'));
        $country = strtoupper(trim($this->input_string($input, 'country_code')));

        if ($full_name === '' || $phone === '' || $line1 === '' || $city === ''
            || !preg_match('/^[A-Z]{2}$/', $country)) {
            return $this->err('NO_ADDRESS', 'A shipping address is required for the physical item(s) in your cart.');
        }

        $id = $this->ci->Shipping_address_model->create(array(
            'user_id' => $user_id,
            'full_name' => mb_substr($full_name, 0, 160),
            'phone' => mb_substr($phone, 0, 32),
            'line1' => mb_substr($line1, 0, 255),
            'line2' => mb_substr($this->input_string($input, 'line2'), 0, 255) ?: null,
            'city' => mb_substr($city, 0, 120),
            'state' => mb_substr($this->input_string($input, 'state'), 0, 120) ?: null,
            'postal_code' => mb_substr($this->input_string($input, 'postal_code'), 0, 32) ?: null,
            'country_code' => $country,
            'is_default' => !empty($input['save_address']) ? 1 : 0,
        ));
        $address = $id ? $this->ci->Shipping_address_model->find_by_id($id) : null;
        if (!$address) return $this->err('ADDRESS_FAILED', 'The shipping address could not be saved. Please try again.');
        return array('ok' => true, 'address' => $address);
    }

    private function input_string(array $input, $key, $default = '') {
        $value = $input[$key] ?? $default;
        return is_scalar($value) ? (string)$value : (string)$default;
    }

    private function money($value) {
        return number_format((float)$value, 8, '.', '');
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
