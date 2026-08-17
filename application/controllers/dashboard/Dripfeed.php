<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Dripfeed — placeholder. Drip-feed ordering ships in Session 10.
 */
class Dripfeed extends Auth_Controller {
    public function index() {
        $this->load->library('DashboardStats');
        $this->load->view('layouts/app', array(
            'title'        => 'Drip-feed',
            'nav_active'   => 'dashboard/drip-feed',
            'content_view' => 'dashboard/placeholder',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'feature'      => 'Drip-feed orders',
            'session'      => 10,
        ));
    }
}
