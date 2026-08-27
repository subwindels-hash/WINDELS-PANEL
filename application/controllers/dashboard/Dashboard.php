<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard — authenticated customer landing page (Session 06).
 * Wallet, order/activity stats, recent orders & transactions, notifications.
 */
class Dashboard extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('DashboardStats');
        $this->load->model(array('Order_model', 'Wallet_model'));
    }

    public function index() {
        $stats = $this->dashboardstats->overview($this->current_user->id);

        $this->load->view('layouts/app_theme', array(
            'title'        => 'Dashboard',
            'nav_active'   => 'dashboard',
            'content_view' => 'dashboard/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $stats['unread_notifications'],
            'wallet'       => $stats['wallet'],
            'totals'       => $stats['totals'],
            'orders'       => $stats['recent_orders'],
            'transactions' => $stats['recent_transactions'],
            'notifications'=> $stats['unread'],
            'page_description' => 'Monitor your account activity, orders, wallet, and recent activity.',
        ));
    }
}
