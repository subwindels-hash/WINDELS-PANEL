<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RazorpayGateway — adapter for Razorpay (orders / payments / webhook HMAC-SHA256).
 *
 * Implemented: initiate(), verify_webhook(), parse_event().
 * SCAFFOLD — NOT wired into PaymentService and UNTESTED against the live API.
 */
class RazorpayGateway implements GatewayInterface {
    private $method;
    private $key_id;
    private $key_secret;
    public function __construct($method_row = null) {
        $this->method = $method_row;
        $this->key_id = getenv('RAZORPAY_KEY_ID') ?: '';
        $this->key_secret = getenv('RAZORPAY_KEY_SECRET') ?: '';
    }
    public function initiate($transaction, $user) {
        if (empty($this->key_secret)) return array('ok' => false, 'error' => 'Razorpay secret not configured', 'code' => 'CONFIG_MISSING');
        $ref = $transaction->public_id ?: ('WIND-' . uniqid());
        return array('ok' => true, 'status' => 'PENDING', 'redirect_url' => 'https://checkout.razorpay.com/v1/payment/' . $ref, 'checkout' => array('reference' => $ref, 'amount_display' => number_format($transaction->amount, 2) . ' ' . ($transaction->currency ?? 'INR'), 'instructions' => 'Complete payment on Razorpay secure page.'), 'metadata' => array('gateway' => 'razorpay', 'reference' => $ref));
    }
    public function verify_webhook($raw_body, array $headers) {
        $sig = $headers['X-Razorpay-Signature'] ?? ($headers['x-razorpay-signature'] ?? '');
        return !empty($sig) && !empty($this->key_secret) && hash_equals(hash_hmac('sha256', $raw_body, $this->key_secret), $sig);
    }
    public function parse_event($raw_body) {
        $d = @json_decode($raw_body, true) ?: array();
        $entity = $d['payload']['payment']['entity'] ?? array();
        $status = ($entity['status'] ?? '') === 'captured' ? 'SUCCESS' : (($entity['status'] ?? '') === 'failed' ? 'FAILED' : 'PENDING');
        return array('event_id' => $d['payload']['entity']['id'] ?? ($entity['id'] ?? uniqid()), 'type' => strtolower(str_replace('.', '_', $d['event'] ?? 'unknown')), 'provider_tx_id' => $entity['id'] ?? null, 'status' => $status, 'amount' => isset($entity['amount']) ? ($entity['amount'] / 100) : null, 'currency' => $entity['currency'] ?? null);
    }
}
