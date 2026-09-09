<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * StripeGateway — Stripe Checkout (hosted).
 *
 * Documented API (https://docs.stripe.com/api):
 *
 *   POST /v1/checkout/sessions        — form-encoded; returns `url`
 *   GET  /v1/checkout/sessions/:id    — authoritative status
 *   webhook: Stripe-Signature: t=<ts>,v1=<hmac-sha256 of "<ts>.<raw body>">
 *
 * The signature check enforces a timestamp tolerance as Stripe documents:
 * without it a captured webhook could be replayed for as long as the endpoint
 * secret lives.
 */
class StripeGateway extends HostedGateway {

    const DEFAULT_BASE_URL = 'https://api.stripe.com';
    const SIGNATURE_HEADER = 'Stripe-Signature';

    /** Replay window for a signed webhook, in seconds (Stripe's own default). */
    const TOLERANCE_SECONDS = 300;

    public function code() { return 'stripe'; }

    public function config() {
        return array(
            'enabled'        => $this->flag('STRIPE_ENABLED', 'stripe_enabled', true),
            'base_url'       => rtrim($this->secret('STRIPE_BASE_URL', 'stripe_base_url') ?: self::DEFAULT_BASE_URL, '/'),
            'secret_key'     => $this->secret('STRIPE_SECRET_KEY', 'stripe_secret_key'),
            'publishable'    => $this->secret('STRIPE_PUBLISHABLE_KEY', 'stripe_publishable_key'),
            'webhook_secret' => $this->secret('STRIPE_WEBHOOK_SECRET', 'stripe_webhook_secret'),
        );
    }

    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['secret_key']);
    }

    public function initiate($transaction, $user) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'Card payments are currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $currency  = strtolower((string)$transaction->currency);
        $reference = $this->reference($transaction);

        // Stripe's API is form-encoded with bracketed nesting, not JSON.
        $payload = array(
            'mode'                 => 'payment',
            'success_url'          => $this->return_url($transaction).'?paid=1',
            'cancel_url'           => $this->return_url($transaction).'?cancelled=1',
            'client_reference_id'  => $reference,
            'customer_email'       => (string)$user->email,
            'line_items[0][quantity]'                        => 1,
            'line_items[0][price_data][currency]'            => $currency,
            'line_items[0][price_data][unit_amount]'         => $this->minor_units($transaction->amount, $currency),
            'line_items[0][price_data][product_data][name]'  => 'Wallet deposit',
            'metadata[internal_reference]'                   => $reference,
            'metadata[payment_transaction_id]'               => (string)$transaction->id,
            'metadata[user_id]'                              => (string)$user->public_id,
            'payment_intent_data[metadata][internal_reference]' => $reference,
        );

        $res = $this->post_form($cfg['base_url'].'/v1/checkout/sessions', $payload, array(
            'Authorization: Bearer '.$cfg['secret_key'],
            'Stripe-Version: 2024-06-20',
        ));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $url = $res['body']['url'] ?? null;
        if (!$url) {
            log_message('error', 'stripe: checkout session returned no url');
            return $this->fail('PROVIDER_ERROR', 'Stripe did not return a checkout link. Try again shortly.');
        }

        return array(
            'ok'             => true,
            'status'         => 'PENDING',
            'redirect_url'   => $url,
            'provider_tx_id' => $reference,
            'checkout'       => array(
                'provider'     => 'stripe',
                'method'       => 'card',
                'reference'    => $reference,
                'session_id'   => $res['body']['id'] ?? null,
                'amount'       => (string)$transaction->amount,
                'currency'     => strtoupper((string)$transaction->currency),
                'instructions' => 'Complete the payment on Stripe Checkout. Your wallet is credited automatically '
                                  .'once Stripe confirms the charge.',
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        if (empty($cfg['webhook_secret'])) return null; // store, never credit
        $header = $this->header($headers, self::SIGNATURE_HEADER);
        if ($header === '') return false;

        $timestamp = null;
        $signatures = array();
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) continue;
            if ($pair[0] === 't')  $timestamp = $pair[1];
            if ($pair[0] === 'v1') $signatures[] = $pair[1];
        }
        if ($timestamp === null || !$signatures) return false;

        // Replay protection: an old signature stays mathematically valid.
        if (abs(time() - (int)$timestamp) > self::TOLERANCE_SECONDS) {
            log_message('error', 'stripe: webhook outside the '.self::TOLERANCE_SECONDS.'s tolerance');
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.(string)$raw_body, $cfg['webhook_secret']);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) return true;
        }
        return false;
    }

    public function parse_event($raw_body) {
        $body = json_decode((string)$raw_body, true);
        $body = is_array($body) ? $body : array();
        $type = (string)($body['type'] ?? '');
        $obj  = isset($body['data']['object']) && is_array($body['data']['object']) ? $body['data']['object'] : array();

        // A completed Checkout Session is only money in the bank when its
        // payment_status says so — `checkout.session.completed` also fires for
        // sessions paying later by bank debit.
        $paid = ($obj['payment_status'] ?? '') === 'paid'
             || ($obj['status'] ?? '') === 'succeeded';
        $failed = in_array($type, array('checkout.session.expired', 'payment_intent.payment_failed', 'charge.failed'), true);

        $reference = (string)($obj['client_reference_id']
            ?? ($obj['metadata']['internal_reference'] ?? ''));

        return array(
            'event_id'       => (string)($body['id'] ?? ($reference.':'.$type)),
            'type'           => strtolower(str_replace('.', '_', $type ?: 'checkout_session_completed')),
            'provider_tx_id' => $reference,
            'status'         => $failed ? 'FAILED' : ($paid ? 'SUCCESS' : 'PENDING'),
            'amount'         => isset($obj['amount_total']) ? (string)((float)$obj['amount_total'] / 100) : null,
            'currency'       => isset($obj['currency']) ? strtoupper((string)$obj['currency']) : null,
            'metadata'       => is_array($obj['metadata'] ?? null) ? $obj['metadata'] : array(),
        );
    }

    /** Authoritative status for a checkout session id. */
    public function verify($session_id) {
        $cfg = $this->config();
        if (!$this->is_configured()) return $this->not_configured();

        $res = $this->get_json($cfg['base_url'].'/v1/checkout/sessions/'.rawurlencode($session_id),
            array('Authorization: Bearer '.$cfg['secret_key']));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $obj = $res['body'];
        return array(
            'ok'             => true,
            'status'         => ($obj['payment_status'] ?? '') === 'paid' ? 'SUCCESS' : 'PENDING',
            'provider_tx_id' => (string)($obj['client_reference_id'] ?? $session_id),
            'amount'         => isset($obj['amount_total']) ? (string)((float)$obj['amount_total'] / 100) : null,
            'currency'       => isset($obj['currency']) ? strtoupper((string)$obj['currency']) : null,
        );
    }
}
