<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * FlutterwaveGateway — Flutterwave Standard hosted checkout (v3).
 *
 * Documented API (https://developer.flutterwave.com):
 *
 *   POST /v3/payments                          — returns data.link
 *   GET  /v3/transactions/verify_by_reference  — authoritative status
 *   webhook: the secret hash an operator sets in the dashboard, sent verbatim
 *            in the `verif-hash` header (Flutterwave does not HMAC the body)
 *
 * Because `verif-hash` is a shared secret rather than a signature, the amount
 * on the callback is never trusted on its own: PaymentService credits the
 * amount stored on our own transaction row, and `verify()` can re-ask
 * Flutterwave for the authoritative figure.
 */
class FlutterwaveGateway extends HostedGateway {

    const DEFAULT_BASE_URL = 'https://api.flutterwave.com/v3';
    const SIGNATURE_HEADER = 'verif-hash';

    public function code() { return 'flutterwave'; }

    public function config() {
        return array(
            'enabled'     => $this->flag('FLUTTERWAVE_ENABLED', 'flutterwave_enabled', true),
            'base_url'    => rtrim($this->secret('FLUTTERWAVE_BASE_URL', 'flutterwave_base_url') ?: self::DEFAULT_BASE_URL, '/'),
            'secret_key'  => $this->secret('FLUTTERWAVE_SECRET_KEY', 'flutterwave_secret_key'),
            'public_key'  => $this->secret('FLUTTERWAVE_PUBLIC_KEY', 'flutterwave_public_key'),
            'secret_hash' => $this->secret('FLUTTERWAVE_SECRET_HASH', 'flutterwave_secret_hash'),
        );
    }

    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['secret_key']);
    }

    public function initiate($transaction, $user) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'This payment method is currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $reference = $this->reference($transaction);
        $name = trim((string)($user->first_name ?? '').' '.(string)($user->last_name ?? ''));

        $res = $this->post_json($cfg['base_url'].'/payments', array(
            'tx_ref'         => $reference,
            // Flutterwave takes the major unit (naira, not kobo).
            'amount'         => (string)round((float)$transaction->amount, 2),
            'currency'       => strtoupper((string)$transaction->currency),
            'redirect_url'   => $this->return_url($transaction),
            'payment_options'=> 'card,banktransfer,ussd',
            'customer'       => array(
                'email'       => (string)$user->email,
                'name'        => $name !== '' ? $name : (string)$user->username,
                'phonenumber' => (string)($user->phone ?? ''),
            ),
            'meta' => array(
                'user_id'                => (string)$user->public_id,
                'payment_transaction_id' => (string)$transaction->id,
                'internal_reference'     => $reference,
            ),
            'customizations' => array(
                'title'       => function_exists('marvy_site_name') ? marvy_site_name() : 'Wallet deposit',
                'description' => 'Wallet deposit',
            ),
        ), array('Authorization: Bearer '.$cfg['secret_key']));

        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $link = $res['body']['data']['link'] ?? null;
        if (!$link) {
            log_message('error', 'flutterwave: /payments returned no link');
            return $this->fail('PROVIDER_ERROR', 'Flutterwave did not return a checkout link. Try again shortly.');
        }

        return array(
            'ok'             => true,
            'status'         => 'PENDING',
            'redirect_url'   => $link,
            'provider_tx_id' => $reference,
            'checkout'       => array(
                'provider'     => 'flutterwave',
                'method'       => 'card',
                'reference'    => $reference,
                'amount'       => (string)$transaction->amount,
                'currency'     => strtoupper((string)$transaction->currency),
                'instructions' => 'Complete the payment on Flutterwave. Your wallet is credited automatically '
                                  .'once the payment is confirmed.',
            ),
        );
    }

    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        // No hash configured means we cannot tell a real callback from a forged
        // one: store the event for the operator, credit nothing.
        if (empty($cfg['secret_hash'])) return null;
        $sent = $this->header($headers, self::SIGNATURE_HEADER);
        if ($sent === '') return false;
        return hash_equals((string)$cfg['secret_hash'], $sent);
    }

    public function parse_event($raw_body) {
        $body = json_decode((string)$raw_body, true);
        $body = is_array($body) ? $body : array();

        // v3 webhooks nest the charge under `data`; the older shape puts the
        // fields at the top level. Both are accepted.
        $data  = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;
        $event = (string)($body['event'] ?? ($body['event.type'] ?? 'charge.completed'));

        $status = $this->normalise_status($data['status'] ?? '', array('successful', 'success'),
            array('failed', 'cancelled', 'error'));

        $reference = (string)($data['tx_ref'] ?? ($data['txRef'] ?? ''));
        return array(
            'event_id'       => (string)($data['id'] ?? ($reference.':'.$event)),
            'type'           => strtolower(str_replace('.', '_', $event)),
            'provider_tx_id' => $reference,
            'status'         => $status,
            'amount'         => isset($data['amount']) ? (string)$data['amount'] : null,
            'currency'       => isset($data['currency']) ? strtoupper((string)$data['currency']) : null,
            'metadata'       => is_array($data['meta'] ?? null) ? $data['meta'] : array(),
        );
    }

    /** Authoritative status straight from Flutterwave, by our own reference. */
    public function verify($reference) {
        $cfg = $this->config();
        if (!$this->is_configured()) return $this->not_configured();

        $res = $this->get_json($cfg['base_url'].'/transactions/verify_by_reference?tx_ref='.rawurlencode($reference),
            array('Authorization: Bearer '.$cfg['secret_key']));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $data = $res['body']['data'] ?? array();
        return array(
            'ok'             => true,
            'status'         => $this->normalise_status($data['status'] ?? '', array('successful', 'success'),
                                    array('failed', 'cancelled', 'error')),
            'provider_tx_id' => (string)($data['tx_ref'] ?? $reference),
            'amount'         => isset($data['amount']) ? (string)$data['amount'] : null,
            'currency'       => isset($data['currency']) ? strtoupper((string)$data['currency']) : null,
        );
    }
}
