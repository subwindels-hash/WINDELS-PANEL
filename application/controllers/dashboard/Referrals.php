<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Referrals — referral program (placeholder).
 * Commission engine and stats ship in Session 14 (Affiliate).
 */
class Referrals extends Auth_Controller {
    public function __construct() {
        parent::__construct();
        $this->load->library('DashboardStats');
    }

    public function index() {
        $code = $this->current_user->referral_code;
        $link = site_url('register?ref='.urlencode($code));

        $this->load->view('layouts/app', array(
            'title'        => 'Referrals',
            'nav_active'   => 'dashboard/referrals',
            'content_view' => 'dashboard/referrals/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'code'         => $code,
            'link'         => $link,
        ));
    }
}
