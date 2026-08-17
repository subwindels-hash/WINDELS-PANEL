<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Orders — customer order history and detail (Session 06).
 *
 * This session ships list + detail (read-only). Order placement, mass-order,
 * drip-feed and subscriptions land in Sessions 09–10 via OrderService.
 */
class Orders extends Auth_Controller {

    const PER_PAGE = 15;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Order_model', 'Service_model'));
        $this->load->library('DashboardStats');
    }

    public function index() {
        $status = $this->input->get('status', true);
        $allowed = array('PENDING','PROCESSING','IN_PROGRESS','COMPLETED','PARTIAL','CANCELED','CANCELLED','FAILED');
        if ($status && !in_array($status, $allowed, true)) $status = null;

        $page = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $offset = ($page - 1) * $limit;

        $orders = $this->Order_model->for_user_with_service($this->current_user->id, $limit, $offset, $status);
        $total  = $this->Order_model->count_for_user($this->current_user->id, $status);

        $this->load->view('layouts/app', array(
            'title'        => 'My Orders',
            'nav_active'   => 'dashboard/orders',
            'content_view' => 'dashboard/orders/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'orders'       => $orders,
            'status'       => $status,
            'total'        => $total,
            'page'         => $page,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function detail($public_id) {
        $order = $this->Order_model->find_public_for_user($public_id, $this->current_user->id);
        if (!$order) show_404();

        $service = $this->Service_model->find_by_id($order->service_id);

        $this->load->model('Order_status_history_model');
        $history = $this->Order_status_history_model->for_order($order->id);

        $this->load->view('layouts/app', array(
            'title'        => 'Order #'.$public_id,
            'nav_active'   => 'dashboard/orders',
            'content_view' => 'dashboard/orders/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'order'        => $order,
            'service'      => $service,
            'history'      => $history,
        ));
    }

    /** Placeholder; the full new-order form ships in Session 09. */
    public function new_order() {
        $this->load->model(array('Service_model', 'Wallet_model'));
        $services = $this->Service_model->active();
        $wallet   = $this->Wallet_model->for_user($this->current_user->id);
        $this->load->view('layouts/app', array(
            'title'        => 'New Order',
            'nav_active'   => 'dashboard/new-order',
            'content_view' => 'dashboard/orders/new_order',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'services'     => $services,
            'wallet'       => $wallet,
        ));
    }

    /** Placeholder; mass-order form ships in Session 10. */
    public function mass_order() { redirect('dashboard/new-order'); }
}
