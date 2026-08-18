<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Permission-gated marketplace moderation and escrow dispute queue. */
class Marketplace extends Admin_Controller {
    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('marketplace.view');
        $this->load->library(array('MarketplaceService', 'DashboardStats'));
        $this->load->model(array(
            'Marketplace_seller_model', 'Marketplace_listing_model',
            'Marketplace_order_model', 'Audit_log_model'
        ));
    }

    public function index() {
        $tab = strtolower((string)$this->input->get('tab', true));
        if (!in_array($tab, array('orders', 'sellers', 'listings'), true)) $tab = 'orders';
        $page = max(1, (int)$this->input->get('page'));
        $filters = array(
            'status' => strtoupper((string)$this->input->get('status', true)),
            'search' => $this->input->get('q', true),
        );
        $offset = ($page - 1) * self::PER_PAGE;
        if ($tab === 'sellers') {
            $rows = $this->Marketplace_seller_model->admin_search($filters, self::PER_PAGE, $offset);
            $total = $this->Marketplace_seller_model->admin_count($filters);
        } elseif ($tab === 'listings') {
            $rows = $this->Marketplace_listing_model->admin_search($filters, self::PER_PAGE, $offset);
            $total = $this->Marketplace_listing_model->admin_count($filters);
        } else {
            $rows = $this->Marketplace_order_model->admin_search($filters, self::PER_PAGE, $offset);
            $total = $this->Marketplace_order_model->admin_count($filters);
        }
        $this->view('index', 'Marketplace operations', array(
            'tab' => $tab, 'rows' => $rows, 'total' => $total,
            'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
        ));
    }

    public function order($public_id) {
        $order = $this->Marketplace_order_model->detail_public($public_id);
        if (!$order) show_404();
        $this->render_order($order, null);
    }

    public function reveal($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.reveal');
        $res = $this->marketplaceservice->reveal($this->current_user, $public_id, true);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('admin/marketplace/orders/'.$public_id);
        }
        $this->render_order($this->Marketplace_order_model->detail_public($public_id), $res['delivery']);
    }

    public function resolve($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.resolve');
        $order = $this->Marketplace_order_model->find_public($public_id);
        if (!$order) show_404();
        $resolution = strtoupper((string)$this->input->post('resolution', true));
        $reason = $this->input->post('reason', true);
        if ($resolution === 'RELEASE') {
            $res = $this->marketplaceservice->release($order, 'ADMIN', $this->current_user->id);
            $success = 'Escrow released to the seller.';
        } elseif ($resolution === 'REFUND') {
            $res = $this->marketplaceservice->refund($order, $this->current_user->id, $reason);
            $success = 'Buyer refunded from escrow.';
        } else {
            $res = array('ok' => false, 'error' => 'Choose a valid resolution.');
            $success = '';
        }
        $this->flash_result($res, $success);
        redirect('admin/marketplace/orders/'.$public_id);
    }

    public function moderate_seller($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.moderate_sellers');
        $res = $this->marketplaceservice->moderate_seller(
            $public_id, $this->input->post('status', true), $this->current_user->id,
            $this->input->post('note', true)
        );
        $this->flash_result($res, 'Seller status updated.');
        redirect('admin/marketplace?tab=sellers');
    }

    public function moderate_listing($public_id) {
        $this->post_only();
        $this->require_perm('marketplace.moderate_listings');
        $res = $this->marketplaceservice->moderate_listing(
            $public_id, $this->input->post('status', true), $this->current_user->id,
            $this->input->post('note', true)
        );
        $this->flash_result($res, 'Listing moderation saved.');
        redirect('admin/marketplace?tab=listings');
    }

    private function render_order($order, $plain) {
        $this->view('order', 'Marketplace order', array(
            'order' => $order,
            'events' => $this->Marketplace_order_model->events($order->id),
            'plain' => $plain,
        ));
    }

    private function post_only() {
        if ($this->input->method() !== 'post') show_404();
    }

    private function flash_result(array $res, $success) {
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : $success);
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => 'admin/marketplace',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'admin/marketplace/'.$view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
        ), $data));
    }
}
