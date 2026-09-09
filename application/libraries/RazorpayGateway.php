<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RazorpayGateway — Razorpay Payment Links (hosted).
 *
 * Documented API (https://razorpay.com/docs/api/payment-links/):
 *
 *   POST /v1/payment_links       — basic auth key_id:key_secret; returns short_url
 *   GET  /v1/payment_links/:id   — authoritative status
 *   webhook: HMAC-SHA256 of the raw body in X-Razorpay-Signature, using the
 *            webhook secret set in the dashboard (NOT the API key secret)
 *
 * A Payment Link is used rather than an Order because it is a hosted page the
 * customer can be redirected to, which is what this panel's deposit flow does;
 * Orders require a client-side checkout script.
 */
class RazorpayGateway extends HostedGateway {

    const DEFAULT_BASE_URL = 'https://api.razorpay.com';
    const SIGNATURE_HEADER = 'X-Razorpay-Signature';

    public function code() { return 'razorpay'; }

    public function config() {
        return array(
            'enabled'        => $this->flag('RAZORPAY_ENABLED', 'razorpay_enabled', true),
            'base_url'       => rtrim($this->secret('RAZORPAY_BASE_URL', 'razorpay_base_url') ?: self::DEFAULT_BASE_URL, '/'),
            'key_id'         => $this->secret('RAZORPAY_KEY_ID', 'razorpay_key_id'),
            'key_secret'     => $this->secret('RAZORPAY_KEY_SECRET', 'razorpay_key_secret'),
            'webhook_secret' => $this->secret('RAZORPAY_WEBHOOK_SECRET', 'razorpay_webhook_secret'),
        );
    }

    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['key_id']) && !empty($cfg['key_secret']);
    }

    public function initiate($transaction, $user) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'This payment method is currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $reference = $this->reference($transaction);
        $currency  = strtoupper((string)$transaction->currency);
        $name = trim((string)($user->first_name ?? '').' '.(string)($user->last_name ?? ''));

        $res = $this->post_json($cfg['base_url'].'/v1/payment_links', array(
            'amount'      => $this->minor_units($transaction->amount, $currency),
            'currency'    => $currency,
            'accept_partial' => false,
            'reference_id'=> $reference,
            'description' => 'Wallet deposit',
            'customer'    => array(
                'name'    => $name !== '' ? $name : (string)$user->username,
                'email'   => (string)$user->email,
                'contact' => (string)($user->phone ?? ''),
            ),
            'notify'      => array('sms' => false, 'email' => false),
            'callback_url'=> $this->return_url($transaction),
            'callback_method' => 'get',
            'notes' => array(
                'internal_reference'     => $reference,
                'payment_transaction_id' => (string)$transaction->id,
            ),
        ), array('Authorization: Basic '.base64_encode($cfg['key_id'].':'.$cfg['key_secret'])));

        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $url = $res['body']['short_url'] ?? null;
        if (!$url) {
            log_message('error', 'razorpay: payment link created without short_url');
            return $this->fail('PROVIDER_ERROR', 'Razorpay did not return a payment link. Try again shortly.');
        }

        return array(
            'ok'             => true,
            'status'         => 'PENDING',
            'redirect_url'   => $url,
            'provider_tx_id' => $reference,
            'checkout'       => array(
                'provider'     => 'razorpay',
                'method'       => 'card',
                'reference'    => $reference,
                'link_id'      => $res['body']['id'] ?? null,
                'amount'       => (string)$transaction->amount,
                'currency'     => $currency,
                'instructions' => 'Complete the payment on the Razorpay page. Your wallet is credited '
                                  .'automatically once the payment is captured.',
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        if (empty($cfg['webhook_secret'])) return null; // store, never credit
        $signature = $this->header($headers, self::SIGNATURE_HEADER);
        if ($signature === '') return false;
        return hash_equals(hash_hmac('sha256', (string)$raw_body, $cfg['webhook_secret']), $signature);
    }

    public function parse_event($raw_body) {
        $body  = json_decode((string)$raw_body, true);
        $body  = is_array($body) ? $body : array();
        $event = (string)($body['event'] ?? '');

        $payment = $body['payload']['payment']['entity'] ?? array();
        $link    = $body['payload']['payment_link']['entity'] ?? array();

        $reference = (string)($link['reference_id']
            ?? ($payment['notes']['internal_reference'] ?? ''));

        $success = in_array($event, array('payment.captured', 'payment_link.paid'), true)
            && strtolower((string)($payment['status'] ?? 'captured')) !== 'failed';
        $failed = in_array($event, array('payment.failed', 'payment_link.cancelled', 'payment_link.expired'), true);

        $amount = $payment['amount'] ?? ($link['amount_paid'] ?? null);

        return array(
            'event_id'       => (string)($payment['id'] ?? ($link['id'] ?? ($reference.':'.$event))),
            'type'           => strtolower(str_replace('.', '_', $event ?: 'payment_captured')),
            'provider_tx_id' => $reference,
            'status'         => $failed ? 'FAILED' : ($success ? 'SUCCESS' : 'PENDING'),
            'amount'         => $amount !== null ? (string)((float)$amount / 100) : null,
            'currency'       => isset($payment['currency']) ? strtoupper((string)$payment['currency']) : null,
            'metadata'       => is_array($payment['notes'] ?? null) ? $payment['notes'] : array(),
        );
    }

    /** Authoritative status for a payment link id. */
    public function verify($link_id) {
        $cfg = $this->config();
        if (!$this->is_configured()) return $this->not_configured();

        $res = $this->get_json($cfg['base_url'].'/v1/payment_links/'.rawurlencode($link_id),
            array('Authorization: Basic '.base64_encode($cfg['key_id'].':'.$cfg['key_secret'])));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $link = $res['body'];
        return array(
            'ok'             => true,
            'status'         => $this->normalise_status($link['status'] ?? '', array('paid'), array('cancelled', 'expired')),
            'provider_tx_id' => (string)($link['reference_id'] ?? $link_id),
            'amount'         => isset($link['amount_paid']) ? (string)((float)$link['amount_paid'] / 100) : null,
            'currency'       => isset($link['currency']) ? strtoupper((string)$link['currency']) : null,
        );
    }
}
