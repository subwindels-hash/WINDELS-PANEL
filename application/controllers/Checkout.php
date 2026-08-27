<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Checkout — /checkout. Charges through the platform's existing payment
 * architecture: ShopCheckoutService turns the cart into one or more
 * marketplace orders, each charged by MarketplaceService::purchase() ->
 * TransactionEngine, exactly the same wallet-charge path every other
 * purchase in this panel already uses. There is no separate/parallel payment
 * system here.
 */
class Checkout extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        if (!marvy_feature_enabled('marketplace', true)) show_404();
        $this->load->library(array('CartService', 'ShopCheckoutService', 'DashboardStats'));
        $this->load->model(array('Wallet_model', 'Shipping_address_model', 'Shipping_method_model'));
    }

    /** GET /checkout */
    public function index() {
        $check = $this->shopcheckoutservice->validate($this->current_user->id);
        if (empty($check['ok'])) {
            $this->session->set_flashdata('error', $check['error']);
            return redirect('cart');
        }

        $this->render('Checkout', 'public/shop/checkout', array_merge($check['view'], array(
            'wallet' => $this->Wallet_model->for_user($this->current_user->id),
            'addresses' => $this->Shipping_address_model->for_user($this->current_user->id),
            'shipping_methods' => $this->Shipping_method_model->active(),
        )));
    }

    /** POST /checkout — charge the cart. */
    public function place() {
        if ($this->input->method(true) !== 'POST') show_404();

        $res = $this->shopcheckoutservice->checkout($this->current_user, $this->input->post(null, true));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('checkout');
        }

        $this->session->set_flashdata('success',
            count($res['orders']) === 1 ? 'Order placed successfully.' : count($res['orders']).' orders placed successfully.');
        // One order: go straight to it. Several: the purchases list, so the
        // customer sees everything that was just bought in one place.
        if (count($res['orders']) === 1) {
            return redirect('dashboard/marketplace/orders/'.$res['orders'][0]->public_id);
        }
        redirect('dashboard/marketplace/orders');
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/main', array(
            'content_view' => $view,
            'data' => array_merge(array('title' => $title), $data),
        ));
    }
}
