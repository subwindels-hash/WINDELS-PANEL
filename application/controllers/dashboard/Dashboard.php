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

        $this->load->library('PinService');
        $pin_set = $this->pinservice->is_set($this->current_user);

        // The inbox card on the landing: latest four of MY messages only,
        // with an unread count. The widget degrades to nothing when the
        // inbox tables are not migrated yet, like every other optional
        // panel module.
        $inbox_recent = array();
        $inbox_unread = 0;
        try {
            $this->load->library('InboxService');
            $inbox_recent = $this->inboxservice->for_user($this->current_user->id, '', 4, 0);
            $inbox_unread = $this->inboxservice->count_user($this->current_user->id, 'UNREAD');
        } catch (Throwable $e) {
            $inbox_recent = array();
        }

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
            // The security PIN card: state only — the value itself appears
            // after the owner clicks "Show my PIN" (one-read session flash).
            'pin_set'      => $pin_set,
            'pin_shown'    => $this->session->flashdata('pin_shown'),
            'inbox_recent' => $inbox_recent,
            'inbox_unread' => $inbox_unread,
            'page_description' => 'Monitor your account activity, orders, wallet, and recent activity.',
        ));
    }
}
