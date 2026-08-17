<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Subscriptions — placeholder. Recurring orders ship in Session 10.
 */
class Subscriptions extends Auth_Controller {
    public function index() {
        $this->load->library('DashboardStats');
        $this->load->view('layouts/app', array(
            'title'        => 'Subscriptions',
            'nav_active'   => 'dashboard/subscriptions',
            'content_view' => 'dashboard/placeholder',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'feature'      => 'Subscription orders',
            'session'      => 10,
        ));
    }
}
