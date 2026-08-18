<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Customer withdrawal request history and cancellation. */
class Withdrawals extends Auth_Controller {
    const PER_PAGE = 20;

    public function __construct() {
        parent::__construct();
        $this->load->library(array('WithdrawalService', 'DashboardStats'));
        $this->load->model(array('Withdrawal_model', 'Wallet_model'));
    }

    public function index() {
        $page = max(1, (int)$this->input->get('page'));
        $offset = ($page - 1) * self::PER_PAGE;
        $token = $this->session->userdata('withdrawal_form_token');
        if (!$token) {
            $token = bin2hex(random_bytes(24));
            $this->session->set_userdata('withdrawal_form_token', $token);
        }
        $this->view('index', 'Withdraw funds', array(
            'wallet' => $this->Wallet_model->for_user($this->current_user->id),
            'withdrawals' => $this->Withdrawal_model->for_user(
                $this->current_user->id, self::PER_PAGE, $offset
            ),
            'total' => $this->Withdrawal_model->count_for_user($this->current_user->id),
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'form_token' => $token,
            'minimum' => $this->withdrawalservice->minimum(),
            'maximum' => $this->withdrawalservice->maximum(),
            'fee_percent' => $this->withdrawalservice->fee_percent(),
            'fixed_fee' => $this->withdrawalservice->fixed_fee(),
            'identity_required' => $this->withdrawalservice->identity_required(),
            'identity_verified' => $this->withdrawalservice->identity_verified($this->current_user->id),
        ));
    }

    public function create() {
        $this->post_only();
        $token = trim((string)$this->input->post('form_token', true));
        $session_token = (string)$this->session->userdata('withdrawal_form_token');
        $recent_tokens = (array)$this->session->userdata('withdrawal_recent_tokens');
        $known_token = $session_token !== '' && hash_equals($session_token, $token);
        if (!$known_token) {
            foreach ($recent_tokens as $recent_token) {
                if (is_string($recent_token) && hash_equals($recent_token, $token)) {
                    $known_token = true;
                    break;
                }
            }
        }
        // Retain a short window of completed form tokens. A browser/network
        // replay therefore reaches the service's idempotency check and returns
        // the original request instead of being reported as an expired form.
        if ($token === '' || !$known_token) {
            $this->session->set_flashdata('error', 'This withdrawal form expired. Please try again.');
            return redirect('dashboard/withdrawals');
        }
        $res = $this->withdrawalservice->request($this->current_user, array(
            'amount' => $this->input->post('amount', true),
            'bank_name' => $this->input->post('bank_name', true),
            'bank_code' => $this->input->post('bank_code', true),
            'account_number' => $this->input->post('account_number', true),
            'account_name' => $this->input->post('account_name', true),
            'idempotency_key' => 'withdrawal:'.$this->current_user->id.':'.hash('sha256', $token),
        ));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/withdrawals');
        }
        if (!in_array($token, $recent_tokens, true)) $recent_tokens[] = $token;
        $recent_tokens = array_slice($recent_tokens, -5);
        $this->session->set_userdata('withdrawal_recent_tokens', $recent_tokens);
        if ($session_token !== '' && hash_equals($session_token, $token)) {
            $this->session->unset_userdata('withdrawal_form_token');
        }
        $this->session->set_flashdata('success', empty($res['duplicate'])
            ? 'Withdrawal requested. The amount is now reserved from your wallet.'
            : 'This withdrawal request was already received.');
        redirect('dashboard/withdrawals/'.$res['withdrawal']->public_id);
    }

    public function detail($public_id) {
        $withdrawal = $this->Withdrawal_model->find_owned($public_id, $this->current_user->id);
        if (!$withdrawal) show_404();
        $this->view('detail', 'Withdrawal '.$withdrawal->public_id, array(
            'withdrawal' => $withdrawal,
        ));
    }

    public function cancel($public_id) {
        $this->post_only();
        // Ownership is checked again inside the transactional service before
        // it tries the guarded PENDING → CANCELLED transition.
        $res = $this->withdrawalservice->cancel($public_id, $this->current_user->id);
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : 'Withdrawal cancelled and the full amount returned to your wallet.');
        redirect('dashboard/withdrawals/'.$public_id);
    }

    private function post_only() {
        if ($this->input->method(true) !== 'POST') show_404();
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => 'dashboard/withdrawals',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/withdrawals/'.$view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
        ), $data));
    }
}
