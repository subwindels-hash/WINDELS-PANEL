<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once __DIR__.'/ShopShippingAllocation.php';

/**
 * MarketplaceService — platform-operated digital goods with buyer escrow.
 *
 * THE PLATFORM IS THE SOLE SELLER. There is no vendor/seller entity, no
 * seller application, no seller moderation, no payout rail and no fee split:
 * staff create, price, publish and fulfil listings from the admin panel, and
 * buyers pay the platform itself.
 *
 * The buyer is charged by TransactionEngine and the universal transaction
 * remains PROCESSING until the buyer accepts delivery, an administrator
 * resolves a dispute, or the bounded auto-release worker runs. Refunds also
 * go through TransactionEngine; releasing escrow moves no money — the full
 * charge was platform revenue from the moment of purchase — it settles the
 * transaction, stamps the order COMPLETED and records the audit trail.
 *
 * Fulfilment is encrypted before it reaches the model. The only plaintext read
 * is reveal(), which verifies ownership and emits an audit record.
 */
class MarketplaceService {
    const DEFAULT_AUTO_RELEASE_HOURS = 72;
    const MAX_QUANTITY = 100;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Marketplace_listing_model', 'Marketplace_order_model',
            'Marketplace_category_model',
            'Service_transaction_model',
            'Wallet_model', 'Audit_log_model', 'Setting_model',
            'Physical_product_model', 'Shipping_address_model',
            'Shipping_method_model', 'Shop_order_shipment_model',
        ));
        $this->ci->load->library(array(
            'TransactionEngine', 'LedgerService', 'EncryptionService'
        ));
    }

    /** Create a listing straight to the shelf, or update an existing one. */
    public function save_listing($user, array $input, $public_id = null) {
        $user_id = $this->user_id($user);
        if (!$user_id) return $this->err('Sign in before managing listings', 'NO_USER');

        $listing = null;
        if ($public_id) {
            $listing = $this->ci->Marketplace_listing_model->find_public($public_id, false);
            if (!$listing) return $this->err('Listing not found', 'NOT_FOUND');
            if ($listing->status === 'ARCHIVED') {
                return $this->err('Archived listings cannot be edited', 'ARCHIVED');
            }
        }

        $title = trim((string)($input['title'] ?? ''));
        $category = strtoupper(trim((string)($input['category'] ?? '')));
        $description = trim((string)($input['description'] ?? ''));
        $price = $this->money($input['price'] ?? 0);
        $promo_price = ($input['promo_price'] ?? '') === '' ? null : $this->money($input['promo_price']);
        $delivery_days = (int)($input['delivery_days'] ?? 1);
        $stock = ($input['stock'] ?? '') === '' ? null : (int)$input['stock'];
        $product_type = strtoupper(trim((string)($input['product_type'] ?? 'DIGITAL')));
        $is_featured = !empty($input['is_featured']) ? 1 : 0;
        $image = ($input['image'] ?? '') !== '' ? trim((string)$input['image']) : null;

        if (mb_strlen($title) < 5 || mb_strlen($title) > 120) {
            return $this->err('Title must be between 5 and 120 characters', 'BAD_TITLE');
        }
        if (mb_strlen($description) < 20 || mb_strlen($description) > 10000) {
            return $this->err('Description must be between 20 and 10,000 characters', 'BAD_DESCRIPTION');
        }
        // Categories are managed rows (admin/marketplace/categories), not a
        // pattern the poster invents.
        if (!$this->ci->Marketplace_category_model->find_active($category)) {
            return $this->err('Choose a valid category', 'BAD_CATEGORY');
        }
        if (bccomp($price, '0', 8) <= 0) return $this->err('Price must be greater than zero', 'BAD_PRICE');
        if ($promo_price !== null
            && (bccomp($promo_price, '0', 8) <= 0 || bccomp($promo_price, $price, 8) >= 0)) {
            return $this->err('The promotional price must be greater than zero and lower than the list price', 'BAD_PROMO');
        }
        if ($delivery_days < 1 || $delivery_days > 30) {
            return $this->err('Delivery time must be between 1 and 30 days', 'BAD_DELIVERY');
        }
        if ($stock !== null && $stock < 0) return $this->err('Stock cannot be negative', 'BAD_STOCK');
        if (!in_array($product_type, array('DIGITAL', 'PHYSICAL'), true)) {
            return $this->err('Choose a valid product type', 'BAD_TYPE');
        }

        $fields = array(
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'price' => $price,
            'promo_price' => $promo_price,
            'stock' => $stock,
            'delivery_days' => $delivery_days,
            'product_type' => $product_type,
            'is_featured' => $is_featured,
        );
        if ($image !== null) $fields['image'] = $image;
        // Staff listings are trusted: they go straight to the shelf instead of
        // an approval queue, with the acting operator stamped as approver.
        if (!$listing) {
            $fields['status'] = 'ACTIVE';
            $fields['approved_at'] = gmdate('Y-m-d H:i:s');
            $fields['approved_by'] = $user_id;
        }
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($listing) {
            $before = array('status' => $listing->status, 'price' => $listing->price);
            $this->ci->Marketplace_listing_model->update_fields($listing->id, $fields);
            $id = $listing->id;
            $action = 'marketplace.listing.update';
        } else {
            $fields['public_id'] = marvy_public_id();
            $fields['created_at'] = gmdate('Y-m-d H:i:s');
            $id = $this->ci->Marketplace_listing_model->create($fields);
            $before = null;
            $action = 'marketplace.listing.create';
        }
        $saved = $this->ci->Marketplace_listing_model->find_id($id);
        $this->audit($user_id, $action, 'marketplace_listing', $saved->public_id, $before,
            array('status' => $saved->status, 'price' => $saved->price));
        return array('ok' => true, 'listing' => $saved);
    }

    /** Charge a buyer and open escrow. The transaction stays PROCESSING. */
    public function purchase($user, array $input) {
        if (!marvy_feature_enabled('marketplace', true)) {
            return $this->err('The marketplace is currently unavailable.', 'FEATURE_DISABLED');
        }
        $buyer_id = $this->user_id($user);
        $raw_listing = $input['listing'] ?? '';
        if (!is_scalar($raw_listing)) return $this->err('That listing is not available', 'NO_LISTING');
        $listing = $this->ci->Marketplace_listing_model->find_public((string)$raw_listing, true);
        if (!$listing) return $this->err('That listing is not available', 'NO_LISTING');
        $raw_quantity = $input['quantity'] ?? 1;
        if (!is_scalar($raw_quantity)) return $this->err('Choose a valid quantity', 'BAD_QUANTITY');
        $quantity = (int)$raw_quantity;
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            return $this->err('Choose a valid quantity', 'BAD_QUANTITY');
        }
        if ($listing->stock !== null && (int)$listing->stock < $quantity) {
            return $this->err('There is not enough stock', 'OUT_OF_STOCK');
        }

        // A physical listing is not a normal "buy now" line: its address and
        // carrier quote must be bound to the same charge that opens escrow.
        // Resolve both from server-side rows before the wallet is touched. A
        // missing physical-product row is treated as not sellable rather than
        // silently creating a shipment with no SKU or package data.
        $shipping = $this->shipping_context($buyer_id, $listing, $input);
        if (empty($shipping['ok'])) return $shipping;
        $shipping_cost = $shipping['cost'];

        // The customer pays the server's price — never a submitted one — and a
        // live promotion undercuts the list price. Selling is platform-side,
        // so the gross IS the revenue: no supplier cost, fee or payout split.
        $unit_price = $this->effective_price($listing);
        $line_amount = bcmul($unit_price, (string)$quantity, 8);
        // Optional per-line discount (e.g. a coupon applied at cart checkout,
        // ShopCheckoutService). Never negative and never larger than the line
        // itself — a caller cannot make a line's charge go below zero.
        $raw_discount = $input['discount'] ?? '0';
        $discount = is_scalar($raw_discount) ? trim((string)$raw_discount) : '0';
        if ($discount !== '' && !preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,8})?$/', $discount)) {
            return $this->err('Invalid line discount', 'BAD_DISCOUNT');
        }
        if ($discount === '' || bccomp($discount, '0', 8) < 0) $discount = '0';
        if (bccomp($discount, $line_amount, 8) > 0) $discount = $line_amount;
        // Coupons discount the product line, not the carrier's separately
        // quoted fee. The universal transaction and the marketplace order
        // therefore agree on the exact amount the buyer paid.
        $gross = $this->money(bcadd(bcsub($line_amount, $discount, 8), $shipping_cost, 8));
        $order_model = $this->ci->Marketplace_order_model;
        $listing_model = $this->ci->Marketplace_listing_model;
        $order_id = null;

        $result = $this->ci->transactionengine->execute($user, array(
            'service_domain' => 'MARKETPLACE',
            'service_type' => 'PURCHASE',
            'service_id' => $listing->id,
            'provider_cost' => null,
            'amount' => $gross,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'source' => $input['source'] ?? 'WEB',
            'metadata' => array(
                'listing' => $listing->public_id,
                'title' => $listing->title,
                'quantity' => $quantity,
                'shipping_cost' => $shipping_cost,
                'shipping_method_id' => $shipping['method_id'],
            ),
            'detail' => function ($transaction_id) use ($listing, $buyer_id, $quantity, $gross,
                                                         $shipping_cost, $order_model, &$order_id, $unit_price) {
                $order_id = $order_model->create(array(
                    'public_id' => marvy_public_id(),
                    'service_transaction_id' => $transaction_id,
                    'listing_id' => $listing->id,
                    'buyer_id' => $buyer_id,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'gross_amount' => $gross,
                    'shipping_cost' => $shipping_cost,
                    'status' => 'PENDING',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ));
            },
            'dispatch' => function ($transaction) use ($listing, $quantity, $order_model, $listing_model,
                                                        $shipping, $shipping_cost) {
                if (!$listing_model->decrement_stock($listing->id, $quantity)) {
                    $order = $order_model->find_by_transaction($transaction->id);
                    if ($order && $order_model->transition($order->id, 'PENDING', 'CANCELLED')) {
                        $order_model->record_event(
                            $order->id, $order->buyer_id, 'CANCELLED', 'PENDING', 'CANCELLED',
                            'Listing sold out before checkout completed'
                        );
                    }
                    return array('ok' => false, 'error' => 'This listing sold out before checkout completed');
                }
                $order = $order_model->find_by_transaction($transaction->id);
                if (!$order || !$order_model->transition($order->id, 'PENDING', 'PAID')) {
                    $listing_model->restore_stock($listing->id, $quantity);
                    return array('ok' => false, 'error' => 'Could not open marketplace escrow');
                }
                $order_model->record_event($order->id, $order->buyer_id, 'PURCHASED', 'PENDING', 'PAID');

                // A physical shipment is part of the paid-order dispatch, not
                // an afterthought in the cart controller. If its insert fails,
                // return false so TransactionEngine refunds the charge and the
                // stock/order rollback below leaves no paid order without a
                // carrier record.
                if (!empty($shipping['required'])) {
                    try {
                        $shipment_id = $this->ci->Shop_order_shipment_model->create(array(
                            'marketplace_order_id' => $order->id,
                            'shipping_address_id' => $shipping['address_id'],
                            'shipping_method_id' => $shipping['method_id'],
                            'shipping_cost' => $shipping_cost,
                            'status' => 'PENDING',
                        ));
                        if (!$shipment_id) throw new RuntimeException('shipment insert returned no id');
                    } catch (Throwable $e) {
                        $order_model->transition($order->id, 'PAID', 'CANCELLED');
                        $order_model->record_event(
                            $order->id, $order->buyer_id, 'CANCELLED', 'PAID', 'CANCELLED',
                            'Shipping record could not be created'
                        );
                        $listing_model->restore_stock($listing->id, $quantity);
                        log_message('error', 'Marketplace shipment creation failed: '.$e->getMessage());
                        return array('ok' => false, 'error' => 'Could not create the shipment for this order');
                    }
                }

                // Automatic fulfilment for DIGITAL listings that carry an
                // uploaded file: secure download access is granted the
                // instant payment settles (ShopDeliveryService), with no
                // human step required. This never touches money — the wallet
                // has already been charged above — so a failure here cannot
                // fail the purchase itself; it is recorded and left for the
                // order's normal support/retry path like any other
                // post-payment delivery step in this codebase. (Gift cards
                // bought from the vendor catalogue are a separate, already
                // fully-built purchase flow — see dashboard/Giftcards /
                // GiftcardService — and are deliberately not routed through
                // here: that flow has its own TransactionEngine charge, and
                // calling it again from inside this one would charge the
                // wallet twice for one purchase.)
                if (strtoupper((string)$listing->product_type) === 'DIGITAL') {
                    try {
                        $this->ci->load->library('ShopDeliveryService');
                        $this->ci->shopdeliveryservice->provision($order, $listing);
                    } catch (Throwable $e) {
                        log_message('error', 'marketplace digital fulfilment failed for order '.$order->public_id.': '.$e->getMessage());
                    }
                }

                return array('ok' => true, 'status' => 'PROCESSING');
            },
        ));

        if (!empty($result['duplicate']) && !empty($result['transaction'])) {
            $order_id = null;
        }
        // TransactionEngine creates the domain row before charging so every
        // attempt is traceable. If charging itself fails, close that row rather
        // than leaving a PENDING escrow order that can never be fulfilled.
        if (empty($result['ok']) && $order_id) {
            $failed_order = $order_model->find_id($order_id);
            if ($failed_order && $order_model->transition($order_id, 'PENDING', 'CANCELLED')) {
                $order_model->record_event(
                    $order_id, $buyer_id, 'CANCELLED', 'PENDING', 'CANCELLED',
                    $result['error'] ?? 'Marketplace checkout failed'
                );
            }
        }
        if (!empty($result['transaction'])) {
            $result['order'] = $order_model->find_by_transaction($result['transaction']->id);
        } elseif ($order_id) {
            $result['order'] = $order_model->find_id($order_id);
        }
        return $result;
    }

    /**
     * Staff encrypt and submit fulfilment. $as_admin is raised only by the
     * admin console, behind require_perm('marketplace.manage'): fulfilment is
     * exclusively a staff action now that the platform is the sole seller —
     * without the flag nobody can deliver, not even the buyer.
     */
    public function deliver($user, $public_id, $delivery, $as_admin = false) {
        $actor_id = $this->user_id($user);
        if (!$as_admin) return $this->err('Order not found', 'NOT_FOUND');
        $order = $this->ci->Marketplace_order_model->find_public($public_id);
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        if ($order->status !== 'PAID') return $this->err('This order cannot be delivered now', 'BAD_STATE');
        if ($this->requires_shipment($order)) {
            return $this->err('Physical orders must be delivered through shipment tracking', 'USE_SHIPMENT_FLOW');
        }
        $delivery = trim((string)$delivery);
        if (mb_strlen($delivery) < 3 || mb_strlen($delivery) > 20000) {
            return $this->err('Delivery must be between 3 and 20,000 characters', 'BAD_DELIVERY');
        }
        $now = gmdate('Y-m-d H:i:s');
        $hours = max(1, min(720, (int)$this->ci->Setting_model->get(
            'marketplace_auto_release_hours', self::DEFAULT_AUTO_RELEASE_HOURS)));
        $due = gmdate('Y-m-d H:i:s', time() + ($hours * 3600));
        if (!$this->ci->Marketplace_order_model->transition($order->id, 'PAID', 'DELIVERED', array(
            'delivery_encrypted' => $this->ci->encryptionservice->encrypt($delivery),
            'delivered_at' => $now,
            'release_due_at' => $due,
        ))) return $this->err('This order changed before delivery was saved', 'CONFLICT');

        $this->ci->Marketplace_order_model->record_event($order->id, $actor_id, 'DELIVERED', 'PAID', 'DELIVERED');
        $this->audit($actor_id, 'marketplace.order.deliver', 'marketplace_order', $public_id,
            array('status' => 'PAID'), array('status' => 'DELIVERED', 'release_due_at' => $due));
        return array('ok' => true, 'order' => $this->ci->Marketplace_order_model->find_id($order->id));
    }

    /** Audited access to plaintext fulfilment; corrupt ciphertext is never returned. */
    public function reveal($user, $public_id, $admin = false) {
        $actor_id = $this->user_id($user);
        $order = $this->ci->Marketplace_order_model->find_public($public_id);
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        if (!$admin && (int)$order->buyer_id !== $actor_id) {
            return $this->err('Order not found', 'NOT_FOUND');
        }
        if (!$order->delivery_encrypted) return $this->err('This order has not been delivered', 'NOT_DELIVERED');
        $plain = $this->ci->encryptionservice->open($order->delivery_encrypted);
        if ($plain === null) return $this->err('Delivery data could not be opened', 'UNAVAILABLE');
        $this->audit($actor_id, 'marketplace.delivery.reveal', 'marketplace_order', $public_id, null,
            array('access' => $admin ? 'ADMIN' : 'OWNER'));
        return array('ok' => true, 'delivery' => $plain, 'order' => $order);
    }

    public function accept($user, $public_id) {
        $order = $this->ci->Marketplace_order_model->find_public($public_id);
        if (!$order || (int)$order->buyer_id !== $this->user_id($user)) {
            return $this->err('Order not found', 'NOT_FOUND');
        }
        return $this->release($order, 'BUYER', $order->buyer_id);
    }

    public function dispute($user, $public_id, $reason) {
        $buyer_id = $this->user_id($user);
        $order = $this->ci->Marketplace_order_model->find_public($public_id);
        if (!$order || (int)$order->buyer_id !== $buyer_id) return $this->err('Order not found', 'NOT_FOUND');
        $reason = trim((string)$reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            return $this->err('Explain the dispute in 10 to 1,000 characters', 'BAD_REASON');
        }
        $now = gmdate('Y-m-d H:i:s');
        if (!$this->ci->Marketplace_order_model->transition($order->id, 'DELIVERED', 'DISPUTED', array(
            'dispute_reason' => $reason,
            'disputed_at' => $now,
            // Removing the due time makes the cron exclusion explicit as well
            // as status-based: disputes can never auto-release.
            'release_due_at' => null,
        ))) return $this->err('This order can no longer be disputed', 'BAD_STATE');
        $this->ci->Marketplace_order_model->record_event($order->id, $buyer_id, 'DISPUTED', 'DELIVERED', 'DISPUTED');
        $this->audit($buyer_id, 'marketplace.order.dispute', 'marketplace_order', $public_id,
            array('status' => 'DELIVERED'), array('status' => 'DISPUTED'));
        return array('ok' => true, 'order' => $this->ci->Marketplace_order_model->find_id($order->id));
    }

    /**
     * Close escrow in the platform's favour. Releasing moves NO money — the
     * buyer's charge was platform revenue at purchase time; this settles the
     * service transaction and stamps the order COMPLETED. The compare-and-set
     * claim below means a release and a refund can never both win for one
     * order, and re-running a finished release is an idempotent duplicate.
     */
    public function release($order, $source = 'CRON', $actor_id = null) {
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        $source = strtoupper((string)$source);
        $allowed = $source === 'ADMIN' ? array('DELIVERED', 'DISPUTED') : array('DELIVERED');
        if ($order->status === 'COMPLETED') {
            return array('ok' => true, 'duplicate' => true, 'order' => $order);
        }
        if (!in_array($order->status, $allowed, true)) {
            return $this->err('This order cannot be released', 'BAD_STATE');
        }
        if ($this->requires_shipment($order)) {
            $shipment = $this->ci->Shop_order_shipment_model->for_order($order->id);
            if (!$shipment || strtoupper((string)$shipment->status) !== 'DELIVERED') {
                return $this->err('A physical order can only be released after its shipment is delivered', 'SHIPMENT_NOT_DELIVERED');
            }
        }

        if (!$this->ci->db->trans_begin()) return $this->err('Escrow transaction could not start', 'DB_ERROR');

        $from = $order->status;
        $now = gmdate('Y-m-d H:i:s');
        try {
            // Claim the escrow row before settling the transaction. Both
            // release and refund use this compare-and-set, so exactly one
            // resolution can win.
            if (!$this->ci->Marketplace_order_model->transition($order->id, $from, 'COMPLETED', array(
                'released_at' => $now,
                'release_due_at' => null,
                'resolved_at' => $from === 'DISPUTED' ? $now : $order->resolved_at,
                'resolved_by' => $from === 'DISPUTED' ? $actor_id : $order->resolved_by,
            ))) {
                $this->ci->db->trans_rollback();
                $fresh = $this->ci->Marketplace_order_model->find_id($order->id);
                if ($fresh && $fresh->status === 'COMPLETED') {
                    return array('ok' => true, 'duplicate' => true, 'order' => $fresh);
                }
                return $this->err('Order release conflicted', 'CONFLICT');
            }

            $tx = $this->ci->Service_transaction_model->find_by_id($order->service_transaction_id);
            if (!$tx) {
                $this->ci->db->trans_rollback();
                return $this->err('Transaction not found', 'NOT_FOUND');
            }
            if ($tx->status === 'PROCESSING') {
                $settled = $this->ci->transactionengine->transition(
                    $tx->id, 'SUCCESSFUL', $source, null, array('refund' => false)
                );
                if (empty($settled['ok'])) {
                    $this->ci->db->trans_rollback();
                    return $this->err($settled['error'] ?? 'Transaction could not be settled',
                        $settled['code'] ?? 'SETTLEMENT_FAILED');
                }
            } elseif ($tx->status !== 'SUCCESSFUL') {
                $this->ci->db->trans_rollback();
                return $this->err('Transaction cannot be settled from '.$tx->status, 'BAD_STATE');
            }

            $this->ci->Marketplace_order_model->record_event(
                $order->id, $actor_id, 'RELEASED', $from, 'COMPLETED', $source
            );
            if ($this->ci->db->trans_status() === false || !$this->ci->db->trans_commit()) {
                $this->ci->db->trans_rollback();
                return $this->err('Escrow release could not be committed', 'DB_ERROR');
            }
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'Marketplace release failed: '.$e->getMessage());
            return $this->err('Escrow release could not be completed', 'DB_ERROR');
        }

        $this->audit($actor_id, 'marketplace.order.release', 'marketplace_order', $order->public_id,
            array('status' => $from), array('status' => 'COMPLETED', 'source' => $source));
        return array('ok' => true, 'order' => $this->ci->Marketplace_order_model->find_id($order->id));
    }

    /**
     * Return part of a marketplace order's money to the buyer.
     *
     * Escrow shipped all-or-nothing (module 11), which is the right first
     * shape and the wrong one for a real dispute: two dead keys in a
     * five-licence bundle, a damaged-but-usable delivery, an agreed discount
     * after a late shipment. Staff had to choose between refunding everything
     * — giving away the three licences that worked — and settling it with a
     * wallet adjustment, which pays the customer while leaving the order
     * claiming it was paid in full, so every revenue figure overstates by the
     * amount actually returned.
     *
     * The rules, in the order they matter:
     *
     *  1. **Never refund more than is left.** The ceiling is
     *     `gross_amount - refunded_amount`, and a request over it is refused
     *     rather than clamped: the difference between "refund ₦5,000" and
     *     "refund what is left, ₦2,000" is a decision for a human.
     *  2. **Released escrow stays out of reach.** Once the money is the
     *     platform's, returning it is a wallet adjustment with its own audit
     *     trail — not a rewrite of a settled order.
     *  3. **Stock only moves for units actually returned.** A goodwill
     *     discount returns nothing to the shelf; `$restock` is explicit and
     *     defaults to none.
     *  4. **A partial refund does not revoke the goods.** The buyer keeps
     *     what they part-paid for; a partial refund is compensation, not a
     *     reversal. Only refunding the last of the money revokes downloads
     *     and returns the remaining stock, exactly as before.
     *  5. **The service transaction is updated too**, because that is the row
     *     every revenue and margin figure reads.
     *
     * @param object $order   Marketplace order row.
     * @param string $amount  Decimal amount to return, in the base currency.
     * @param int    $restock Units to put back on the shelf (0 = none).
     */
    public function refund_partial($order, $amount, $actor_id, $reason, $restock = 0) {
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        // Released first: a settled order is COMPLETED, and "this order cannot
        // be refunded" would send staff looking for a bug instead of telling
        // them the money is already the platform's and the tool they want is a
        // wallet adjustment.
        if ($order->released_at) {
            return $this->err('Released escrow cannot be refunded — use a wallet adjustment',
                'ALREADY_RELEASED');
        }
        if (!in_array($order->status, array('PAID', 'DELIVERED', 'DISPUTED', 'PARTIALLY_REFUNDED'), true)) {
            return $this->err('This order cannot be refunded', 'BAD_STATE');
        }
        // A physical parcel that is not yet delivered cannot be part-refunded.
        // A partial refund is compensation for goods the buyer keeps; in
        // transit there are no goods to keep — and allowing it would leave
        // the order uncloseable: only fully paid orders can be recorded
        // delivered, so a part-refunded shipment could never be marked so,
        // and the escrow remainder would ride the abandonment sweep back to
        // the buyer who is about to receive the parcel. Refund in full to
        // cancel the shipment, or part-refund after delivery as usual. The
        // same applies to a RETURNED parcel, which is with fulfilment staff,
        // not the buyer.
        if ($this->requires_shipment($order)) {
            $shipment = $this->ci->Shop_order_shipment_model->for_order($order->id);
            if (!$shipment || strtoupper((string)$shipment->status) !== 'DELIVERED') {
                return $this->err(
                    'This physical order is still with the carrier — refund it in full to cancel the shipment, or wait for delivery',
                    'SHIPMENT_IN_TRANSIT');
            }
        }

        $amount = $this->money($amount);
        if (bccomp($amount, '0', 8) <= 0) {
            return $this->err('Enter an amount greater than zero', 'BAD_AMOUNT');
        }
        $already   = $this->money(isset($order->refunded_amount) ? $order->refunded_amount : '0');
        $remaining = bcsub($this->money($order->gross_amount), $already, 8);
        if (bccomp($remaining, '0', 8) <= 0) {
            return $this->err('This order has already been refunded in full', 'ALREADY_REFUNDED');
        }
        if (bccomp($amount, $remaining, 8) > 0) {
            return $this->err('That is more than the '.$remaining.' still refundable on this order',
                'OVER_REFUND');
        }

        $restock = max(0, (int)$restock);
        $units_left = max(0, (int)$order->quantity - (int)(isset($order->refunded_quantity) ? $order->refunded_quantity : 0));
        if ($restock > $units_left) {
            return $this->err('That is more units than remain on this order', 'OVER_RESTOCK');
        }

        // Refunding the last of the money IS a full refund: same closure, same
        // revocation, same restock of whatever is left. Routing it through
        // refund() keeps one implementation of "this order is over".
        $final = bccomp($amount, $remaining, 8) === 0;
        if ($final) {
            return $this->refund($order, $actor_id, $reason, $restock === 0 ? null : $restock);
        }

        $reason = trim((string)$reason);
        if ($reason === '') $reason = 'Partial refund issued by administrator';

        $tx = $this->ci->Service_transaction_model->find_by_id($order->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        $wallet = $this->ci->Wallet_model->for_user($order->buyer_id);
        if (!$wallet) return $this->err('The buyer has no wallet to refund into', 'NO_WALLET');

        $target = bcadd($already, $amount, 8);
        // The idempotency key carries the cumulative total, so a retried
        // request pays once while a genuine second partial refund of the same
        // size still goes through.
        //
        // The refund reference is the marketplace order, but the charge was
        // booked against its service transaction (TransactionEngine) — so the
        // pinned rate a foreign wallet must replay is looked up from THAT row
        // and passed explicitly. A refund at today's rate instead of the
        // charge-day rate would quietly create or destroy money.
        $charge_row = $this->ci->db
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', 'ServiceTransaction')
            ->where('reference_id', (string)$tx->id)
            ->where('direction', 'DEBIT')
            ->order_by('id', 'DESC')->limit(1)
            ->get('wallet_transactions')->row();
        $fx_rate = ($charge_row && $charge_row->fx_rate !== null) ? (string)$charge_row->fx_rate : null;
        $res = $this->ci->ledgerservice->refund(
            $wallet->id, $amount, 'MARKETPLACE_ORDER', $order->public_id,
            'mp:partial:'.$order->public_id.':'.$target, $fx_rate
        );
        if (empty($res['ok'])) {
            return $this->err($res['error'] ?? 'The refund could not be paid', 'REFUND_FAILED');
        }
        if (!empty($res['duplicate'])) {
            return $this->err('That refund has already been paid', 'DUPLICATE');
        }

        $from = $order->status;
        $this->ci->Marketplace_order_model->transition($order->id, $from, 'PARTIALLY_REFUNDED', array(
            'refunded_amount'   => $target,
            'refunded_quantity' => (int)(isset($order->refunded_quantity) ? $order->refunded_quantity : 0) + $restock,
        ));

        // Analytics read service_transactions, not marketplace_orders: a
        // partial refund invisible here would leave net revenue overstated.
        $this->ci->db->where('id', $tx->id)->update('service_transactions', array(
            'refunded_amount' => bcadd($this->money(isset($tx->refunded_amount) ? $tx->refunded_amount : '0'), $amount, 8),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ));

        if ($restock > 0) {
            $this->ci->Marketplace_listing_model->restore_stock($order->listing_id, $restock);
        }

        $this->ci->Marketplace_order_model->record_event(
            $order->id, $actor_id, 'PARTIAL_REFUND', $from, 'PARTIALLY_REFUNDED',
            mb_substr($amount.' refunded: '.$reason, 0, 500)
        );
        $this->audit($actor_id, 'marketplace.order.partial_refund', 'marketplace_order', $order->public_id,
            array('status' => $from, 'refunded' => $already),
            array('status' => 'PARTIALLY_REFUNDED', 'refunded' => $target,
                  'amount' => $amount, 'restocked' => $restock, 'reason' => $reason));

        return array('ok' => true, 'refunded' => $amount, 'refunded_total' => $target,
                     'remaining' => bcsub($remaining, $amount, 8),
                     'order' => $this->ci->Marketplace_order_model->find_id($order->id));
    }

    /** How much of this order could still be returned to the buyer. */
    public function refundable($order) {
        if (!$order) return '0.00000000';
        $already = $this->money(isset($order->refunded_amount) ? $order->refunded_amount : '0');
        $left = bcsub($this->money($order->gross_amount), $already, 8);
        return bccomp($left, '0', 8) > 0 ? $left : '0.00000000';
    }

    /** Admin dispute resolution in the buyer's favour. */
    public function refund($order, $actor_id, $reason, $restock = null) {
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        if (!in_array($order->status, array('PAID', 'DELIVERED', 'DISPUTED', 'PARTIALLY_REFUNDED'), true)) {
            return $this->err('This order cannot be refunded', 'BAD_STATE');
        }
        if ($order->released_at) {
            return $this->err('Released escrow cannot be refunded', 'ALREADY_RELEASED');
        }
        $reason = trim((string)$reason);
        if ($reason === '') $reason = 'Marketplace order refunded by administrator';
        if (!$this->ci->db->trans_begin()) return $this->err('Refund transaction could not start', 'DB_ERROR');

        $from = $order->status;
        $now = gmdate('Y-m-d H:i:s');
        try {
            // Claim the same escrow row release uses before refunding. A
            // release and refund can therefore never both commit for one order.
            // Whatever has already gone back plus whatever is left: a full
            // refund after a partial one must total the gross, not the gross
            // twice.
            $already_returned = $this->money(isset($order->refunded_amount) ? $order->refunded_amount : '0');
            $units_returned   = (int)(isset($order->refunded_quantity) ? $order->refunded_quantity : 0);
            $units_left       = max(0, (int)$order->quantity - $units_returned);
            $restock_units    = $restock === null ? $units_left : min($units_left, max(0, (int)$restock));

            if (!$this->ci->Marketplace_order_model->transition($order->id, $from, 'REFUNDED', array(
                'release_due_at' => null,
                'resolved_at' => $now,
                'resolved_by' => $actor_id,
                'refunded_amount'   => $this->money($order->gross_amount),
                'refunded_quantity' => $units_returned + $restock_units,
            ))) {
                $this->ci->db->trans_rollback();
                return $this->err('Order state changed before the refund', 'CONFLICT');
            }

            $tx = $this->ci->Service_transaction_model->find_by_id($order->service_transaction_id);
            if (!$tx) {
                $this->ci->db->trans_rollback();
                return $this->err('Transaction not found', 'NOT_FOUND');
            }
            $res = $this->ci->transactionengine->transition($tx->id, 'REFUNDED', 'ADMIN', $reason);
            if (empty($res['ok'])) {
                $this->ci->db->trans_rollback();
                return $this->err($res['error'] ?? 'Refund failed', $res['code'] ?? 'REFUND_FAILED');
            }
            if ($restock_units > 0
                && !$this->ci->Marketplace_listing_model->restore_stock($order->listing_id, $restock_units)) {
                $this->ci->db->trans_rollback();
                return $this->err('Listing stock changed before it could be restored', 'CONFLICT');
            }
            $this->ci->Marketplace_order_model->record_event(
                $order->id, $actor_id, 'REFUNDED', $from, 'REFUNDED', mb_substr($reason, 0, 500)
            );
            if ($this->ci->db->trans_status() === false || !$this->ci->db->trans_commit()) {
                $this->ci->db->trans_rollback();
                return $this->err('Escrow refund could not be committed', 'DB_ERROR');
            }
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'Marketplace refund failed: '.$e->getMessage());
            return $this->err('Escrow refund could not be completed', 'DB_ERROR');
        }

        // A physical refund also closes the carrier record. This is repeated
        // defensively by ShopShippingService's shipment screen, but keeping
        // the invariant here prevents the older Marketplace → Resolve →
        // Refund entry point from leaving a paid-looking shipment behind.
        if ($this->requires_shipment($order)) {
            $shipment = $this->ci->Shop_order_shipment_model->for_order($order->id);
            if ($shipment && !$this->ci->Shop_order_shipment_model->cancel_after_refund($shipment->id)
                && $shipment->status !== 'CANCELLED') {
                log_message('error', 'Refunded marketplace order '.$order->public_id.' but its shipment could not be cancelled');
            }
        }

        // The money is back; the goods must go with it. A digital order that
        // kept its download after a refund gave the buyer the file for free —
        // and left it in "My Downloads" for ever, where nobody would look.
        // After the commit and never fatal: the refund is already final.
        try {
            $this->ci->load->library('ShopDeliveryService');
            $this->ci->shopdeliveryservice->revoke_for_order($order, $actor_id,
                'Order refunded: '.mb_substr($reason, 0, 180));
        } catch (Throwable $e) {
            log_message('error', 'marketplace refund could not revoke downloads: '.$e->getMessage());
        }

        $this->audit($actor_id, 'marketplace.order.refund', 'marketplace_order', $order->public_id,
            array('status' => $from), array('status' => 'REFUNDED', 'amount' => $order->gross_amount));
        return array('ok' => true, 'refunded' => $res['refunded'] ?? $order->gross_amount,
            'order' => $this->ci->Marketplace_order_model->find_id($order->id));
    }

    public function moderate_listing($public_id, $status, $actor_id, $note = null) {
        $status = strtoupper((string)$status);
        if (!in_array($status, array('ACTIVE', 'REJECTED', 'PAUSED', 'ARCHIVED'), true)) {
            return $this->err('Unsupported listing status', 'BAD_STATUS');
        }
        $listing = $this->ci->Marketplace_listing_model->find_public($public_id, false);
        if (!$listing) return $this->err('Listing not found', 'NOT_FOUND');
        $fields = array(
            'status' => $status,
            'moderation_note' => $note ? mb_substr(trim($note), 0, 500) : null,
            'approved_at' => $status === 'ACTIVE' ? gmdate('Y-m-d H:i:s') : null,
            'approved_by' => $status === 'ACTIVE' ? $actor_id : null,
        );
        $this->ci->Marketplace_listing_model->update_fields($listing->id, $fields);
        $this->audit($actor_id, 'marketplace.listing.moderate', 'marketplace_listing', $public_id,
            array('status' => $listing->status), array('status' => $status));
        return array('ok' => true, 'listing' => $this->ci->Marketplace_listing_model->find_id($listing->id));
    }

    /** Toggle a listing on/off the storefront's featured shelf. */
    public function set_featured($public_id, $featured, $actor_id) {
        $listing = $this->ci->Marketplace_listing_model->find_public($public_id, false);
        if (!$listing) return $this->err('Listing not found', 'NOT_FOUND');
        $value = $featured ? 1 : 0;
        $this->ci->Marketplace_listing_model->update_fields($listing->id, array('is_featured' => $value));
        $this->audit($actor_id, 'marketplace.listing.feature_toggled', 'marketplace_listing', $public_id,
            array('is_featured' => (int)$listing->is_featured), array('is_featured' => $value));
        return array('ok' => true, 'listing' => $this->ci->Marketplace_listing_model->find_id($listing->id));
    }

    /**
     * Apply one action to many listings at once — the admin table's bulk
     * checkbox row. Each listing is processed independently through the
     * same single-row methods above (so every existing rule, permission and
     * audit entry still applies per listing) and the run never partially
     * fails silently: every skip is reported back with a reason.
     *
     * @param string[] $public_ids
     * @param string $action one of publish|unpublish|archive|feature|unfeature
     */
    public function bulk_listing_action(array $public_ids, $action, $actor_id) {
        $action = strtolower(trim((string)$action));
        $map = array(
            'publish'   => array('ACTIVE', null),
            'unpublish' => array('PAUSED', null),
            'archive'   => array('ARCHIVED', null),
        );
        $ok = array(); $failed = array();
        foreach (array_unique(array_filter($public_ids)) as $pid) {
            if (isset($map[$action])) {
                $res = $this->moderate_listing($pid, $map[$action][0], $actor_id);
            } elseif ($action === 'feature') {
                $res = $this->set_featured($pid, true, $actor_id);
            } elseif ($action === 'unfeature') {
                $res = $this->set_featured($pid, false, $actor_id);
            } else {
                return $this->err('Unsupported bulk action', 'BAD_ACTION');
            }
            if (!empty($res['ok'])) $ok[] = $pid;
            else $failed[$pid] = $res['error'] ?? 'unknown error';
        }
        return array('ok' => true, 'applied' => count($ok), 'failed' => $failed);
    }

    /** Whether this order is bound to a carrier-managed physical shipment. */
    private function requires_shipment($order) {
        $listing = $this->ci->Marketplace_listing_model->find_id($order->listing_id);
        if (!$listing || strtoupper((string)$listing->product_type) !== 'PHYSICAL') return false;
        $physical = $this->ci->Physical_product_model->for_listing($listing->id);
        // A malformed physical order must not fall back to the digital
        // fulfilment endpoint. Missing package metadata is therefore treated
        // as requiring the shipment flow; normal purchases are rejected much
        // earlier by shipping_context().
        return !$physical || (int)$physical->requires_shipping === 1;
    }

    /**
     * Resolve the physical fulfilment context before charging.
     *
     * Prices and foreign-wallet conversion belong to TransactionEngine/
     * LedgerService; this method only binds the carrier quote and the
     * customer-owned address to the order. The method is re-read by numeric
     * id and must still be active, so a stale checkout cannot make the panel
     * honour a disabled or changed shipping option.
     */
    private function shipping_context($buyer_id, $listing, array $input) {
        $empty = array(
            'ok' => true, 'required' => false, 'address_id' => null,
            'method_id' => null, 'cost' => '0.00000000',
        );
        if (strtoupper((string)$listing->product_type) !== 'PHYSICAL') return $empty;

        $physical = $this->ci->Physical_product_model->for_listing($listing->id);
        if (!$physical || trim((string)($physical->sku ?? '')) === '') {
            return $this->err('This physical listing is not ready for fulfilment', 'PHYSICAL_DETAILS_REQUIRED');
        }
        if ((int)$physical->requires_shipping !== 1) return $empty;

        $raw_address_id = $input['shipping_address_id'] ?? 0;
        $raw_method_id = $input['shipping_method_id'] ?? 0;
        if (!is_scalar($raw_address_id) || !is_scalar($raw_method_id)) {
            return $this->err('A shipping address and method are required for this item', 'SHIPPING_REQUIRED');
        }
        $address_id = (int)$raw_address_id;
        $method_id = (int)$raw_method_id;
        if ($address_id <= 0 || $method_id <= 0) {
            return $this->err('A shipping address and method are required for this item', 'SHIPPING_REQUIRED');
        }

        $address = $this->ci->Shipping_address_model->find_for_user($address_id, $buyer_id);
        if (!$address) return $this->err('Choose one of your saved shipping addresses', 'BAD_ADDRESS');
        $method = $this->ci->Shipping_method_model->find_active($method_id);
        if (!$method) return $this->err('That shipping method is no longer available', 'BAD_SHIPPING_METHOD');
        if (strtoupper((string)$method->currency) !== strtoupper((string)marvy_base_currency())) {
            return $this->err('That shipping method is not available in the panel currency', 'SHIPPING_CURRENCY_MISMATCH');
        }

        $cost = $this->money($method->price);
        if (bccomp($cost, '0', 8) < 0) {
            return $this->err('That shipping method has an invalid price', 'BAD_SHIPPING_METHOD');
        }
        // The checkout orchestrator can allocate one active-method quote to
        // the first physical line when a cart becomes several marketplace
        // orders. It never submits a price; this service still resolves and
        // validates the method above, then honours only its internal PHP
        // allocation object. A scalar `shipping_charge` from a browser is
        // ignored, so direct callers cannot underpay by posting false.
        $allocation = $input['shipping_allocation'] ?? null;
        if ($allocation instanceof ShopShippingAllocation && !$allocation->is_chargeable()) {
            $cost = '0.00000000';
        }
        return array(
            'ok' => true, 'required' => true,
            'address_id' => (int)$address->id,
            'method_id' => (int)$method->id,
            'cost' => $cost,
        );
    }

    /**
     * The shelf price right now: a valid promotion wins over the list price.
     * Zero/blank or "promo >= list" rows fall back to the list price, which is
     * also what save_listing validates against, so this total's branch can
     * never price above list.
     */
    private function effective_price($listing) {
        $list = $this->money($listing->price);
        $promo = isset($listing->promo_price) && $listing->promo_price !== null
            ? $this->money($listing->promo_price) : null;
        if ($promo !== null && bccomp($promo, '0', 8) > 0 && bccomp($promo, $list, 8) < 0) {
            return $promo;
        }
        return $list;
    }

    private function user_id($user) {
        return is_object($user) ? (int)$user->id : (int)$user;
    }

    private function audit($actor_id, $action, $resource, $resource_id, $before = null, $after = null) {
        $input = isset($this->ci->input) ? $this->ci->input : null;
        $this->ci->Audit_log_model->record(
            $actor_id ?: null, $action, $resource, (string)$resource_id, $before, $after,
            $input ? $input->ip_address() : null,
            $input ? $input->user_agent() : null,
            function_exists('marvy_request_id') ? marvy_request_id() : null
        );
    }

    private function money($value) {
        return number_format((float)$value, 8, '.', '');
    }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
