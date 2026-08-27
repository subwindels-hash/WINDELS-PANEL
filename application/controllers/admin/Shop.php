<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Shop — the parts of shop management that sit outside the existing
 * Marketplace/Giftcards admin screens: digital-download access control,
 * physical-order shipment tracking, shipping methods and coupons.
 *
 * Products, categories and orders themselves stay exactly where they already
 * are (Admin → Marketplace, Admin → Gift cards) — this controller does not
 * duplicate that management surface, only the shop-specific pieces that did
 * not exist before (secure download revocation, shipment/tracking, coupons).
 * Gated on the same `marketplace.manage`/`marketplace.view` permissions the
 * rest of the shop catalogue already uses.
 */
class Shop extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('marketplace.view');
        $this->load->library(array('DashboardStats', 'ShopDeliveryService', 'CouponService'));
        $this->load->model(array(
            'Digital_delivery_model', 'Shop_order_shipment_model',
            'Shipping_method_model', 'Coupon_model', 'Product_review_model',
            'Audit_log_model',
        ));
    }

    /** GET /admin/shop — dashboard overview linking to each area. */
    public function index() {
        $this->render('Shop', 'admin/shop/index', 'admin/shop', array(
            'shipment_counts' => $this->shipment_status_counts(),
        ));
    }

    /* ------------------------------ downloads ---------------------------- */

    /** GET /admin/shop/downloads */
    public function downloads() {
        $page = max(1, (int)$this->input->get('page'));
        $rows = $this->ci_db_downloads($page);
        $this->render('Digital downloads', 'admin/shop/downloads', 'admin/shop', array(
            'downloads' => $rows['rows'],
            'total' => $rows['total'],
            'page' => $page,
        ));
    }

    /** POST /admin/shop/downloads/:id/revoke */
    public function revoke_download($public_id) {
        $this->guard('marketplace.manage');
        $res = $this->shopdeliveryservice->revoke($public_id, $this->current_user->id, $this->input->post('reason', true));
        $this->finish($res, 'Download access revoked.', 'admin/shop/downloads');
    }

    /** POST /admin/shop/downloads/:id/restore */
    public function restore_download($public_id) {
        $this->guard('marketplace.manage');
        $res = $this->shopdeliveryservice->restore($public_id, $this->current_user->id);
        $this->finish($res, 'Download access restored.', 'admin/shop/downloads');
    }

    /* ------------------------------ shipments ---------------------------- */

    /** GET /admin/shop/shipments */
    public function shipments() {
        $page = max(1, (int)$this->input->get('page'));
        $filters = array('status' => $this->input->get('status', true));
        $this->render('Shipments', 'admin/shop/shipments', 'admin/shop', array(
            'shipments' => $this->Shop_order_shipment_model->admin_search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $this->Shop_order_shipment_model->admin_count($filters),
            'filters' => $filters,
            'page' => $page,
            'statuses' => Shop_order_shipment_model::STATUSES,
        ));
    }

    /** GET /admin/shop/shipments/:id */
    public function shipment($public_id) {
        $shipment = $this->Shop_order_shipment_model->find_public($public_id);
        if (!$shipment) show_404();
        $this->render('Shipment '.$shipment->public_id, 'admin/shop/shipment_detail', 'admin/shop', array(
            'shipment' => $shipment,
            'statuses' => Shop_order_shipment_model::STATUSES,
        ));
    }

    /** POST /admin/shop/shipments/:id/status */
    public function update_shipment($public_id) {
        $this->guard('marketplace.manage');
        $shipment = $this->Shop_order_shipment_model->find_public($public_id);
        if (!$shipment) show_404();

        $status = strtoupper((string)$this->input->post('status', true));
        if (!in_array($status, Shop_order_shipment_model::STATUSES, true)) {
            $this->session->set_flashdata('error', 'Choose a valid shipment status.');
            return redirect('admin/shop/shipments/'.$public_id);
        }

        $extra = array();
        $tracking = trim((string)$this->input->post('tracking_number', true));
        $carrier = trim((string)$this->input->post('carrier', true));
        if ($tracking !== '') $extra['tracking_number'] = mb_substr($tracking, 0, 120);
        if ($carrier !== '') $extra['carrier'] = mb_substr($carrier, 0, 80);
        if ($status === 'SHIPPED' && empty($shipment->shipped_at)) $extra['shipped_at'] = gmdate('Y-m-d H:i:s');
        if ($status === 'DELIVERED' && empty($shipment->delivered_at)) $extra['delivered_at'] = gmdate('Y-m-d H:i:s');

        $before = array('status' => $shipment->status);
        $this->Shop_order_shipment_model->update_status($shipment->id, $status, $extra);
        $this->Audit_log_model->record(
            $this->current_user->id, 'shop.shipment.updated', 'shop_order_shipments', $public_id,
            $before, array_merge(array('status' => $status), $extra),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata('success', 'Shipment updated.');
        redirect('admin/shop/shipments/'.$public_id);
    }

    /**
     * POST /admin/shop/shipments/:id/refund — refund the underlying
     * marketplace order from the shipment screen, without sending staff to
     * a different admin section to find it. Delegates to the exact same
     * MarketplaceService::refund() escrow-refund path admin/Marketplace's
     * dispute-resolution screen uses for any order type — this is a second
     * entry point to one refund implementation, not a second refund system.
     * Gated on marketplace.resolve (a sharper permission than
     * marketplace.manage — refunding money is a bigger action than
     * updating a tracking number) exactly like the existing dispute flow.
     */
    public function refund_shipment($public_id) {
        $this->guard('marketplace.resolve');
        $shipment = $this->Shop_order_shipment_model->find_public($public_id);
        if (!$shipment) show_404();

        $this->load->library('MarketplaceService');
        $this->load->model('Marketplace_order_model');
        $order = $this->Marketplace_order_model->find_id($shipment->marketplace_order_id);
        if (!$order) {
            $this->session->set_flashdata('error', 'The order behind this shipment could not be found.');
            return redirect('admin/shop/shipments/'.$public_id);
        }

        $reason = trim((string)$this->input->post('reason', true));
        $res = $this->marketplaceservice->refund($order, $this->current_user->id, $reason);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not refund this order.');
        } else {
            $this->Shop_order_shipment_model->update_status($shipment->id, 'CANCELLED', array());
            $this->Audit_log_model->record(
                $this->current_user->id, 'shop.shipment.refunded', 'shop_order_shipments', $public_id,
                array('order_status' => $shipment->order_status), array('order_status' => 'REFUNDED'),
                $this->input->ip_address(), $this->input->user_agent(), $this->request_id
            );
            $this->session->set_flashdata('success', 'Order refunded from escrow and the shipment marked cancelled.');
        }
        redirect('admin/shop/shipments/'.$public_id);
    }

    /* ------------------------------ shipping methods ---------------------------- */

    /** GET /admin/shop/shipping-methods */
    public function shipping_methods() {
        $this->render('Shipping methods', 'admin/shop/shipping_methods', 'admin/shop', array(
            'methods' => $this->Shipping_method_model->all_rows(),
        ));
    }

    /** POST /admin/shop/shipping-methods/save */
    public function save_shipping_method() {
        $this->guard('marketplace.manage');
        $name = trim((string)$this->input->post('name', true));
        if ($name === '') {
            $this->session->set_flashdata('error', 'A shipping method needs a name.');
            return redirect('admin/shop/shipping-methods');
        }
        $this->Shipping_method_model->create(array(
            'name' => mb_substr($name, 0, 120),
            'carrier' => mb_substr((string)$this->input->post('carrier', true), 0, 80) ?: null,
            'price' => number_format((float)$this->input->post('price'), 8, '.', ''),
            'currency' => marvy_base_currency(),
            'estimated_days_min' => $this->input->post('estimated_days_min') !== '' ? (int)$this->input->post('estimated_days_min') : null,
            'estimated_days_max' => $this->input->post('estimated_days_max') !== '' ? (int)$this->input->post('estimated_days_max') : null,
            'is_active' => 1,
        ));
        $this->session->set_flashdata('success', 'Shipping method added.');
        redirect('admin/shop/shipping-methods');
    }

    /** POST /admin/shop/shipping-methods/:id/status */
    public function shipping_method_status($public_id) {
        $this->guard('marketplace.manage');
        $method = $this->Shipping_method_model->find_public($public_id);
        if (!$method) show_404();
        $this->Shipping_method_model->update_fields($method->id, array('is_active' => (int)$method->is_active === 1 ? 0 : 1));
        redirect('admin/shop/shipping-methods');
    }

    /* ------------------------------ coupons ---------------------------- */

    /** GET /admin/shop/coupons */
    public function coupons() {
        $this->render('Coupons', 'admin/shop/coupons', 'admin/shop', array(
            'coupons' => $this->Coupon_model->admin_search(50, 0),
            'total' => $this->Coupon_model->admin_count(),
        ));
    }

    /** POST /admin/shop/coupons/save */
    public function save_coupon($public_id = null) {
        $this->guard('marketplace.manage');
        $res = $this->couponservice->save($this->input->post(null, true), $this->current_user->id, $public_id);
        $this->finish($res, $public_id ? 'Coupon updated.' : 'Coupon created.', 'admin/shop/coupons');
    }

    /** POST /admin/shop/coupons/:id/status */
    public function coupon_status($public_id) {
        $this->guard('marketplace.manage');
        $active = (bool)$this->input->post('active');
        $res = $this->couponservice->set_active($public_id, $active);
        $this->finish($res, $active ? 'Coupon enabled.' : 'Coupon disabled.', 'admin/shop/coupons');
    }

    /** POST /admin/shop/coupons/:id/visibility — list/unlist on the cart page. */
    public function coupon_visibility($public_id) {
        $this->guard('marketplace.manage');
        $public = (bool)$this->input->post('public');
        $res = $this->couponservice->set_public($public_id, $public);
        $this->finish($res, $public ? 'Coupon is now listed on the cart page.' : 'Coupon unlisted.', 'admin/shop/coupons');
    }

    /* ------------------------------ reviews ---------------------------- */

    /** GET /admin/shop/reviews */
    public function reviews() {
        $page = max(1, (int)$this->input->get('page'));
        $filters = array('status' => $this->input->get('status', true));
        $this->render('Reviews', 'admin/shop/reviews', 'admin/shop', array(
            'reviews' => $this->Product_review_model->admin_search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $this->Product_review_model->admin_count($filters),
            'filters' => $filters,
            'page' => $page,
        ));
    }

    /** POST /admin/shop/reviews/:id/moderate */
    public function moderate_review($public_id) {
        $this->guard('marketplace.moderate_listings');
        $this->load->library('ProductReviewService');
        $res = $this->productreviewservice->moderate($public_id, $this->input->post('decision', true), $this->current_user->id);
        $this->finish($res, 'Review moderated.', 'admin/shop/reviews');
    }

    /* ------------------------------ helpers ----------------------------- */

    private function shipment_status_counts() {
        $rows = $this->db->select('status, COUNT(*) AS n', false)->group_by('status')->get('shop_order_shipments')->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->n;
        return $out;
    }

    private function ci_db_downloads($page) {
        $limit = self::PER_PAGE;
        $offset = ($page - 1) * $limit;
        $rows = $this->db
            ->select('digital_deliveries.*, marketplace_listings.title AS listing_title, users.username, users.email', false)
            ->from('digital_deliveries')
            ->join('digital_products', 'digital_products.id = digital_deliveries.digital_product_id', 'left')
            ->join('marketplace_listings', 'marketplace_listings.id = digital_products.listing_id', 'left')
            ->join('users', 'users.id = digital_deliveries.user_id', 'left')
            ->order_by('digital_deliveries.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
        $total = (int)$this->db->count_all('digital_deliveries');
        return array('rows' => $rows, 'total' => $total);
    }

    private function guard($perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
    }

    private function finish($res, $success, $redirect_to) {
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : $success);
        redirect($redirect_to);
    }

    private function render($title, $view, $nav, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => $nav,
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }
}
