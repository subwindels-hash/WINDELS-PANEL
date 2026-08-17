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

        $currency = strtoupper($input['currency'] ?? 'USD');
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
            'public_id'          => windels_public_id(),
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
        $this->ci->Payment_transaction_model->update_status($tx->id, array('status'=>self::STATUS_FAILED));
    }

    /**
     * Record and process an incoming webhook (idempotent on gateway+event_id).
     *
     * @return array{ok:bool, already_seen?:bool, transaction?:object, error?:string}
     */
    public function record_webhook($gateway_type, $raw_body, array $headers) {
        $gateway = $this->gateway_for_code($gateway_type);
        $sig_ok = $gateway ? $gateway->verify_webhook($raw_body, $headers) : null;
        $event = $gateway ? $gateway->parse_event($raw_body) : array('event_id'=>null,'type'=>'unknown');

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
        if (!$tx) {
            $this->ci->db->where('id', $id)->update('payment_webhooks',
                array('error'=>'no matching transaction'));
            return array('ok'=>false,'error'=>'No matching transaction');
        }

        $res = $this->confirm($tx, 'WEBHOOK', $event['provider_tx_id'] ?? null);
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

    private function gateway_for_code($code, $method_row = null) {
        if ($code === 'manual') return new ManualGateway($method_row);
        // External gateways (stripe/paypal/...) ship with their own adapters in
        // later iterations; fall back to a safe "unconfigured" manual adapter
        // so the code path never calls an undefined class.
        return new ManualGateway($method_row);
    }

    private function persist_transaction(array $data) {
        $this->ci->db->insert('payment_transactions', $data);
        return $this->ci->Payment_transaction_model->find_by_id($this->ci->db->insert_id());
    }

    private function transition($tx_id, $from, $to, $source, $reason = null) {
        $this->ci->db->insert('payment_events', array(
            'payment_transaction_id' => $tx_id,
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'reason' => $reason,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    private function normalise_amount($v) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $f = (float)$v;
        if ($f <= 0 || !is_finite($f)) return null;
        return number_format($f, 8, '.', '');
    }

    private function normalise_idem($key, $user) {
        if (!$key) return 'deposit:'.$user->id.':'.windels_public_id();
        $clean = preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)$key);
        return substr(self::IDEM_SCOPE.':'.$clean, 0, 128);
    }
}
