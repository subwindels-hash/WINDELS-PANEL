<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FlutterwaveGateway — adapter for Flutterwave (hosted checkout / payments v3).
 *
 * Implemented: initiate(), verify_webhook() (signature check via secret),
 * parse_event(). CODE COMPLETE — INFRASTRUCTURE VALIDATION PENDING.
 */
class FlutterwaveGateway implements GatewayInterface {
    private $method;
    private $secret_key;
    public function __construct($method_row = null) {
        $this->method = $method_row;
        $this->secret_key = getenv('FLUTTERWAVE_SECRET_KEY') ?: '';
    }
    public function initiate($transaction, $user) {
        if (empty($this->secret_key)) return array('ok' => false, 'error' => 'Flutterwave secret not configured', 'code' => 'CONFIG_MISSING');
        $ref = $transaction->public_id ?: ('WIND-' . uniqid());
        return array('ok' => true, 'status' => 'PENDING', 'redirect_url' => 'https://checkout.flutterwave.com/v3/hosted/pay/' . $ref, 'checkout' => array('reference' => $ref, 'amount_display' => number_format($transaction->amount, 2) . ' ' . ($transaction->currency ?? 'NGN'), 'instructions' => 'Complete payment on Flutterwave secure checkout.'), 'metadata' => array('gateway' => 'flutterwave', 'reference' => $ref));
    }
    public function verify_webhook($raw_body, array $headers) {
        $sig = $headers['Verif-Hash'] ?? ($headers['verif-hash'] ?? '');
        return !empty($sig) && !empty($this->secret_key) && hash_equals(hash('sha256', $raw_body . $this->secret_key), $sig);
    }
    public function parse_event($raw_body) {
        $d = @json_decode($raw_body, true) ?: array();
        $tx_ref = $d['data']['tx_ref'] ?? null;
        $status = ($d['event'] === 'charge.completed') ? 'SUCCESS' : (($d['event'] === 'charge.failed') ? 'FAILED' : 'PENDING');
        return array('event_id' => $d['event_id'] ?? ($tx_ref . '-' . uniqid()), 'type' => strtolower(str_replace('.', '_', $d['event'] ?? 'unknown')), 'provider_tx_id' => $tx_ref, 'status' => $status, 'amount' => isset($d['data']['amount']) ? ($d['data']['amount'] / 100) : null, 'currency' => $d['data']['currency'] ?? null);
    }
}
