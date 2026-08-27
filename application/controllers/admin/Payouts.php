<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin → Payouts and earnings.
 *
 * Every mutation is POST-only and permission-gated. Money leaves the platform
 * through a human decision recorded here, never automatically: `paid()`
 * requires the reference of a transfer that has actually been sent.
 */
class Payouts extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->load->library(array('DashboardStats', 'PayoutService', 'EarningsService', 'ReferralService'));
        $this->load->model(array('Payout_request_model', 'Earning_model',
                                 'Referral_signup_model', 'Referral_campaign_model',
                                 'Referral_code_model', 'Audit_log_model'));
    }

    /** GET /admin/payouts — the review queue. */
    public function index() {
        $this->require_perm('payouts.review');

        $filters = array('status' => $this->input->get('status', true));
        $page = max(1, (int)$this->input->get('page'));

        $this->render('Payouts', 'admin/payouts/index', array(
            'payouts' => $this->Payout_request_model->admin_search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'   => $this->Payout_request_model->admin_count($filters),
            'totals'  => $this->Payout_request_model->admin_totals(),
            'filters' => $filters,
            'page'    => $page,
        ));
    }

    /** GET /admin/earnings — the platform-wide earnings ledger. */
    public function earnings() {
        $this->require_perm('earnings.view');

        $filters = array(
            'status' => $this->input->get('status', true),
            'source' => $this->input->get('source', true),
        );
        $page = max(1, (int)$this->input->get('page'));

        $this->render('Earnings', 'admin/payouts/earnings', array(
            'entries' => $this->Earning_model->admin_search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'   => $this->Earning_model->admin_count($filters),
            'totals'  => $this->Earning_model->admin_totals(),
            'filters' => $filters,
            'page'    => $page,
        ));
    }

    /** GET /admin/referrals — signups, fraud flags and campaign performance. */
    public function referrals() {
        $this->require_perm('earnings.view');

        $filters = array(
            'status'  => $this->input->get('status', true),
            'flagged' => $this->input->get('flagged') ? 1 : null,
        );
        $page = max(1, (int)$this->input->get('page'));

        $this->render('Referrals', 'admin/payouts/referrals', array(
            'signups'   => $this->Referral_signup_model->admin_search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'     => $this->Referral_signup_model->admin_count($filters),
            'campaigns' => $this->Referral_campaign_model->performance(),
            'filters'   => $filters,
            'page'      => $page,
            // What the panel can actually see about a visitor's country. Shown
            // so an operator setting a geo restriction knows whether it will
            // bite or silently do nothing.
            'geo_detected' => $this->referralservice->visitor_country(),
        ));
    }

    /** POST /admin/campaigns — create an advertising code. */
    public function create_campaign() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('earnings.manage');

        $code = strtoupper(trim((string)$this->input->post('code', true)));
        $name = trim((string)$this->input->post('name', true));

        if ($code === '' || $name === '') {
            $this->session->set_flashdata('error', 'A campaign needs a name and a code.');
            return redirect('admin/referrals');
        }
        if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            $this->session->set_flashdata('error',
                'Campaign codes may use letters, digits, dashes and underscores only.');
            return redirect('admin/referrals');
        }
        // A campaign code and a personal referral code share one namespace —
        // whichever resolves first would silently win otherwise.
        if ($this->Referral_campaign_model->by_code($code) || $this->Referral_code_model->by_code($code)) {
            $this->session->set_flashdata('error', 'That code is already in use.');
            return redirect('admin/referrals');
        }

        $qualify = strtoupper((string)$this->input->post('qualify_event', true));
        if (!in_array($qualify, ReferralService::EVENTS, true)) $qualify = 'FIRST_ORDER';

        $id = $this->Referral_campaign_model->create(array(
            'name'          => mb_substr($name, 0, 160),
            'code'          => $code,
            'source'        => mb_substr((string)$this->input->post('source', true), 0, 64) ?: null,
            'reward_amount' => number_format((float)$this->input->post('reward_amount'), 8, '.', ''),
            'qualify_event' => $qualify,
            'hold_hours'    => max(0, (int)$this->input->post('hold_hours')),
            'budget'        => $this->input->post('budget') !== ''
                               ? number_format((float)$this->input->post('budget'), 8, '.', '') : null,
            'geo_allow'     => $this->clean_geo($this->input->post('geo_allow', true)),
            'cost'          => $this->input->post('cost') !== ''
                               ? number_format((float)$this->input->post('cost'), 8, '.', '') : null,
            'status'        => 'ACTIVE',
            'created_by_id' => (int)$this->current_user->id,
        ));

        $this->Audit_log_model->record(
            $this->current_user->id, 'campaign.created', 'referral_campaigns', (string)$id,
            null, array('code' => $code, 'name' => $name),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata('success',
            'Campaign created. Share '.site_url('register').'?ref='.$code);
        redirect('admin/referrals');
    }

    /** POST /admin/campaigns/:id/status — pause or resume. */
    public function campaign_status($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('earnings.manage');

        $campaign = $this->db->where('public_id', $public_id)->get('referral_campaigns')->row();
        if (!$campaign) show_404();

        $status = strtoupper((string)$this->input->post('status', true));
        if (!in_array($status, array('ACTIVE', 'PAUSED', 'ENDED'), true)) show_404();

        $this->Referral_campaign_model->update_row($campaign->id, array('status' => $status));
        $this->session->set_flashdata('success', 'Campaign is now '.strtolower($status).'.');
        redirect('admin/referrals');
    }

    /* ------------------------------ actions ----------------------------- */

    /** POST /admin/payouts/:id/approve */
    public function approve($public_id) {
        $payout = $this->guard($public_id, 'payouts.review');
        $res = $this->payoutservice->approve($payout, $this->current_user,
            $this->input->post('note', true));
        $this->finish($res, 'Payout approved. Send the transfer, then record its reference.');
    }

    /** POST /admin/payouts/:id/reject */
    public function reject($public_id) {
        $payout = $this->guard($public_id, 'payouts.review');
        $res = $this->payoutservice->reject($payout, $this->current_user,
            $this->input->post('reason', true));
        $this->finish($res, 'Payout rejected and the earnings released back to the customer.');
    }

    /** POST /admin/payouts/:id/paid — record a transfer that has been sent. */
    public function paid($public_id) {
        $payout = $this->guard($public_id, 'payouts.review');
        $res = $this->payoutservice->mark_paid($payout, $this->current_user,
            $this->input->post('reference', true));
        $this->finish($res, 'Payout recorded as sent.');
    }

    /** POST /admin/earnings/:id/reverse */
    public function reverse_earning($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('earnings.manage');

        $earning = $this->db->where('public_id', $public_id)->get('earnings')->row();
        if (!$earning) show_404();

        $res = $this->earningsservice->reverse($earning, $this->current_user->id,
            $this->input->post('reason', true));

        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : 'Earning reversed and recorded in the audit log.');
        redirect('admin/earnings');
    }

    /** POST /admin/referrals/:id/review — approve or reject a flagged referral. */
    public function review_signup($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('earnings.manage');

        $signup = $this->db->where('public_id', $public_id)->get('referral_signups')->row();
        if (!$signup) show_404();

        $res = $this->referralservice->review($signup,
            $this->input->post('decision', true), $this->current_user,
            $this->input->post('note', true));

        $this->Audit_log_model->record(
            $this->current_user->id, 'referral.reviewed', 'referral_signups', $public_id,
            array('status' => $signup->status), array('decision' => $this->input->post('decision', true)),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? ($res['error'] ?? 'Could not review that referral.') : 'Referral reviewed.');
        redirect('admin/referrals');
    }

    /**
     * Normalise a comma-separated ISO-2 allow-list.
     *
     * Anything that is not two letters is dropped rather than stored: a typo
     * like "UK" (the ISO code is GB) would silently exclude every visitor from
     * that country, which is the opposite of what the operator intended.
     */
    private function clean_geo($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;

        $codes = array();
        foreach (explode(',', $raw) as $part) {
            $code = strtoupper(trim($part));
            if (preg_match('/^[A-Z]{2}$/', $code)) $codes[$code] = true;
        }
        return $codes ? implode(',', array_keys($codes)) : null;
    }

    /* ------------------------------ helpers ----------------------------- */

    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $payout = $this->Payout_request_model->admin_find($public_id);
        if (!$payout) show_404();
        return $payout;
    }

    private function finish($res, $success_message) {
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : $success_message);
        redirect('admin/payouts');
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/payouts',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }
}
