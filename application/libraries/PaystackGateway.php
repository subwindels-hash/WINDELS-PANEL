<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PaystackGateway — Paystack hosted checkout (NGN, GHS, ZAR, KES, USD).
 *
 * Documented API (https://paystack.com/docs/api):
 *
 *   POST /transaction/initialize   — returns data.authorization_url
 *   GET  /transaction/verify/:ref  — authoritative status for a reference
 *   webhook: HMAC-SHA512 of the raw body in X-Paystack-Signature
 *
 * Money moves on the webhook, never on the browser's return trip: the return
 * URL is something a customer can open by hand, the signed callback is not.
 * `verify()` exists so staff (and the reconciliation sweep) can ask Paystack
 * directly when a callback was missed.
 *
 * Amounts are sent in the currency's minor unit (kobo/pesewas/cents), which is
 * what Paystack expects; the reference we send is our own internal_reference,
 * so the callback identifies the deposit without trusting anything else.
 */
class PaystackGateway extends HostedGateway {

    const DEFAULT_BASE_URL = 'https://api.paystack.co';
    const SIGNATURE_HEADER = 'X-Paystack-Signature';

    /** Currencies Paystack settles. Anything else is refused up front. */
    const SUPPORTED = array('NGN', 'GHS', 'ZAR', 'KES', 'USD');

    public function code() { return 'paystack'; }

    public function config() {
        return array(
            'enabled'    => $this->flag('PAYSTACK_ENABLED', 'paystack_enabled', true),
            'base_url'   => rtrim($this->secret('PAYSTACK_BASE_URL', 'paystack_base_url') ?: self::DEFAULT_BASE_URL, '/'),
            'secret_key' => $this->secret('PAYSTACK_SECRET_KEY', 'paystack_secret_key'),
            'public_key' => $this->secret('PAYSTACK_PUBLIC_KEY', 'paystack_public_key'),
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

        $currency = strtoupper((string)$transaction->currency);
        if (!in_array($currency, self::SUPPORTED, true)) {
            return $this->fail('CURRENCY_UNSUPPORTED',
                'Paystack does not settle '.$currency.'. Supported: '.implode(', ', self::SUPPORTED).'.');
        }

        $reference = $this->reference($transaction);
        $res = $this->post_json($cfg['base_url'].'/transaction/initialize', array(
            'email'        => (string)$user->email,
            'amount'       => $this->minor_units($transaction->amount, $currency),
            'currency'     => $currency,
            'reference'    => $reference,
            'callback_url' => $this->return_url($transaction),
            'metadata'     => array(
                'user_id'                => (string)$user->public_id,
                'payment_transaction_id' => (string)$transaction->id,
                'internal_reference'     => $reference,
            ),
        ), array('Authorization: Bearer '.$cfg['secret_key']));

        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $url = $res['body']['data']['authorization_url'] ?? null;
        if (!$url) {
            log_message('error', 'paystack: initialize returned no authorization_url');
            return $this->fail('PROVIDER_ERROR', 'Paystack did not return a checkout link. Try again shortly.');
        }

        return array(
            'ok'              => true,
            'status'          => 'PENDING',
            'redirect_url'    => $url,
            'provider_tx_id'  => $reference,
            'checkout'        => array(
                'provider'     => 'paystack',
                'method'       => 'card',
                'reference'    => $reference,
                'access_code'  => $res['body']['data']['access_code'] ?? null,
                'amount'       => (string)$transaction->amount,
                'currency'     => $currency,
                'instructions' => 'Complete the payment on Paystack. Your wallet is credited automatically once '
                                  .'Paystack confirms it — do not send the money twice if the page is slow to return.',
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        if (empty($cfg['secret_key'])) return null; // unverifiable: store, never credit
        $signature = $this->header($headers, self::SIGNATURE_HEADER);
        if ($signature === '') return false;
        return hash_equals(hash_hmac('sha512', (string)$raw_body, $cfg['secret_key']), $signature);
    }

    public function parse_event($raw_body) {
        $data  = json_decode((string)$raw_body, true);
        $data  = is_array($data) ? $data : array();
        $event = (string)($data['event'] ?? '');
        $tx    = isset($data['data']) && is_array($data['data']) ? $data['data'] : array();

        // Paystack's own `status` is authoritative; the event name alone would
        // credit a `charge.success` carrying status=failed.
        $status = $this->normalise_status($tx['status'] ?? '', array('success'), array('failed', 'abandoned', 'reversed'));
        if ($event !== '' && strpos($event, 'charge.success') !== 0 && $status === 'SUCCESS') {
            $status = 'PENDING';
        }

        $reference = (string)($tx['reference'] ?? '');
        return array(
            // Paystack's numeric transaction id is unique per charge and is
            // what makes a retry idempotent.
            'event_id'       => (string)($tx['id'] ?? ($reference.':'.$event)),
            'type'           => strtolower(str_replace('.', '_', $event ?: 'charge_success')),
            'provider_tx_id' => $reference,
            'status'         => $status,
            'amount'         => isset($tx['amount']) ? (string)((float)$tx['amount'] / 100) : null,
            'currency'       => isset($tx['currency']) ? strtoupper((string)$tx['currency']) : null,
            'metadata'       => is_array($tx['metadata'] ?? null) ? $tx['metadata'] : array(),
        );
    }

    /**
     * Ask Paystack directly what happened to a reference.
     *
     * Used by staff and by reconciliation when a webhook never arrived.
     */
    public function verify($reference) {
        $cfg = $this->config();
        if (!$this->is_configured()) return $this->not_configured();

        $res = $this->get_json($cfg['base_url'].'/transaction/verify/'.rawurlencode($reference),
            array('Authorization: Bearer '.$cfg['secret_key']));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $tx = $res['body']['data'] ?? array();
        return array(
            'ok'             => true,
            'status'         => $this->normalise_status($tx['status'] ?? '', array('success'), array('failed', 'abandoned', 'reversed')),
            'provider_tx_id' => (string)($tx['reference'] ?? $reference),
            'amount'         => isset($tx['amount']) ? (string)((float)$tx['amount'] / 100) : null,
            'currency'       => isset($tx['currency']) ? strtoupper((string)$tx['currency']) : null,
        );
    }
}
