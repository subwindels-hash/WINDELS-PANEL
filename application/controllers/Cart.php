<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cart — /cart. Requires an account, same as every other purchase path in
 * this panel (a wallet, and therefore a cart, only exists for a signed-in
 * customer).
 */
class Cart extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        if (!marvy_feature_enabled('marketplace', true)) show_404();
        $this->load->library('CartService');
        $this->load->model('Coupon_model');
    }

    /** GET /cart */
    public function index() {
        $view = $this->cartservice->view($this->current_user->id);
        $view['available_coupons'] = $this->Coupon_model->public_valid(10);
        $this->render('Your cart', 'public/shop/cart', $view);
    }

    /** POST /cart/add */
    public function add() {
        $this->post_only();
        $res = $this->cartservice->add(
            $this->current_user->id,
            $this->input->post('listing', true),
            (int)$this->input->post('quantity')
        );
        $this->finish($res, 'Added to your cart.');
        redirect($this->input->post('redirect_to', true) ?: 'cart');
    }

    /** POST /cart/update */
    public function update() {
        $this->post_only();
        $res = $this->cartservice->set_quantity(
            $this->current_user->id,
            $this->input->post('listing', true),
            (int)$this->input->post('quantity')
        );
        $this->finish($res, 'Cart updated.');
        redirect('cart');
    }

    /** POST /cart/remove */
    public function remove() {
        $this->post_only();
        $res = $this->cartservice->remove($this->current_user->id, $this->input->post('listing', true));
        $this->finish($res, 'Removed from your cart.');
        redirect('cart');
    }

    /** POST /cart/coupon */
    public function coupon() {
        $this->post_only();
        $action = $this->input->post('action', true);
        $res = $action === 'remove'
            ? $this->cartservice->remove_coupon($this->current_user->id)
            : $this->cartservice->apply_coupon($this->current_user->id, $this->input->post('code', true));
        $this->finish($res, $action === 'remove' ? 'Coupon removed.' : 'Coupon applied.');
        redirect('cart');
    }

    /* ------------------------------ helpers ----------------------------- */

    private function post_only() {
        if ($this->input->method(true) !== 'POST') show_404();
    }

    private function finish($res, $success) {
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : $success);
    }

    private function render($title, $view, array $data) {
        $this->load->library('DashboardStats');
        $this->load->view('layouts/main', array(
            'content_view' => $view,
            'data' => array_merge(array('title' => $title), $data),
        ));
    }
}
