<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GiftcardService — selling gift card codes (§23; rebuild-spec phase F).
 *
 * Thin in the same way VtuService, NumberService and IdentityService are thin:
 * money, refunds, status history and idempotency belong to TransactionEngine.
 * What is specific to this domain is the **two-step delivery**, and the fact
 * that the thing being delivered is a bearer instrument.
 *
 * The lifecycle maps onto the engine as:
 *
 *   purchase() → execute(), whose dispatch places the vendor order and returns
 *                PROCESSING. The customer is charged and is owed a code.
 *   collect()  → the codes arrive → transition(SUCCESSFUL). The money is
 *                earned at the moment the customer can actually spend
 *                something, not when the vendor said "accepted".
 *   abandon()  → the vendor took the order and never produced a card →
 *                transition(FAILED), which refunds in full inside the engine.
 *
 * Three rules carry the domain, and each one is a deliberate cost:
 *
 *  1. **Accepted is not delivered.** A purchase is never SUCCESSFUL on the
 *     order call, even when the vendor answers instantly, because a
 *     transaction that is already settled cannot be refunded by the engine's
 *     normal path — it would have to claw money out of a closed record. So the
 *     dispatch returns PROCESSING and collect() settles it, even in the common
 *     case where collect() runs microseconds later.
 *
 *  2. **A code that never arrives is refunded, and it costs us.** Reloadly has
 *     already billed our wallet by then. Keeping the customer's money for a
 *     card we cannot hand over is not an option, so the panel eats it — and
 *     because that is a real loss, abandon() records the attempt count on the
 *     row where an operator can see the pattern rather than burying it in a
 *     log line.
 *
 *  3. **Reading a code is an event, not a read.** reveal() decrypts, counts
 *     the access on the order, stamps the card and writes an audit entry.
 *     There is no other path to a plaintext card number anywhere in the
 *     codebase — the detail page shows the masked tail until the customer
 *     presses the button. That is what makes "who has seen this code?"
 *     answerable after a dispute, which for a bearer instrument is the whole
 *     question.
 */
class GiftcardService {

    /** How long a placed order may go undelivered before it is written off. */
    const DEFAULT_GIVE_UP_MINUTES = 60;

    /** How many code-retrieval attempts before an order is written off. */
    const MAX_CODE_ATTEMPTS = 6;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Giftcard_brand_model', 'Giftcard_product_model',
            'Giftcard_order_model', 'Giftcard_code_model',
            'Service_transaction_model', 'Provider_transaction_model', 'Provider_model',
            'Audit_log_model', 'Setting_model',
        ));
        $this->ci->load->library(array('TransactionEngine', 'Provider_manager', 'EncryptionService'));
    }

    /**
     * Buy one or more cards of one product.
     *
     * @param array $input product (code or id), quantity?, recipient_email?,
     *                     idempotency_key?, source?
     * @return array{ok:bool,transaction?:object,order?:object,cards?:array,error?:string,code?:string}
     */
    public function purchase($user, array $input) {
        $product = $this->resolve_product($input);
        if (!$product) return $this->err('Unknown gift card', 'NO_PRODUCT');
        if ($product->price === null) return $this->err('That card has no price', 'NO_PRICE');
        // A RANGE product has no denomination until somebody names one, and
        // there is no form for that yet. Refuse explicitly rather than
        // charging the price of a card whose face value is NULL.
        if ($product->denomination_type !== 'FIXED') {
            return $this->err('That card is sold in custom amounts, which are not available yet',
                              'NOT_FIXED');
        }

        $quantity = (int)($input['quantity'] ?? 1);
        if ($quantity < 1) return $this->err('Choose how many cards you want', 'BAD_QUANTITY');
        $max = max(1, (int)$product->max_quantity);
        if ($quantity > $max) {
            return $this->err('You can buy at most '.$max.' of these at a time', 'BAD_QUANTITY');
        }

        $email = trim((string)($input['recipient_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->err('That delivery email address is not valid', 'BAD_EMAIL');
        }

        $provider = $this->provider_for($product);
        if (!$provider) return $this->err('No provider configured for gift cards', 'NO_PROVIDER');

        $manager      = $this->ci->provider_manager;
        $order_model  = $this->ci->Giftcard_order_model;
        $provider_log = $this->ci->Provider_transaction_model;
        // What the recipient sees the card came from on the vendor's receipt.
        // A branding decision, so it is a setting rather than a constant.
        $sender = $this->sender_name();

        // The engine's failure result is deliberately minimal — an error and a
        // code, no transaction — but a rejected gift card order still has a
        // receipt the customer is entitled to see. The detail callback is the
        // one place that learns the id before a failure, so capture it there.
        $tx_id = null;

        $result = $this->ci->transactionengine->execute($user, array(
            'service_domain'  => 'GIFTCARD',
            'service_type'    => 'PURCHASE',
            'service_id'      => $product->id,
            'provider_id'     => $provider->id,
            'amount'          => $this->money(bcmul($this->money($product->price), (string)$quantity, 8)),
            'provider_cost'   => $product->provider_cost !== null
                                    ? $this->money(bcmul($this->money($product->provider_cost),
                                                         (string)$quantity, 8))
                                    : null,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'source'          => $input['source'] ?? 'WEB',
            'coupon_code'     => $input['coupon_code'] ?? null,
            // Metadata is readable by anyone who can see the transaction, so
            // it carries what was bought and never a card number.
            'metadata'        => array('product' => $product->code, 'quantity' => $quantity),

            'detail' => function ($id) use ($product, $quantity, $email, $order_model, &$tx_id) {
                $tx_id = $id;
                $order_model->create(array(
                    'service_transaction_id' => $id,
                    'product_id'         => $product->id,
                    'brand_id'           => $product->brand_id,
                    'quantity'           => $quantity,
                    // Frozen at purchase: the catalogue's face value can be
                    // re-synced tomorrow, and the receipt must still say what
                    // the customer actually bought.
                    'face_value'         => $product->face_value,
                    'recipient_currency' => $product->recipient_currency,
                    'recipient_email'    => $email !== '' ? $email : null,
                    'status'             => 'PENDING',
                    'created_at'         => gmdate('Y-m-d H:i:s'),
                ));
            },

            'dispatch' => function ($tx) use ($manager, $provider, $product, $quantity, $email,
                                              $order_model, $provider_log, $sender) {
                $started = microtime(true);
                $adapter = $manager->adapter($provider, Provider_manager::FAMILY_GIFTCARD);
                $res = $adapter->order(array(
                    'product_id'      => $product->provider_product_id,
                    'quantity'        => $quantity,
                    'unit_price'      => $product->face_value,
                    'country_code'    => $product->country_code,
                    'recipient_email' => $email !== '' ? $email : null,
                    'sender_name'     => $sender,
                    // The vendor's idempotency key, so a timeout on the way
                    // out cannot become two purchases.
                    'reference'       => $tx->public_id,
                ));
                $latency = (int)round((microtime(true) - $started) * 1000);

                $provider_log->record(array(
                    'provider_id'            => $provider->id,
                    'service_transaction_id' => $tx->id,
                    'action'                 => 'PURCHASE',
                    'provider_reference'     => $res['reference'] ?? null,
                    'status'                 => !empty($res['ok']) ? 'SUCCESS' : 'FAILED',
                    'cost'                   => $res['cost'] ?? null,
                    'latency_ms'             => $latency,
                    'error'                  => $res['error'] ?? null,
                ));

                if (empty($res['ok'])) {
                    $order_model->update_for_transaction($tx->id, array(
                        'status'         => 'FAILED',
                        'failure_reason' => isset($res['error'])
                            ? mb_substr((string)$res['error'], 0, 255) : null,
                    ));
                    return $res;
                }

                $order_model->update_for_transaction($tx->id, array(
                    'status'            => 'PLACED',
                    'provider_order_id' => $res['reference'] ?? null,
                    'placed_at'         => gmdate('Y-m-d H:i:s'),
                ));

                // Always PROCESSING. The vendor has our money; the customer
                // does not have a code yet, and settling here would close the
                // transaction against the refund path that undelivered orders
                // need. collect() promotes it.
                $res['status'] = 'PROCESSING';
                return $res;
            },
        ));

        // On the failure paths the engine hands back no transaction, so fall
        // back to the id the detail callback captured. A rejection that never
        // reached the engine (no wallet, no balance) has no id and no order,
        // and correctly stays a plain error.
        if (empty($result['transaction']) && $tx_id) {
            $result['transaction'] = $this->ci->Service_transaction_model->find_by_id($tx_id);
        }
        if (empty($result['ok'])) {
            if (!empty($result['transaction'])) {
                $result['order'] = $order_model->for_transaction($result['transaction']->id);
            }
            return $result;
        }

        // A duplicate resolved by the idempotency key is the original
        // purchase, already delivered — collecting again would call the vendor
        // for codes it has already handed over.
        if (empty($result['duplicate'])) {
            $order = $order_model->for_transaction($result['transaction']->id);
            if ($order && $order->status === 'PLACED') {
                $this->collect($order, 'SYSTEM');
            }
        }

        $result['transaction'] = $this->ci->Service_transaction_model
            ->find_by_id($result['transaction']->id);
        $result['order'] = $order_model->for_transaction($result['transaction']->id);
        $result['cards'] = $result['order']
            ? $this->ci->Giftcard_code_model->for_order($result['order']->id) : array();
        return $result;
    }

    /**
     * Ask the vendor for the codes, and settle if they are ready.
     *
     * Shared by the purchase path, the cron worker and the admin queue, so all
     * three apply exactly the same rules — including the one that matters:
     * codes are only ever written once, and writing them is what marks the
     * purchase delivered.
     *
     * @param object $order  giftcard_orders row
     * @param string $source SYSTEM|CRON|ADMIN, for the status history
     */
    public function collect($order, $source = 'CRON') {
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');

        $tx = $this->ci->Service_transaction_model->find_by_id($order->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if ($order->status === 'DELIVERED') {
            return array('ok' => true, 'ready' => true, 'order' => $order,
                         'cards' => $this->ci->Giftcard_code_model->for_order($order->id));
        }
        if (!in_array($order->status, Giftcard_order_model::$open_states, true)) {
            return $this->err('This order is already '.strtolower($order->status), 'NOT_OPEN');
        }
        if (!$order->provider_order_id) {
            return $this->err('That order has no vendor reference', 'NO_REFERENCE');
        }

        $provider = $tx->provider_id ? $this->ci->Provider_model->find_by_id($tx->provider_id) : null;
        if (!$provider) return $this->err('The vendor for this order is no longer configured', 'NO_PROVIDER');

        // Counted before the call, not after: a call that throws still used an
        // attempt, and a vendor that reliably times out must still reach the
        // give-up threshold rather than retrying forever.
        $this->ci->Giftcard_order_model->record_attempt($order->id);

        $started = microtime(true);
        try {
            $adapter = $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_GIFTCARD);
            $res = $adapter->codes($order->provider_order_id);
        } catch (Exception $e) {
            log_message('error', 'giftcard collect failed for order '.$order->id);
            return $this->err('Could not reach the gift card vendor', 'PROVIDER_ERROR');
        }
        $latency = (int)round((microtime(true) - $started) * 1000);

        $this->ci->Provider_transaction_model->record(array(
            'provider_id'            => $provider->id,
            'service_transaction_id' => $tx->id,
            'action'                 => 'STATUS',
            'provider_reference'     => $order->provider_order_id,
            'status'                 => !empty($res['ok']) ? 'SUCCESS' : 'FAILED',
            'latency_ms'             => $latency,
            'error'                  => $res['error'] ?? null,
        ));

        if (empty($res['ok'])) {
            return array('ok' => false, 'ready' => false,
                         'error' => $res['error'] ?? 'The vendor could not be reached',
                         'code' => 'VENDOR_ERROR',
                         'order' => $this->ci->Giftcard_order_model->find_by_id($order->id));
        }
        if (empty($res['ready']) || empty($res['cards'])) {
            // Accepted, not issued yet. Perfectly normal; the worker returns.
            return array('ok' => true, 'ready' => false,
                         'order' => $this->ci->Giftcard_order_model->find_by_id($order->id),
                         'cards' => array());
        }

        $stored = $this->store_cards($order, $res['cards']);
        if ($stored === 0) {
            return array('ok' => true, 'ready' => false,
                         'order' => $this->ci->Giftcard_order_model->find_by_id($order->id),
                         'cards' => $this->ci->Giftcard_code_model->for_order($order->id));
        }

        $this->ci->Giftcard_order_model->update_fields($order->id, array(
            'status'       => 'DELIVERED',
            'delivered_at' => gmdate('Y-m-d H:i:s'),
        ));

        // The customer can now spend something, so the money is earned.
        if ($tx->status === 'PROCESSING') {
            $this->ci->transactionengine->transition($tx->id, 'SUCCESSFUL', 'PROVIDER');
        }

        return array(
            'ok'    => true,
            'ready' => true,
            'order' => $this->ci->Giftcard_order_model->find_by_id($order->id),
            'cards' => $this->ci->Giftcard_code_model->for_order($order->id),
        );
    }

    /**
     * Give up on an order the vendor never delivered, and refund.
     *
     * The refund is the engine's, not ours — transition(FAILED) returns the
     * charge through LedgerService with the refunded_amount cap, exactly as an
     * expired virtual number does. An order that has codes is refused: those
     * are spendable, and handing back the money as well would be giving the
     * card away.
     *
     * @param string $source CRON|ADMIN
     */
    public function abandon($order, $source = 'CRON', $reason = null) {
        if (!$order) return $this->err('Order not found', 'NOT_FOUND');

        $tx = $this->ci->Service_transaction_model->find_by_id($order->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if ($this->ci->Giftcard_code_model->count_for_order($order->id) > 0) {
            return $this->err('This order has delivered codes and cannot be written off', 'HAS_CODES');
        }
        if (!in_array($order->status, Giftcard_order_model::$open_states, true)) {
            return $this->err('This order is already '.strtolower($order->status), 'NOT_OPEN');
        }

        $reason = $reason ?: 'The vendor accepted the order but never issued a code';
        $this->ci->Giftcard_order_model->update_fields($order->id, array(
            'status'         => 'FAILED',
            'failure_reason' => mb_substr($reason, 0, 255),
        ));

        $refunded = null;
        if (!in_array($tx->status, array('FAILED','CANCELLED','REFUNDED'), true)) {
            $res = $this->ci->transactionengine->transition($tx->id, 'FAILED', $source, $reason);
            if (!empty($res['ok'])) $refunded = $res['refunded'];
        }

        return array(
            'ok'       => true,
            'refunded' => $refunded,
            'order'    => $this->ci->Giftcard_order_model->find_by_id($order->id),
        );
    }

    /**
     * Decrypt one card, recording that someone read it (§23).
     *
     * Every caller goes through here — there is no other way to the plaintext
     * — so the access count on the order, the stamp on the card and the audit
     * entry cannot be bypassed by a new screen forgetting to log.
     *
     * @param object $card   giftcard_codes row
     * @param object $actor  the user reading it
     * @param string $reason ADMIN|CUSTOMER, for the audit trail
     * @return array{ok:bool,card?:array,error?:string,code?:string}
     */
    public function reveal($card, $actor, $reason = 'CUSTOMER') {
        if (!$card) return $this->err('Card not found', 'NOT_FOUND');
        if (empty($card->card_number_encrypted)) {
            return $this->err('That card has no stored code', 'NO_CODE');
        }

        // open(), not decrypt(): decrypt() hands back its input when the tag
        // does not verify, which would render a base64 blob as if it were the
        // customer's card number.
        $number = $this->ci->encryptionservice->open($card->card_number_encrypted);
        if ($number === null) {
            return $this->err('That card could not be decrypted', 'UNREADABLE');
        }
        $pin = null;
        if (!empty($card->pin_encrypted)) {
            $pin = $this->ci->encryptionservice->open($card->pin_encrypted);
        }

        $actor_id = $actor && isset($actor->id) ? (int)$actor->id : null;
        $this->ci->Giftcard_order_model->record_reveal($card->giftcard_order_id, $actor_id);
        $this->ci->Giftcard_code_model->mark_revealed($card->id);

        // The audit entry proves the access happened; it must not itself
        // become a second, unencrypted copy of the card.
        $this->ci->Audit_log_model->record(
            $actor_id, 'giftcard.code.reveal', 'giftcard_code', $card->id,
            null, array('order' => (int)$card->giftcard_order_id,
                        'card' => (int)$card->card_index,
                        'last4' => $card->card_last4, 'by' => $reason),
            null, null, function_exists('marvy_request_id') ? marvy_request_id() : null);

        return array('ok' => true, 'card' => array(
            'card_number'    => $number,
            'pin'            => $pin,
            'redemption_url' => $card->redemption_url,
            'expires_on'     => $card->expires_on,
        ));
    }

    /**
     * Chase undelivered orders, and write off the ones past hope (§23).
     *
     * Collection runs before the write-off on purpose: a card issued in the
     * last few seconds before the deadline still counts, and checking first
     * means a customer keeps a code they actually received.
     *
     * @return array{processed:int,failed:int,message:string}
     */
    public function settle_open_orders($limit = 100) {
        $orders = $this->ci->Giftcard_order_model->awaiting_codes($limit);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->give_up_minutes() * 60));
        $stale  = $this->ci->Giftcard_order_model->stale($cutoff, self::MAX_CODE_ATTEMPTS, $limit);

        if (!$orders && !$stale) {
            return array('processed'=>0, 'failed'=>0, 'message'=>'no gift card orders awaiting codes');
        }

        $processed = 0; $failed = 0; $delivered = 0; $written_off = 0;
        $handled = array();

        foreach ($orders as $order) {
            $handled[(int)$order->id] = true;
            try {
                $res = $this->collect($order, 'CRON');
            } catch (Exception $e) {
                log_message('error', 'giftcard_codes collect: '.$e->getMessage());
                $failed++;
                continue;
            }
            $processed++;
            if (empty($res['ok'])) { $failed++; continue; }
            if (!empty($res['ready'])) { $delivered++; continue; }

            // Still nothing, and out of patience: the customer has paid for a
            // card that is not coming.
            $fresh = $res['order'] ?? $this->ci->Giftcard_order_model->find_by_id($order->id);
            if ($this->is_past_hope($fresh, $cutoff)) {
                $out = $this->abandon($fresh, 'CRON');
                if (!empty($out['ok'])) $written_off++;
            }
        }

        // Orders the collection pass did not reach — the list was truncated by
        // $limit, or they have no vendor reference — still have to be settled,
        // or a customer stays charged for a card nobody will check again.
        foreach ($stale as $order) {
            if (isset($handled[(int)$order->id])) continue;
            try {
                $out = $this->abandon($order, 'CRON');
            } catch (Exception $e) {
                log_message('error', 'giftcard_codes abandon: '.$e->getMessage());
                $failed++;
                continue;
            }
            $processed++;
            if (empty($out['ok'])) { $failed++; continue; }
            $written_off++;
        }

        return array(
            'processed' => $processed,
            'failed'    => $failed,
            'message'   => $delivered.' delivered, '.$written_off.' written off of '.$processed.' checked',
        );
    }

    public function give_up_minutes() {
        $configured = $this->ci->config->item('giftcard_give_up_minutes');
        return $configured !== null && $configured !== '' && (int)$configured > 0
            ? (int)$configured : self::DEFAULT_GIVE_UP_MINUTES;
    }

    /* ------------------------------------------------------------------ */

    /** The name printed on the vendor's receipt as the sender. */
    private function sender_name() {
        $configured = $this->ci->Setting_model->get('giftcard_sender_name');
        $configured = trim((string)$configured);
        return $configured !== '' ? $configured : 'MarvySocials';
    }

    /**
     * Store the vendor's cards, encrypted, exactly once.
     *
     * Returns how many were newly written. Re-collection is idempotent by
     * card_index (UNIQUE on the table), because a worker that ran twice must
     * not double a customer's cards — and because two rows claiming to be
     * "card 1" would make the audit trail meaningless.
     */
    private function store_cards($order, array $cards) {
        $enc = $this->ci->encryptionservice;
        $existing = array();
        foreach ($this->ci->Giftcard_code_model->for_order($order->id) as $row) {
            $existing[(int)$row->card_index] = true;
        }

        $stored = 0;
        $index = 0;
        foreach ($cards as $card) {
            $index++;
            if (isset($existing[$index])) continue;
            $number = trim((string)($card['card_number'] ?? ''));
            $pin    = isset($card['pin']) ? trim((string)$card['pin']) : '';
            if ($number === '' && $pin === '') continue;

            $this->ci->Giftcard_code_model->create(array(
                'giftcard_order_id'     => $order->id,
                'card_index'            => $index,
                // A PIN-only brand still needs a row; the number column is NOT
                // NULL, so the PIN carries it and card_number reads back empty.
                'card_number_encrypted' => $enc->encrypt($number),
                'pin_encrypted'         => $pin !== '' ? $enc->encrypt($pin) : null,
                'card_last4'            => $number !== '' ? substr($number, -4) : null,
                'redemption_url'        => isset($card['redemption_url'])
                    ? mb_substr((string)$card['redemption_url'], 0, 512) : null,
                'expires_on'            => $card['expires_on'] ?? null,
            ));
            $stored++;
        }
        return $stored;
    }

    /** Whether an order has been waiting long enough to write off. */
    private function is_past_hope($order, $cutoff) {
        if (!$order) return false;
        if ((int)$order->code_attempts < self::MAX_CODE_ATTEMPTS) return false;
        return !empty($order->placed_at) && $order->placed_at <= $cutoff;
    }

    private function resolve_product(array $input) {
        $key = $input['product'] ?? null;
        if ($key === null || $key === '') return null;
        return ctype_digit((string)$key)
            ? $this->ci->Giftcard_product_model->find_active((int)$key)
            : $this->ci->Giftcard_product_model->find_active_by_code((string)$key);
    }

    /** Per-product provider, else the first ACTIVE one that can do gift cards. */
    private function provider_for($product) {
        if (!empty($product->provider_id)) {
            $p = $this->ci->Provider_model->find_by_id($product->provider_id);
            if ($p && $p->status === 'ACTIVE') return $p;
        }
        $types = Provider_manager::supported_types(Provider_manager::FAMILY_GIFTCARD);
        foreach ($this->ci->Provider_model->active() as $p) {
            if (in_array(strtoupper($p->api_type), $types, true)) return $p;
        }
        return null;
    }

    private function money($v) { return number_format((float)$v, 8, '.', ''); }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
