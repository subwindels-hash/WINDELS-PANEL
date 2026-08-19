<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PaystackGateway — adapter for Paystack hosted gateway.
 *
 * Implemented: initiate() (redirect to hosted checkout), verify_webhook()
 * (HMAC-SHA512), parse_event() (normalized). Credentials read from env/config;
 * never hardcoded. Requires live Paystack account + webhook URL validation.
 * Status: SCAFFOLD — NOT wired into PaymentService and UNTESTED against the
 * live API (live keys + webhook endpoint verification needed before
 * production enable).
 */
class PaystackGateway implements GatewayInterface {
    private $method;
    private $secret_key;
    private $public_key;

    public function __construct($method_row = null) {
        $this->method = $method_row;
        $this->secret_key = getenv('PAYSTACK_SECRET_KEY') ?: (getenv('PAYSTACK_KEY') ?: '');
        $this->public_key = getenv('PAYSTACK_PUBLIC_KEY') ?: (getenv('PAYSTACK_PK') ?: '');
    }

    public function initiate($transaction, $user) {
        if (empty($this->secret_key)) {
            return array('ok' => false, 'error' => 'Paystack secret key not configured', 'code' => 'CONFIG_MISSING');
        }
        $amount_kobo = round($transaction->amount * 100); // Paystack uses kobo
        $reference = $transaction->public_id ?: ('WIND-' . uniqid());
        // Hosted checkout redirect building (real endpoint requires secret-key call)
        $checkout_url = 'https://checkout.paystack.com/' . ($this->public_key ?: 'demo');
        return array(
            'ok' => true,
            'status' => 'PENDING',
            'redirect_url' => $checkout_url,
            'checkout' => array(
                'reference' => $reference,
                'amount_display' => number_format($transaction->amount, 2) . ' ' . ($transaction->currency ?? 'NGN'),
                'instructions' => 'Complete payment on the secure Paystack hosted page. Do not refresh after payment.',
            ),
            'metadata' => array('gateway' => 'paystack', 'reference' => $reference),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $sig_header = $headers['X-Paystack-Signature'] ?? ($headers['x-paystack-signature'] ?? '');
        if (!$sig_header || empty($this->secret_key)) return false;
        $expected = hash_hmac('sha512', $raw_body, $this->secret_key);
        return hash_equals($expected, $sig_header);
    }

    public function parse_event($raw_body) {
        $data = @json_decode($raw_body, true) ?: array();
        $event = $data['event'] ?? 'charge.success';
        $ref = $data['data']['reference'] ?? null;
        $tx_ref = $data['data']['reference'] ?? $ref;
        $status = ($event === 'charge.success') ? 'SUCCESS' : (($event === 'charge.failed') ? 'FAILED' : 'PENDING');
        return array(
            'event_id' => $data['data']['transaction_tag'] ?? ($ref . '-' . ($data['data']['id'] ?? uniqid())),
            'type' => strtolower(str_replace('.', '_', $event)),
            'provider_tx_id' => $data['data']['id'] ?? $ref,
            'status' => $status,
            'amount' => isset($data['data']['amount']) ? ($data['data']['amount'] / 100) : null,
            'currency' => $data['data']['currency'] ?? null,
        );
    }
}
