<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Masked withdrawal operations queue with separately authorized reveal. */
class Withdrawals extends Admin_Controller {
    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('withdrawals.view');
        $this->load->library(array('WithdrawalService', 'DashboardStats'));
        $this->load->model(array('Withdrawal_model', 'Audit_log_model'));
    }

    public function index() {
        $page = max(1, (int)$this->input->get('page'));
        $filters = array(
            'status' => strtoupper(trim((string)$this->input->get('status', true))),
            'search' => trim((string)$this->input->get('q', true)),
        );
        $valid = array('', 'PENDING', 'APPROVED', 'PAID', 'REJECTED', 'CANCELLED');
        if (!in_array($filters['status'], $valid, true)) $filters['status'] = '';
        $offset = ($page - 1) * self::PER_PAGE;
        $this->view('index', 'Withdrawal operations', array(
            'rows' => $this->Withdrawal_model->admin_search($filters, self::PER_PAGE, $offset),
            'total' => $this->Withdrawal_model->admin_count($filters),
            'totals' => $this->Withdrawal_model->admin_totals(),
            'page' => $page, 'per_page' => self::PER_PAGE, 'filters' => $filters,
        ));
    }

    public function detail($public_id) {
        $withdrawal = $this->Withdrawal_model->admin_find($public_id);
        if (!$withdrawal) show_404();
        $this->render_detail($withdrawal, null);
    }

    public function approve($public_id) {
        $this->process_guard();
        $this->transition($public_id, 'approve', array(
            $this->current_user->id,
            $this->input->post('note', true),
        ), 'Withdrawal approved. The destination can now be paid externally.');
    }

    public function reject($public_id) {
        $this->process_guard();
        $this->transition($public_id, 'reject', array(
            $this->current_user->id,
            $this->input->post('reason', true),
        ), 'Withdrawal rejected and the full amount returned to the customer wallet.');
    }

    public function paid($public_id) {
        $this->process_guard();
        $this->transition($public_id, 'mark_paid', array(
            $this->current_user->id,
            $this->input->post('payout_reference', true),
            $this->input->post('note', true),
        ), 'Withdrawal marked paid.');
    }

    /** POST-only: destination plaintext is rendered once and never flashed. */
    public function reveal($public_id) {
        $this->post_only();
        $this->require_perm('withdrawals.reveal');
        $withdrawal = $this->Withdrawal_model->admin_find($public_id);
        if (!$withdrawal) show_404();
        $res = $this->withdrawalservice->reveal($public_id, $this->current_user->id);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('admin/withdrawals/'.$public_id);
        }
        $this->audit('withdrawal.destination_revealed', $withdrawal, $withdrawal, array(
            'withdrawal_public_id' => $public_id,
        ));
        $this->render_detail($this->Withdrawal_model->admin_find($public_id), $res['destination']);
    }

    private function transition($public_id, $method, array $args, $success) {
        $before = $this->Withdrawal_model->admin_find($public_id);
        if (!$before) show_404();
        array_unshift($args, $public_id);
        $res = call_user_func_array(array($this->withdrawalservice, $method), $args);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
        } else {
            $after = $this->Withdrawal_model->admin_find($public_id);
            $this->audit('withdrawal.'.strtolower($after->status), $before, $after, array(
                'withdrawal_public_id' => $public_id,
                'amount' => $after->amount,
                'currency' => $after->currency,
            ));
            $this->session->set_flashdata('success', $success);
        }
        redirect('admin/withdrawals/'.$public_id);
    }

    private function render_detail($withdrawal, $destination) {
        $this->view('detail', 'Withdrawal '.$withdrawal->public_id, array(
            'withdrawal' => $withdrawal,
            'events' => $this->Withdrawal_model->events($withdrawal->id),
            'destination' => $destination,
        ));
    }

    private function process_guard() {
        $this->post_only();
        $this->require_perm('withdrawals.process');
    }

    private function post_only() {
        if ($this->input->method(true) !== 'POST') show_404();
    }

    private function audit($action, $before, $after, array $metadata) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'withdrawal_request', $before->id,
            array('status' => $before->status),
            array_merge(array('status' => $after->status), $metadata),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => 'admin/withdrawals',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'admin/withdrawals/'.$view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
        ), $data));
    }
}
