<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_api — JSON referral, earnings and withdrawal endpoints (§33).
 *
 * Session-authenticated, for the panel's own frontend. Every figure is computed
 * server-side from the ledger: the client is told what it has, never asked.
 *
 * /api/referrals/validate is the one endpoint reachable without a session,
 * because a visitor needs to know whether the code they were given is real
 * before they create an account. It answers only valid/invalid and the owner's
 * display name — never the owner's email, id, or how much the code is worth.
 */
class Referral_api extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('AuthService', 'ReferralService', 'EarningsService', 'PayoutService'));
        $this->load->model(array('Earning_model', 'Payout_request_model', 'Referral_signup_model'));
    }

    /* --------------------------- referrals --------------------------- */

    /** GET /api/referrals/my-code */
    public function my_code() {
        $user = $this->require_user();
        if (!$user) return;

        $code = $this->referralservice->code_for($user);
        return $this->ok(array(
            'code' => $code->code,
            'link' => $this->referralservice->link_for($code->code),
            'visits' => (int)$code->total_visits,
            'signups' => (int)$code->total_signups,
            'qualified' => (int)$code->total_qualified,
        ));
    }

    /**
     * POST /api/referrals/validate — is this code usable?
     *
     * Rate limited: without it this is an oracle for enumerating which codes
     * exist, which leaks the shape of the user base.
     */
    public function validate() {
        if ($this->input->method(true) !== 'POST') return $this->fail(405, 'METHOD', 'POST required.');

        $this->load->library('RateLimiter');
        $bucket = RateLimiter::scope('refvalidate', $this->input->ip_address());
        if ($this->ratelimiter->too_many_failures($this->input->ip_address(), $bucket, 20, 300)) {
            return $this->fail(429, 'RATE_LIMITED', 'Too many attempts. Try again shortly.');
        }

        $body = $this->json_input();
        $code = $body['code'] ?? $this->input->post('code', true);

        $res = $this->referralservice->resolve($code);
        if (empty($res['ok'])) {
            $this->ratelimiter->record($this->input->ip_address(), $bucket, false, 'BAD_CODE');
            return $this->ok(array('valid' => false, 'reason' => $res['code'] ?? 'UNKNOWN'));
        }

        $owner = null;
        if (!empty($res['owner_id'])) {
            $row = $this->db->select('username')->where('id', (int)$res['owner_id'])->get('users')->row();
            $owner = $row ? $row->username : null;
        }

        return $this->ok(array(
            'valid' => true,
            'kind'  => $res['kind'],
            // Just enough for "you were referred by X" — no contact details.
            'referrer' => $owner,
        ));
    }

    /** GET /api/referrals/dashboard */
    public function dashboard() {
        $user = $this->require_user();
        if (!$user) return;
        return $this->ok($this->referralservice->dashboard_for($user));
    }

    /** GET /api/referrals/history */
    public function history() {
        $user = $this->require_user();
        if (!$user) return;

        $limit = max(1, min(100, (int)($this->input->get('limit') ?: 25)));
        $rows = $this->Referral_signup_model->for_referrer($user->id, $limit);

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'reference'    => $row->public_id,
                'code'         => $row->referral_code,
                'status'       => $row->status,
                'qualified_at' => $row->qualified_at,
                'created_at'   => $row->created_at,
            );
        }
        return $this->ok(array('referrals' => $out));
    }

    /* --------------------------- earnings ---------------------------- */

    /** GET /api/earnings */
    public function earnings() {
        $user = $this->require_user();
        if (!$user) return;

        return $this->ok(array(
            'balance'   => $this->earningsservice->balance($user->id),
            'by_source' => $this->earningsservice->by_source($user->id),
        ));
    }

    /** GET /api/earnings/history */
    public function earnings_history() {
        $user = $this->require_user();
        if (!$user) return;

        $limit  = max(1, min(100, (int)($this->input->get('limit') ?: 25)));
        $offset = max(0, (int)$this->input->get('offset'));

        $out = array();
        foreach ($this->earningsservice->history($user->id, $limit, $offset) as $row) {
            $out[] = array(
                'reference'    => $row->public_id,
                'source'       => $row->source,
                'amount'       => (string)$row->amount,
                'currency'     => $row->currency,
                'status'       => $row->status,
                'description'  => $row->description,
                'available_at' => $row->available_at,
                'created_at'   => $row->created_at,
            );
        }
        return $this->ok(array('earnings' => $out, 'limit' => $limit, 'offset' => $offset));
    }

    /* -------------------------- withdrawals -------------------------- */

    /** POST /api/withdrawals */
    public function withdrawals() {
        if ($this->input->method(true) !== 'POST') return $this->fail(405, 'METHOD', 'POST required.');

        $user = $this->require_user();
        if (!$user) return;

        $body = $this->json_input();
        $res = $this->payoutservice->request($user, array(
            'amount'           => $body['amount'] ?? $this->input->post('amount'),
            'method'           => $body['method'] ?? $this->input->post('method', true),
            'destination'      => $body['destination'] ?? $this->input->post('destination', true),
            'destination_name' => $body['destination_name'] ?? $this->input->post('destination_name', true),
        ));

        if (empty($res['ok'])) {
            return $this->fail(422, $res['code'] ?? 'PAYOUT_FAILED', $res['error']);
        }

        return $this->ok(array(
            'reference' => $res['payout']->public_id,
            'status'    => $res['payout']->status,
            'amount'    => (string)$res['payout']->amount,
            'converted' => !empty($res['converted']),
        ));
    }

    /** GET /api/withdrawals/history */
    public function withdrawals_history() {
        $user = $this->require_user();
        if (!$user) return;

        $out = array();
        foreach ($this->Payout_request_model->for_user($user->id, 50) as $row) {
            $out[] = array(
                'reference'    => $row->public_id,
                'amount'       => (string)$row->amount,
                'currency'     => $row->currency,
                'method'       => $row->method,
                'status'       => $row->status,
                'requested_at' => $row->requested_at,
                'paid_at'      => $row->paid_at,
                // The payout reference is shown; the destination account is not
                // echoed back in full.
                'payout_reference' => $row->payout_reference,
            );
        }
        return $this->ok(array('withdrawals' => $out));
    }

    /* ------------------------------------------------------------------ */

    private function require_user() {
        $user = $this->authservice->check() ? $this->authservice->user() : null;
        if (!$user) {
            $this->fail(401, 'UNAUTHENTICATED', 'Sign in to use this endpoint.');
            return null;
        }
        return $user;
    }

    private function json_input() {
        $raw = file_get_contents('php://input');
        if (!$raw) return array();
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function ok(array $data) {
        return $this->output->set_content_type('application/json')->set_status_header(200)
            ->set_output(json_encode(array('success' => true, 'data' => $data), JSON_UNESCAPED_SLASHES));
    }

    private function fail($http, $code, $message) {
        return $this->output->set_content_type('application/json')->set_status_header($http)
            ->set_output(json_encode(array('success' => false,
                'error' => array('code' => $code, 'message' => $message)), JSON_UNESCAPED_SLASHES));
    }
}
