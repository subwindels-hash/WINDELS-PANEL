<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * HostedGateway — shared machinery for the hosted-checkout payment adapters.
 *
 * Every gateway in this panel follows the same shape:
 *
 *   1. `initiate()` calls the provider's own API and returns the URL the
 *      provider gave us. It never invents a URL — a fabricated checkout link
 *      sends a paying customer to a dead page, which is worse than refusing
 *      the deposit.
 *   2. `verify_webhook()` verifies the provider's signature over the RAW body.
 *   3. `parse_event()` normalises the callback into the shape PaymentService
 *      reconciles against, echoing back OUR reference so the deposit can be
 *      matched even if the provider renames its own ids.
 *
 * Credentials come from the environment first (containers inject them there)
 * and the settings table second (a cPanel operator with no shell can still
 * finish the configuration in Admin → Settings). Nothing is ever hardcoded,
 * and an unconfigured gateway fails closed with CONFIG_MISSING rather than
 * pretending to work.
 */
abstract class HostedGateway implements GatewayInterface {

    /** @var object CI instance */
    protected $ci;

    /** @var object|null the payment_methods row this adapter was built for */
    protected $method;

    public function __construct($method_row = null) {
        $this->ci =& get_instance();
        $this->method = $method_row;
    }

    /* ------------------------------------------------------------------ */
    /* Contract each adapter fills in                                      */
    /* ------------------------------------------------------------------ */

    /** Lower-case gateway code, e.g. 'paystack'. */
    abstract public function code();

    /** Credentials and behaviour for this gateway. */
    abstract public function config();

    /** Whether a payment can actually be taken right now. */
    abstract public function is_configured();

    /* ------------------------------------------------------------------ */
    /* Shared helpers                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * The reference WE own for a transaction.
     *
     * Stored on the transaction as `internal_reference` at creation, so it is
     * the one value support can search on regardless of gateway. It is sent to
     * the provider and echoed back on the callback, which is how a webhook
     * finds its deposit.
     */
    protected function reference($transaction) {
        $ref = isset($transaction->internal_reference) ? trim((string)$transaction->internal_reference) : '';
        if ($ref === '') $ref = 'MVS-'.strtoupper((string)$transaction->public_id);
        return $ref;
    }

    /** Where the provider sends the customer once they are done. */
    protected function return_url($transaction = null) {
        $url = $this->secret(strtoupper($this->code()).'_RETURN_URL', $this->code().'_return_url');
        if ($url) return $url;
        return site_url('dashboard/wallet/deposits'.($transaction ? '/'.$transaction->public_id : ''));
    }

    /** The callback URL an operator must configure with the provider. */
    public function webhook_url() {
        return site_url('webhook/'.$this->code());
    }

    /** Minor units (kobo/cents) as an integer, for gateways that want them. */
    protected function minor_units($amount, $currency = null) {
        $zero_decimal = array('JPY', 'KRW', 'VND', 'CLP', 'XOF', 'XAF');
        if ($currency !== null && in_array(strtoupper((string)$currency), $zero_decimal, true)) {
            return (int)round((float)$amount);
        }
        return (int)round((float)$amount * 100);
    }

    /** A failed result in the shape PaymentService expects. */
    protected function fail($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }

    /** The "not set up yet" refusal every adapter shares. */
    protected function not_configured() {
        return $this->fail('CONFIG_MISSING',
            ucfirst($this->code()).' payments are not configured yet. Add the API credentials in '
            .'Admin → Settings before enabling this method.');
    }

    /**
     * A secret from the environment, falling back to the settings table.
     *
     * Environment wins: a containerised deployment injects credentials that
     * way and must not be silently overridden by a row somebody edited.
     */
    protected function secret($env_key, $setting_key) {
        $env = getenv($env_key);
        if ($env !== false && trim((string)$env) !== '') return trim((string)$env);

        try {
            $this->ci->load->model('Setting_model');
            $value = $this->ci->Setting_model->get($setting_key);
            if ($value !== null && $value !== '') return (string)$value;
        } catch (Throwable $e) { /* settings unavailable — treat as unset */ }

        return null;
    }

    /** A boolean setting with a documented default. */
    protected function flag($env_key, $setting_key, $default) {
        $raw = $this->secret($env_key, $setting_key);
        if ($raw === null) return $default;
        return in_array(strtolower(trim((string)$raw)), array('1', 'true', 'yes', 'on'), true);
    }

    /* ------------------------------------------------------------------ */
    /* HTTP                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * POST JSON and decode the answer.
     *
     * @return array{ok:bool, status:int, body:array, error:?string}
     */
    protected function post_json($url, array $payload, array $headers = array()) {
        return $this->send('POST', $url, json_encode($payload, JSON_UNESCAPED_SLASHES),
            array_merge(array('Content-Type: application/json', 'Accept: application/json'), $headers));
    }

    /** POST an application/x-www-form-urlencoded body (Stripe, CoinPayments). */
    protected function post_form($url, array $payload, array $headers = array()) {
        return $this->send('POST', $url, http_build_query($payload),
            array_merge(array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'), $headers));
    }

    /** GET JSON. */
    protected function get_json($url, array $headers = array()) {
        return $this->send('GET', $url, null, array_merge(array('Accept: application/json'), $headers));
    }

    private function send($method, $url, $body, array $headers) {
        $this->ci->load->library('SecureHttpClient');

        $res = $method === 'GET'
            ? $this->ci->securehttpclient->get($url, $headers)
            : $this->ci->securehttpclient->post($url, $body, $headers);

        $status = (int)($res['http_code'] ?? 0);
        $decoded = json_decode((string)($res['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : array();

        if ($status === 0) {
            log_message('error', $this->code().': '.$method.' '.$url.' unreachable: '.($res['error'] ?? 'unknown'));
            return array('ok' => false, 'status' => 0, 'body' => $decoded,
                'error' => 'Could not reach the payment provider. Try again shortly.');
        }
        if ($status < 200 || $status >= 300) {
            // Surface the provider's own message where there is one: "amount
            // is below the minimum" is actionable, a generic error is not.
            $message = $this->provider_message($decoded) ?: ('The payment provider rejected the request (HTTP '.$status.').');
            log_message('error', $this->code().': '.$method.' '.$url.' http='.$status.' msg='.$message);
            return array('ok' => false, 'status' => $status, 'body' => $decoded, 'error' => $message);
        }

        return array('ok' => true, 'status' => $status, 'body' => $decoded, 'error' => null);
    }

    /** Best-effort human message out of a provider error body. */
    protected function provider_message(array $body) {
        foreach (array('message', 'error_description', 'error', 'detail') as $key) {
            if (!empty($body[$key]) && is_string($body[$key])) return $body[$key];
        }
        if (!empty($body['error']['message']) && is_string($body['error']['message'])) {
            return $body['error']['message'];
        }
        if (!empty($body['errors'][0]['message']) && is_string($body['errors'][0]['message'])) {
            return $body['errors'][0]['message'];
        }
        return null;
    }

    /**
     * Case-insensitive header lookup.
     *
     * Header casing depends on the SAPI: `getallheaders()` yields
     * `X-Paystack-Signature` under Apache and `x-paystack-signature` under
     * nginx/FPM, and a signature check that misses the header fails closed —
     * i.e. silently drops real payments.
     */
    protected function header(array $headers, $name) {
        $needle = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === $needle) {
                return is_array($value) ? reset($value) : (string)$value;
            }
        }
        return '';
    }

    /** Normalised status from a provider's own vocabulary. */
    protected function normalise_status($raw, array $success, array $failed = array()) {
        $value = strtolower(trim((string)$raw));
        if (in_array($value, array_map('strtolower', $success), true)) return 'SUCCESS';
        if (in_array($value, array_map('strtolower', $failed), true))  return 'FAILED';
        return 'PENDING';
    }
}
