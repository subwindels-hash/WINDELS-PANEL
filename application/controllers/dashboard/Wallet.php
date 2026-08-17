<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Wallet — transactions history and add-funds entry point.
 *
 * Payment initialization and gateway handling ship in Session 11 (Payments);
 * this session renders the read-only ledger and the manual/bank option.
 */
class Wallet extends Auth_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Wallet_model', 'Wallet_transaction_model'));
        $this->load->library('DashboardStats');
    }

    public function transactions() {
        $wallet = $this->Wallet_model->for_user($this->current_user->id);
        $page = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $txns = $this->Wallet_transaction_model->for_wallet($wallet->id, $limit, ($page-1)*$limit);
        $total = (int)$this->db->where('wallet_id', $wallet->id)->count_all_results('wallet_transactions');

        $this->load->view('layouts/app', array(
            'title'        => 'Transactions',
            'nav_active'   => 'dashboard/transactions',
            'content_view' => 'dashboard/wallet/transactions',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'wallet'       => $wallet,
            'transactions' => $txns,
            'page'         => $page,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** Add funds — lists methods; checkout flows arrive in Session 11. */
    public function add_funds() {
        $wallet = $this->Wallet_model->for_user($this->current_user->id);
        $methods = $this->db->where('is_active', 1)
            ->order_by('sorting', 'ASC')->get('payment_methods')->result();

        $this->load->view('layouts/app', array(
            'title'        => 'Add Funds',
            'nav_active'   => 'dashboard/add-funds',
            'content_view' => 'dashboard/wallet/add_funds',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'wallet'       => $wallet,
            'methods'      => $methods,
        ));
    }
}
