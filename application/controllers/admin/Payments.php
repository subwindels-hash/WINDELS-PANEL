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

    /**
     * GET /admin/payments/methods — the deposit methods customers are offered.
     *
     * Activating a gateway used to require an UPDATE statement: the panel had
     * adapters for six providers and no screen to switch any of them on, set
     * a fee, or see whether its credentials were even present.
     */
    public function methods() {
        $this->require_perm('payments.view');

        $rows = $this->db->order_by('sorting', 'ASC')->get('payment_methods')->result();
        $state = array();
        foreach ($rows as $row) {
            $state[$row->code] = array(
                'implemented' => in_array(strtolower($row->code), $this->paymentservice->implemented_gateways(), true),
                'configured'  => $this->paymentservice->method_is_configured($row),
                // Manual bank transfer is reconciled by a human and needs no
                // API credentials, so "not configured" would be a lie there.
                'needs_credentials' => $this->paymentservice->method_needs_credentials($row),
            );
        }

        $this->load->view('layouts/app', array(
            'title'        => 'Deposit methods',
            'nav_active'   => 'admin/payments',
            'content_view' => 'admin/payments/methods',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'methods'      => $rows,
            'state'        => $state,
            'page_description' => 'Which deposit methods customers see, what each charges, and whether its '
                                  .'credentials are in place. A method with no credentials stays hidden from '
                                  .'Add funds even when it is switched on.',
        ));
    }

    /** POST /admin/payments/methods/:code/save */
    public function save_method($code) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('payments.manage');

        $method = $this->db->where('code', $code)->get('payment_methods')->row();
        if (!$method) show_404();

        $percent = $this->numeric($this->input->post('fee_percent', true), 0, 100);
        $bonus   = $this->numeric($this->input->post('bonus_percent', true), 0, 100);
        $fixed   = $this->numeric($this->input->post('fee_fixed', true), 0, null);
        $min     = $this->numeric($this->input->post('min_amount', true), 0, null, true);
        $max     = $this->numeric($this->input->post('max_amount', true), 0, null, true);

        if ($percent === null || $bonus === null || $fixed === null) {
            $this->session->set_flashdata('error', 'Fees and bonuses must be numbers; percentages between 0 and 100.');
            return redirect('admin/payments/methods');
        }
        if ($min !== null && $max !== null && bccomp((string)$min, (string)$max, 8) > 0) {
            $this->session->set_flashdata('error', 'The minimum deposit cannot be greater than the maximum.');
            return redirect('admin/payments/methods');
        }

        $before = array(
            'is_active' => (int)$method->is_active, 'fee_percent' => $method->fee_percent,
            'fee_fixed' => $method->fee_fixed, 'bonus_percent' => $method->bonus_percent,
            'min_amount' => $method->min_amount, 'max_amount' => $method->max_amount,
            'sorting' => (int)$method->sorting,
        );
        $after = array(
            'is_active'     => $this->input->post('is_active', true) ? 1 : 0,
            'fee_percent'   => number_format((float)$percent, 4, '.', ''),
            'fee_fixed'     => number_format((float)$fixed, 8, '.', ''),
            'bonus_percent' => number_format((float)$bonus, 4, '.', ''),
            'min_amount'    => $min === null ? null : number_format((float)$min, 8, '.', ''),
            'max_amount'    => $max === null ? null : number_format((float)$max, 8, '.', ''),
            'sorting'       => (int)$this->input->post('sorting', true),
            'instructions'  => $this->input->post('instructions', true) ?: null,
            'name'          => trim((string)$this->input->post('name', true)) ?: $method->name,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        );
        $this->db->where('id', $method->id)->update('payment_methods', $after);

        $this->Audit_log_model->record($this->current_user->id, 'payment_method.updated', 'payment_methods',
            $method->public_id, $before, $after, $this->input->ip_address(), $this->input->user_agent(), $this->request_id);

        $warning = '';
        if ($after['is_active'] && !$this->paymentservice->method_is_configured($method)) {
            $warning = ' It stays hidden from Add funds until its API credentials are set in Settings → '
                     .'Card and wallet gateways.';
        }
        $this->session->set_flashdata('success', $method->name.' updated.'.$warning);
        redirect('admin/payments/methods');
    }

    /** A bounded numeric field, or null when it was left blank. */
    private function numeric($value, $min = null, $max = null, $nullable = false) {
        $raw = trim((string)$value);
        if ($raw === '') return $nullable ? null : 0;
        if (!is_numeric($raw)) return null;
        $n = (float)$raw;
        if ($min !== null && $n < $min) return null;
        if ($max !== null && $n > $max) return null;
        return $n;
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
