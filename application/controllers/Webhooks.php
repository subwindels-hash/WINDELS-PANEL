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
     * POST /webhook/vtpass — VTpass transaction-update callback.
     *
     * VTpass pushes final states (delivered, and code-040 reversals) here,
     * keyed by the request_id we sent on /pay. The payload carries no
     * signature, so nothing in it is trusted: the handler treats it as a
     * pointer to a purchase, then asks VTpass's own /requery what actually
     * happened — the same trust path as the settlement cron. Settlement and
     * refunds happen inside TransactionEngine, exactly once.
     *
     * The docs require acknowledging with {"response":"success"}; any other
     * body makes VTpass re-deliver. An unparseable or unrecognised push is
     * therefore logged and acknowledged rather than answered with an error —
     * retrying it could not change the outcome, and a flood of 4xx/5xx here
     * reads to VTpass as an outage.
     */
    public function vtpass() {
        if ($this->input->method(true) !== 'POST') {
            return $this->respond(405, array('response' => 'error', 'error' => 'method not allowed'));
        }

        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['type'])) {
            log_message('error', 'vtpass webhook: unparseable push ('.strlen($raw).'B)');
            return $this->respond(200, array('response' => 'success'));
        }
        if ($data['type'] !== 'transaction-update') {
            // variation-codes-update and anything VTpass adds later: not a
            // transaction state, nothing to settle.
            log_message('info', 'vtpass webhook: ignored push of type '.$data['type']);
            return $this->respond(200, array('response' => 'success'));
        }

        $request_id = (string)($data['data']['requestId'] ?? '');
        if ($request_id === '') {
            log_message('error', 'vtpass webhook: transaction-update without a requestId');
            return $this->respond(200, array('response' => 'success'));
        }

        try {
            $this->load->library('VtuService');
            $res = $this->vtuservice->settle_from_provider_update($request_id);
        } catch (Throwable $e) {
            // Acknowledge anyway: a thrown settlement error would make VTpass
            // redeliver the same push, and the settlement cron re-checks every
            // pending purchase regardless. The log is what support works from.
            log_message('error', 'vtpass webhook settlement threw for '.$request_id.': '.$e->getMessage());
            return $this->respond(200, array('response' => 'success'));
        }

        if (empty($res['ok'])) {
            log_message('error', 'vtpass webhook: could not settle '.$request_id.': '
                .($res['error'] ?? 'unknown reason'));
        } elseif (!empty($res['settled'])) {
            log_message('info', 'vtpass webhook: settled '.$request_id.' as '
                .($res['status'] ?? '?').' from a provider requery');
        }

        return $this->respond(200, array('response' => 'success'));
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

    private function respond($code, $body) {
        return $this->output->set_status_header($code)
            ->set_output(json_encode($body));
    }
}
