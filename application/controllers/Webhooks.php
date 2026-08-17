<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Webhooks — public, unauthenticated gateway callbacks (Session 11).
 *
 * Each request is signature-verified and stored idempotently on
 * (gateway_type, event_id) before any side effect. Successful payment events
 * credit the wallet exactly once via PaymentService.
 */
class Webhooks extends MY_Controller {

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
        if ($this->input->method(true) !== 'POST') {
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
        // Return 200 even on non-fatal errors so the gateway doesn't retry a
        // malformed/replayed event forever; fatal signature failures use 401.
        $code = ($result['error'] ?? '') === 'Invalid signature' ? 401 : 200;
        return $this->respond($code, array('ok'=>false,'error'=>$result['error'] ?? 'error'));
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
