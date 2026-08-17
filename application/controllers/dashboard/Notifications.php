<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Notifications — list + mark read.
 */
class Notifications extends Auth_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->load->model('Notification_model');
        $this->load->library('DashboardStats');
    }

    public function index() {
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $rows  = $this->Notification_model->for_user($this->current_user->id, $limit, ($page-1)*$limit);
        $total = $this->Notification_model->count_for_user($this->current_user->id);

        $this->load->view('layouts/app', array(
            'title'        => 'Notifications',
            'nav_active'   => 'dashboard/notifications',
            'content_view' => 'dashboard/notifications/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'notifications'=> $rows,
            'page'         => $page,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** POST /dashboard/notifications/read — mark one or all as read. */
    public function mark_read() {
        $id = $this->input->post('public_id', true);
        $this->Notification_model->mark_read($this->current_user->id, $id ?: null);
        redirect('dashboard/notifications');
    }
}
