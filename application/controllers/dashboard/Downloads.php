<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Downloads — "My Downloads": every digital item a customer has
 * bought, with a button that issues a fresh, short-lived signed link rather
 * than a permanent one. The actual bytes are served by the public
 * Downloads::file() controller, which only trusts that signed token.
 */
class Downloads extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('ShopDeliveryService', 'DashboardStats'));
    }

    /** GET /dashboard/downloads */
    public function index() {
        $this->render('My Downloads', 'dashboard/shop/downloads', array(
            'downloads' => $this->shopdeliveryservice->for_user($this->current_user->id),
        ));
    }

    /** POST /dashboard/downloads/:id/link — issue a fresh signed download link. */
    public function link($delivery_public_id) {
        if ($this->input->method(true) !== 'POST') show_404();

        $res = $this->shopdeliveryservice->issue_link($delivery_public_id, $this->current_user->id);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/downloads');
        }
        redirect('downloads/file?token='.rawurlencode($res['token']));
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => 'dashboard/downloads',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }
}
