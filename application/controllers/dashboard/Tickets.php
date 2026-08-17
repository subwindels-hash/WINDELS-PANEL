<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Tickets — support inbox (placeholder).
 * Threads, replies and attachments ship in Session 13 (Support + Content).
 */
class Tickets extends Auth_Controller {
    public function __construct() {
        parent::__construct();
        $this->load->model('Ticket_model');
        $this->load->library('DashboardStats');
    }

    public function index() {
        $tickets = $this->Ticket_model->for_user($this->current_user->id);
        $this->load->view('layouts/app', array(
            'title'        => 'Support',
            'nav_active'   => 'dashboard/tickets',
            'content_view' => 'dashboard/tickets/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'tickets'     => $tickets,
        ));
    }

    public function detail($public_id = null) { $this->index(); }
}
