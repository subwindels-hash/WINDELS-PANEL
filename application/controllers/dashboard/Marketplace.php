<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Customer marketplace storefront, seller workspace and escrow actions. */
class Marketplace extends Auth_Controller {
    const PER_PAGE = 24;

    public function __construct() {
        parent::__construct();
        $this->load->library(array('MarketplaceService', 'DashboardStats'));
        $this->load->model(array(
            'Marketplace_seller_model', 'Marketplace_listing_model',
            'Marketplace_order_model', 'Wallet_model', 'Setting_model'
        ));
    }

    public function index() {
        $page = max(1, (int)$this->input->get('page'));
        $filters = array(
            'category' => $this->input->get('category', true),
            'search' => $this->input->get('q', true),
        );
        $offset = ($page - 1) * self::PER_PAGE;
        $this->view('index', 'Marketplace', array(
            'listings' => $this->Marketplace_listing_model->catalogue($filters, self::PER_PAGE, $offset),
            'total' => $this->Marketplace_listing_model->catalogue_count($filters),
            'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
            'seller' => $this->Marketplace_seller_model->find_for_user($this->current_user->id),
        ));
    }

    public function listing($public_id) {
        $listing = $this->Marketplace_listing_model->find_public($public_id, true);
        if (!$listing) show_404();
        // Wallet_model::for_user is a single-row accessor, not an unbounded
        // list query; keep that explicit for readers and source-level gates.
        $wallet = $this->Wallet_model->for_user($this->current_user->id);
        $this->view('listing', $listing->title, array(
            'listing' => $listing,
            'wallet' => $wallet,
        ));
    }

    public function buy($public_id) {
        $this->post_only();
        $res = $this->marketplaceservice->purchase($this->current_user, array(
            'listing' => $public_id,
            'quantity' => (int)$this->input->post('quantity', true),
            'idempotency_key' => 'marketplace:'.$this->current_user->id.':'
                .substr(sha1((string)$this->input->post('form_token', true)), 0, 32),
            'source' => 'WEB',
        ));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/marketplace/'.$public_id);
        }
        $this->session->set_flashdata('success', 'Payment secured. The seller can now fulfil your order.');
        redirect('dashboard/marketplace/orders/'.$res['order']->public_id);
    }

    public function seller() {
        $seller = $this->Marketplace_seller_model->find_for_user($this->current_user->id);
        $checks = $this->db
            ->select('identity_checks.id, identity_checks.id_type, identity_checks.identifier_last4, identity_checks.created_at')
            ->from('identity_checks')
            ->join('service_transactions', 'service_transactions.id = identity_checks.service_transaction_id', 'inner')
            ->where('service_transactions.user_id', $this->current_user->id)
            ->where('identity_checks.status', 'VERIFIED')
            ->order_by('identity_checks.created_at', 'DESC')->limit(20)->get()->result();
        $identity_setting = $this->Setting_model->get('marketplace_require_verified_identity', true);
        $require_identity = is_bool($identity_setting) ? $identity_setting
            : in_array(strtolower((string)$identity_setting), array('1', 'true', 'yes', 'on'), true);
        $this->view('seller', 'Seller workspace', array(
            'seller' => $seller,
            'checks' => $checks,
            'require_identity' => $require_identity,
            'listings' => $seller ? $this->Marketplace_listing_model->for_seller($seller->id, 50, 0) : array(),
        ));
    }

    public function apply() {
        $this->post_only();
        $res = $this->marketplaceservice->apply_seller($this->current_user, array(
            'display_name' => $this->input->post('display_name', true),
            'bio' => $this->input->post('bio', true),
            'identity_check_id' => $this->input->post('identity_check_id', true),
        ));
        $this->flash_result($res, 'Application submitted for review.');
        redirect('dashboard/marketplace/seller');
    }

    public function save_listing($public_id = null) {
        $this->post_only();
        $res = $this->marketplaceservice->save_listing($this->current_user, array(
            'title' => $this->input->post('title', true),
            'category' => $this->input->post('category', true),
            // XSS filtering is intentionally enabled; listings are rendered as
            // text, never trusted HTML.
            'description' => $this->input->post('description', true),
            'price' => $this->input->post('price', true),
            'stock' => $this->input->post('stock', true),
            'delivery_days' => $this->input->post('delivery_days', true),
        ), $public_id);
        $this->flash_result($res, 'Listing saved and sent for review.');
        redirect('dashboard/marketplace/seller');
    }

    public function listing_status($public_id) {
        $this->post_only();
        $res = $this->marketplaceservice->change_listing_status(
            $this->current_user, $public_id, $this->input->post('status', true)
        );
        $this->flash_result($res, 'Listing status updated.');
        redirect('dashboard/marketplace/seller');
    }

    public function orders() {
        $role = strtoupper((string)$this->input->get('as', true)) === 'SELLER' ? 'SELLER' : 'BUYER';
        $this->view('orders', 'Marketplace orders', array(
            'role' => $role,
            'orders' => $this->Marketplace_order_model->for_user($this->current_user->id, $role, 50, 0),
            'seller' => $this->Marketplace_seller_model->find_for_user($this->current_user->id),
        ));
    }

    public function order($public_id) {
        $order = $this->owned_order($public_id);
        $this->render_order($order, null);
    }

    public function deliver($public_id) {
        $this->post_only();
        $res = $this->marketplaceservice->deliver(
            $this->current_user, $public_id, $this->input->post('delivery', false)
        );
        $this->flash_result($res, 'Delivery submitted securely.');
        redirect('dashboard/marketplace/orders/'.$public_id);
    }

    public function reveal($public_id) {
        $this->post_only();
        $this->owned_order($public_id);
        $res = $this->marketplaceservice->reveal($this->current_user, $public_id, false);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/marketplace/orders/'.$public_id);
        }
        // Never flash delivery plaintext into the session. It exists for this
        // one audited response only.
        $this->render_order($this->Marketplace_order_model->detail_public($public_id), $res['delivery']);
    }

    public function accept($public_id) {
        $this->post_only();
        $res = $this->marketplaceservice->accept($this->current_user, $public_id);
        $this->flash_result($res, 'Order completed and the seller has been paid.');
        redirect('dashboard/marketplace/orders/'.$public_id);
    }

    public function dispute($public_id) {
        $this->post_only();
        $res = $this->marketplaceservice->dispute(
            $this->current_user, $public_id, $this->input->post('reason', true)
        );
        $this->flash_result($res, 'Dispute opened. Escrow will not auto-release while it is reviewed.');
        redirect('dashboard/marketplace/orders/'.$public_id);
    }

    private function owned_order($public_id) {
        $order = $this->Marketplace_order_model->detail_public($public_id);
        if (!$order || !in_array((int)$this->current_user->id,
            array((int)$order->buyer_id, (int)$order->seller_id), true)) show_404();
        return $order;
    }

    private function render_order($order, $plain) {
        $this->view('order', 'Marketplace order', array(
            'order' => $order,
            'events' => $this->Marketplace_order_model->events($order->id),
            'plain' => $plain,
            'is_buyer' => (int)$order->buyer_id === (int)$this->current_user->id,
            'is_seller' => (int)$order->seller_id === (int)$this->current_user->id,
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
            'nav_active' => 'dashboard/marketplace',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/marketplace/'.$view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
        ), $data));
    }
}
