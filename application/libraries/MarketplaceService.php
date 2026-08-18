<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MarketplaceService — moderated peer-to-peer digital goods with escrow.
 *
 * The buyer is charged by TransactionEngine and the universal transaction
 * remains PROCESSING until the buyer accepts delivery, an administrator
 * resolves a dispute for the seller, or the bounded auto-release worker runs.
 * Refunds also go through TransactionEngine. Seller payouts are the only other
 * money movement and always go through LedgerService with an idempotency key.
 *
 * Fulfilment is encrypted before it reaches the model. The only plaintext read
 * is reveal(), which verifies ownership and emits an audit record.
 */
class MarketplaceService {
    const DEFAULT_FEE_PERCENT = '10.00000000';
    const DEFAULT_AUTO_RELEASE_HOURS = 72;
    const MAX_QUANTITY = 100;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Marketplace_seller_model', 'Marketplace_listing_model',
            'Marketplace_order_model', 'Service_transaction_model',
            'Wallet_model', 'Audit_log_model', 'Setting_model',
        ));
        $this->ci->load->library(array(
            'TransactionEngine', 'LedgerService', 'EncryptionService'
        ));
    }

    /** Apply once to sell. A verified identity check is required by default. */
    public function apply_seller($user, array $input) {
        $user_id = $this->user_id($user);
        if (!$user_id) return $this->err('Sign in before applying', 'NO_USER');
        if ($this->ci->Marketplace_seller_model->find_for_user($user_id)) {
            return $this->err('You already have a seller application', 'EXISTS');
        }

        $name = trim((string)($input['display_name'] ?? ''));
        $bio = trim((string)($input['bio'] ?? ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            return $this->err('Seller name must be between 3 and 80 characters', 'BAD_NAME');
        }
        if (mb_strlen($bio) > 500) return $this->err('Bio is too long', 'BAD_BIO');

        $identity_id = !empty($input['identity_check_id']) ? (int)$input['identity_check_id'] : null;
        if ($this->setting_bool('marketplace_require_verified_identity', true)) {
            if (!$identity_id || !$this->verified_identity_belongs_to($identity_id, $user_id)) {
                return $this->err('A successful identity check is required to become a seller', 'IDENTITY_REQUIRED');
            }
        } elseif ($identity_id && !$this->verified_identity_belongs_to($identity_id, $user_id)) {
            return $this->err('That identity check is not available', 'BAD_IDENTITY');
        }

        $id = $this->ci->Marketplace_seller_model->create(array(
            'public_id' => windels_public_id(),
            'user_id' => $user_id,
            'identity_check_id' => $identity_id,
            'display_name' => $name,
            'bio' => $bio !== '' ? $bio : null,
            'status' => 'PENDING',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $seller = $this->ci->Marketplace_seller_model->find_id($id);
        $this->audit($user_id, 'marketplace.seller.apply', 'marketplace_seller', $seller->public_id, null,
            array('status' => 'PENDING'));
        return array('ok' => true, 'seller' => $seller);
    }

    /** Create a listing for review, or update one owned by the seller. */
    public function save_listing($user, array $input, $public_id = null) {
        $user_id = $this->user_id($user);
        $seller = $this->ci->Marketplace_seller_model->find_for_user($user_id);
        if (!$seller || $seller->status !== 'APPROVED') {
            return $this->err('Your seller account is not approved', 'SELLER_NOT_APPROVED');
        }

        $listing = null;
        if ($public_id) {
            $listing = $this->ci->Marketplace_listing_model->find_public($public_id, false);
            if (!$listing || (int)$listing->seller_id !== (int)$seller->id) {
                return $this->err('Listing not found', 'NOT_FOUND');
            }
            if ($listing->status === 'ARCHIVED') {
                return $this->err('Archived listings cannot be edited', 'ARCHIVED');
            }
        }

        $title = trim((string)($input['title'] ?? ''));
        $category = strtoupper(trim((string)($input['category'] ?? 'DIGITAL_GOODS')));
        $description = trim((string)($input['description'] ?? ''));
        $price = $this->money($input['price'] ?? 0);
        $delivery_days = (int)($input['delivery_days'] ?? 1);
        $stock = ($input['stock'] ?? '') === '' ? null : (int)$input['stock'];

        if (mb_strlen($title) < 5 || mb_strlen($title) > 120) {
            return $this->err('Title must be between 5 and 120 characters', 'BAD_TITLE');
        }
        if (mb_strlen($description) < 20 || mb_strlen($description) > 10000) {
            return $this->err('Description must be between 20 and 10,000 characters', 'BAD_DESCRIPTION');
        }
        if (!preg_match('/^[A-Z0-9_-]{2,64}$/', $category)) {
            return $this->err('Choose a valid category', 'BAD_CATEGORY');
        }
        if (bccomp($price, '0', 8) <= 0) return $this->err('Price must be greater than zero', 'BAD_PRICE');
        if ($delivery_days < 1 || $delivery_days > 30) {
            return $this->err('Delivery time must be between 1 and 30 days', 'BAD_DELIVERY');
        }
        if ($stock !== null && $stock < 0) return $this->err('Stock cannot be negative', 'BAD_STOCK');

        $fields = array(
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'price' => $price,
            'stock' => $stock,
            'delivery_days' => $delivery_days,
            // Every material edit returns to moderation.
            'status' => 'PENDING',
            'moderation_note' => null,
            'approved_at' => null,
            'approved_by' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($listing) {
            $before = array('status' => $listing->status, 'price' => $listing->price);
            $this->ci->Marketplace_listing_model->update_fields($listing->id, $fields);
            $id = $listing->id;
            $action = 'marketplace.listing.update';
        } else {
            $fields['public_id'] = windels_public_id();
            $fields['seller_id'] = $seller->id;
            $fields['created_at'] = gmdate('Y-m-d H:i:s');
            $id = $this->ci->Marketplace_listing_model->create($fields);
            $before = null;
            $action = 'marketplace.listing.create';
        }
        $saved = $this->ci->Marketplace_listing_model->find_id($id);
        $this->audit($user_id, $action, 'marketplace_listing', $saved->public_id, $before,
            array('status' => 'PENDING', 'price' => $saved->price));
        return array('ok' => true, 'listing' => $saved);
    }

    /** Pause or archive one of the current seller's listings. */
    public function change_listing_status($user, $public_id, $status) {
        $status = strtoupper((string)$status);
        if (!in_array($status, array('PAUSED', 'ARCHIVED'), true)) {
            return $this->err('Unsupported listing status', 'BAD_STATUS');
        }
        $seller = $this->ci->Marketplace_seller_model->find_for_user($this->user_id($user));
        $listing = $this->ci->Marketplace_listing_model->find_public($public_id, false);
        if (!$seller || !$listing || (int)$listing->seller_id !== (int)$seller->id) {
            return $this->err('Listing not found', 'NOT_FOUND');
        }
        if (!in_array($listing->status, array('ACTIVE', 'PAUSED'), true)) {
            return $this->err('That listing cannot be changed now', 'BAD_STATE');
        }
        $this->ci->Marketplace_listing_model->update_fields($listing->id, array('status' => $status));
        $this->audit($seller->user_id, 'marketplace.listing.status', 'marketplace_listing', $public_id,
            array('status' => $listing->status), array('status' => $status));
        return array('ok' => true, 'listing' => $this->ci->Marketplace_listing_model->find_id($listing->id));
    }

    /** Charge a buyer and open escrow. The transaction stays PROCESSING. */
    public function purchase($user, array $input) {
        $buyer_id = $this->user_id($user);
        $listing = $this->ci->Marketplace_listing_model->find_public((string)($input['listing'] ?? ''), true);
        if (!$listing) return $this->err('That listing is not available', 'NO_LISTING');
        if ((int)$listing->seller_user_id === $buyer_id) {
            return $this->err('You cannot buy your own listing', 'SELF_PURCHASE');
        }
        $quantity = (int)($input['quantity'] ?? 1);
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            return $this->err('Choose a valid quantity', 'BAD_QUANTITY');
        }
        if ($listing->stock !== null && (int)$listing->stock < $quantity) {
            return $this->err('There is not enough stock', 'OUT_OF_STOCK');
        }

        $gross = $this->money(bcmul($this->money($listing->price), (string)$quantity, 8));
        $fee_percent = $this->fee_percent();
        $fee = $this->money(bcdiv(bcmul($gross, $fee_percent, 8), '100', 8));
        $seller_amount = $this->money(bcsub($gross, $fee, 8));
        $order_model = $this->ci->Marketplace_order_model;
        $listing_model = $this->ci->Marketplace_listing_model;
        $order_id = null;

        $result = $this->ci->transactionengine->execute($user, array(
            'service_domain' => 'MARKETPLACE',
            'service_type' => 'PURCHASE',
            'service_id' => $listing->id,
            // Seller payout is the direct cost of this marketplace sale.
            'provider_cost' => $seller_amount,
            'amount' => $gross,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'source' => $input['source'] ?? 'WEB',
            'metadata' => array(
                'listing' => $listing->public_id,
                'title' => $listing->title,
                'quantity' => $quantity,
                'seller' => $listing->seller_name,
            ),
            'detail' => function ($transaction_id) use ($listing, $buyer_id, $quantity, $gross, $fee,
                                                         $seller_amount, $order_model, &$order_id) {
                $order_id = $order_model->create(array(
                    'public_id' => windels_public_id(),
                    'service_transaction_id' => $transaction_id,
                    'listing_id' => $listing->id,
                    'buyer_id' => $buyer_id,
                    'seller_id' => $listing->seller_user_id,
                    'quantity' => $quantity,
                    'unit_price' => $listing->price,
                    'gross_amount' => $gross,
                    'fee_amount' => $fee,
                    'seller_amount' => $seller_amount,
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

    /** Seller encrypts and submits fulfilment. */
    public function deliver($user, $public_id, $delivery) {
        $seller_id = $this->user_id($user);
        $order = $this->ci->Marketplace_order_model->find_public($public_id);
        if (!$order || (int)$order->seller_id !== $seller_id) {
            return $this->err('Order not found', 'NOT_FOUND');
        }
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

        $this->ci->Marketplace_order_model->record_event($order->id, $seller_id, 'DELIVERED', 'PAID', 'DELIVERED');
        $this->audit($seller_id, 'marketplace.order.deliver', 'marketplace_order', $public_id,
            array('status' => 'PAID'), array('status' => 'DELIVERED', 'release_due_at' => $due));
        return array('ok' => true, 'order' => $this->ci->Marketplace_order_model->find_id($order->id));
    }

    /** Audited access to plaintext fulfilment; corrupt ciphertext is never returned. */
    public function reveal($user, $public_id, $admin = false) {
        $actor_id = $this->user_id($user);
        $order = $this->ci->Marketplace_order_model->find_public($public_id);
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');
        if (!$admin && !in_array($actor_id, array((int)$order->buyer_id, (int)$order->seller_id), true)) {
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

    /** Release escrow. Admin may release a disputed order; cron/buyer may not. */
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

        $wallet = $this->ci->Wallet_model->for_user($order->seller_id);
        if (!$wallet) return $this->err('Seller wallet not found', 'NO_WALLET');
        if (!$this->ci->db->trans_begin()) return $this->err('Escrow transaction could not start', 'DB_ERROR');

        $from = $order->status;
        $now = gmdate('Y-m-d H:i:s');
        try {
            // Claim the escrow row before moving money. Both release and refund
            // use this compare-and-set, so exactly one resolution can win.
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

            $payout = $this->ci->ledgerservice->credit(
                $wallet->id, $order->seller_amount, 'MARKETPLACE_PAYOUT',
                'MARKETPLACE_ORDER', $order->id,
                'marketplace:'.$order->id.':payout',
                array('order' => $order->public_id, 'gross' => $order->gross_amount,
                      'fee' => $order->fee_amount, 'source' => $source)
            );
            if (empty($payout['ok'])) {
                $this->ci->db->trans_rollback();
                return $this->err('Seller payout could not be completed', 'PAYOUT_FAILED');
            }
            $payout_id = $this->wallet_transaction_id($payout);
            if (!$payout_id) {
                $this->ci->db->trans_rollback();
                return $this->err('Seller payout could not be traced', 'PAYOUT_FAILED');
            }
            $this->ci->Marketplace_order_model->update_fields($order->id, array(
                'payout_wallet_transaction_id' => $payout_id,
            ));

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
        if ($order->released_at || $order->payout_wallet_transaction_id) {
            return $this->err('Released escrow cannot be refunded', 'ALREADY_RELEASED');
        }
        $reason = trim((string)$reason);
        if ($reason === '') $reason = 'Marketplace order refunded by administrator';
        if (!$this->ci->db->trans_begin()) return $this->err('Refund transaction could not start', 'DB_ERROR');

        $from = $order->status;
        $now = gmdate('Y-m-d H:i:s');
        try {
            // Claim the same escrow row release uses before refunding. A payout
            // and refund can therefore never both commit for one order.
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

    public function moderate_seller($public_id, $status, $actor_id, $note = null) {
        $status = strtoupper((string)$status);
        if (!in_array($status, array('APPROVED', 'SUSPENDED', 'REJECTED'), true)) {
            return $this->err('Unsupported seller status', 'BAD_STATUS');
        }
        $seller = $this->ci->Marketplace_seller_model->find_public($public_id);
        if (!$seller) return $this->err('Seller not found', 'NOT_FOUND');
        $fields = array(
            'status' => $status,
            'admin_note' => $note ? mb_substr(trim($note), 0, 500) : null,
            'approved_at' => $status === 'APPROVED' ? gmdate('Y-m-d H:i:s') : null,
            'approved_by' => $status === 'APPROVED' ? $actor_id : null,
        );
        $this->ci->Marketplace_seller_model->update_fields($seller->id, $fields);
        if ($status !== 'APPROVED') {
            // A suspended seller cannot leave stock purchasable.
            $this->ci->db->where('seller_id', $seller->id)->where('status', 'ACTIVE')
                ->update('marketplace_listings', array('status' => 'PAUSED', 'updated_at' => gmdate('Y-m-d H:i:s')));
        }
        $this->audit($actor_id, 'marketplace.seller.moderate', 'marketplace_seller', $public_id,
            array('status' => $seller->status), array('status' => $status));
        return array('ok' => true, 'seller' => $this->ci->Marketplace_seller_model->find_id($seller->id));
    }

    public function moderate_listing($public_id, $status, $actor_id, $note = null) {
        $status = strtoupper((string)$status);
        if (!in_array($status, array('ACTIVE', 'REJECTED', 'PAUSED', 'ARCHIVED'), true)) {
            return $this->err('Unsupported listing status', 'BAD_STATUS');
        }
        $listing = $this->ci->Marketplace_listing_model->find_public($public_id, false);
        if (!$listing) return $this->err('Listing not found', 'NOT_FOUND');
        if ($status === 'ACTIVE' && $listing->seller_status !== 'APPROVED') {
            return $this->err('Approve the seller before activating a listing', 'SELLER_NOT_APPROVED');
        }
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

    private function verified_identity_belongs_to($check_id, $user_id) {
        return (bool)$this->ci->db
            ->select('identity_checks.id')
            ->from('identity_checks')
            ->join('service_transactions', 'service_transactions.id = identity_checks.service_transaction_id', 'inner')
            ->where('identity_checks.id', (int)$check_id)
            ->where('identity_checks.status', 'VERIFIED')
            ->where('service_transactions.user_id', (int)$user_id)
            ->get()->row();
    }

    private function fee_percent() {
        $fee = $this->money($this->ci->Setting_model->get('marketplace_fee_percent', self::DEFAULT_FEE_PERCENT));
        if (bccomp($fee, '0', 8) < 0) return '0.00000000';
        if (bccomp($fee, '50', 8) > 0) return '50.00000000';
        return $fee;
    }

    private function setting_bool($key, $default) {
        $value = $this->ci->Setting_model->get($key, $default);
        if (is_bool($value)) return $value;
        return in_array(strtolower((string)$value), array('1', 'true', 'yes', 'on'), true);
    }

    private function user_id($user) {
        return is_object($user) ? (int)$user->id : (int)$user;
    }

    private function wallet_transaction_id(array $result) {
        if (!empty($result['tx']) && isset($result['tx']->id)) return (int)$result['tx']->id;
        if (!empty($result['public_id'])) {
            $row = $this->ci->db->where('public_id', $result['public_id'])
                ->get('wallet_transactions')->row();
            return $row ? (int)$row->id : null;
        }
        return null;
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
