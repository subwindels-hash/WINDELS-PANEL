<?php
defined('BASEPATH') OR exit('No direct script access allowed');

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
            $fields['public_id'] = windels_public_id();
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
        $buyer_id = $this->user_id($user);
        $listing = $this->ci->Marketplace_listing_model->find_public((string)($input['listing'] ?? ''), true);
        if (!$listing) return $this->err('That listing is not available', 'NO_LISTING');
        $quantity = (int)($input['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            return $this->err('Choose a valid quantity', 'BAD_QUANTITY');
        }
        if ($listing->stock !== null && (int)$listing->stock < $quantity) {
            return $this->err('There is not enough stock', 'OUT_OF_STOCK');
        }

        // The customer pays the server's price — never a submitted one — and a
        // live promotion undercuts the list price. Selling is platform-side,
        // so the gross IS the revenue: no supplier cost, fee or payout split.
        $unit_price = $this->effective_price($listing);
        $gross = $this->money(bcmul($unit_price, (string)$quantity, 8));
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
            ),
            'detail' => function ($transaction_id) use ($listing, $buyer_id, $quantity, $gross,
                                                         $order_model, &$order_id, $unit_price) {
                $order_id = $order_model->create(array(
                    'public_id' => windels_public_id(),
                    'service_transaction_id' => $transaction_id,
                    'listing_id' => $listing->id,
                    'buyer_id' => $buyer_id,
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'gross_amount' => $gross,
                    'status' => 'PENDING',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ));
            },
            'dispatch' => function ($transaction) use ($listing, $quantity, $order_model, $listing_model) {
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

    /** Admin dispute resolution in the buyer's favour. */
    public function refund($order, $actor_id, $reason) {
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        if (!in_array($order->status, array('PAID', 'DELIVERED', 'DISPUTED'), true)) {
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
            if (!$this->ci->Marketplace_order_model->transition($order->id, $from, 'REFUNDED', array(
                'release_due_at' => null,
                'resolved_at' => $now,
                'resolved_by' => $actor_id,
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
            if (!$this->ci->Marketplace_listing_model->restore_stock($order->listing_id, $order->quantity)) {
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
            function_exists('windels_request_id') ? windels_request_id() : null
        );
    }

    private function money($value) {
        return number_format((float)$value, 8, '.', '');
    }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
