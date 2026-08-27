<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Earnings — the customer's earnings wallet and payout requests.
 *
 * Separate from dashboard/Wallet on purpose. The wallet holds money the
 * customer paid in to spend here; earnings are money the platform owes them.
 * They have different rules (one is non-withdrawable, one is payable) and
 * showing them as one balance would misrepresent both.
 */
class Earnings extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('DashboardStats', 'EarningsService', 'PayoutService', 'ReferralService'));
        $this->load->model(array('Earning_model', 'Payout_request_model'));
    }

    /** GET /dashboard/earnings */
    public function index() {
        $user = $this->current_user;

        $this->render('Earnings', 'dashboard/earnings/index', array(
            'balance'         => $this->earningsservice->balance($user->id),
            'by_source'       => $this->earningsservice->by_source($user->id),
            'recent'          => $this->earningsservice->history($user->id, 10),
            'payouts'         => $this->Payout_request_model->for_user($user->id, 5),
            'referral'        => $this->referralservice->dashboard_for($user),
            'min_payout'      => $this->earningsservice->min_payout(),
            'payouts_enabled' => $this->earningsservice->payouts_enabled(),
        ));
    }

    /** GET /dashboard/earnings/history */
    public function history() {
        $user  = $this->current_user;
        $page  = max(1, (int)$this->input->get('page'));
        $limit = 25;

        $this->render('Earnings history', 'dashboard/earnings/history', array(
            'entries' => $this->earningsservice->history($user->id, $limit, ($page - 1) * $limit),
            'total'   => $this->Earning_model->count_for_user($user->id),
            'page'    => $page,
            'limit'   => $limit,
        ));
    }

    /** POST /dashboard/earnings/withdraw */
    public function withdraw() {
        if ($this->input->method(true) !== 'POST') show_404();

        $res = $this->payoutservice->request($this->current_user, array(
            'amount'           => $this->input->post('amount'),
            'method'           => $this->input->post('method', true),
            'destination'      => $this->input->post('destination', true),
            'destination_name' => $this->input->post('destination_name', true),
        ));

        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
        } elseif (!empty($res['converted'])) {
            $this->session->set_flashdata('success', 'Your earnings were added to your wallet balance.');
        } else {
            $this->session->set_flashdata('success',
                'Payout requested. The amount is held while staff review it.');
        }
        redirect('dashboard/earnings');
    }

    /** POST /dashboard/earnings/payouts/:id/cancel */
    public function cancel_payout($public_id = null) {
        if ($this->input->method(true) !== 'POST') show_404();

        $payout = $this->Payout_request_model->find_public_for_user($public_id, $this->current_user->id);
        if (!$payout) show_404();

        $res = $this->payoutservice->cancel($payout, $this->current_user);
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : 'Payout request cancelled and your earnings released.');
        redirect('dashboard/earnings');
    }

    /** Shared shell render, matching the other dashboard controllers. */
    private function render($title, $view, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'dashboard/earnings',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }
}
