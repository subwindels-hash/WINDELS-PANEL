<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Identity — customer NIN/BVN verification (§22, §17).
 *
 * The controller validates shape and renders; IdentityService owns the vendor
 * call, the charge and the refund. Two behaviours here are specific to this
 * domain rather than copied from the other dashboards:
 *
 *  - **The raw identifier leaves scope with the request.** It is read from the
 *    POST, handed to the service and never put in a flash message, a redirect,
 *    a session value or a re-rendered form field. A customer who mistypes
 *    retypes it; that is a far better outcome than a NIN sitting in the
 *    session store or a URL.
 *
 *  - **The result is fetched through the service, so the read is audited.**
 *    Even the customer looking at their own receipt goes through
 *    IdentityService::reveal(), which counts the access. There is no path in
 *    this controller that decrypts a stored result directly.
 */
class Identity extends Auth_Controller {

    const PER_PAGE = 15;

    public function __construct() {
        parent::__construct();
        $this->load->model(array(
            'Identity_product_model', 'Identity_check_model',
            'Service_transaction_model', 'Wallet_model',
        ));
        $this->load->library(array('IdentityService', 'DashboardStats'));
    }

    /** The check form. */
    public function index() {
        // Wallet_model::for_user() is a single-row accessor, not a list; bound
        // to a local so it reads that way to a human and to PerformanceTest,
        // which otherwise cannot tell it from an unpaginated for_user() query.
        $wallet = $this->Wallet_model->for_user($this->current_user->id);

        $this->view('index', 'Identity verification', array(
            'products' => $this->Identity_product_model->active(),
            'wallet'   => $wallet,
            'selected' => $this->input->get('product', true),
        ));
    }

    /** POST — run a check. */
    public function verify() {
        if ($this->input->method() !== 'post') show_404();

        $result = $this->identityservice->verify($this->current_user, array(
            'product'    => $this->input->post('product', true),
            'coupon_code' => $this->input->post('coupon_code', true),
            // Deliberately not re-read anywhere after this call.
            'identifier' => $this->input->post('identifier', true),
            'consent'    => (bool)$this->input->post('consent', true),
            'consent_ip' => $this->input->ip_address(),
            'idempotency_key' => 'idt:'.$this->current_user->id.':'
                                 .substr(sha1((string)$this->input->post('form_token', true)), 0, 32),
            'source'     => 'WEB',
        ));

        if (empty($result['ok'])) {
            // A not-found check still produced a transaction and a receipt
            // worth showing — it was refunded, and the customer should see
            // that rather than just a red banner on an empty form.
            if (!empty($result['transaction'])) {
                $this->session->set_flashdata('error', $result['error']);
                return redirect('dashboard/identity/'.$result['transaction']->public_id);
            }
            $this->session->set_flashdata('error', $result['error']);
            return redirect('dashboard/identity');
        }

        $this->session->set_flashdata('success', 'Identity verified.');
        redirect('dashboard/identity/'.$result['transaction']->public_id);
    }

    /**
     * One check.
     *
     * The result is only decrypted when the customer asks for it with the
     * Reveal button; the page itself shows the masked summary. That keeps
     * casual page loads — a back button, a refresh, a bookmarked link — out of
     * the access trail, so a reveal in the audit log means somebody genuinely
     * looked.
     */
    public function detail($public_id) {
        list($tx, $check) = $this->owned($public_id);

        $this->view('detail', 'Identity check', array(
            'tx'      => $tx,
            'check'   => $check,
            'product' => $check && $check->product_id
                ? $this->Identity_product_model->find_by_id($check->product_id) : null,
            'entity'  => null,
            'retention_days' => $this->identityservice->retention_days(),
        ));
    }

    /** POST — show me the result. Audited, and counted on the row. */
    public function reveal($public_id) {
        if ($this->input->method() !== 'post') show_404();
        list($tx, $check) = $this->owned($public_id);
        if (!$check) show_404();

        $res = $this->identityservice->reveal($check, $this->current_user, 'CUSTOMER');
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/identity/'.$public_id);
        }

        // Rendered straight into this response rather than flashed: a decrypted
        // identity record must not be written to the session store.
        $this->view('detail', 'Identity check', array(
            'tx'      => $tx,
            'check'   => $this->Identity_check_model->find_by_id($check->id),
            'product' => $check->product_id
                ? $this->Identity_product_model->find_by_id($check->product_id) : null,
            'entity'  => $res['entity'],
            'retention_days' => $this->identityservice->retention_days(),
        ));
    }

    /** Every check this customer has run. */
    public function history() {
        $page = max(1, (int)$this->input->get('page'));
        $offset = ($page - 1) * self::PER_PAGE;
        $filters = array_filter(array(
            'domain' => 'IDENTITY',
            'status' => $this->input->get('status', true),
        ));

        $transactions = $this->Service_transaction_model->history_for_user(
            $this->current_user->id, $filters, self::PER_PAGE, $offset);

        $this->view('history', 'Identity history', array(
            'transactions' => $transactions,
            // One query for the page, not one per row.
            'checks'       => $this->Identity_check_model->for_transactions(
                array_map(function ($t) { return $t->id; }, $transactions)),
            'total'        => $this->Service_transaction_model->count_history_for_user(
                $this->current_user->id, $filters),
            'page'         => $page,
            'per_page'     => self::PER_PAGE,
            'filters'      => $filters,
        ));
    }

    /* ------------------------------------------------------------------ */

    /** A check of this customer's, or a 404. Never another customer's. */
    private function owned($public_id) {
        $tx = $this->Service_transaction_model->find_public_for_user(
            $public_id, $this->current_user->id);
        if (!$tx || $tx->service_domain !== 'IDENTITY') show_404();
        return array($tx, $this->Identity_check_model->for_transaction($tx->id));
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'dashboard/identity',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/identity/'.$view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
        ), $data));
    }
}
