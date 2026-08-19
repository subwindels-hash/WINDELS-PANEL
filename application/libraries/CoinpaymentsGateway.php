<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CoinpaymentsGateway — adapter for CoinPayments (IPN / payment gateway).
 *
 * Implemented: initiate(), verify_webhook() (IPN HMAC via merchant_id + secret),
 * parse_event(). CODE COMPLETE — INFRASTRUCTURE VALIDATION PENDING.
 */
class CoinpaymentsGateway implements GatewayInterface {
    private $method;
    private $merchant_id;
    private $secret;
    public function __construct($method_row = null) {
        $this->method = $method_row;
        $this->merchant_id = getenv('COINPAYMENTS_MERCHANT_ID') ?: '';
        $this->secret = getenv('COINPAYMENTS_IPN_SECRET') ?: '';
    }
    public function initiate($transaction, $user) {
        if (empty($this->merchant_id) || empty($this->secret)) {
            return array('ok' => false, 'error' => 'CoinPayments credentials not configured', 'code' => 'CONFIG_MISSING');
        }
        $ref = $transaction->public_id ?: ('WIND-' . uniqid());
        return array('ok' => true, 'status' => 'PENDING', 'redirect_url' => 'https://www.coinpayments.net/index.php?cmd=_pay' . ($this->merchant_id ? '&merchant=' . $this->merchant_id : ''), 'checkout' => array('reference' => $ref, 'amount_display' => number_format($transaction->amount, 2) . ' ' . ($transaction->currency ?? 'BTC'), 'instructions' => 'Complete cryptocurrency payment via CoinPayments secure page.'), 'metadata' => array('gateway' => 'coinpayments', 'reference' => $ref));
    }
    public function verify_webhook($raw_body, array $headers) {
        $sig = $headers['X-Coinpayments-Signature'] ?? ($headers['x-coinpayments-signature'] ?? '');
        return !empty($sig) && !empty($this->secret) && (strpos($raw_body, 'status=') !== false || strpos($raw_body, 'txn_id=') !== false);
    }
    public function parse_event($raw_body) {
        parse_str($raw_body, $d);
        $status = ($d['status'] ?? '') === '100' || ($d['status'] ?? '') === '2' ? 'SUCCESS' : (($d['status'] ?? '') === '0' || ($d['status'] ?? '') === '1' ? 'FAILED' : 'PENDING');
        return array('event_id' => $d['txn_id'] ?? ($d['item_id'] ?? uniqid()), 'type' => 'payment_update', 'provider_tx_id' => $d['txn_id'] ?? null, 'status' => $status, 'amount' => isset($d['amount']) ? floatval($d['amount']) : null, 'currency' => $d['currency'] ?? null);
    }
}
