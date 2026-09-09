<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Orders — the operational order queue (Session 15).
 *
 * Read requires `orders.view`; each mutation requires its own permission
 * (`orders.edit`, `orders.cancel`, `orders.refund`) and is POST-only,
 * CSRF-protected and audit-logged.
 *
 * Every state change goes through OrderService::apply_status(), so the state
 * machine, the append-only history and refund-through-LedgerService rules are
 * identical to the customer and cron paths. This controller never writes
 * `orders.status` or touches a wallet directly.
 */
class Orders extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('orders.view');
        $this->load->library(array('OrderService', 'DashboardStats'));
        $this->load->model(array(
            'Order_model', 'Order_status_history_model', 'Service_model',
            'Provider_model', 'User_model', 'Audit_log_model',
        ));
    }

    /** GET /admin/orders/failed */
    public function failed() {
        $_GET['status'] = $this->input->get('status') ?: 'FAILED';
        $this->index();
    }

    /** GET /admin/refunds — orders with a recorded refund. */
    public function refunds() {
        $page = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $this->db->from('orders')->where('refunded_amount >', 0);
        $total = (int)$this->db->count_all_results();
        $rows = $this->db->select('orders.*, users.username, services.name AS service_name', false)
            ->from('orders')
            ->join('users', 'users.id = orders.user_id', 'left')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->where('orders.refunded_amount >', 0)
            ->order_by('orders.updated_at', 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->get()->result();

        $this->load->view('layouts/app', array(
            'title'        => 'Refunds',
            'nav_active'   => 'admin/orders',
            'content_view' => 'admin/orders/refunds',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'orders'       => $rows,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
            'page_description' => 'Orders that already have a ledger refund recorded.',
        ));
    }

    public function index() {
        $filters = array(
            'status'  => $this->input->get('status', true),
            'source'  => $this->input->get('source', true),
            'search'  => $this->input->get('q', true),
            'user_id' => $this->input->get('user_id', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $total = $this->Order_model->admin_count($filters);

        $this->load->view('layouts/app', array(
            'title'        => 'Orders',
            'nav_active'   => 'admin/orders',
            'content_view' => 'admin/orders/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'orders'       => $this->Order_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'counts'       => $this->Order_model->status_counts(),
            'filters'      => $filters,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function detail($public_id) {
        $order = $this->Order_model->admin_find($public_id);
        if (!$order) show_404();

        $this->load->view('layouts/app', array(
            'title'        => 'Order '.$order->public_id,
            'nav_active'   => 'admin/orders',
            'content_view' => 'admin/orders/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'order'        => $order,
            'history'      => $this->Order_status_history_model->for_order($order->id),
        ));
    }

    /** POST /admin/orders/:id/status — drive the order through the state machine. */
    public function status($public_id) {
        $order = $this->guard($public_id, 'orders.edit');

        $new    = strtoupper((string)$this->input->post('status', true));
        $reason = trim((string)$this->input->post('reason', true));
        $extra  = array();
        // PARTIAL needs the delivered remainder so the refund can be computed.
        if ($new === 'PARTIAL') {
            $remains = $this->input->post('remains', true);
            if ($remains === null || $remains === '' || !ctype_digit((string)$remains)) {
                return $this->fail($order, 'A whole-number "remains" value is required for a partial delivery.');
            }
            if ((int)$remains > (int)$order->quantity) {
                return $this->fail($order, 'Remains cannot exceed the ordered quantity.');
            }
            $extra['remains'] = (int)$remains;
        }

        $before = array('status' => $order->status);
        $result = $this->orderservice->apply_status($order, $new, 'ADMIN', $reason ?: null, $extra);
        if (empty($result['ok'])) {
            return $this->fail($order, $result['error'] ?? 'Could not change the order status.');
        }

        $this->audit('order.status_changed', $order, $before, array('status' => $new, 'reason' => $reason));
        $this->session->set_flashdata('success', 'Order moved to '.$new.'.');
        redirect('admin/orders/'.$order->public_id);
    }

    /**
     * POST /admin/orders/:id/cancel — cancel and refund in one step.
     *
     * This used to go through apply_status(), which never spoke to the
     * provider at all: staff cancelled the order, the customer's wallet was
     * credited, and the provider carried on delivering an order we had already
     * paid for. It now goes through OrderService::cancel(), which asks the
     * provider first and refuses when the provider says no — unless staff tick
     * "cancel anyway", which is a cost they are choosing to accept.
     */
    public function cancel($public_id) {
        $order  = $this->guard($public_id, 'orders.cancel');
        $reason = trim((string)$this->input->post('reason', true));
        $force  = (bool)$this->input->post('force');

        $before = array('status' => $order->status);
        $result = $this->orderservice->cancel($order, null, array(
            'source' => 'ADMIN', 'force' => $force, 'reason' => $reason ?: 'Canceled by staff',
        ));
        if (empty($result['ok'])) {
            $message = $result['error'] ?? 'Could not cancel the order.';
            if (($result['code'] ?? '') === 'PROVIDER_REFUSED') {
                $message .= ' Tick “cancel anyway” to refund the customer regardless — the provider charge stands.';
            }
            return $this->fail($order, $message);
        }

        $this->audit('order.canceled', $order, $before, array('status' => 'CANCELED', 'reason' => $reason));
        $this->session->set_flashdata('success', 'Order canceled and the charge refunded.');
        redirect('admin/orders/'.$order->public_id);
    }

    /** POST /admin/orders/:id/submit — submit a held (PENDING) order to its provider. */
    public function submit($public_id) {
        $order = $this->guard($public_id, 'orders.edit');

        $before = array('status' => $order->status, 'provider_order_id' => $order->provider_order_id);
        $result = $this->orderservice->manually_submit($order, $this->current_user);
        if (empty($result['ok'])) {
            return $this->fail($order, $result['error'] ?? 'Could not submit the order.');
        }

        $this->audit('order.submitted', $order, $before, array(
            'status' => 'PROCESSING',
            'provider_order_id' => $result['order']->provider_order_id,
        ));
        $this->session->set_flashdata('success', 'Order submitted to the provider.');
        redirect('admin/orders/'.$order->public_id);
    }

    /** POST /admin/orders/:id/refund — refund a completed order. */
    public function refund($public_id) {
        $order  = $this->guard($public_id, 'orders.refund');
        $reason = trim((string)$this->input->post('reason', true));

        $before = array('status' => $order->status, 'refunded_amount' => $order->refunded_amount);
        $result = $this->orderservice->apply_status($order, 'REFUNDED', 'ADMIN', $reason ?: 'Refunded by staff');
        if (empty($result['ok'])) {
            return $this->fail($order, $result['error'] ?? 'Could not refund the order.');
        }

        $this->audit('order.refunded', $order, $before, array('status' => 'REFUNDED', 'reason' => $reason));
        $this->session->set_flashdata('success', 'Order refunded.');
        redirect('admin/orders/'.$order->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $order = $this->Order_model->admin_find($public_id);
        if (!$order) show_404();
        return $order;
    }

    private function fail($order, $message) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/orders/'.$order->public_id);
    }

    private function audit($action, $order, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'orders', (string)$order->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
