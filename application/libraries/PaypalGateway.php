<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PaypalGateway — PayPal Orders v2 (hosted approval flow).
 *
 * Documented API (https://developer.paypal.com/docs/api/orders/v2/):
 *
 *   POST /v1/oauth2/token                              — client-credentials
 *   POST /v2/checkout/orders                           — returns the approve link
 *   POST /v2/checkout/orders/:id/capture               — takes the money
 *   GET  /v2/checkout/orders/:id                       — authoritative status
 *   POST /v1/notifications/verify-webhook-signature    — webhook verification
 *
 * The flow the docs prescribe for the redirect integration is: create the
 * order, send the buyer to the approve link, CAPTURE the order once they
 * return. Approval is a signature, not a payment — PayPal charges the
 * buyer's instrument only at capture, which is why this adapter carries an
 * explicit capture() (used on the customer's return and from
 * reconciliation) and why CHECKOUT.ORDER.APPROVED webhooks never credit a
 * wallet. PAYMENT.CAPTURE.COMPLETED is the event that means money moved.
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

    /**
     * Currencies PayPal settles (developer.paypal.com currency codes). The
     * panel's base currency NGN is not among them, so an order in naira can
     * only ever die at PayPal with an opaque INVALID_CURRENCY_CODE — refused
     * up front instead, with the list, before a customer reaches PayPal.
     */
    const SUPPORTED = array('AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK',
                            'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN',
                            'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RUB', 'SEK',
                            'SGD', 'THB', 'TWD', 'USD');

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

        $currency = strtoupper((string)$transaction->currency);
        if (!in_array($currency, self::SUPPORTED, true)) {
            return $this->fail('CURRENCY_UNSUPPORTED',
                'PayPal does not settle '.$currency.'. Supported: '.implode(', ', self::SUPPORTED).'.');
        }

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
                    'currency_code' => $currency,
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
     * Capture an approved order (POST /v2/checkout/orders/{id}/capture).
     *
     * Approval is not payment: PayPal charges the buyer's instrument - and
     * only then fires PAYMENT.CAPTURE.COMPLETED - when the merchant captures.
     * The PayPal-Request-Id is derived from the order id, so re-running the
     * same capture (a customer refreshing the return page, the reconciliation
     * sweep racing the webhook) is idempotent at PayPal rather than a second
     * charge.
     *
     * @return array{ok:bool, status?:string, provider_tx_id?:string, amount?:?string, currency?:?string, detail?:?string, error?:string}
     */
    public function capture($order_id) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'PayPal is currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $order_id = $this->order_id($order_id);
        if ($order_id === null) {
            return $this->fail('BAD_ORDER', 'No PayPal order to capture.');
        }

        $token = $this->access_token($cfg);
        if (empty($token['ok'])) return $this->fail('PROVIDER_ERROR', $token['error']);

        $res = $this->post_json($cfg['base_url'].'/v2/checkout/orders/'.rawurlencode($order_id).'/capture',
            array(), // the docs' own cURL sample posts an empty JSON object
            array('Authorization: Bearer '.$token['token'],
                  'PayPal-Request-Id: mvs-capture-'.$order_id));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        return $this->order_result($res['body'], $order_id);
    }

    /**
     * Ask PayPal what happened to an order (GET /v2/checkout/orders/{id}).
     *
     * Used by staff and by reconciliation when the return visit and the
     * webhook both missed - the docs' own advice for a missed capture webhook
     * is to poll this endpoint. An APPROVED order is captured here: the buyer
     * finished their part of the flow, and reconciliation exists to finish
     * ours when the return request never landed.
     */
    public function verify($order_id) {
        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'PayPal is currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $order_id = $this->order_id($order_id);
        if ($order_id === null) {
            return $this->fail('BAD_ORDER', 'No PayPal order to look up.');
        }

        $token = $this->access_token($cfg);
        if (empty($token['ok'])) return $this->fail('PROVIDER_ERROR', $token['error']);

        $res = $this->get_json($cfg['base_url'].'/v2/checkout/orders/'.rawurlencode($order_id),
            array('Authorization: Bearer '.$token['token']));
        if (empty($res['ok'])) return $this->fail('PROVIDER_ERROR', $res['error']);

        $body = is_array($res['body']) ? $res['body'] : array();
        if (strtoupper((string)($body['status'] ?? '')) === 'APPROVED') {
            $cap = $this->capture($order_id); // token is cached, so no re-auth
            if (empty($cap['ok'])) {
                // A blip here must not close a deposit: reconciliation retries
                // on the next tick, and a genuinely declined instrument keeps
                // the order APPROVED, still answerable next time.
                return array('ok' => true, 'status' => 'PENDING', 'provider_tx_id' => null,
                             'amount' => null, 'currency' => null,
                             'detail' => 'capture failed: '.($cap['error'] ?? 'unknown'));
            }
            return $cap;
        }

        return $this->order_result($body, $order_id);
    }

    /** Validate PayPal's order-id shape ([A-Z0-9], 1..36 chars) or give up. */
    private function order_id($raw) {
        $id = strtoupper(trim((string)$raw));
        if ($id === '' || !preg_match('/^[A-Z0-9]{1,36}$/', $id)) return null;
        return $id;
    }

    /**
     * Read the money and the outcome out of an order (or capture) response.
     *
     * COMPLETED means PayPal holds the money; VOIDED and a DECLINED capture
     * are the terminal failures; CREATED/PAYER_ACTION_REQUIRED and friends
     * are still in flight.
     */
    private function order_result(array $body, $order_id) {
        $status = strtoupper((string)($body['status'] ?? ''));
        $unit   = isset($body['purchase_units'][0]) && is_array($body['purchase_units'][0])
            ? $body['purchase_units'][0] : array();
        $capture = isset($unit['payments']['captures'][0]) && is_array($unit['payments']['captures'][0])
            ? $unit['payments']['captures'][0] : array();

        // The reference WE sent (custom_id) is what PaymentService matches the
        // deposit on; the fallbacks keep a bare capture response useful too.
        $reference = (string)($capture['custom_id']
            ?? ($unit['custom_id']
            ?? ($unit['reference_id'] ?? $order_id)));

        $amount   = $capture['amount']['value']         ?? ($unit['amount']['value'] ?? null);
        $currency = $capture['amount']['currency_code'] ?? ($unit['amount']['currency_code'] ?? null);
        $capture_status = strtoupper((string)($capture['status'] ?? ''));

        $state = 'PENDING';
        if ($status === 'VOIDED' || $capture_status === 'DECLINED') $state = 'FAILED';
        elseif ($status === 'COMPLETED' && ($capture_status === 'COMPLETED' || $capture_status === '')) $state = 'SUCCESS';

        return array(
            'ok'             => true,
            'status'         => $state,
            'provider_tx_id' => $reference,
            'amount'         => $amount !== null ? (string)$amount : null,
            'currency'       => $currency !== null ? strtoupper((string)$currency) : null,
            'detail'         => $status !== '' ? $status : null,
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

        // Money has moved only when PayPal has CAPTURED. CHECKOUT.ORDER.APPROVED
        // is deliberately absent from the success list: an approval is the
        // customer's signature, not the payment - PayPal charges the instrument
        // only when our server captures the order. Crediting on approval hands
        // out wallet balance for a payment that never happened. The flow the
        // docs prescribe is approve -> capture -> PAYMENT.CAPTURE.COMPLETED.
        $success = in_array($type, array('PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.COMPLETED'), true)
            && strtoupper((string)($res['status'] ?? 'COMPLETED')) !== 'DECLINED';
        $failed = in_array($type, array('PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REVERSED',
                                        'PAYMENT.CAPTURE.REFUNDED', 'CHECKOUT.ORDER.VOIDED',
                                        'CHECKOUT.ORDER.DECLINED'), true);

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
