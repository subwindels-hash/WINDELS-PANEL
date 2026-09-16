<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Webhooks — public, unauthenticated gateway callbacks (Session 11).
 *
 * Each request is signature-verified and stored idempotently on
 * (gateway_type, event_id) before any side effect. Successful payment events
 * credit the wallet exactly once via PaymentService.
 *
 * Retry taxonomy: invalid signatures fail hard (401), duplicates stay
 * idempotent (200), and transient internal processing failures answer 503 so
 * the gateway retries — the stored event stays unprocessed until it lands.
 */
class Webhooks extends MY_Controller {

    /**
     * Gateways whose callback is an authenticated GET rather than a signed
     * POST body. Kept as an explicit allowlist so no other gateway can be
     * driven by a URL a browser (or a crawler) might follow.
     */
    const GET_CALLBACK_GATEWAYS = array('blockonomics');

    public function __construct() {
        parent::__construct();
        $this->load->library('PaymentService');
        // Webhooks must never use sessions/cookies.
        $this->output->set_header('Content-Type: application/json');
    }

    /**
     * POST /webhook/(:gateway)
     */
    public function index($gateway = null) {
        // Most gateways POST a signed JSON body. Blockonomics is different by
        // design: it is a non-custodial address service that pings a GET
        // callback carrying ?status=&addr=&value=&txid=, with a shared secret
        // embedded in the URL. Refusing GET here would mean its confirmations
        // never arrived and crypto deposits silently never credited.
        $method = $this->input->method(true);
        $allowed = in_array($gateway, self::GET_CALLBACK_GATEWAYS, true)
            ? array('GET', 'POST')
            : array('POST');

        if (!in_array($method, $allowed, true)) {
            return $this->respond(405, array('ok'=>false,'error'=>'method not allowed'));
        }
        if (!$gateway || !preg_match('/^[a-z0-9_\-]+$/i', $gateway)) {
            return $this->respond(400, array('ok'=>false,'error'=>'bad gateway'));
        }
        $raw = file_get_contents('php://input') ?: '';
        $headers = $this->all_headers();

        $result = $this->paymentservice->record_webhook(strtolower($gateway), $raw, $headers);
        if (!empty($result['already_seen'])) {
            return $this->respond(200, array('ok'=>true,'duplicate'=>true));
        }
        if (!empty($result['ok'])) {
            return $this->respond(200, array('ok'=>true));
        }
        // Retry taxonomy:
        //  - retryable transient processing failures get 503 so the gateway
        //    retries; the webhook row stays unprocessed until it succeeds.
        //  - invalid signatures are a hard 401.
        //  - malformed/unknown events return 200 so the gateway doesn't retry
        //    a permanently undeliverable event forever.
        if (!empty($result['retryable'])) {
            return $this->respond(503, array('ok'=>false,'retry'=>true,
                'error'=>$result['error'] ?? 'temporary processing failure'));
        }
        $code = ($result['error'] ?? '') === 'Invalid signature' ? 401 : 200;
        return $this->respond($code, array('ok'=>false,'error'=>$result['error'] ?? 'error'));
    }

    /**
     * GET/POST /webhook/currency-rates — optional signed URL trigger for the
     * automatic FX refresh. Some shared hosts cannot run a PHP CLI command but
     * can hit a URL from a scheduler; this keeps that path safe by requiring a
     * dedicated secret and then running the exact same locked `currency_rates`
     * worker used by cron and Admin → Currencies → Update all.
     */
    public function currency_rates() {
        $method = $this->input->method(true);
        if (!in_array($method, array('GET', 'POST'), true)) {
            return $this->respond(405, array('ok' => false, 'error' => 'method not allowed'));
        }

        $raw = file_get_contents('php://input') ?: '';
        $headers = $this->all_headers();
        $auth = $this->verify_currency_webhook($raw, $headers);
        if (empty($auth['configured'])) {
            return $this->respond(503, array(
                'ok' => false,
                'error' => 'currency rate webhook secret is not configured',
            ));
        }
        if (empty($auth['ok'])) {
            return $this->respond(401, array('ok' => false, 'error' => 'invalid signature'));
        }

        $this->load->library(array('JobRunner', 'CronRegistry', 'CronControlService'));
        $job = 'currency_rates';
        if ($this->croncontrolservice->is_paused($job)) {
            return $this->respond(423, array('ok' => false, 'error' => 'currency rate updates are paused'));
        }

        $worker = $this->cronregistry->worker($job);
        if ($worker === null) {
            return $this->respond(503, array('ok' => false, 'error' => 'currency_rates job is not available'));
        }

        $res = $this->jobrunner->run($job, $worker);
        $foreign_checked = (int)($res['foreign_checked'] ?? 0);
        $foreign_processed = (int)($res['foreign_processed'] ?? 0);
        $ok = !empty($res['ok'])
            && ((int)($res['failed'] ?? 0) === 0 || (int)($res['processed'] ?? 0) > 0)
            && ($foreign_checked === 0 || $foreign_processed > 0);
        $status = $ok || !empty($res['skipped']) ? 200 : 503;
        return $this->respond($status, array(
            'ok' => $ok,
            'skipped' => !empty($res['skipped']),
            'processed' => (int)($res['processed'] ?? 0),
            'failed' => (int)($res['failed'] ?? 0),
            'foreign_processed' => $foreign_processed,
            'foreign_checked' => $foreign_checked,
            'message' => (string)($res['message'] ?? ($res['error'] ?? '')),
        ));
    }

    private function verify_currency_webhook($raw, array $headers) {
        $secret = $this->currency_webhook_secret();
        if ($secret === '') return array('configured' => false, 'ok' => false);

        $presented = (string)($this->input->get('secret', true) ?: '');
        if ($presented === '') $presented = $this->header($headers, 'X-Currency-Webhook-Secret');
        if ($presented === '') $presented = $this->header($headers, 'X-Marvy-Webhook-Secret');
        if ($presented !== '' && hash_equals($secret, $presented)) {
            return array('configured' => true, 'ok' => true);
        }

        $signature = $this->header($headers, 'X-Marvy-Signature');
        if ($signature === '') $signature = $this->header($headers, 'X-Currency-Signature');
        if ($signature !== '') {
            $expected = hash_hmac('sha256', (string)$raw, $secret);
            $sent = preg_replace('/^sha256=/i', '', trim($signature));
            if (hash_equals($expected, $sent)) {
                return array('configured' => true, 'ok' => true);
            }
        }

        return array('configured' => true, 'ok' => false);
    }

    private function currency_webhook_secret() {
        foreach (array('CURRENCY_RATE_WEBHOOK_SECRET', 'CURRENCY_WEBHOOK_SECRET') as $name) {
            if (class_exists('Env') && method_exists('Env', 'get')) {
                $value = Env::get($name, '');
            } elseif (function_exists('env_str')) {
                $value = env_str($name, '');
                if ($value === '') $value = env_str('VP_'.$name, '');
            } else {
                $value = getenv($name);
                if ($value === false || trim((string)$value) === '') $value = getenv('VP_'.$name);
            }
            $value = trim((string)$value);
            if ($value !== '') return $value;
        }
        return '';
    }

    private function header(array $headers, $name) {
        $wanted = strtolower($name);
        foreach ($headers as $k => $v) {
            if (strtolower((string)$k) === $wanted) return trim((string)$v);
        }
        return '';
    }

    private function all_headers() {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            return is_array($h) ? $h : array();
        }
        $out = array();
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $out[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
            }
        }
        return $out;
    }

    /**
     * POST /webhook/vtpass — VTpass transaction-update push.
     *
     * Unlike every other gateway here, VTpass signs nothing: the push is an
     * unauthenticated JSON POST, so it is treated purely as a hint. The only
     * value taken from it is the request_id of the purchase it names; the
     * provider is then asked through its own /requery before a single naira
     * moves (VtuService::settle_from_provider_update), which is why this
     * handler can safely acknowledge every push.
     *
     * The acknowledgement body is fixed by the docs: {"response":"success"} —
     * anything else makes VTpass retry an event forever.
     */
    public function vtpass() {
        if ($this->input->method(true) !== 'POST') {
            return $this->respond(405, array('ok' => false, 'error' => 'method not allowed'));
        }

        $body = json_decode((string)(file_get_contents('php://input') ?: ''), true);

        // The push names the purchase by the request_id we sent. Accept the
        // documented wrapper and the spellings their samples have used.
        $reference = null;
        foreach (array($body['requestId'] ?? null, $body['request_id'] ?? null,
                       $body['data']['requestId'] ?? null, $body['data']['request_id'] ?? null) as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') { $reference = trim($candidate); break; }
        }

        if ($reference === null) {
            // Nothing actionable in the push. Acknowledge (so VTpass stops
            // retrying) and leave a trail for the operator.
            log_message('error', 'vtpass webhook: push without a usable request_id');
            return $this->respond(200, array('response' => 'success'));
        }

        try {
            $this->load->library('VtuService');
            $result = $this->vtuservice->settle_from_provider_update($reference);
            if (empty($result['matched'])) {
                // A probe, a stale replay, or a purchase that already settled:
                // acknowledged and ignored — never a guess.
                log_message('debug', 'vtpass webhook: no pending purchase for '.$reference);
            } elseif (empty($result['settled']) && !empty($result['error'])) {
                log_message('error', 'vtpass webhook: '.$reference.' — '.$result['error']);
            }
        } catch (Throwable $e) {
            // A processing failure must not look like rejection: the cron's
            // vtu_status sweep re-asks the provider anyway.
            log_message('error', 'vtpass webhook threw for '.$reference.': '.$e->getMessage());
        }

        return $this->respond(200, array('response' => 'success'));
    }

    private function respond($code, $body) {
        return $this->output->set_status_header($code)
            ->set_output(json_encode($body));
    }
}
