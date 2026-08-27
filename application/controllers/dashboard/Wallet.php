<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Wallet — deposit initialization, transaction and deposit history.
 * Gateway checkout + webhooks are handled by PaymentService (Session 11).
 */
class Wallet extends Auth_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Wallet_model','Wallet_transaction_model','Payment_transaction_model'));
        $this->load->library(array('DashboardStats','PaymentService','form_validation'));
    }

    public function transactions() {
        $wallet = $this->Wallet_model->for_user($this->current_user->id);
        $page = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $txns = $this->Wallet_transaction_model->for_wallet($wallet->id, $limit, ($page-1)*$limit);
        $total = (int)$this->db->where('wallet_id', $wallet->id)->count_all_results('wallet_transactions');

        $this->load->view('layouts/app', array(
            'title' => 'Transactions',
            'nav_active' => 'dashboard/transactions',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/wallet/transactions',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'wallet' => $wallet,
            'transactions' => $txns,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function add_funds() {
        $wallet = $this->Wallet_model->for_user($this->current_user->id);
        $methods = $this->db->where('is_active',1)->order_by('sorting','ASC')->get('payment_methods')->result();

        // Deposit bounds come from settings, not the view. They were hardcoded
        // as 5/10000 in the template, which silently contradicted the settings
        // table and made no sense at all once the base currency became naira.
        $this->load->model('Setting_model');

        $this->load->view('layouts/app', array(
            'title' => 'Add Funds',
            'nav_active' => 'dashboard/add-funds',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/wallet/add_funds',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'wallet' => $wallet,
            'methods' => $methods,
            'min_deposit' => $this->Setting_model->get('min_deposit', '500.00000000'),
            'max_deposit' => $this->Setting_model->get('max_deposit', '5000000.00000000'),
            'base_currency' => marvy_base_currency(),
        ));
    }

    /** POST /dashboard/wallet/deposit — initialise a payment. */
    public function deposit() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->form_validation->set_rules('payment_method','Payment method','required|trim');
        $this->form_validation->set_rules('amount','Amount','required|numeric|greater_than[0]');
        if (!$this->form_validation->run()) {
            $this->session->set_flashdata('error', validation_errors());
            redirect('dashboard/add-funds');
        }
        $res = $this->paymentservice->deposit($this->current_user, array(
            'payment_method' => $this->input->post('payment_method', true),
            'amount' => $this->input->post('amount'),
            'currency' => marvy_base_currency(),
        ));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not initiate payment');
            redirect('dashboard/add-funds');
        }

        $tx = $res['transaction'];
        if (!empty($res['redirect_url'])) {
            redirect($res['redirect_url']);
        }
        // Manual gateway: show instructions / pending state.
        $this->session->set_flashdata('success',
            'Deposit request created. Your wallet will be credited once payment is confirmed.');
        redirect('dashboard/wallet/deposits/'.$tx->public_id);
    }

    public function deposits($public_id = null) {
        $tx = $public_id
            ? $this->Payment_transaction_model->find_public_for_user($public_id, $this->current_user->id)
            : null;
        $deposits = $this->Payment_transaction_model->for_user($this->current_user->id, 25);

        // A bank-transfer deposit is useless to the customer without the
        // account details, and those live on the checkout row rather than on
        // the transaction. Loaded here so the view stays free of queries.
        $checkout = null;
        if ($tx && $tx->status === 'PENDING') {
            try {
                $this->load->model('Fundsvera_checkout_model');
                $checkout = $this->Fundsvera_checkout_model->for_transaction($tx->id);
            } catch (Throwable $e) {
                log_message('error', 'could not load checkout details: '.$e->getMessage());
            }
        }
        $this->load->view('layouts/app', array(
            'title' => 'Deposits',
            'nav_active' => 'dashboard/add-funds',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/wallet/deposits',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'deposits' => $deposits,
            'active_deposit' => $tx,
            'checkout' => $checkout,
        ));
    }
}
