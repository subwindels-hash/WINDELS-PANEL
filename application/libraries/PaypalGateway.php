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

    /**
     * Currencies PayPal can actually settle for a merchant account.
     *
     * PayPal does not settle NGN (the panel's base currency): an order created
     * in naira is refused at capture time — after the customer has already
     * approved it — which is the worst possible moment to fail. Refusing at
     * initiation, before any provider call, is the honest behaviour; the list
     * is PayPal's own documented set of supported currencies.
     */
    const SETTLEABLE_CURRENCIES = array(
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF',
        'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD',
        'THB', 'TWD', 'USD',
    );

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

        $currency = strtoupper(trim((string)$transaction->currency));
        if (!in_array($currency, self::SETTLEABLE_CURRENCIES, true)) {
            // Refused before a single provider call: nothing has been asked of
            // PayPal, so nothing can be half-created there.
            return $this->fail('CURRENCY_UNSUPPORTED',
                'PayPal cannot settle '.($currency ?: 'that currency').'. Pay with a card or '
                .'bank-transfer method, or choose a PayPal-supported currency such as USD.');
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

        $success = in_array($type, array('PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.COMPLETED'), true)
            && strtoupper((string)($res['status'] ?? 'COMPLETED')) !== 'DECLINED';
        // CHECKOUT.ORDER.APPROVED is deliberately NOT a success: an approval is
        // the customer's signature, not a payment — the money only moves when
        // the order is captured (see capture()/verify()). Crediting on the
        // approval event would hand out wallet balance for money PayPal never
        // took. It parses as PENDING and the capture path settles it.
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
     * Capture an approved order — the step that actually moves the money.
     *
     * PayPal's hosted flow is two-phase: the customer APPROVES the order on
     * PayPal's site, but the charge only happens when we capture it. The
     * approval webhook deliberately credits nothing; the capture — triggered
     * here from the customer's return (PaymentService::settle_hosted_return)
     * or by verify() during reconciliation — is what completes the payment.
     *
     * PayPal-Request-Id is the documented idempotency key: a retried capture
     * with the same request id is safe, so a network blip mid-capture cannot
     * double-charge the customer.
     *
     * @return array{ok:bool, status?:string, provider_tx_id?:string, amount?:string,
     *               currency?:string, error?:string, code?:string}
     */
    public function capture($order_id) {
        $order_id = trim((string)$order_id);
        if ($order_id === '') return $this->fail('BAD_ORDER', 'No PayPal order to capture.');

        $cfg = $this->config();
        if (empty($cfg['enabled']))  return $this->fail('PROVIDER_DISABLED', 'PayPal is currently unavailable.');
        if (!$this->is_configured()) return $this->not_configured();

        $token = $this->access_token($cfg);
        if (empty($token['ok'])) return $this->fail('PROVIDER_ERROR', $token['error']);

        $res = $this->post_json($cfg['base_url'].'/v2/checkout/orders/'.rawurlencode($order_id).'/capture', array(), array(
            'Authorization: Bearer '.$token['token'],
            'PayPal-Request-Id: capture-'.$order_id,
            'Prefer: return=representation',
        ));
        if (empty($res['ok'])) {
            return $this->fail('CAPTURE_FAILED',
                $res['error'] ?: 'PayPal refused the capture. Try again shortly.');
        }
        return $this->order_verdict($res['body']);
    }

    /**
     * Ask PayPal what happened to one order (by PayPal's own order id).
     *
     * Unlike the other hosted gateways, PayPal never finishes a charge on its
     * own: an APPROVED order still has to be captured by us. So verify() is
     * not a passive status read — when it finds an approved order it captures
     * it, which is what lets the reconciliation sweep settle a PayPal deposit
     * whose return never landed.
     *
     * @param string $order_id PayPal's own order id (metadata.checkout.order_id)
     * @return array{ok:bool, status?:string, provider_tx_id?:string, amount?:string,
     *               currency?:string, error?:string}
     *         status is SUCCESS | FAILED | PENDING
     */
    public function verify($order_id) {
        $order_id = trim((string)$order_id);
        if ($order_id === '') return array('ok' => false, 'error' => 'No PayPal order to verify.');

        $cfg = $this->config();
        if (!$this->is_configured()) {
            return array('ok' => false, 'unsupported' => true, 'error' => 'PayPal is not configured');
        }

        $token = $this->access_token($cfg);
        if (empty($token['ok'])) return array('ok' => false, 'error' => $token['error']);

        $res = $this->get_json($cfg['base_url'].'/v2/checkout/orders/'.rawurlencode($order_id),
            array('Authorization: Bearer '.$token['token']));
        if (empty($res['ok'])) {
            // Transport/HTTP failure is an outage, not a verdict: the caller
            // must treat it as "ask again later", never as failed.
            return array('ok' => false, 'error' => $res['error'] ?: 'Could not reach PayPal.');
        }

        $status = strtoupper((string)($res['body']['status'] ?? ''));
        if ($status === 'APPROVED') {
            // The buyer signed; the capture is our step, so verify finishes it.
            $capture = $this->capture($order_id);
            if (empty($capture['ok'])) {
                // The order exists and is approved — a capture hiccup is not a
                // failure verdict. Report pending so the caller retries.
                return array('ok' => true, 'status' => 'PENDING',
                    'provider_tx_id' => (string)($res['body']['purchase_units'][0]['reference_id']
                        ?? $res['body']['purchase_units'][0]['custom_id'] ?? null),
                    'detail' => 'APPROVED (capture pending)');
            }
            return $capture;
        }

        return $this->order_verdict($res['body']);
    }

    /**
     * Normalise an order body (from GET or from the capture response, which
     * with `Prefer: return=representation` is the full order) into the verdict
     * shape every adapter's verify() answers with.
     */
    private function order_verdict(array $body) {
        $units = isset($body['purchase_units'][0]) && is_array($body['purchase_units'][0])
            ? $body['purchase_units'][0] : array();
        $captures = isset($units['payments']['captures'][0]) && is_array($units['payments']['captures'][0])
            ? $units['payments']['captures'][0] : array();

        $status = strtoupper((string)($body['status'] ?? ''));
        // COMPLETED = captured (paid). VOIDED is the terminal failure. CREATED
        // and anything unknown is still in flight — never guess a failure.
        $verdict = $status === 'COMPLETED' ? 'SUCCESS'
            : ($status === 'VOIDED' ? 'FAILED' : 'PENDING');

        return array(
            'ok'             => true,
            'status'         => $verdict,
            // custom_id is OUR reference — the value that matches the deposit.
            'provider_tx_id' => (string)($captures['custom_id']
                ?? ($units['custom_id'] ?? ($units['reference_id'] ?? ''))) ?: null,
            'amount'         => isset($captures['amount']['value'])
                ? (string)$captures['amount']['value']
                : (isset($units['amount']['value']) ? (string)$units['amount']['value'] : null),
            'currency'       => isset($captures['amount']['currency_code'])
                ? strtoupper((string)$captures['amount']['currency_code'])
                : (isset($units['amount']['currency_code']) ? strtoupper((string)$units['amount']['currency_code']) : null),
            'detail'         => $status ?: null,
        );
    }

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
