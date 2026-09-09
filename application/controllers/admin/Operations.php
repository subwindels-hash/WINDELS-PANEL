<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Operations — refills, cancellations, drip-feeds and subscriptions.
 *
 * Four queues that were routed in Session 15 with no controller behind them:
 * `admin/refills`, `admin/cancellations`, `admin/drip-feed` and
 * `admin/subscriptions` all 404'd, and `orders.refill` / `orders.cancel`
 * gated nothing. Every one of these has a working engine (RefillService,
 * OrderService::cancel, DripfeedService, SubscriptionService) and a customer
 * UI — what was missing was the staff view, so support could see that a
 * refill had been stuck in PROCESSING for three days but could do nothing
 * about it.
 *
 * These are **queues over existing engines**, not new engines. Every action
 * here calls the same service method the customer's own button calls, which
 * matters more than it sounds: a drip-feed cancellation refunds the unspent
 * reserve, and reimplementing that here would eventually pay a different
 * amount than the customer path does.
 *
 * The consequence is the pattern to notice: an admin acting on someone's
 * schedule passes **the owner**, not themselves, to the service. The service
 * scopes its lookup by user, so passing the admin would 404 on every row —
 * and, worse, a refund computed against the wrong wallet.
 */
class Operations extends Admin_Controller {

    const PER_PAGE = 25;

    /** The four queues, and the permission each needs. */
    private static $queues = array(
        'refills'       => array('Refills',       'orders.refill'),
        'cancellations' => array('Cancellations', 'orders.cancel'),
        'dripfeed'      => array('Drip-feeds',    'orders.edit'),
        'subscriptions' => array('Subscriptions', 'orders.edit'),
    );

    public function __construct() {
        parent::__construct();
        $this->require_perm('orders.view');
        $this->load->library(array(
            'RefillService', 'OrderService', 'DripfeedService', 'SubscriptionService',
            'DashboardStats',
        ));
        $this->load->model(array(
            'Refill_model', 'Order_model', 'Subscription_model', 'Dripfeed_order_model',
            'Dripfeed_run_model', 'Subscription_event_model', 'User_model', 'Audit_log_model',
        ));
    }

    public function index() {
        redirect('admin/refills');
    }

    /* ------------------------------ queues ------------------------------ */

    /** GET /admin/refills */
    public function refills() {
        $filters = $this->filters();
        $page    = max(1, (int)$this->input->get('page'));
        $total   = $this->Refill_model->admin_count($filters);

        $this->render('refills', 'admin/operations/refills', array(
            'rows'    => $this->Refill_model->admin_search($filters, self::PER_PAGE,
                            ($page - 1) * self::PER_PAGE),
            'counts'  => $this->Refill_model->status_counts(),
            'statuses'=> array('PENDING','PROCESSING','IN_PROGRESS','COMPLETED','FAILED'),
        ) + $this->pager($page, $total, $filters));
    }

    /**
     * GET /admin/cancellations
     *
     * `cancellation_requests` is written by nothing in this build: the
     * customer path cancels synchronously through OrderService, which does
     * not queue a request row. Rather than show an empty table that looks
     * broken, this queue lists orders that are *cancellable right now*, which
     * is the question an operator actually has.
     */
    public function cancellations() {
        $filters = array(
            'status' => $this->input->get('status', true) ?: null,
            'search' => $this->input->get('q', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $total = $this->Order_model->admin_count($filters);

        $this->render('cancellations', 'admin/operations/cancellations', array(
            'rows'     => $this->Order_model->admin_search($filters, self::PER_PAGE,
                             ($page - 1) * self::PER_PAGE),
            'counts'   => $this->Order_model->status_counts(),
            'requests' => $this->db->order_by('created_at', 'DESC')->limit(25)
                              ->get('cancellation_requests')->result(),
        ) + $this->pager($page, $total, $filters));
    }

    /** GET /admin/drip-feed */
    public function dripfeed() {
        $filters = $this->filters();
        $page    = max(1, (int)$this->input->get('page'));
        $total   = $this->Dripfeed_order_model->admin_count($filters);

        $this->render('dripfeed', 'admin/operations/dripfeed', array(
            'rows'     => $this->Dripfeed_order_model->admin_search($filters, self::PER_PAGE,
                             ($page - 1) * self::PER_PAGE),
            'counts'   => $this->Dripfeed_order_model->status_counts(),
            'statuses' => array('ACTIVE','PAUSED','CANCELED','COMPLETED'),
        ) + $this->pager($page, $total, $filters));
    }

    /** GET /admin/subscriptions */
    public function subscriptions() {
        $filters = $this->filters();
        $page    = max(1, (int)$this->input->get('page'));
        $total   = $this->Subscription_model->admin_count($filters);

        $this->render('subscriptions', 'admin/operations/subscriptions', array(
            'rows'     => $this->Subscription_model->admin_search($filters, self::PER_PAGE,
                             ($page - 1) * self::PER_PAGE),
            'counts'   => $this->Subscription_model->status_counts(),
            'statuses' => array('ACTIVE','PAUSED','CANCELED','EXPIRED','COMPLETED'),
        ) + $this->pager($page, $total, $filters));
    }

    /* ------------------------------ actions ----------------------------- */

    /**
     * POST /admin/refills/:order/request — ask the provider to refill.
     *
     * Keyed by the *order*, not the refill: an operator refilling a dropped
     * order does not have a refill id yet, and RefillService already refuses
     * a second one while the first is live.
     */
    public function refill_request($order_public_id) {
        $this->guard('refills');
        $order = $this->Order_model->admin_find($order_public_id);
        if (!$order) show_404();

        $owner = $this->User_model->find_by_id($order->user_id);
        if (!$owner) return $this->fail('refills', 'That order has no owner on file.');

        // The owner, not the actor — RefillService scopes its lookup by user.
        $res = $this->refillservice->request($order->public_id, $owner);
        if (empty($res['ok'])) return $this->fail('refills', $res['error']);

        $this->audit('refill.requested', 'refills',
            isset($res['refill']) ? $res['refill'] : null,
            null, array('order' => $order->public_id));
        // The service reports what the provider actually said — accepted,
        // queued for a retry, or handed to staff. Reporting "requested" for
        // all three is how refills used to disappear silently.
        $this->done('refills', 'Refill for order '.$order->public_id.': '
            .($res['message'] ?? 'requested.'));
    }

    /** POST /admin/cancellations/:order/cancel — cancel and refund. */
    public function cancel($order_public_id) {
        $this->guard('cancellations');
        $order = $this->Order_model->admin_find($order_public_id);
        if (!$order) show_404();

        // Through OrderService::cancel(), not apply_status(): the provider has
        // to be asked, or we refund the customer while still paying for a
        // delivery that keeps running. `force` is the deliberate override.
        $before = array('status' => $order->status);
        $res = $this->orderservice->cancel($order, null, array(
            'source' => 'ADMIN',
            'force'  => (bool)$this->input->post('force'),
            'reason' => trim((string)$this->input->post('reason', true)) ?: 'Canceled by staff',
        ));
        if (empty($res['ok'])) {
            $message = $res['error'];
            if (($res['code'] ?? '') === 'PROVIDER_REFUSED') {
                $message .= ' Use “cancel anyway” if you accept the provider charge.';
            }
            return $this->fail('cancellations', $message);
        }

        $this->audit('order.canceled', 'orders', $order, $before, array('status' => 'CANCELED'));
        $this->done('cancellations', 'Order '.$order->public_id.' canceled and refunded.');
    }

    /** POST /admin/drip-feed/:id/:action — pause, resume or cancel. */
    public function dripfeed_action($public_id, $action) {
        $this->guard('dripfeed');
        $drip = $this->Dripfeed_order_model->admin_find($public_id);
        if (!$drip) show_404();

        $owner = $this->User_model->find_by_id($drip->user_id);
        if (!$owner) return $this->fail('dripfeed', 'That schedule has no owner on file.');

        $before = array('status' => $drip->status);
        $res = $this->dispatch($this->dripfeedservice, $action, $public_id, $owner);
        if ($res === null) show_404();
        if (empty($res['ok'])) return $this->fail('dripfeed', $res['error']);

        $this->audit('dripfeed.'.$action, 'dripfeed_orders', $drip, $before,
            array('status' => isset($res['dripfeed']) ? $res['dripfeed']->status : $action));
        $this->done('dripfeed', 'Schedule '.$drip->public_id.' '.$this->past($action).'.');
    }

    /** POST /admin/subscriptions/:id/:action — pause, resume or cancel. */
    public function subscription_action($public_id, $action) {
        $this->guard('subscriptions');
        $sub = $this->Subscription_model->admin_find($public_id);
        if (!$sub) show_404();

        $owner = $this->User_model->find_by_id($sub->user_id);
        if (!$owner) return $this->fail('subscriptions', 'That subscription has no owner on file.');

        $before = array('status' => $sub->status);
        $res = $this->dispatch($this->subscriptionservice, $action, $public_id, $owner);
        if ($res === null) show_404();
        if (empty($res['ok'])) return $this->fail('subscriptions', $res['error']);

        $this->audit('subscription.'.$action, 'subscriptions', $sub, $before,
            array('status' => isset($res['subscription']) ? $res['subscription']->status : $action));
        $this->done('subscriptions', 'Subscription '.$sub->public_id.' '.$this->past($action).'.');
    }

    /* ------------------------------ helpers ----------------------------- */

    /** pause/resume/cancel on either scheduler, or null for anything else. */
    private function dispatch($service, $action, $public_id, $owner) {
        if (!in_array($action, array('pause', 'resume', 'cancel'), true)) return null;
        return $service->$action($public_id, $owner);
    }

    private function past($action) {
        $m = array('pause' => 'paused', 'resume' => 'resumed', 'cancel' => 'canceled');
        return isset($m[$action]) ? $m[$action] : $action.'d';
    }

    private function filters() {
        return array(
            'status' => $this->input->get('status', true),
            'search' => $this->input->get('q', true),
        );
    }

    private function pager($page, $total, array $filters) {
        return array(
            'filters'     => $filters,
            'page'        => $page,
            'total'       => (int)$total,
            'total_pages' => max(1, (int)ceil($total / self::PER_PAGE)),
        );
    }

    private function render($queue, $view, array $data) {
        list($label, ) = self::$queues[$queue];
        $tabs = array();
        foreach (self::$queues as $key => $spec) {
            $tabs[$key] = array('label' => $spec[0], 'url' => $this->url($key),
                                'allowed' => $this->auth->can($spec[1]));
        }
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $label,
            'nav_active'   => 'admin/refills',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'queue'        => $queue,
            'tabs'         => $tabs,
        ), $data));
    }

    /** The drip-feed queue is routed as `drip-feed`, not `dripfeed`. */
    private function url($queue) {
        return $queue === 'dripfeed' ? 'admin/drip-feed' : 'admin/'.$queue;
    }

    private function guard($queue) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm(self::$queues[$queue][1]);
    }

    private function fail($queue, $message) {
        $this->session->set_flashdata('error', $message);
        redirect($this->url($queue));
    }

    private function done($queue, $message) {
        $this->session->set_flashdata('success', $message);
        redirect($this->url($queue));
    }

    private function audit($action, $table, $row, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, $table,
            $row && isset($row->id) ? (string)$row->id : null,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
