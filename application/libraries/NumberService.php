<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * NumberService — virtual numbers and OTP (§10, §11; rebuild-spec phase D).
 *
 * Thin, in the same way VtuService is thin: money, refunds, status history and
 * idempotency belong to TransactionEngine, and this class only owns what is
 * specific to renting a phone number.
 *
 * What is specific is the **reservation lifecycle**, which the audit flagged as
 * the thing the order engine does not model. A purchase here does not finish
 * when the vendor accepts it — it finishes when a code arrives, or when the
 * deadline passes without one. That maps onto the engine as:
 *
 *   reserve()  → execute(), whose dispatch returns status PROCESSING.
 *                The customer is charged and holds a live number.
 *   poll()     → an SMS arrives → transition(SUCCESSFUL). The money is earned
 *                the moment the customer has a usable code.
 *   expire()   → the deadline passed with no code → transition(FAILED), which
 *                refunds in full inside the engine. The customer paid for a
 *                code they never got.
 *   cancel()   → the customer gives up before any code → same refund path.
 *   release()  → after a code, the customer is done → finish() at the vendor,
 *                no money moves.
 *
 * Two rules that are easy to get wrong and expensive when they are:
 *
 *   - **A number that received a code is not refundable here.** Once the code
 *     is delivered the service was rendered, and cancel() must refuse rather
 *     than hand back money for something the customer used. An admin can still
 *     refund it as goodwill through the engine; that is a deliberate decision,
 *     not an automatic one.
 *   - **The vendor owns the deadline.** expires_at is whatever the vendor
 *     returned. Where a vendor gives none, the product's ttl_minutes is used
 *     as a documented fallback, never a silent one.
 */
class NumberService {

    /** Reservation states in which the vendor still holds the number. */
    private static $live_states = array('RESERVED', 'RECEIVED');

    /** Fallback hold time when neither vendor nor product says otherwise. */
    const DEFAULT_TTL_MINUTES = 15;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Number_country_model', 'Number_service_model', 'Number_product_model',
            'Virtual_number_model', 'Otp_message_model',
            'Service_transaction_model', 'Provider_transaction_model', 'Provider_model',
        ));
        $this->ci->load->library(array('TransactionEngine', 'Provider_manager'));
    }

    /**
     * Rent a number.
     *
     * @param array $input country (code or id), service (code or id),
     *                     idempotency_key?, source?
     * @return array{ok:bool,transaction?:object,number?:object,error?:string,code?:string}
     */
    public function reserve($user, array $input) {
        $country = $this->resolve_country($input);
        if (!$country) return $this->err('Unknown country', 'NO_COUNTRY');

        $service = $this->resolve_service($input);
        if (!$service) return $this->err('Unknown service', 'NO_SERVICE');

        $product = $this->ci->Number_product_model->find_for_pair($country->id, $service->id);
        if (!$product) return $this->err('That number is unavailable right now', 'NO_PRODUCT');
        if ($product->price === null) return $this->err('That number has no price', 'NO_PRICE');
        // Vendor stock is advisory — it is a snapshot from the last sync — but
        // a known-zero is worth refusing before charging anyone.
        if ($product->stock !== null && (int)$product->stock <= 0) {
            return $this->err('That number is out of stock', 'NO_STOCK');
        }

        $provider = $this->provider_for($product);
        if (!$provider) return $this->err('No provider configured for numbers', 'NO_PROVIDER');

        $manager      = $this->ci->provider_manager;
        $number_model = $this->ci->Virtual_number_model;
        $otp_model    = $this->ci->Otp_message_model;
        $provider_log = $this->ci->Provider_transaction_model;
        $ttl          = $this->ttl_minutes($product);

        $payload = array(
            'country'   => $country->code,
            'service'   => $service->code,
            'product'   => $product->provider_product ?: strtolower($service->code),
            'operator'  => $product->provider_operator ?: 'any',
            'ttl_minutes' => $ttl,
        );

        $result = $this->ci->transactionengine->execute($user, array(
            'service_domain'  => 'NUMBER',
            'service_type'    => 'RENTAL',
            'service_id'      => $product->id,
            'provider_id'     => $provider->id,
            'amount'          => $this->money($product->price),
            'provider_cost'   => $product->provider_cost !== null
                                    ? $this->money($product->provider_cost) : null,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'source'          => $input['source'] ?? 'WEB',
            'coupon_code'     => $input['coupon_code'] ?? null,
            'metadata'        => array('country' => $country->code, 'service' => $service->code),

            // Written before the vendor is called, so a rejected reservation
            // still shows what was attempted. msisdn is NOT NULL, so a
            // placeholder stands in until the vendor names the number.
            'detail' => function ($tx_id) use ($country, $service, $product, $number_model) {
                $number_model->create(array(
                    'service_transaction_id' => $tx_id,
                    'country_id'  => $country->id,
                    'service_id'  => $service->id,
                    'product_id'  => $product->id,
                    'msisdn'      => '',
                    'status'      => 'RESERVED',
                    'reserved_at' => gmdate('Y-m-d H:i:s'),
                    'created_at'  => gmdate('Y-m-d H:i:s'),
                ));
            },

            'dispatch' => function ($tx) use ($manager, $provider, $payload, $ttl,
                                              $number_model, $otp_model, $provider_log) {
                $payload['reference'] = $tx->public_id;
                $started = microtime(true);
                $adapter = $manager->adapter($provider, Provider_manager::FAMILY_NUMBER);
                $res = $adapter->reserve($payload);
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

                if (empty($res['ok'])) return $res;

                $number_model->update_for_transaction($tx->id, array(
                    'msisdn'            => (string)($res['msisdn'] ?? ''),
                    'operator'          => $res['operator'] ?? null,
                    'provider_order_id' => $res['reference'] ?? null,
                    'status'            => 'RESERVED',
                    'expires_at'        => $this->deadline($res, $ttl),
                ));
                // A vendor that delivers a code in the buy response is rare
                // but legal; dropping it would lose the customer's OTP.
                $this->ingest($number_model->for_transaction($tx->id), $res, $otp_model, $number_model);

                // Always PROCESSING: a reservation is not a delivered service
                // until a code arrives or the deadline decides otherwise.
                $res['status'] = 'PROCESSING';
                return $res;
            },
        ));

        if (empty($result['ok'])) return $result;

        $result['number'] = $this->ci->Virtual_number_model->for_transaction($result['transaction']->id);
        return $result;
    }

    /**
     * Ask the vendor whether a code has arrived, and settle if it has.
     *
     * Shared by the cron worker, the customer's "check now" button and the
     * admin queue, so all three apply exactly the same rules.
     *
     * @param object $number virtual_numbers row
     * @param string $source SYSTEM|CRON|CUSTOMER|ADMIN, for the status history
     */
    public function poll($number, $source = 'CRON') {
        $tx = $this->ci->Service_transaction_model->find_by_id($number->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if (!$number->provider_order_id) {
            return $this->err('That reservation has no vendor reference', 'NO_REFERENCE');
        }

        $call = $this->vendor_call($tx, $number, 'status', 'STATUS');
        if (empty($call['ok'])) return $call;
        $res = $call['response'];

        $stored = $this->ingest($number, $res, $this->ci->Otp_message_model, $this->ci->Virtual_number_model);
        $number = $this->ci->Virtual_number_model->find_by_id($number->id);

        // A code makes the purchase worth what the customer paid.
        if ($stored > 0 || ($number->sms_count > 0 && $tx->status === 'PROCESSING')) {
            $this->set_state($number, 'RECEIVED');
            if ($tx->status === 'PROCESSING') {
                $this->ci->transactionengine->transition($tx->id, 'SUCCESSFUL', 'PROVIDER');
            }
            return array('ok' => true, 'state' => 'RECEIVED', 'new_messages' => $stored,
                         'number' => $this->ci->Virtual_number_model->find_by_id($number->id));
        }

        // No code. If the vendor says it is over, it is over — even before our
        // own clock agrees, because the vendor holds the number, not us.
        $vendor_state = strtoupper((string)($res['state'] ?? ''));
        if (in_array($vendor_state, array('EXPIRED', 'CANCELLED', 'BANNED'), true)) {
            return $this->settle_unfulfilled($tx, $number, $vendor_state, $source,
                'The vendor released this number before a code arrived');
        }
        if ($this->is_past_deadline($number)) {
            return $this->expire($number, $source);
        }

        return array('ok' => true, 'state' => $number->status, 'new_messages' => 0,
                     'number' => $number);
    }

    /**
     * The deadline passed with no code: release at the vendor and refund.
     *
     * The refund is the engine's, not ours — transition(FAILED) returns the
     * charge through LedgerService with the refunded_amount cap, exactly as a
     * VTU failure does. A cancel that the vendor refuses is not fatal: the
     * money still has to go back.
     */
    public function expire($number, $source = 'CRON') {
        $tx = $this->ci->Service_transaction_model->find_by_id($number->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if ($number->sms_count > 0) {
            return $this->err('That number received a code and cannot be expired', 'HAS_CODE');
        }

        $this->vendor_call($tx, $number, 'cancel', 'STATUS');
        return $this->settle_unfulfilled($tx, $number, 'EXPIRED', $source,
            'No code arrived before the reservation expired');
    }

    /**
     * The customer gives up before any code arrives.
     *
     * Refuses once a code has landed: the service was rendered, and a refund
     * from here would let a customer keep the code and the money.
     */
    public function cancel($number, $source = 'CUSTOMER') {
        $tx = $this->ci->Service_transaction_model->find_by_id($number->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if ($number->sms_count > 0) {
            return $this->err('This number already received a code, so it cannot be cancelled', 'HAS_CODE');
        }
        if (!in_array($number->status, self::$live_states, true)) {
            return $this->err('This reservation is already '.strtolower($number->status), 'NOT_LIVE');
        }

        $call = $this->vendor_call($tx, $number, 'cancel', 'STATUS');
        // "order has sms" means a code landed between our read and this call.
        if (empty($call['ok']) && stripos((string)($call['error'] ?? ''), 'code') !== false) {
            return $this->err('This number just received a code, so it cannot be cancelled', 'HAS_CODE');
        }

        return $this->settle_unfulfilled($tx, $number, 'CANCELLED', $source,
            'Cancelled before a code arrived');
    }

    /**
     * The customer is done with a number that worked.
     *
     * No money moves: the purchase is already SUCCESSFUL. This exists so the
     * vendor's hold is released promptly rather than expiring on its own,
     * which is what keeps the vendor account's rating usable.
     */
    public function release($number, $source = 'CUSTOMER') {
        $tx = $this->ci->Service_transaction_model->find_by_id($number->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if (!in_array($number->status, self::$live_states, true)) {
            return $this->err('This reservation is already '.strtolower($number->status), 'NOT_LIVE');
        }

        $this->vendor_call($tx, $number, 'finish', 'STATUS');
        $this->set_state($number, 'COMPLETED', gmdate('Y-m-d H:i:s'));

        // A number released without ever receiving a code was still never
        // delivered, so it refunds like an expiry rather than silently
        // pocketing the charge.
        if ((int)$number->sms_count === 0 && $tx->status === 'PROCESSING') {
            $this->ci->transactionengine->transition(
                $tx->id, 'FAILED', $source, 'Released before a code arrived');
        }

        return array('ok' => true, 'state' => 'COMPLETED',
                     'number' => $this->ci->Virtual_number_model->find_by_id($number->id));
    }

    /**
     * Report a number as unusable (already registered with the service).
     *
     * Bans cost vendor rating, so this is an explicit action rather than
     * something the expiry sweep does on its own.
     */
    public function ban($number, $source = 'CUSTOMER') {
        $tx = $this->ci->Service_transaction_model->find_by_id($number->service_transaction_id);
        if (!$tx) return $this->err('Transaction not found', 'NOT_FOUND');
        if (!in_array($number->status, self::$live_states, true)) {
            return $this->err('This reservation is already '.strtolower($number->status), 'NOT_LIVE');
        }
        if ($number->sms_count > 0) {
            return $this->err('This number received a code, so it cannot be reported unusable', 'HAS_CODE');
        }

        $this->vendor_call($tx, $number, 'ban', 'STATUS');
        return $this->settle_unfulfilled($tx, $number, 'BANNED', $source,
            'Reported unusable before a code arrived');
    }

    /* ------------------------------------------------------------------ */

    /**
     * End an unfulfilled reservation: mark the number, refund the charge.
     *
     * One place, so expiry, cancellation, a ban and a vendor-side release can
     * never drift apart on whether the customer gets their money back.
     */
    private function settle_unfulfilled($tx, $number, $state, $source, $reason) {
        $this->set_state($number, $state, gmdate('Y-m-d H:i:s'));

        $refunded = null;
        if (!in_array($tx->status, array('FAILED','CANCELLED','REFUNDED'), true)) {
            $res = $this->ci->transactionengine->transition(
                $tx->id, $state === 'CANCELLED' ? 'CANCELLED' : 'FAILED', $source, $reason);
            if (!empty($res['ok'])) $refunded = $res['refunded'];
        }

        return array(
            'ok'       => true,
            'state'    => $state,
            'refunded' => $refunded,
            'number'   => $this->ci->Virtual_number_model->find_by_id($number->id),
        );
    }

    /**
     * Store any SMS in a vendor response.
     *
     * @return int how many were new — the caller settles on that, not on how
     *             many the vendor sent, because a repeated poll resends them.
     */
    private function ingest($number, array $res, $otp_model, $number_model) {
        if (!$number || empty($res['messages']) || !is_array($res['messages'])) return 0;

        $stored = 0; $last_code = null;
        foreach ($res['messages'] as $sms) {
            if (!is_array($sms)) continue;
            if ($otp_model->record($number->id, $sms)) $stored++;
            if (!empty($sms['code'])) $last_code = (string)$sms['code'];
        }
        if ($stored === 0) return 0;

        $fields = array('sms_count' => $otp_model->count_for_number($number->id));
        if ($last_code !== null) $fields['last_code'] = mb_substr($last_code, 0, 32);
        $number_model->update_fields($number->id, $fields);
        return $stored;
    }

    /** One vendor call, logged like every other provider call in the app. */
    private function vendor_call($tx, $number, $method, $action) {
        $provider = $tx->provider_id ? $this->ci->Provider_model->find_by_id($tx->provider_id) : null;
        if (!$provider) return $this->err('The provider for this reservation no longer exists', 'NO_PROVIDER');

        $started = microtime(true);
        try {
            $adapter = $this->ci->provider_manager->adapter($provider, Provider_manager::FAMILY_NUMBER);
            $res = $adapter->$method($number->provider_order_id);
        } catch (Exception $e) {
            log_message('error', 'number '.$method.' failed: '.$e->getMessage());
            return $this->err('Could not reach the vendor', 'PROVIDER_ERROR');
        }
        $latency = (int)round((microtime(true) - $started) * 1000);

        $this->ci->Provider_transaction_model->record(array(
            'provider_id'            => $provider->id,
            'service_transaction_id' => $tx->id,
            'action'                 => $action,
            'provider_reference'     => $number->provider_order_id,
            'status'                 => !empty($res['ok']) ? 'SUCCESS' : 'FAILED',
            'latency_ms'             => $latency,
            'error'                  => $res['error'] ?? null,
        ));

        if (empty($res['ok'])) {
            return array('ok' => false, 'error' => $res['error'] ?? 'The vendor rejected the request',
                         'code' => 'PROVIDER_REJECTED');
        }
        return array('ok' => true, 'response' => $res);
    }

    private function set_state($number, $state, $released_at = null) {
        $fields = array('status' => $state);
        if ($released_at !== null) $fields['released_at'] = $released_at;
        $this->ci->Virtual_number_model->update_fields($number->id, $fields);
    }

    /** The vendor's deadline, or the product's hold time as a fallback. */
    private function deadline(array $res, $ttl_minutes) {
        if (!empty($res['expires_at'])) return $res['expires_at'];
        return gmdate('Y-m-d H:i:s', time() + (max(1, (int)$ttl_minutes) * 60));
    }

    private function is_past_deadline($number) {
        if (empty($number->expires_at)) return false;
        return strtotime($number->expires_at.' UTC') <= time();
    }

    private function ttl_minutes($product) {
        $ttl = (int)($product->ttl_minutes ?? 0);
        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_MINUTES;
    }

    /** The provider that should handle this product. */
    private function provider_for($product) {
        if ($product && !empty($product->provider_id)) {
            $p = $this->ci->Provider_model->find_by_id($product->provider_id);
            if ($p && $p->status === 'ACTIVE') return $p;
        }
        foreach ($this->ci->Provider_model->find_by(array('status' => 'ACTIVE')) as $row) {
            if (in_array(strtoupper($row->api_type),
                         Provider_manager::supported_types(Provider_manager::FAMILY_NUMBER), true)) {
                return $row;
            }
        }
        return null;
    }

    private function resolve_country(array $input) {
        $key = $input['country'] ?? $input['country_code'] ?? null;
        if ($key === null || $key === '') return null;
        $row = ctype_digit((string)$key)
            ? $this->ci->Number_country_model->find_by_id((int)$key)
            : $this->ci->Number_country_model->find_by_code((string)$key);
        return ($row && $row->is_active) ? $row : null;
    }

    private function resolve_service(array $input) {
        $key = $input['service'] ?? $input['service_code'] ?? null;
        if ($key === null || $key === '') return null;
        $row = ctype_digit((string)$key)
            ? $this->ci->Number_service_model->find_by_id((int)$key)
            : $this->ci->Number_service_model->find_by_code((string)$key);
        return ($row && $row->is_active) ? $row : null;
    }

    private function money($v) { return number_format((float)$v, 8, '.', ''); }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
