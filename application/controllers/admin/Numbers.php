<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Numbers — the operational virtual-number queue (§10, §25).
 *
 * Built to the pattern Session 23 set for VTU, because the operational
 * question is the same one: a customer paid, something is stuck, and support
 * needs to see what happened and put it right.
 *
 * What differs is what "stuck" means here. A VTU purchase is stuck when the
 * provider has not answered. A number reservation is stuck when the deadline
 * is approaching and no code has arrived — so this queue leads with the
 * reservation state and the expiry, not just the transaction status, and the
 * mutations are the reservation's own verbs.
 *
 * Read requires `numbers.view`; each mutation requires its own permission
 * (`numbers.manage` to poll or release a reservation, `numbers.refund` to
 * return money) and is POST-only, CSRF-protected and audit-logged. Every
 * state change goes through NumberService or TransactionEngine::transition(),
 * so the refund-through-LedgerService rule and the refunded_amount cap are
 * identical to the cron and customer paths. This controller never writes a
 * status column or touches a wallet directly.
 */
class Numbers extends Admin_Controller {

    const PER_PAGE = 25;
    const DOMAIN   = 'NUMBER';

    public function __construct() {
        parent::__construct();
        $this->require_perm('numbers.view');
        $this->load->library(array('TransactionEngine', 'NumberService', 'DashboardStats'));
        $this->load->model(array(
            'Service_transaction_model', 'Virtual_number_model', 'Otp_message_model',
            'Service_transaction_status_history_model', 'Provider_transaction_model',
            'Number_country_model', 'Number_service_model', 'Audit_log_model',
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
            'title'        => 'Virtual numbers',
            'nav_active'   => 'admin/numbers',
            'content_view' => 'admin/numbers/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'transactions' => $this->Service_transaction_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'counts'       => $this->Service_transaction_model->status_counts(self::DOMAIN),
            'filters'      => $filters,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function detail($public_id) {
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();

        $number = $this->Virtual_number_model->for_transaction($tx->id);

        $this->load->view('layouts/app', array(
            'title'          => 'Number '.$tx->public_id,
            'nav_active'     => 'admin/numbers',
            'content_view'   => 'admin/numbers/detail',
            'current_user'   => $this->current_user,
            'permissions'    => $this->auth->permissions(),
            'unread'         => $this->dashboardstats->unread_count($this->current_user->id),
            'tx'             => $tx,
            'number'         => $number,
            'messages'       => $number ? $this->Otp_message_model->for_number($number->id) : array(),
            'country'        => $number && $number->country_id
                ? $this->Number_country_model->find_by_id($number->country_id) : null,
            'service'        => $number && $number->service_id
                ? $this->Number_service_model->find_by_id($number->service_id) : null,
            'history'        => $this->Service_transaction_status_history_model->for_transaction($tx->id),
            'provider_calls' => $this->Provider_transaction_model->for_transaction($tx->id),
        ));
    }

    /**
     * POST /admin/numbers/:id/recheck — poll the vendor now.
     *
     * The same poll the numbers_status cron runs, on demand, for the case that
     * actually reaches support: a customer on the phone insisting their code
     * arrived. Whatever the vendor says is applied through NumberService, so a
     * code settles the purchase and an expiry refunds it, exactly as the
     * worker would.
     */
    public function recheck($public_id) {
        list($tx, $number) = $this->guard($public_id, 'numbers.manage');

        $before = array('status' => $tx->status, 'reservation' => $number->status,
                        'sms_count' => $number->sms_count);
        $res = $this->numberservice->poll($number, 'ADMIN');
        if (empty($res['ok'])) {
            return $this->fail($tx, $res['error'] ?? 'Could not re-check this reservation.');
        }

        $after = array('reservation' => $res['state'], 'new_messages' => $res['new_messages'] ?? 0);
        $this->audit('numbers.rechecked', $tx, $before, $after);

        $this->session->set_flashdata('success', !empty($res['new_messages'])
            ? $res['new_messages'].' new message(s) collected — the reservation is '.$res['state'].'.'
            : 'No new codes. The reservation is '.$res['state'].'.');
        redirect('admin/numbers/'.$tx->public_id);
    }

    /**
     * POST /admin/numbers/:id/release — hand the number back to the vendor.
     *
     * For the reservation support has decided is finished with, whether or not
     * a code arrived. NumberService decides what that means for the money: a
     * number that never received one still refunds.
     */
    public function release($public_id) {
        list($tx, $number) = $this->guard($public_id, 'numbers.manage');

        $before = array('status' => $tx->status, 'reservation' => $number->status);
        $res = $this->numberservice->release($number, 'ADMIN');
        if (empty($res['ok'])) {
            return $this->fail($tx, $res['error'] ?? 'Could not release this reservation.');
        }

        $this->audit('numbers.released', $tx, $before, array('reservation' => $res['state']));
        $this->session->set_flashdata('success', 'Reservation released.');
        redirect('admin/numbers/'.$tx->public_id);
    }

    /** POST /admin/numbers/:id/refund — return the charge to the wallet. */
    public function refund($public_id) {
        list($tx, $number) = $this->guard($public_id, 'numbers.refund');
        $reason = trim((string)$this->input->post('reason', true));

        $before = array('status' => $tx->status, 'refunded_amount' => $tx->refunded_amount);
        $result = $this->transactionengine->transition(
            $tx->id, 'REFUNDED', 'ADMIN', $reason ?: 'Refunded by staff'
        );
        if (empty($result['ok'])) {
            return $this->fail($tx, $result['error'] ?? 'Could not refund this reservation.');
        }

        $refunded = $result['refunded'] ?? null;
        $this->audit('numbers.refunded', $tx, $before,
            array('status' => 'REFUNDED', 'refunded' => $refunded, 'reason' => $reason));
        $this->session->set_flashdata('success', $refunded
            ? 'Reservation refunded — '.windels_money($refunded).' returned to the wallet.'
            : 'Reservation marked refunded. No money moved: nothing was charged.');
        redirect('admin/numbers/'.$tx->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();
        $number = $this->Virtual_number_model->for_transaction($tx->id);
        if (!$number) show_404();
        return array($tx, $number);
    }

    private function fail($tx, $message) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/numbers/'.$tx->public_id);
    }

    private function audit($action, $tx, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'service_transactions', (string)$tx->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
