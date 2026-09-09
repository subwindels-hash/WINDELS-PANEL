<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PaypalGateway — PayPal Orders v2 (hosted approval flow).
 *
 * Documented API (https://developer.paypal.com/docs/api/orders/v2/):
 *
 *   POST /v1/oauth2/token                              — client-credentials
 *   POST /v2/checkout/orders                           — returns the approve link
 *   GET  /v2/checkout/orders/:id                       — authoritative status
 *   POST /v1/notifications/verify-webhook-signature    — webhook verification
 *
 * PayPal does not hand out a shared secret to HMAC against; verification is an
 * API call that PayPal itself answers. That means a webhook can only be
 * trusted while PayPal is reachable — when the verification call fails we
 * return null ("cannot verify"), which stores the event without moving money,
 * rather than false (which would discard a real payment) or true (which would
 * credit an unverified one).
 */
class PaypalGateway extends HostedGateway {

    const LIVE_BASE_URL    = 'https://api-m.paypal.com';
    const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    public function code() { return 'paypal'; }

    public function config() {
        $sandbox = $this->flag('PAYPAL_SANDBOX', 'paypal_sandbox', false);
        return array(
            'enabled'        => $this->flag('PAYPAL_ENABLED', 'paypal_enabled', true),
            'sandbox'        => $sandbox,
            'base_url'       => rtrim($this->secret('PAYPAL_BASE_URL', 'paypal_base_url')
                                      ?: ($sandbox ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL), '/'),
            'client_id'      => $this->secret('PAYPAL_CLIENT_ID', 'paypal_client_id'),
            'client_secret'  => $this->secret('PAYPAL_CLIENT_SECRET', 'paypal_client_secret'),
            'webhook_id'     => $this->secret('PAYPAL_WEBHOOK_ID', 'paypal_webhook_id'),
        );
    }

    public function is_configured() {
        $cfg = $this->config();
        return !empty($cfg['client_id']) && !empty($cfg['client_secret']);
    }

    public function initiate($transaction, $user) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'PayPal is currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $token = $this->access_token($cfg);
        if (empty($token['ok'])) return $this->fail('PROVIDER_ERROR', $token['error']);

        $reference = $this->reference($transaction);
        $res = $this->post_json($cfg['base_url'].'/v2/checkout/orders', array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(array(
                'reference_id' => $reference,
                'custom_id'    => $reference,
                'description'  => 'Wallet deposit',
                'amount'       => array(
                    'currency_code' => strtoupper((string)$transaction->currency),
                    'value'         => number_format((float)$transaction->amount, 2, '.', ''),
                ),
            )),
            'application_context' => array(
                'brand_name'  => function_exists('marvy_site_name') ? marvy_site_name() : 'Wallet deposit',
                'user_action' => 'PAY_NOW',
                'return_url'  => $this->return_url($transaction).'?paid=1',
                'cancel_url'  => $this->return_url($transaction).'?cancelled=1',
            ),
        ), array('Authorization: Bearer '.$token['token']));

        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $approve = null;
        foreach (($res['body']['links'] ?? array()) as $link) {
            if (($link['rel'] ?? '') === 'approve' || ($link['rel'] ?? '') === 'payer-action') {
                $approve = $link['href'] ?? null;
                break;
            }
        }
        if (!$approve) {
            log_message('error', 'paypal: order created without an approve link');
            return $this->fail('PROVIDER_ERROR', 'PayPal did not return an approval link. Try again shortly.');
        }

        return array(
            'ok'             => true,
            'status'         => 'PENDING',
            'redirect_url'   => $approve,
            'provider_tx_id' => $reference,
            'checkout'       => array(
                'provider'     => 'paypal',
                'method'       => 'paypal',
                'reference'    => $reference,
                'order_id'     => $res['body']['id'] ?? null,
                'amount'       => (string)$transaction->amount,
                'currency'     => strtoupper((string)$transaction->currency),
                'instructions' => 'Approve the payment in PayPal. Your wallet is credited automatically once '
                                  .'PayPal confirms the capture.',
            ),
        );
    }

    /**
     * PayPal verifies its own webhooks: we hand back the headers it sent and
     * it answers SUCCESS or FAILURE.
     */
    public function verify_webhook($raw_body, array $headers) {
        $cfg = $this->config();
        if (empty($cfg['webhook_id']) || !$this->is_configured()) return null;

        $required = array('paypal-transmission-id', 'paypal-transmission-time',
                          'paypal-transmission-sig', 'paypal-cert-url', 'paypal-auth-algo');
        $sent = array();
        foreach ($required as $name) {
            $value = $this->header($headers, $name);
            if ($value === '') return false; // a real PayPal callback always carries all five
            $sent[$name] = $value;
        }

        $token = $this->access_token($cfg);
        if (empty($token['ok'])) return null; // cannot verify right now — do not discard

        $body = json_decode((string)$raw_body, true);
        $res = $this->post_json($cfg['base_url'].'/v1/notifications/verify-webhook-signature', array(
            'transmission_id'   => $sent['paypal-transmission-id'],
            'transmission_time' => $sent['paypal-transmission-time'],
            'cert_url'          => $sent['paypal-cert-url'],
            'auth_algo'         => $sent['paypal-auth-algo'],
            'transmission_sig'  => $sent['paypal-transmission-sig'],
            'webhook_id'        => $cfg['webhook_id'],
            'webhook_event'     => is_array($body) ? $body : array(),
        ), array('Authorization: Bearer '.$token['token']));

        if (empty($res['ok'])) return null;
        return ($res['body']['verification_status'] ?? '') === 'SUCCESS';
    }

    public function parse_event($raw_body) {
        $body = json_decode((string)$raw_body, true);
        $body = is_array($body) ? $body : array();
        $type = (string)($body['event_type'] ?? '');
        $res  = isset($body['resource']) && is_array($body['resource']) ? $body['resource'] : array();

        $success = in_array($type, array('PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED'), true)
            && strtoupper((string)($res['status'] ?? 'COMPLETED')) !== 'DECLINED';
        $failed = in_array($type, array('PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REVERSED',
                                        'PAYMENT.CAPTURE.REFUNDED', 'CHECKOUT.ORDER.VOIDED'), true);

        // custom_id on the capture, reference_id on the order — whichever the
        // event carries is our own reference.
        $reference = (string)($res['custom_id']
            ?? ($res['purchase_units'][0]['custom_id']
            ?? ($res['purchase_units'][0]['reference_id'] ?? '')));

        $amount = $res['amount']['value'] ?? ($res['purchase_units'][0]['amount']['value'] ?? null);
        $currency = $res['amount']['currency_code'] ?? ($res['purchase_units'][0]['amount']['currency_code'] ?? null);

        return array(
            'event_id'       => (string)($body['id'] ?? ($reference.':'.$type)),
            'type'           => strtolower(str_replace('.', '_', $type ?: 'payment_capture_completed')),
            'provider_tx_id' => $reference,
            'status'         => $failed ? 'FAILED' : ($success ? 'SUCCESS' : 'PENDING'),
            'amount'         => $amount !== null ? (string)$amount : null,
            'currency'       => $currency !== null ? strtoupper((string)$currency) : null,
            'metadata'       => array(),
        );
    }

    /** @var array{token:string,expires:int}|null token cached for this adapter instance */
    private $token_cache = null;

    /**
     * OAuth2 client-credentials token.
     *
     * Cached on the instance, not statically: a static cache would outlive a
     * credential rotation inside the same process and, worse, make the second
     * PayPal call of a request silently reuse a token issued for different
     * credentials.
     */
    private function access_token(array $cfg) {
        if ($this->token_cache !== null && $this->token_cache['expires'] > time() + 30) {
            return array('ok' => true, 'token' => $this->token_cache['token']);
        }

        $res = $this->post_form($cfg['base_url'].'/v1/oauth2/token',
            array('grant_type' => 'client_credentials'),
            array('Authorization: Basic '.base64_encode($cfg['client_id'].':'.$cfg['client_secret'])));

        if (empty($res['ok']) || empty($res['body']['access_token'])) {
            return array('ok' => false, 'error' => $res['error'] ?: 'PayPal refused the API credentials.');
        }
        $this->token_cache = array(
            'token'   => (string)$res['body']['access_token'],
            'expires' => time() + (int)($res['body']['expires_in'] ?? 300),
        );
        return array('ok' => true, 'token' => $this->token_cache['token']);
    }
}
