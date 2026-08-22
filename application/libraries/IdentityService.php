<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * IdentityService — NIN/BVN verification (§22; rebuild-spec phase E).
 *
 * Thin in the same way VtuService and NumberService are thin: money, refunds,
 * status history and idempotency belong to TransactionEngine. What is specific
 * to this domain is not the lifecycle — a lookup is one call and one answer,
 * the simplest flow in the panel — it is the handling of the payload.
 *
 * Three rules carry that, and each one is a deliberate cost:
 *
 *  1. **"No record" is refunded.** The vendor charges us either way, so a
 *     not-found lookup is a real cost to the business. Billing the customer
 *     for it anyway is how identity resellers get a reputation for selling
 *     nothing, and it also creates an incentive to keep a broken vendor
 *     connected. The panel eats it: found:false refunds in full and the check
 *     is recorded NOT_FOUND, which is distinct from FAILED (we never got an
 *     answer) precisely so the two can be counted separately — a rising
 *     NOT_FOUND rate is a fraud or data-entry signal, a rising FAILED rate is
 *     an outage.
 *
 *  2. **The identifier is used and dropped.** It goes to the vendor and into
 *     a blind index; it is never written to a column, never put in metadata,
 *     never logged, and never included in a failure reason. Everything the
 *     panel shows afterwards is built from the last four digits.
 *
 *  3. **Reading a stored result is an event, not a read.** reveal() decrypts,
 *     counts the access on the row and writes an audit entry. Support looking
 *     up a customer's date of birth is a legitimate act that must leave a
 *     trace, so there is no unaudited path to the plaintext anywhere in the
 *     codebase — the admin view is handed a masked summary unless the operator
 *     explicitly presses Reveal.
 *
 * Consent is captured on the row (consent_at/consent_ip) because running a
 * government identity check without the subject's permission is the illegal
 * version of this product. The service refuses to dispatch without it.
 */
class IdentityService {

    /** How long a result stays readable before the sweep scrubs it. */
    const DEFAULT_RETENTION_DAYS = 30;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Identity_product_model', 'Identity_check_model',
            'Service_transaction_model', 'Provider_transaction_model', 'Provider_model',
            'Audit_log_model',
        ));
        $this->ci->load->library(array('TransactionEngine', 'Provider_manager', 'EncryptionService'));
    }

    /**
     * Run one identity check.
     *
     * @param array $input product (code or id), identifier, consent (bool),
     *                     consent_ip?, idempotency_key?, source?
     * @return array{ok:bool,transaction?:object,check?:object,found?:bool,error?:string,code?:string}
     */
    public function verify($user, array $input) {
        $product = $this->resolve_product($input);
        if (!$product) return $this->err('Unknown identity check', 'NO_PRODUCT');
        if ($product->price === null) return $this->err('That check has no price', 'NO_PRICE');

        if (empty($input['consent'])) {
            return $this->err(
                'You must confirm you have the consent of the person being checked',
                'NO_CONSENT');
        }

        $raw = $this->normalise($input['identifier'] ?? '');
        $invalid = $this->validate($product, $raw);
        if ($invalid !== null) return $this->err($invalid, 'BAD_IDENTIFIER');

        $provider = $this->provider_for($product);
        if (!$provider) return $this->err('No provider configured for identity checks', 'NO_PROVIDER');

        $enc          = $this->ci->encryptionservice;
        $manager      = $this->ci->provider_manager;
        $check_model  = $this->ci->Identity_check_model;
        $provider_log = $this->ci->Provider_transaction_model;

        $hash  = $enc->blind_index($raw, $product->id_type);
        $last4 = substr($raw, -4);

        // TransactionEngine's failure result is deliberately minimal — an
        // error and a code, no transaction — because most callers only need
        // to say "that did not work". This domain does: a NOT_FOUND lookup is
        // a completed, refunded purchase with a receipt the customer is
        // entitled to see. The detail callback is the one place that learns
        // the id before the failure, so capture it there.
        $tx_id = null;

        $payload = array(
            'id_type'       => $product->id_type,
            'lookup_field'  => $product->lookup_field,
            'provider_code' => $product->provider_code,
            'identifier'    => $raw,
        );

        $result = $this->ci->transactionengine->execute($user, array(
            'service_domain'  => 'IDENTITY',
            'service_type'    => $product->id_type,
            'service_id'      => $product->id,
            'provider_id'     => $provider->id,
            'amount'          => $this->money($product->price),
            'provider_cost'   => $product->provider_cost !== null
                                    ? $this->money($product->provider_cost) : null,
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'source'          => $input['source'] ?? 'WEB',
            // Metadata is readable by anyone who can see the transaction, so it
            // carries the *kind* of check and nothing that identifies a person.
            'metadata'        => array('product' => $product->code, 'id_type' => $product->id_type),

            // Written before the vendor is called, so a rejected lookup still
            // shows what was attempted — by hash and last four, never in full.
            'detail' => function ($id) use ($product, $hash, $last4, $input, $check_model, &$tx_id) {
                $tx_id = $id;
                $check_model->create(array(
                    'service_transaction_id' => $id,
                    'product_id'       => $product->id,
                    'id_type'          => $product->id_type,
                    'lookup_field'     => $product->lookup_field,
                    'identifier_hash'  => $hash,
                    'identifier_last4' => $last4,
                    'status'           => 'PENDING',
                    'consent_at'       => gmdate('Y-m-d H:i:s'),
                    'consent_ip'       => isset($input['consent_ip'])
                                            ? substr((string)$input['consent_ip'], 0, 45) : null,
                    'created_at'       => gmdate('Y-m-d H:i:s'),
                ));
            },

            'dispatch' => function ($tx) use ($manager, $provider, $payload, $enc,
                                              $check_model, $provider_log) {
                $payload['reference'] = $tx->public_id;
                $started = microtime(true);
                $adapter = $manager->adapter($provider, Provider_manager::FAMILY_IDENTITY);
                $res = $adapter->lookup($payload);
                $latency = (int)round((microtime(true) - $started) * 1000);

                // The provider log records that a call happened and what it
                // cost. It must not record who was looked up.
                $provider_log->record(array(
                    'provider_id'            => $provider->id,
                    'service_transaction_id' => $tx->id,
                    'action'                 => 'VERIFY',
                    'provider_reference'     => $res['reference'] ?? null,
                    'status'                 => !empty($res['ok']) ? 'SUCCESS' : 'FAILED',
                    'cost'                   => $res['cost'] ?? null,
                    'latency_ms'             => $latency,
                    'error'                  => $res['error'] ?? null,
                ));

                // The vendor never answered — engine refunds in full.
                if (empty($res['ok'])) {
                    $check_model->update_for_transaction($tx->id, array('status' => 'FAILED'));
                    return $res;
                }

                // It answered "nobody". A real answer, billed to us, worth
                // nothing to the customer: recorded as its own status and
                // refunded by returning a failure to the engine.
                if (empty($res['found'])) {
                    $check_model->update_for_transaction($tx->id, array(
                        'status'             => 'NOT_FOUND',
                        'provider_reference' => $res['reference'] ?? null,
                    ));
                    return array(
                        'ok'    => false,
                        'error' => 'No record was found for that identifier — you have not been charged',
                        'reference' => $res['reference'] ?? null,
                    );
                }

                $check_model->update_for_transaction($tx->id, array(
                    'status'             => 'VERIFIED',
                    'provider_reference' => $res['reference'] ?? null,
                    'result_encrypted'   => $enc->encrypt(json_encode(
                        $this->safe_entity($res['entity'] ?? array()))),
                ));

                $res['status'] = 'SUCCESSFUL';
                return $res;
            },
        ));

        // On the failure paths the engine hands back no transaction, so fall
        // back to the id the detail callback captured. A rejection that never
        // reached the engine (no wallet, no balance) has no id and no check,
        // and correctly stays a plain error.
        if (empty($result['transaction']) && $tx_id) {
            $result['transaction'] = $this->ci->Service_transaction_model->find_by_id($tx_id);
        }

        $check = !empty($result['transaction'])
            ? $this->ci->Identity_check_model->for_transaction($result['transaction']->id) : null;

        if (empty($result['ok'])) {
            $result['check'] = $check;
            $result['found'] = $check && $check->status === 'NOT_FOUND' ? false : null;
            return $result;
        }

        $result['check'] = $check;
        $result['found'] = true;
        return $result;
    }

    /**
     * Decrypt a stored result, recording that someone read it (§22).
     *
     * Every caller goes through here — there is no other way to the plaintext —
     * so the access count on the row and the audit entry cannot be bypassed by
     * a new screen forgetting to log.
     *
     * @param object $check    identity_checks row
     * @param object $actor    the user reading it
     * @param string $reason   ADMIN|CUSTOMER, for the audit trail
     * @return array{ok:bool,entity?:array,error?:string,code?:string}
     */
    public function reveal($check, $actor, $reason = 'CUSTOMER') {
        if (!$check) return $this->err('Check not found', 'NOT_FOUND');
        if ($check->status !== 'VERIFIED') {
            return $this->err('That check has no result to show', 'NO_RESULT');
        }
        if (!empty($check->purged_at) || empty($check->result_encrypted)) {
            return $this->err(
                'That result has passed its retention period and has been deleted',
                'PURGED');
        }

        // open(), not decrypt(): decrypt() hands back its input when the tag
        // does not verify, which would render a base64 blob as if it were the
        // customer's identity record.
        $plain = $this->ci->encryptionservice->open($check->result_encrypted);
        if ($plain === null) {
            return $this->err('That result could not be decrypted', 'UNREADABLE');
        }
        $entity = json_decode($plain, true);
        if (!is_array($entity)) return $this->err('That result could not be read', 'UNREADABLE');

        $actor_id = $actor && isset($actor->id) ? (int)$actor->id : null;
        $this->ci->Identity_check_model->record_reveal($check->id, $actor_id);

        // The audit entry proves the access happened; it must not itself
        // become a second, unencrypted copy of the record.
        $this->ci->Audit_log_model->record(
            $actor_id, 'identity.result.reveal', 'identity_check', $check->id,
            null, array('id_type' => $check->id_type, 'last4' => $check->identifier_last4,
                        'by' => $reason),
            null, null, function_exists('windels_request_id') ? windels_request_id() : null);

        return array('ok' => true, 'entity' => $this->safe_entity($entity));
    }

    /**
     * Scrub results past their retention window (§22).
     *
     * Deletes the payload, keeps the row. The transaction, the money and the
     * blind index are not the sensitive part, and accounting and dispute
     * handling both need the check to have existed.
     *
     * @return array{processed:int,failed:int,message:string}
     */
    public function purge_expired($days = null, $limit = 500) {
        $days = $days !== null ? (int)$days : $this->retention_days();
        if ($days <= 0) {
            return array('processed'=>0, 'failed'=>0, 'message'=>'retention disabled');
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $rows = $this->ci->Identity_check_model->purgeable($cutoff, $limit);
        if (!$rows) return array('processed'=>0, 'failed'=>0, 'message'=>'nothing to purge');

        $processed = 0; $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->ci->Identity_check_model->purge($row->id);
                $processed++;
            } catch (Exception $e) {
                log_message('error', 'identity purge failed for check '.$row->id);
                $failed++;
            }
        }
        return array('processed'=>$processed, 'failed'=>$failed,
                     'message'=>$processed.' identity results scrubbed after '.$days.' days');
    }

    public function retention_days() {
        // Wired to the settings table, not config->item(): SettingsService
        // exposes this key on Admin → Settings and Setting_model is where
        // every other schema setting is read from. Nothing ever copies DB
        // settings into the CI config registry, so the previous config->item()
        // read always returned NULL and silently ignored the operator's value.
        $this->ci->load->model('Setting_model');
        $configured = $this->ci->Setting_model->get('identity_retention_days');
        return $configured !== null && $configured !== '' && (int)$configured >= 0
            ? (int)$configured : self::DEFAULT_RETENTION_DAYS;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Whatever we are willing to keep, whatever the vendor sent.
     *
     * An allow-list applied on the way in *and* on the way out: a photo that
     * somehow reached storage before this rule existed still never reaches a
     * screen. Keys are fixed here rather than taken from the vendor so a new
     * field in a vendor response cannot silently become a new stored field.
     */
    private function safe_entity(array $entity) {
        $allowed = array('first_name','middle_name','last_name','date_of_birth',
                         'gender','phone_number','nationality','state_of_origin',
                         'lga_of_origin');
        $out = array();
        foreach ($allowed as $k) {
            if (isset($entity[$k]) && $entity[$k] !== '' && !is_array($entity[$k])) {
                $out[$k] = (string)$entity[$k];
            }
        }
        return $out;
    }

    /**
     * Why this identifier cannot be sent, or NULL when it is well-formed.
     *
     * Checked before the customer is charged: a 10-digit NIN is a typo, and
     * charging for a lookup that the vendor will certainly reject — and bill
     * us for — is money nobody gets back.
     */
    private function validate($product, $raw) {
        if ($raw === '') return 'Enter the number you want to check';

        if ($product->lookup_field === 'PHONE') {
            return preg_match('/^0\d{10}$/', $raw) || preg_match('/^234\d{10}$/', $raw)
                ? null : 'Enter a valid Nigerian phone number, e.g. 08031234567';
        }

        if (!preg_match('/^\d{11}$/', $raw)) {
            return 'A '.$product->id_type.' is exactly 11 digits';
        }
        // BVNs are issued in the 22xxxxxxxxx range; a NIN is not. This catches
        // the commonest customer error — pasting one into the other's form —
        // before it becomes a billed lookup that was never going to match.
        if ($product->id_type === 'BVN' && substr($raw, 0, 2) !== '22') {
            return 'That does not look like a BVN — BVNs start with 22';
        }
        return null;
    }

    private function resolve_product(array $input) {
        $key = $input['product'] ?? null;
        if ($key === null || $key === '') return null;
        return ctype_digit((string)$key)
            ? $this->ci->Identity_product_model->find_active((int)$key)
            : $this->ci->Identity_product_model->find_active_by_code((string)$key);
    }

    /** Per-product provider, else the first ACTIVE one that can do identity. */
    private function provider_for($product) {
        if (!empty($product->provider_id)) {
            $p = $this->ci->Provider_model->find_by_id($product->provider_id);
            if ($p && $p->status === 'ACTIVE') return $p;
        }
        $types = Provider_manager::supported_types(Provider_manager::FAMILY_IDENTITY);
        foreach ($this->ci->Provider_model->active() as $p) {
            if (in_array(strtoupper($p->api_type), $types, true)) return $p;
        }
        return null;
    }

    private function normalise($value) {
        return preg_replace('/[\s-]+/', '', trim((string)$value));
    }

    private function money($v) { return number_format((float)$v, 8, '.', ''); }

    private function err($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
