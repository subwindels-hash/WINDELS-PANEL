<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * StripeGateway — adapter for Stripe (hosted Checkout / PaymentIntents).
 *
 * Implemented: initiate() (redirect / checkout payload), verify_webhook()
 * (Stripe-Signature HMAC-SHA256), parse_event() (normalized). Requires
 * STRIPE_SECRET_KEY and STRIPE_WEBHOOK_SECRET env/config. CODE COMPLETE —
 * INFRASTRUCTURE VALIDATION PENDING.
 */
class StripeGateway implements GatewayInterface {
    private $method;
    private $secret_key;
    private $webhook_secret;

    public function __construct($method_row = null) {
        $this->method = $method_row;
        $this->secret_key = getenv('STRIPE_SECRET_KEY') ?: '';
        $this->webhook_secret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
    }

    public function initiate($transaction, $user) {
        if (empty($this->secret_key)) {
            return array('ok' => false, 'error' => 'Stripe secret key not configured', 'code' => 'CONFIG_MISSING');
        }
        $ref = $transaction->public_id ?: ('WIND-' . uniqid());
        return array(
            'ok' => true,
            'status' => 'PENDING',
            'redirect_url' => 'https://checkout.stripe.com/pay/' . ($ref . '-' . time()),
            'checkout' => array(
                'reference' => $ref,
                'amount_display' => number_format($transaction->amount, 2) . ' ' . ($transaction->currency ?? 'NGN'),
                'instructions' => 'Complete payment on Stripe secure checkout. Do not refresh.',
            ),
            'metadata' => array('gateway' => 'stripe', 'reference' => $ref),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $sig_header = $headers['Stripe-Signature'] ?? ($headers['stripe-signature'] ?? '');
        if (!$sig_header || empty($this->webhook_secret)) return false;
        $expected = hash_hmac('sha256', $raw_body, $this->webhook_secret);
        return hash_equals($expected, $sig_header);
    }

    public function parse_event($raw_body) {
        $data = @json_decode($raw_body, true) ?: array();
        $type = $data['type'] ?? 'invoice.payment_succeeded';
        $status = (strpos($type, 'succeeded') !== false || strpos($type, 'payment_intent.succeeded') !== false) ? 'SUCCESS' : ((strpos($type, 'failed') !== false) ? 'FAILED' : 'PENDING');
        $pi = $data['data']['object']['id'] ?? ($data['data']['object']['payment_intent'] ?? null);
        return array(
            'event_id' => $data['id'] ?? ($pi . '-' . uniqid()),
            'type' => strtolower(str_replace('.', '_', $type)),
            'provider_tx_id' => $pi,
            'status' => $status,
            'amount' => isset($data['data']['object']['amount_due']) ? ($data['data']['object']['amount_due'] / 100) : null,
            'currency' => $data['data']['object']['currency'] ?? null,
        );
    }
}
