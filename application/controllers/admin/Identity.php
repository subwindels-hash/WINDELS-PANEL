<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Identity — the identity verification queue (§22, §25).
 *
 * Same operational shape as Admin/Numbers and Admin/Vtu, with one significant
 * difference: on the other queues, seeing the payload *is* the job — support
 * needs the meter token or the OTP in front of them to help. Here the payload
 * is a stranger's identity record, and the person it belongs to is not the one
 * on the phone.
 *
 * So this screen is built to be useful without showing it. The queue and the
 * detail page render the check's outcome, the masked tail and the money, which
 * is everything needed to answer "did this work and should it be refunded".
 * The record itself is behind a separate POST button, a separate permission
 * (`identity.reveal`, which the STAFF role does not get) and an audit entry
 * that names the operator — see IdentityService::reveal().
 *
 * Read requires `identity.view`; refunds require `identity.refund`. Every
 * mutation is POST-only, CSRF-protected and audit-logged, and all money moves
 * through TransactionEngine::transition().
 */
class Identity extends Admin_Controller {

    const PER_PAGE = 25;
    const DOMAIN   = 'IDENTITY';

    public function __construct() {
        parent::__construct();
        $this->require_perm('identity.view');
        $this->load->library(array('TransactionEngine', 'IdentityService', 'DashboardStats'));
        $this->load->model(array(
            'Service_transaction_model', 'Identity_check_model', 'Identity_product_model',
            'Service_transaction_status_history_model', 'Provider_transaction_model',
            'Audit_log_model',
        ));
    }

    public function index() {
        $filters = array(
            'domain' => self::DOMAIN,
            'status' => $this->input->get('status', true),
            'search' => $this->input->get('q', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $total = $this->Service_transaction_model->admin_count($filters);

        $this->load->view('layouts/app', array(
            'title'        => 'Identity checks',
            'nav_active'   => 'admin/identity',
            'content_view' => 'admin/identity/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'transactions' => $this->Service_transaction_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'counts'       => $this->Service_transaction_model->status_counts(self::DOMAIN),
            'check_counts' => $this->Identity_check_model->status_counts(),
            'filters'      => $filters,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** One check — outcome and money, but not the record. */
    public function detail($public_id) {
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();

        $this->render_detail($tx, null);
    }

    /**
     * POST /admin/identity/:id/reveal — open the record, on the record.
     *
     * Separate permission, separate button, separate audit entry. The result
     * is rendered into this one response and nothing else: it is not flashed
     * (that would put an identity record in the session store), not redirected
     * to (that would make it re-viewable by refresh without a second audit
     * entry) and not cached.
     */
    public function reveal($public_id) {
        $tx = $this->guard($public_id, 'identity.reveal');
        $check = $this->Identity_check_model->for_transaction($tx->id);
        if (!$check) show_404();

        $res = $this->identityservice->reveal($check, $this->current_user, 'ADMIN');
        if (empty($res['ok'])) {
            return $this->fail($tx, $res['error']);
        }

        // The reveal is already audited inside the service, on the path that
        // does the decryption, so it cannot be bypassed by another caller.
        $this->render_detail($tx, $res['entity']);
    }

    /** POST /admin/identity/:id/refund — return the charge to the wallet. */
    public function refund($public_id) {
        $tx = $this->guard($public_id, 'identity.refund');
        $reason = trim((string)$this->input->post('reason', true));

        $before = array('status' => $tx->status, 'refunded_amount' => $tx->refunded_amount);
        $result = $this->transactionengine->transition(
            $tx->id, 'REFUNDED', 'ADMIN', $reason ?: 'Refunded by staff'
        );
        if (empty($result['ok'])) {
            return $this->fail($tx, $result['error'] ?? 'Could not refund this check.');
        }

        $refunded = $result['refunded'] ?? null;
        $this->audit('identity.refunded', $tx, $before,
            array('status' => 'REFUNDED', 'refunded' => $refunded, 'reason' => $reason));
        $this->session->set_flashdata('success', $refunded
            ? 'Check refunded — '.windels_money($refunded).' returned to the wallet.'
            : 'Check marked refunded. No money moved: nothing was charged.');
        redirect('admin/identity/'.$tx->public_id);
    }

    /**
     * POST /admin/identity/:id/purge — scrub this result now.
     *
     * The retention sweep does this on a schedule; this is the button for a
     * subject who asks for their data to be deleted today. It clears the
     * payload and leaves the transaction, because the erasure request covers
     * the personal data, not the fact that a paid check occurred.
     */
    public function purge($public_id) {
        $tx = $this->guard($public_id, 'identity.manage');
        $check = $this->Identity_check_model->for_transaction($tx->id);
        if (!$check) show_404();

        if (empty($check->result_encrypted)) {
            return $this->fail($tx, 'There is no stored result to delete.');
        }

        $this->Identity_check_model->purge($check->id);
        $this->audit('identity.purged', $tx,
            array('has_result' => true), array('has_result' => false));
        $this->session->set_flashdata('success', 'Stored result deleted. The check record remains.');
        redirect('admin/identity/'.$tx->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    /**
     * @param array|null $entity decrypted record, only on the reveal path
     */
    private function render_detail($tx, $entity) {
        $check = $this->Identity_check_model->for_transaction($tx->id);

        $this->load->view('layouts/app', array(
            'title'          => 'Identity check '.$tx->public_id,
            'nav_active'     => 'admin/identity',
            'content_view'   => 'admin/identity/detail',
            'current_user'   => $this->current_user,
            'permissions'    => $this->auth->permissions(),
            'unread'         => $this->dashboardstats->unread_count($this->current_user->id),
            'tx'             => $tx,
            'check'          => $check,
            'entity'         => $entity,
            'product'        => $check && $check->product_id
                ? $this->Identity_product_model->find_by_id($check->product_id) : null,
            'retention_days' => $this->identityservice->retention_days(),
            'history'        => $this->Service_transaction_status_history_model->for_transaction($tx->id),
            'provider_calls' => $this->Provider_transaction_model->for_transaction($tx->id),
        ));
    }

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();
        return $tx;
    }

    private function fail($tx, $message) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/identity/'.$tx->public_id);
    }

    private function audit($action, $tx, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'service_transactions', (string)$tx->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
