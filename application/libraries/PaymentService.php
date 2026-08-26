<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PaymentService — wallet top-ups and webhook reconciliation (Session 11).
 *
 * Responsibilities:
 *   - create a PaymentTransaction (CREATED/PENDING) with idempotency
 *   - delegate to a GatewayInterface for initiation
 *   - on confirmed payment (manual approval or webhook) credit the wallet
 *     exactly once through LedgerService, recording the wallet_transaction_id
 *
 * Reconciliation is idempotent: a given (gateway, event_id) is stored once,
 * and a transaction can only move to SUCCESS once. No controller credits a
 * wallet directly.
 */
class PaymentService {

    const IDEM_SCOPE = 'payment:deposit';
    const STATUS_CREATED = 'CREATED';
    const STATUS_PENDING = 'PENDING';
    const STATUS_SUCCESS = 'SUCCESS';
    const STATUS_FAILED  = 'FAILED';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Payment_transaction_model','Payment_webhook_model','Payment_event_model',
            'Wallet_model','Setting_model',
        ));
        $this->ci->load->library(array('LedgerService','EncryptionService'));
        // Gateway classes are plain (unnamespaced) library files: CI3 does not
        // autoload them and composer's PSR-4 prefix does not cover them, so
        // they must be required explicitly — `new ManualGateway` in deposit()/
        // record_webhook() fataled ("Class not found") on every live deposit.
        if (!interface_exists('GatewayInterface', false)) {
            require_once APPPATH.'libraries/GatewayInterface.php';
        }
        if (!class_exists('ManualGateway', false)) {
            require_once APPPATH.'libraries/ManualGateway.php';
        }
        if (!class_exists('BlockonomicsGateway', false)) {
            require_once APPPATH.'libraries/BlockonomicsGateway.php';
        }
    }

    /**
     * Initialise a deposit.
     *
     * @param object $user
     * @param array $input  payment_method (code), amount, currency, idempotency_key?
     * @return array{ok:bool, transaction?:object, redirect_url?:string, checkout?:array, error?:string, code?:string}
     */
    public function deposit($user, array $input) {
        $method = $this->resolve_method($input['payment_method'] ?? null);
        if (!$method) return array('ok'=>false,'error'=>'Unknown payment method','code'=>'NO_METHOD');
        if (!(int)$method->is_active) return array('ok'=>false,'error'=>'That payment method is unavailable','code'=>'METHOD_INACTIVE');

        $amount = $this->normalise_amount($input['amount'] ?? null);
        if ($amount === null) return array('ok'=>false,'error'=>'Invalid amount','code'=>'BAD_AMOUNT');
        if ($method->min_amount !== null && bccomp($amount, (string)$method->min_amount, 8) < 0)
            return array('ok'=>false,'error'=>'Minimum is '.$method->min_amount,'code'=>'AMOUNT_TOO_LOW');
        if ($method->max_amount !== null && bccomp($amount, (string)$method->max_amount, 8) > 0)
            return array('ok'=>false,'error'=>'Maximum is '.$method->max_amount,'code'=>'AMOUNT_TOO_HIGH');

        $currency = strtoupper($input['currency'] ?? marvy_base_currency());
        if (!preg_match('/^[A-Z]{3}$/', $currency)) return array('ok'=>false,'error'=>'Bad currency','code'=>'BAD_CURRENCY');

        $idem = $this->normalise_idem($input['idempotency_key'] ?? null, $user);
        if ($idem) {
            $existing = $this->ci->Payment_transaction_model->find_by_idempotency_key($idem);
            if ($existing) return array('ok'=>true,'transaction'=>$existing,'duplicate'=>true);
        }

        $fee = $this->calculate_fee($method, $amount);
        $bonus = $this->calculate_bonus($method, $amount);
        $credited = bcadd(bcsub($amount, $fee, 8), $bonus, 8);

        $tx = $this->persist_transaction(array(
            'public_id'          => marvy_public_id(),
            'user_id'            => $user->id,
            'payment_method_id'  => $method->id,
            'amount'             => $amount,
            'fee'                => $fee,
            'bonus'              => $bonus,
            'credited_amount'    => $credited,
            'currency'           => $currency,
            'status'             => self::STATUS_CREATED,
            'idempotency_key'    => $idem,
            'metadata'           => !empty($input['note']) ? json_encode(array('note'=>$input['note'])) : null,
            'created_at'         => gmdate('Y-m-d H:i:s'),
        ));

        $this->transition($tx->id, null, self::STATUS_CREATED, 'SYSTEM', 'Initialised');

        $gateway = $this->gateway_for($method);
        $init = $gateway->initiate($tx, $user);
        if (empty($init['ok'])) {
            $this->mark_failed($tx->id, $init['error'] ?? 'Gateway error');
            return array('ok'=>false,'error'=>$init['error'] ?? 'Could not initiate payment','code'=>'GATEWAY_ERROR');
        }
        $status = $init['status'] ?? self::STATUS_PENDING;
        $this->transition($tx->id, self::STATUS_CREATED, $status, 'GATEWAY', 'Initiated');
        $tx = $this->ci->Payment_transaction_model->find_by_id($tx->id);

        return array(
            'ok' => true,
            'transaction' => $tx,
            'redirect_url' => $init['redirect_url'] ?? null,
            'checkout' => $init['checkout'] ?? null,
        );
    }

    /**
     * Confirm a transaction and credit the wallet once.
     *
     * @param object $tx        the payment transaction
     * @param string $source    SYSTEM|ADMIN|WEBHOOK
     * @param string|null $provider_tx_id
     */
    public function confirm($tx, $source = 'SYSTEM', $provider_tx_id = null) {
        if (!$tx || $tx->status === self::STATUS_SUCCESS) return array('ok'=>true,'duplicate'=>true,'transaction'=>$tx);
        if (!in_array($tx->status, array(self::STATUS_CREATED, self::STATUS_PENDING), true)) {
            return array('ok'=>false,'error'=>'Transaction cannot be confirmed in '.$tx->status,'code'=>'BAD_STATE');
        }
        $wallet = $this->ci->Wallet_model->for_user($tx->user_id);
        $credited = $tx->credited_amount !== null ? (string)$tx->credited_amount : (string)$tx->amount;
        $idem = 'payment:credit:'.($tx->idempotency_key ?: $tx->public_id);

        $this->ci->db->trans_start();
        $credit = $this->ci->ledgerservice->credit(
            $wallet->id, $credited, 'DEPOSIT', 'PaymentTransaction', $tx->public_id, $idem,
            array('fee'=>(string)$tx->fee, 'bonus'=>(string)$tx->bonus, 'tx_id'=>$tx->public_id)
        );
        if (empty($credit['ok'])) {
            $this->ci->db->trans_complete();
            return array('ok'=>false,'error'=>$credit['error'] ?? 'Could not credit wallet','code'=>'CREDIT_FAILED');
        }
        // Find the wallet transaction we just created.
        $wt = $this->ci->db->where('idempotency_key', $idem)->get('wallet_transactions')->row();
        $update = array(
            'status' => self::STATUS_SUCCESS,
            'wallet_transaction_id' => $wt ? $wt->id : null,
            'verified_at' => gmdate('Y-m-d H:i:s'),
        );
        if ($provider_tx_id) $update['provider_tx_id'] = substr((string)$provider_tx_id, 0, 128);
        $this->ci->Payment_transaction_model->update_status($tx->id, $update);
        $this->transition($tx->id, $tx->status, self::STATUS_SUCCESS, $source, 'Confirmed');
        $this->ci->db->trans_complete();

        return array('ok'=>true,'transaction'=>$this->ci->Payment_transaction_model->find_by_id($tx->id));
    }

    /** Mark a transaction failed (terminal). */
    public function mark_failed($tx_id, $reason = null) {
        $tx = $this->ci->Payment_transaction_model->find_by_id($tx_id);
        if (!$tx || in_array($tx->status, array(self::STATUS_SUCCESS, self::STATUS_FAILED), true)) return;
        $this->transition($tx->id, $tx->status, self::STATUS_FAILED, 'SYSTEM', $reason);
    }

    /**
     * Record and process an incoming webhook (idempotent on gateway+event_id).
     *
     * @return array{ok:bool, already_seen?:bool, transaction?:object, error?:string}
     */
    public function record_webhook($gateway_type, $raw_body, array $headers) {
        // Gateways with a real adapter verify and parse their own callbacks.
        // Anything else goes through the generic HMAC envelope, which is
        // fail-closed: no configured secret means the event is stored but no
        // money moves.
        $gateway = in_array($gateway_type, $this->implemented_gateways(), true)
            ? $this->gateway_for_code($gateway_type)
            : null;

        $sig_ok = $gateway
            ? $gateway->verify_webhook($raw_body, $headers)
            : $this->verify_generic_signature($gateway_type, $raw_body, $headers);

        if ($gateway instanceof BlockonomicsGateway) {
            // Blockonomics reports progress in the query string, so its parser
            // needs the headers too.
            $event = $gateway->parse_event($raw_body, $headers);
        } elseif ($gateway) {
            $event = $gateway->parse_event($raw_body);
        } else {
            $event = $this->parse_generic_event($raw_body);
        }

        $id = $this->ci->Payment_webhook_model->record_once(
            $gateway_type,
            $event['event_id'] ?? null,
            $raw_body,
            $sig_ok,
            $event['type'] ?? null
        );
        if ($id === false) {
            return array('ok'=>true,'already_seen'=>true); // duplicate, do not reprocess
        }

        // Only process when signature is valid AND the event indicates success.
        if ($sig_ok === false) {
            $this->ci->db->where('id', $id)->update('payment_webhooks',
                array('processed'=>1,'processed_at'=>gmdate('Y-m-d H:i:s'),'error'=>'invalid signature'));
            return array('ok'=>false,'error'=>'Invalid signature');
        }
        // null means "no secret configured, cannot verify": store the event for
        // the operator to inspect but never move money on it.
        if ($sig_ok === null) {
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'processed' => 1,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'error' => 'unverified: no webhook secret configured for '.$gateway_type,
            ));
            return array('ok'=>true,'unverified'=>true);
        }

        $terminal = strtolower($event['status'] ?? '');
        if (!in_array($terminal, array('success','succeeded','completed','paid','approved'), true)) {
            $this->ci->db->where('id', $id)->update('payment_webhooks', array('processed'=>1,'processed_at'=>gmdate('Y-m-d H:i:s')));
            return array('ok'=>true,'ignored'=>true);
        }

        $tx = null;
        if (!empty($event['provider_tx_id'])) {
            $tx = $this->ci->Payment_transaction_model->find_by_provider_tx($event['provider_tx_id']);
        }
        if (!$tx && !empty($event['metadata']['idempotency_key'])) {
            $tx = $this->ci->Payment_transaction_model->find_by_idempotency_key($event['metadata']['idempotency_key']);
        }
        // Crypto callbacks identify the payment by the receive address, not by
        // a provider transaction id we issued.
        if (!$tx && !empty($event['metadata']['payment_transaction_id'])) {
            $tx = $this->ci->Payment_transaction_model->find_by_id(
                (int) $event['metadata']['payment_transaction_id']
            );
        }
        if (!$tx) {
            // Accepted and logged, but there is nothing to reconcile — the
            // event references no transaction of ours. Treat it as processed
            // so the gateway stops retrying; the error column flags it for the
            // operator.
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'processed' => 1,
                'processed_at' => gmdate('Y-m-d H:i:s'),
                'error' => 'no matching transaction',
            ));
            return array('ok'=>true,'unmatched'=>true,'error'=>'No matching transaction');
        }

        $res = $this->confirm($tx, 'WEBHOOK', $event['provider_tx_id'] ?? null);
        if (empty($res['ok'])) {
            // Transient processing failure (e.g. the ledger write rolled
            // back). Leave the row UNPROCESSED so the gateway's retry — and
            // the reconciliation sweep over unprocessed() — re-runs it, and
            // flag it retryable so the controller answers 503 (which real
            // gateways retry) instead of a swallowed 200.
            $this->ci->db->where('id', $id)->update('payment_webhooks', array(
                'payment_transaction_id' => $tx->id,
                'error' => substr('retryable: '.($res['error'] ?? 'confirmation failed'), 0, 250),
            ));
            return array('ok'=>false,'retryable'=>true,'error'=>$res['error'] ?? 'Payment processing failed');
        }
        $this->ci->db->where('id', $id)->update('payment_webhooks', array(
            'payment_transaction_id' => $tx->id,
            'processed' => 1,
            'processed_at' => gmdate('Y-m-d H:i:s'),
        ));
        return $res;
    }

    /* -------------------------------------------------------------- */

    public function calculate_fee($method, $amount) {
        $pct = (float)$method->fee_percent;
        $fixed = (float)$method->fee_fixed;
        $fee = bcadd(bcmul($amount, (string)($pct/100), 8), (string)$fixed, 8);
        return bccomp($fee, '0', 8) > 0 ? number_format((float)$fee, 8, '.', '') : '0.00000000';
    }

    public function calculate_bonus($method, $amount) {
        $pct = (float)$method->bonus_percent;
        if ($pct <= 0) return '0.00000000';
        return number_format((float)bcmul($amount, (string)($pct/100), 8), 8, '.', '');
    }

    private function resolve_method($code) {
        if (!$code) return null;
        return $this->ci->db->where('code', $code)->get('payment_methods')->row();
    }

    private function gateway_for($method) {
        return $this->gateway_for_code($method->code, $method);
    }

    /**
     * The adapter that handles a payment-method code.
     *
     * Only adapters that are actually implemented and wired are routed here.
     * Everything else falls back to ManualGateway, which marks the deposit
     * PENDING for admin review — a deposit that waits for a human is always
     * safer than one handed to an untested integration.
     */
    private function gateway_for_code($code, $method_row = null) {
        switch ($code) {
            case 'blockonomics':
            case 'btc':
                return new BlockonomicsGateway($method_row);
            case 'manual':
            default:
                return new ManualGateway($method_row);
        }
    }

    /** Payment-method codes that have a real, wired adapter. */
    public function implemented_gateways() {
        return array('manual', 'blockonomics');
    }

    private function persist_transaction(array $data) {
        $this->ci->db->insert('payment_transactions', $data);
        return $this->ci->Payment_transaction_model->find_by_id($this->ci->db->insert_id());
    }

    private function transition($tx_id, $from, $to, $source, $reason = null) {
        // Persist the new state first, then append to the (append-only) event
        // log. Writing only the log would leave the transaction stuck in its
        // previous status forever.
        if ($from !== $to) {
            $this->ci->Payment_transaction_model->update_status($tx_id, array(
                'status'     => $to,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
        $this->ci->db->insert('payment_events', array(
            'payment_transaction_id' => $tx_id,
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    /**
     * Verify an HMAC-SHA256 signature header against the configured secret.
     *
     * @return bool|null true/false when we can decide, null when no secret is
     *                   configured (the caller must not process the event).
     */
    private function verify_generic_signature($gateway_type, $raw_body, array $headers) {
        $sig = null;
        foreach ($headers as $name => $value) {
            if (stripos((string)$name, 'signature') !== false) { $sig = trim((string)$value); break; }
        }
        // An unsigned callback is always rejected.
        if ($sig === null || $sig === '') return false;

        $secret = $this->ci->Setting_model->get('payments.'.$gateway_type.'.webhook_secret');
        if (!$secret) $secret = getenv('MARVYSOCIALS_'.strtoupper($gateway_type).'_WEBHOOK_SECRET') ?: null;
        if (!$secret) return null;

        $expected = hash_hmac('sha256', (string)$raw_body, (string)$secret);
        // Strip an optional "sha256=" / "v1=" prefix before comparing.
        if (strpos($sig, '=') !== false) {
            $parts = explode('=', $sig, 2);
            if (ctype_alnum($parts[0])) $sig = $parts[1];
        }
        return hash_equals($expected, $sig);
    }

    /** Parse a plain JSON webhook envelope into the normalised event shape. */
    private function parse_generic_event($raw_body) {
        $data = json_decode((string)$raw_body, true);
        if (!is_array($data)) return array('event_id'=>null,'type'=>'unknown');
        $pick = function(array $keys) use ($data) {
            foreach ($keys as $k) {
                if (isset($data[$k]) && $data[$k] !== '') return $data[$k];
            }
            return null;
        };
        return array(
            'event_id'       => $pick(array('id','event_id','eventId')),
            'type'           => $pick(array('type','event','event_type')) ?: 'unknown',
            'provider_tx_id' => $pick(array('provider_tx_id','transaction_id','reference','txn_id')),
            'status'         => $pick(array('status','state','result')),
            'amount'         => $pick(array('amount','value')),
            'currency'       => $pick(array('currency','currency_code')),
            'metadata'       => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : array(),
        );
    }

    private function normalise_amount($v) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $f = (float)$v;
        if ($f <= 0 || !is_finite($f)) return null;
        return number_format($f, 8, '.', '');
    }

    private function normalise_idem($key, $user) {
        if (!$key) return 'deposit:'.$user->id.':'.marvy_public_id();
        $clean = preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)$key);
        return substr(self::IDEM_SCOPE.':'.$clean, 0, 128);
    }
}
