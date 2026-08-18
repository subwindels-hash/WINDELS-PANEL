<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Orders — customer order history, detail, and placement.
 *
 * Order placement is delegated to OrderService (Session 09): validation,
 * pricing, ledger charge, state history and provider submission.
 */
class Orders extends Auth_Controller {

    const PER_PAGE = 15;

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Order_model', 'Service_model', 'Order_status_history_model', 'Refill_model'));
        $this->load->library(array('DashboardStats', 'OrderService', 'PricingService', 'RefillService', 'form_validation'));
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
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/orders/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
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
        $history = $this->Order_status_history_model->for_order($order->id);

        $this->load->view('layouts/app', array(
            'title'        => 'Order #'.$public_id,
            'nav_active'   => 'dashboard/orders',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/orders/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'order'        => $order,
            'service'      => $service,
            'history'      => $history,
        ));
    }

    /** GET /dashboard/new-order — order form. */
    public function new_order() {
        $this->load->model('Wallet_model');
        $service = null;
        $svc_param = $this->input->get('service', true);
        if ($svc_param) {
            $service = ctype_digit((string)$svc_param)
                ? $this->Service_model->find_by_id((int)$svc_param)
                : $this->Service_model->find_by_public_id($svc_param);
        }
        $categories = $this->db->order_by('sorting','ASC')->get('service_categories')->result();
        $services = $this->Service_model->active_for_picker();
        $wallet = $this->Wallet_model->for_user($this->current_user->id);

        $this->load->view('layouts/app', array(
            'title'        => 'New Order',
            'nav_active'   => 'dashboard/new-order',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/orders/new_order',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'service'      => $service,
            'services'     => $services,
            'categories'   => $categories,
            'wallet'       => $wallet,
            'user_rate'    => $service ? $this->pricingservice->price_for($service, $this->current_user) : null,
        ));
    }

    /** POST /dashboard/orders — place an order. */
    public function create() {
        if ($this->input->method(true) !== 'POST') show_404();

        $this->form_validation->set_rules('service', 'Service', 'required|trim');
        $this->form_validation->set_rules('link', 'Link', 'required|trim|max_length[2048]');
        $this->form_validation->set_rules('quantity', 'Quantity', 'required|integer|greater_than[0]');
        if (!$this->form_validation->run()) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('dashboard/new-order');
        }

        $payload = array(
            'service'         => $this->input->post('service', true),
            'link'            => $this->input->post('link', true),
            'quantity'        => (int)$this->input->post('quantity'),
            'note'            => $this->input->post('note', true),
            'idempotency_key' => $this->input->post('idempotency_key') ?: $this->generate_idem(),
            'source'          => 'WEB',
        );

        $result = $this->orderservice->place($this->current_user, $payload);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $this->place_error($result['code'] ?? '', $result['error'] ?? ''));
            // Preserve input so the form is not wiped on a soft error.
            $this->session->set_flashdata('old', $payload);
            return redirect('dashboard/new-order');
        }

        $this->session->set_flashdata('success',
            !empty($result['duplicate']) ? 'Order already exists.' : 'Order placed successfully.');
        redirect('dashboard/orders/'.$result['order']->public_id);
    }

    /** POST /dashboard/orders/:public_id/cancel */
    public function cancel($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $result = $this->orderservice->cancel($public_id, $this->current_user);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error'] ?? 'Could not cancel order.');
        } else {
            $this->session->set_flashdata('success', 'Order canceled.');
        }
        redirect('dashboard/orders/'.$public_id);
    }

    /** GET /dashboard/mass-order — bounded bulk-entry form and last result. */
    public function mass_order() {
        $this->guard_mass_orders();
        $this->load->model('Wallet_model');

        $old = $this->session->flashdata('mass_old');
        $wallet = $this->Wallet_model->for_user($this->current_user->id);
        $services = $this->Service_model->active_for_picker();
        $rates = array();
        foreach ($services as $service) {
            $rates[(int)$service->id] = $this->pricingservice->price_for($service, $this->current_user);
        }

        $this->load->view('layouts/app', array(
            'title'        => 'Mass Order',
            'nav_active'   => 'dashboard/mass-order',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/orders/mass_order',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'services'     => $services,
            'service_rates'=> $rates,
            'wallet'       => $wallet,
            'batch_token'  => !empty($old['batch_token']) ? $old['batch_token'] : $this->generate_mass_token(),
            'mass_input'   => isset($old['orders']) ? $old['orders'] : '',
            'mass_result'  => $this->session->flashdata('mass_result'),
        ));
    }

    /** POST /dashboard/mass-order/create — process rows independently. */
    public function mass_create() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->guard_mass_orders();
        $this->load->library('MassOrderService');

        $orders_input = $this->input->post('orders');
        $token_input = $this->input->post('batch_token');
        $orders = is_string($orders_input) ? $orders_input : '';
        $batch_token = is_scalar($token_input) ? (string)$token_input : '';
        $result = $this->massorderservice->process_text($this->current_user, $orders, $batch_token);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error'] ?? 'Could not process the mass order.');
            $this->session->set_flashdata('mass_old', array(
                'orders' => strlen($orders) <= MassOrderService::MAX_BYTES ? $orders : '',
                'batch_token' => $batch_token,
            ));
        } else {
            $this->session->set_flashdata('mass_result', $result);
        }
        redirect('dashboard/mass-order');
    }

    /** POST /dashboard/orders/:public_id/refill */
    public function refill($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->refillservice->request($public_id, $this->current_user);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not request refill');
        } else {
            $this->session->set_flashdata('success', 'Refill requested.');
        }
        redirect('dashboard/orders/'.$public_id);
    }

    /* -------------------------------------------------------------- */

    private function generate_idem() {
        // Stored in the form so a back-button resubmit returns the same order.
        return 'web:'.$this->current_user->id.':'.bin2hex(random_bytes(12));
    }

    private function generate_mass_token() {
        return 'mass.'.$this->current_user->id.'.'.bin2hex(random_bytes(24));
    }

    private function guard_mass_orders() {
        $this->load->model('Feature_flag_model');
        if (!$this->Feature_flag_model->enabled('mass_order')) show_404();
    }

    private function place_error($code, $fallback) {
        $map = array(
            'INSUFFICIENT_BALANCE' => 'You do not have enough balance. Add funds and try again.',
            'BAD_QUANTITY'         => $fallback,
            'BAD_LINK'             => 'Please enter a valid http(s) link.',
            'BLACKLISTED'          => 'That link is not permitted.',
            'NO_SERVICE'           => 'Please choose a valid service.',
            'SERVICE_INACTIVE'     => 'That service is currently unavailable.',
            'SUBMIT_FAILED'        => $fallback ?: 'The provider could not accept the order; any charge has been refunded.',
        );
        return $map[$code] ?? ($fallback ?: 'Could not place your order.');
    }
}
