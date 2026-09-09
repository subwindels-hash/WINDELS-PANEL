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

    /** One page of downloads; the list is bounded so a long purchase history
     *  can never render an unpaginated query. */
    const PER_PAGE = 50;

    /** GET /dashboard/downloads */
    public function index() {
        $page   = max(1, (int)$this->input->get('page'));
        $limit  = self::PER_PAGE;
        $offset = ($page - 1) * $limit;
        $rows   = $this->shopdeliveryservice->for_user($this->current_user->id, $limit + 1, $offset);
        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);

        $this->render('My Downloads', 'dashboard/shop/downloads', array(
            'downloads' => $rows,
            'page'      => $page,
            'has_more'  => $has_more,
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
