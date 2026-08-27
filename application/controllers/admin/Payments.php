<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Payments — the manual-deposit approval queue (Session 15).
 *
 * A manual bank transfer lands as a PENDING payment_transaction; nothing has
 * been credited yet. Approving it calls PaymentService::confirm(), which is the
 * only path that credits a wallet (via LedgerService) and records the resulting
 * wallet_transaction_id on the row. Rejecting marks it FAILED and credits
 * nothing.
 *
 * Read requires `payments.view`; approve/reject require `payments.manage`, are
 * POST-only, CSRF-protected and audit-logged. This controller never calls
 * LedgerService or writes to `wallets` itself.
 */
class Payments extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('payments.view');
        $this->load->library(array('PaymentService', 'DashboardStats'));
        $this->load->model(array(
            'Payment_transaction_model', 'Payment_event_model', 'Audit_log_model',
        ));
    }

    /**
     * GET /admin/payments/webhooks — stored gateway callbacks.
     *
     * The support view for "the customer says they paid". A callback that
     * arrived but could not be verified, or was verified but matched no
     * transaction, is invisible without this.
     */
    public function webhooks() {
        $this->require_perm('payments.view');
        $this->load->model('Payment_webhook_admin_model');

        $filters = array(
            'gateway'   => $this->input->get('gateway', true),
            'processed' => $this->input->get('processed', true),
            'signature' => $this->input->get('signature', true),
            'search'    => $this->input->get('q', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = 25;

        $this->load->view('layouts/app', array(
            'title'        => 'Webhook events',
            'nav_active'   => 'admin/payments',
            'content_view' => 'admin/payments/webhooks',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'events'       => $this->Payment_webhook_admin_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'total'        => $this->Payment_webhook_admin_model->admin_count($filters),
            'health'       => $this->Payment_webhook_admin_model->health(),
            'filters'      => $filters,
            'page'         => $page,
            'limit'        => $limit,
            'total_pages'  => max(1, (int)ceil($this->Payment_webhook_admin_model->admin_count($filters) / $limit)),
        ));
    }

    /**
     * POST /admin/payments/webhooks/:id/reprocess — retry one stored event.
     *
     * Safe to press twice: it replays the payload through the same
     * PaymentService path a live callback takes, which is idempotent on the
     * gateway event id and on the ledger's own key. It cannot invent a payment
     * — an event whose signature never verified still will not credit.
     */
    public function reprocess_webhook($id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('payments.manage');
        $this->load->model('Payment_webhook_admin_model');

        $event = $this->Payment_webhook_admin_model->find($id);
        if (!$event) show_404();

        if ((int)$event->signature_valid !== 1) {
            $this->session->set_flashdata('error',
                'That event never passed signature verification, so it cannot be credited. '
                .'Check the gateway secret before retrying.');
            return redirect('admin/payments/webhooks');
        }

        // Clear the processed marker so record_webhook re-runs the match, then
        // replay the stored payload verbatim.
        $this->db->where('id', $event->id)->update('payment_webhooks',
            array('processed' => 0, 'error' => null));

        $res = $this->paymentservice->record_webhook(
            $event->gateway_type, (string)$event->payload, array()
        );

        // audit() expects a transaction; this is a webhook row, so record it
        // directly against the event rather than bending that helper.
        $this->Audit_log_model->record(
            $this->current_user->id, 'payment.webhook_reprocessed', 'payment_webhooks',
            (string)$event->id, null,
            array('event_id' => $event->event_id, 'ok' => !empty($res['ok'])),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok'])
                ? ('Reprocessing did not complete: '.($res['error'] ?? 'unknown'))
                : 'Event reprocessed.');
        redirect('admin/payments/webhooks');
    }

    public function index() {
        // Default to the queue staff actually work: deposits awaiting review.
        $status  = $this->input->get('status', true);
        if ($status === null || $status === '') $status = 'PENDING';
        if ($status === 'ALL') $status = null;

        $filters = array('status' => $status, 'search' => $this->input->get('q', true));
        $page    = max(1, (int)$this->input->get('page'));
        $limit   = self::PER_PAGE;
        $total   = $this->Payment_transaction_model->admin_count($filters);

        $this->load->view('layouts/app', array(
            'title'        => 'Payments',
            'nav_active'   => 'admin/payments',
            'content_view' => 'admin/payments/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'transactions' => $this->Payment_transaction_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'totals'       => $this->Payment_transaction_model->admin_totals(),
            'status'       => $status,
            'search'       => $filters['search'],
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function detail($public_id) {
        $tx = $this->Payment_transaction_model->admin_find($public_id);
        if (!$tx) show_404();

        $this->load->view('layouts/app', array(
            'title'        => 'Deposit '.$tx->public_id,
            'nav_active'   => 'admin/payments',
            'content_view' => 'admin/payments/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'tx'           => $tx,
            'events'       => $this->Payment_event_model->for_transaction($tx->id),
        ));
    }

    /** POST /admin/payments/:id/approve — confirm the deposit and credit the wallet. */
    public function approve($public_id) {
        $tx = $this->guard($public_id);

        // confirm() is idempotent, but checking first gives a clearer message
        // than "duplicate" and keeps the audit log honest.
        if ($tx->status === 'SUCCESS') {
            $this->session->set_flashdata('error', 'That deposit has already been credited.');
            return redirect('admin/payments/'.$tx->public_id);
        }

        $before = array('status' => $tx->status);
        $result = $this->paymentservice->confirm($tx, 'ADMIN', $this->input->post('provider_tx_id', true) ?: null);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error'] ?? 'Could not confirm the deposit.');
            return redirect('admin/payments/'.$tx->public_id);
        }

        $confirmed = $result['transaction'];
        $this->audit('payment.approved', $tx, $before, array(
            'status'                => $confirmed->status ?? 'SUCCESS',
            'credited_amount'       => (string)$tx->credited_amount,
            'wallet_transaction_id' => $confirmed->wallet_transaction_id ?? null,
        ));
        $this->session->set_flashdata('success',
            'Deposit approved — '.marvy_money($tx->credited_amount).' credited to '.$tx->username.'.');
        redirect('admin/payments/'.$tx->public_id);
    }

    /** POST /admin/payments/:id/reject — mark the deposit failed, credit nothing. */
    public function reject($public_id) {
        $tx = $this->guard($public_id);

        if ($tx->status === 'SUCCESS') {
            $this->session->set_flashdata('error',
                'That deposit was already credited — reverse it with a wallet adjustment instead.');
            return redirect('admin/payments/'.$tx->public_id);
        }

        $reason = trim((string)$this->input->post('reason', true));
        $before = array('status' => $tx->status);
        $this->paymentservice->mark_failed($tx->id, $reason ?: 'Rejected by staff');

        $this->audit('payment.rejected', $tx, $before, array('status' => 'FAILED', 'reason' => $reason));
        $this->session->set_flashdata('success', 'Deposit rejected. No funds were credited.');
        redirect('admin/payments/'.$tx->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    private function guard($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('payments.manage');
        $tx = $this->Payment_transaction_model->admin_find($public_id);
        if (!$tx) show_404();
        return $tx;
    }

    private function audit($action, $tx, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'payment_transactions', (string)$tx->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
