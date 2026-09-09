<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Customer marketplace storefront and buyer-side escrow actions.
 *
 * Customers are BUYERS ONLY. There is deliberately no seller workspace, no
 * application form and no fulfilment endpoint here: the platform — and only the
 * platform, through admin/marketplace — sells. The service double-checks the
 * role so a direct POST to a removed route still cannot mint a seller.
 */
class Marketplace extends Auth_Controller {
    const PER_PAGE = 24;

    public function __construct() {
        parent::__construct();
        $this->load->library(array('MarketplaceService', 'DashboardStats'));
        $this->load->model(array(
            'Marketplace_listing_model', 'Marketplace_order_model',
            'Marketplace_category_model', 'Wallet_model', 'Setting_model',
            'Shop_order_shipment_model'
        ));
        // 'orders' stays reachable even when the module is switched off, so a
        // customer's existing escrow/order history and downloads never
        // disappear — only browsing/buying new listings is blocked.
        $method = strtolower((string)$this->router->fetch_method());
        if (!marvy_feature_enabled('marketplace', true) && $method !== 'orders'
            && strpos($method, 'order') === false && strpos($method, 'reveal') === false
            && strpos($method, 'accept') === false && strpos($method, 'dispute') === false) {
            show_404();
        }
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
            'featured' => $this->Marketplace_listing_model->featured(6),
            'categories' => $this->Marketplace_category_model->active(),
            'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
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
        $listing = $this->Marketplace_listing_model->find_public($public_id, true);
        if (!$listing) show_404();

        // Physical purchases need an address and a carrier quote, neither of
        // which belongs on the one-click marketplace form. Put the requested
        // quantity in the real cart and send the buyer through checkout rather
        // than creating a paid order with no shipment record.
        if (strtoupper((string)$listing->product_type) === 'PHYSICAL') {
            $this->load->library('CartService');
            $res = $this->cartservice->add(
                $this->current_user->id, $public_id,
                (int)$this->input->post('quantity', true)
            );
            if (empty($res['ok'])) {
                $this->session->set_flashdata('error', $res['error']);
                return redirect('dashboard/marketplace/'.$public_id);
            }
            $this->session->set_flashdata('success', 'Item added to your cart. Enter shipping details to continue.');
            return redirect('checkout');
        }

        $res = $this->marketplaceservice->purchase($this->current_user, array(
            'listing' => $public_id,
            // Quantity is the ONLY customer-chosen number; the price is looked
            // up server-side inside purchase().
            'quantity' => (int)$this->input->post('quantity', true),
            // Only a token the client actually sent can deduplicate anything.
            // Hashing an empty string gave every tokenless purchase by this
            // customer the SAME key, so their second one resolved to their
            // first as a "duplicate": they were shown an old order, no charge
            // was made and no new order existed. A missing token means no
            // double-click protection, not a permanent one-purchase limit.
            'idempotency_key' => $this->purchase_key(),
            'source' => 'WEB',
        ));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/marketplace/'.$public_id);
        }
        $this->session->set_flashdata('success', 'Payment secured. Your order is being fulfilled.');
        redirect('dashboard/marketplace/orders/'.$res['order']->public_id);
    }

    /**
     * The double-click key for one purchase attempt, or null when the client
     * did not supply a token to deduplicate against.
     */
    private function purchase_key() {
        $token = trim((string)$this->input->post('form_token', true));
        if ($token === '') return null;
        return 'marketplace:'.$this->current_user->id.':'.substr(sha1($token), 0, 32);
    }

    public function orders() {
        $this->view('orders', 'Marketplace orders', array(
            'orders' => $this->Marketplace_order_model->for_user($this->current_user->id, 50, 0),
        ));
    }

    public function order($public_id) {
        $order = $this->owned_order($public_id);
        $this->render_order($order, null);
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
        $this->flash_result($res, 'Order completed.');
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

    /* ------------------------------ helpers ----------------------------- */

    /** Buyers only ever see orders they bought. */
    private function owned_order($public_id) {
        $order = $this->Marketplace_order_model->detail_public($public_id);
        if (!$order || (int)$order->buyer_id !== (int)$this->current_user->id) show_404();
        return $order;
    }

    private function render_order($order, $plain) {
        $this->view('order', 'Marketplace order', array(
            'order' => $order,
            'shipment' => $this->Shop_order_shipment_model->for_order($order->id),
            'events' => $this->Marketplace_order_model->events($order->id),
            'plain' => $plain,
            'is_buyer' => true,
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
