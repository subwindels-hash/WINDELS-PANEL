<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Vtu — airtime, data, cable, electricity and exam PINs (§9, §17).
 *
 * The controller only validates shape and renders. Every purchase goes through
 * VtuService → TransactionEngine, which owns pricing, the wallet charge, the
 * refund-on-failure and the audit trail. No controller in this app may write
 * wallet or transaction tables directly.
 */
class Vtu extends Auth_Controller {

    const PER_PAGE = 15;

    /** tab => [service_type, view, human label] */
    private static $tabs = array(
        'airtime'     => array('AIRTIME',     'airtime',     'Airtime'),
        'data'        => array('DATA',        'data',        'Data'),
        'cable'       => array('CABLE',       'cable',       'Cable TV'),
        'electricity' => array('ELECTRICITY', 'electricity', 'Electricity'),
        'education'   => array('EXAM_PIN',    'education',   'Exam PINs'),
    );

    public function __construct() {
        parent::__construct();
        $this->load->model(array(
            'Vtu_network_model', 'Vtu_product_model', 'Vtu_transaction_model',
            'Service_transaction_model', 'Wallet_model',
        ));
        $this->load->library(array('VtuService', 'DashboardStats', 'form_validation'));
    }

    public function index() { $this->airtime(); }

    public function airtime()     { $this->render('airtime'); }
    public function data()        { $this->render('data'); }
    public function cable()       { $this->render('cable'); }
    public function electricity() { $this->render('electricity'); }
    public function education()   { $this->render('education'); }

    /* ------------------------------ actions ----------------------------- */

    /** POST — buy. One entry point for all five types. */
    public function buy($tab) {
        if (!isset(self::$tabs[$tab])) show_404();
        if ($this->input->method() !== 'post') show_404();

        $input = array(
            'network'    => $this->input->post('network', true),
            'product'    => $this->input->post('product', true),
            'msisdn'     => $this->input->post('msisdn', true),
            'smartcard'  => $this->input->post('smartcard', true),
            'meter'      => $this->input->post('meter', true),
            'meter_type' => $this->input->post('meter_type', true),
            'amount'     => $this->input->post('amount', true),
            'quantity'   => $this->input->post('quantity', true),
            'phone'      => $this->input->post('phone', true),
            'customer_name' => $this->input->post('customer_name', true),
            // Scoped to the user so one customer's retry key cannot collide
            // with another's, and a double-click cannot double-charge.
            'idempotency_key' => 'vtu:'.$this->current_user->id.':'
                                 .substr(sha1((string)$this->input->post('form_token', true)), 0, 32),
            'source'     => 'WEB',
        );

        $method = self::$tabs[$tab][1] === 'education' ? 'education' : self::$tabs[$tab][1];
        $result = $this->vtuservice->$method($this->current_user, $input);

        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error']);
            redirect('dashboard/vtu/'.$tab);
            return;
        }

        $tx = $result['transaction'];
        $this->session->set_flashdata('success',
            self::$tabs[$tab][2].' purchase '.strtolower($tx->status).'.');
        redirect('dashboard/vtu/receipt/'.$tx->public_id);
    }

    /**
     * POST (AJAX) — resolve a meter or smartcard to a customer name before
     * the customer commits money to it.
     */
    public function verify() {
        if ($this->input->method() !== 'post') show_404();
        $res = $this->vtuservice->verify(array(
            'service_type' => $this->input->post('service_type', true),
            'network'      => $this->input->post('network', true),
            'meter'        => $this->input->post('meter', true),
            'smartcard'    => $this->input->post('smartcard', true),
            'meter_type'   => $this->input->post('meter_type', true),
        ));
        $this->output
             ->set_content_type('application/json')
             ->set_output(json_encode($res));
    }

    /** Products for a network, for the dependent dropdown. */
    public function products($network_code, $service_type) {
        $network = $this->Vtu_network_model->find_by_code(strtoupper($network_code));
        $rows = array();
        if ($network) {
            foreach ($this->Vtu_product_model->active_for($network->id, strtoupper($service_type)) as $p) {
                $rows[] = array(
                    'code'  => $p->code,
                    'name'  => $p->name,
                    'price' => $p->price,
                    'validity' => $p->validity,
                );
            }
        }
        $this->output->set_content_type('application/json')->set_output(json_encode($rows));
    }

    public function receipt($public_id) {
        $tx = $this->Service_transaction_model->find_public_for_user(
            $public_id, $this->current_user->id);
        if (!$tx) show_404();

        $this->view('receipt', 'Receipt', array(
            'tx'     => $tx,
            'detail' => $this->Vtu_transaction_model->for_transaction($tx->id),
        ));
    }

    /** Unified VTU history (§20). */
    public function history() {
        $page = max(1, (int)$this->input->get('page'));
        $offset = ($page - 1) * self::PER_PAGE;
        $filters = array_filter(array(
            'domain' => 'VTU',
            'type'   => $this->input->get('type', true),
            'status' => $this->input->get('status', true),
        ));

        $this->view('history', 'VTU History', array(
            'transactions' => $this->Service_transaction_model->history_for_user(
                $this->current_user->id, $filters, self::PER_PAGE, $offset),
            'total'   => $this->Service_transaction_model->count_history_for_user(
                $this->current_user->id, $filters),
            'page'    => $page,
            'per_page'=> self::PER_PAGE,
            'filters' => $filters,
        ));
    }

    /* ------------------------------ render ------------------------------ */

    private function render($tab) {
        list($service_type, $view, $label) = self::$tabs[$tab];
        $networks = $this->Vtu_network_model->active($service_type);

        // Products for the first network, so the page is useful before any JS runs.
        $products = array();
        if ($networks) {
            $products = $this->Vtu_product_model->active_for($networks[0]->id, $service_type);
        }

        // Single row, not a list — Wallet_model::for_user() returns one wallet.
        $wallet = $this->Wallet_model->for_user($this->current_user->id);

        $this->view($view, $label, array(
            'tab'      => $tab,
            'tabs'     => self::$tabs,
            'networks' => $networks,
            'products' => $products,
            'wallet'   => $wallet,
        ));
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'dashboard/vtu',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/vtu/'.$view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'tabs'         => self::$tabs,
        ), $data));
    }
}
