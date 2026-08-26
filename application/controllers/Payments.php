<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Payments — JSON payment API for the signed-in customer.
 *
 *   POST /api/payments/fundsvera/initialize
 *   GET  /api/payments/history
 *   GET  /api/payments/:reference
 *
 * Thin by design: it authenticates, validates shape, and delegates to
 * PaymentService. No gateway call, no balance arithmetic and no status
 * transition happens here — that logic belongs to the service layer so the
 * dashboard and this API cannot drift apart.
 *
 * These are session-authenticated endpoints for the panel's own frontend, not
 * the reseller API (that is Api_v1, which authenticates by key). A customer can
 * only ever see their own payments: every lookup is scoped by user_id, so a
 * guessed reference belonging to someone else returns 404, not their data.
 */
class Payments extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('AuthService', 'PaymentService'));
        $this->load->model(array('Payment_transaction_model', 'Setting_model'));
    }

    /**
     * POST /api/payments/fundsvera/initialize
     *
     * Creates the internal payment record first, then asks Fundsvera for
     * transfer instructions. The order matters: if the provider call fails we
     * still hold a FAILED record explaining why, rather than a silent gap.
     */
    public function initialize() {
        if ($this->input->method(true) !== 'POST') return $this->json_error(405, 'METHOD', 'POST required.');

        $user = $this->require_customer();
        if (!$user) return;

        // Rate limit: initiating a checkout is an outbound API call and a row.
        $this->load->library('RateLimiter');
        $bucket = RateLimiter::scope('payinit', (string)$user->id);
        if ($this->ratelimiter->too_many_failures($this->input->ip_address(), $bucket, 10, 300)) {
            return $this->json_error(429, 'RATE_LIMITED', 'Too many payment attempts. Try again shortly.');
        }

        $payload = $this->json_input();
        $amount  = isset($payload['amount']) ? $payload['amount'] : $this->input->post('amount');

        $res = $this->paymentservice->deposit($user, array(
            'payment_method'  => 'fundsvera',
            'amount'          => $amount,
            'currency'        => marvy_base_currency(),
            'idempotency_key' => $payload['idempotency_key'] ?? $this->input->post('idempotency_key'),
        ));

        if (empty($res['ok'])) {
            $this->ratelimiter->record($this->input->ip_address(), $bucket, false, $res['code'] ?? 'DEPOSIT_FAILED');
            return $this->json_error(422, $res['code'] ?? 'DEPOSIT_FAILED', $res['error']);
        }

        $tx = $res['transaction'];
        return $this->json_ok(array(
            'reference'    => $tx->internal_reference ?: $tx->public_id,
            'status'       => $tx->status,
            'amount'       => (string)$tx->amount,
            'currency'     => $tx->currency,
            'checkout'     => $res['checkout'] ?? null,
            'redirect_url' => $res['redirect_url'] ?? null,
            // Stated plainly because it is the whole security model: the
            // redirect is a convenience, the webhook is the truth.
            'note'         => 'Your wallet is credited when the transfer is confirmed by the payment provider, '
                             .'not when you return to this site.',
        ));
    }

    /** GET /api/payments/:reference — status of one payment. */
    public function show($reference = null) {
        $user = $this->require_customer();
        if (!$user) return;

        if (!$reference) return $this->json_error(400, 'NO_REFERENCE', 'A payment reference is required.');

        $tx = $this->Payment_transaction_model->for_user_reference($user->id, $reference);
        if (!$tx) return $this->json_error(404, 'NOT_FOUND', 'No payment with that reference.');

        return $this->json_ok($this->present($tx));
    }

    /** GET /api/payments/history — this customer's payments. */
    public function history() {
        $user = $this->require_customer();
        if (!$user) return;

        $limit  = max(1, min(100, (int)($this->input->get('limit') ?: 25)));
        $offset = max(0, (int)$this->input->get('offset'));

        $rows = $this->Payment_transaction_model->admin_search(
            array('user_id' => $user->id), $limit, $offset
        );

        $out = array();
        foreach ($rows as $row) $out[] = $this->present($row);
        return $this->json_ok(array('payments' => $out, 'limit' => $limit, 'offset' => $offset));
    }

    /* ------------------------------------------------------------------ */

    /**
     * The public shape of a payment.
     *
     * Deliberately excludes provider credentials, raw payloads and internal
     * ids. A customer needs to know what they paid and whether it landed.
     */
    private function present($tx) {
        return array(
            'reference'        => $tx->internal_reference ?: $tx->public_id,
            'provider'         => $tx->provider,
            'payment_method'   => $tx->payment_method,
            'amount'           => (string)$tx->amount,
            'fee'              => (string)$tx->fee,
            'credited_amount'  => $tx->credited_amount === null ? null : (string)$tx->credited_amount,
            'currency'         => $tx->currency,
            'status'           => $tx->status,
            'provider_reference' => $tx->provider_tx_id,
            'initiated_at'     => $tx->initiated_at,
            'paid_at'          => $tx->paid_at,
            'failed_at'        => $tx->failed_at,
            'created_at'       => $tx->created_at,
        );
    }

    /** The signed-in customer, or a 401 JSON body. */
    private function require_customer() {
        $user = $this->authservice->check() ? $this->authservice->user() : null;
        if (!$user) {
            $this->json_error(401, 'UNAUTHENTICATED', 'Sign in to use this endpoint.');
            return null;
        }
        return $user;
    }

    /** Accept a JSON body as well as form-encoded input. */
    private function json_input() {
        $raw = file_get_contents('php://input');
        if (!$raw) return array();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function json_ok(array $data) {
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header(200)
            ->set_output(json_encode(array('success' => true, 'data' => $data), JSON_UNESCAPED_SLASHES));
    }

    private function json_error($http, $code, $message) {
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header($http)
            ->set_output(json_encode(array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message),
            ), JSON_UNESCAPED_SLASHES));
    }
}
