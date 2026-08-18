<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Dashboard — admin landing with the operational widgets (Session 15).
 *
 * Read-only: it aggregates through AdminStats and links into the queues.
 * The `reports.view` permission gates the whole page.
 */
class Dashboard extends Admin_Controller {

    public function index() {
        $this->require_perm('reports.view');
        $this->load->library(array('AdminStats', 'ActivityFeed', 'DashboardStats'));
        $permissions = $this->auth->permissions();

        $this->load->view('layouts/app', array(
            'title'         => 'Admin',
            'nav_active'    => 'admin',
            'content_view'  => 'admin/dashboard',
            'current_user'  => $this->current_user,
            'permissions'   => $permissions,
            'unread'        => $this->dashboardstats->unread_count($this->current_user->id),
            'today'         => $this->adminstats->revenue(1),
            'month'         => $this->adminstats->revenue(30),
            'queue'         => $this->adminstats->action_queue(),
            'customers'     => $this->adminstats->customers(),
            'health'        => $this->adminstats->provider_health(),
            'status_counts' => $this->adminstats->order_status_counts(),
            // Every domain, not just SMM. The feed decides which rows get a
            // link from the viewer's permissions, but shows all of them —
            // hiding a domain would under-report the business to whoever
            // happens to be logged in.
            'recent'        => $this->activityfeed->recent($permissions, 8),
            'domains'       => $this->adminstats->revenue_by_domain(30),
        ));
    }
}
