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

        // Today and the month come from one pass over each money table
        // instead of a query per window: this is the first screen every staff
        // member opens, and the two windows are nested anyway.
        $revenue = $this->adminstats->revenue_windows(array(1, 30));

        $this->load->view('layouts/app', array(
            'title'         => 'Admin',
            'nav_active'    => 'admin',
            'content_view'  => 'admin/dashboard',
            'current_user'  => $this->current_user,
            'permissions'   => $permissions,
            'unread'        => $this->dashboardstats->unread_count($this->current_user->id),
            'overview'      => $this->adminstats->platform_overview(),
            'series'        => $this->adminstats->revenue_series(14),
            'today'         => $revenue[1],
            'month'         => $revenue[30],
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
