<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Numbers — rent a virtual number and read its OTP (§10, §11, §17).
 *
 * The controller validates shape and renders. Every reservation, refund and
 * vendor call goes through NumberService → TransactionEngine, which owns the
 * charge, the refund-on-expiry and the audit trail.
 *
 * The one thing this screen does that no other customer screen does is expose
 * a live vendor call on a button (`check`). It is not a page render — it is an
 * explicit "has my code arrived yet?" press, which is the entire product. It
 * is POST-only, so it cannot be triggered by a prefetch or a link, and the
 * work still happens inside NumberService rather than here.
 */
class Numbers extends Auth_Controller {

    const PER_PAGE = 15;

    /** How many recent reservations the front page scans for live ones. */
    const ACTIVE_LIMIT = 10;

    public function __construct() {
        parent::__construct();
        $this->load->model(array(
            'Number_country_model', 'Number_service_model', 'Number_product_model',
            'Virtual_number_model', 'Otp_message_model',
            'Service_transaction_model', 'Wallet_model',
        ));
        $this->load->library(array('NumberService', 'DashboardStats'));
    }

    /** The rental form, plus whatever the customer currently has live. */
    public function index() {
        $countries = $this->Number_country_model->active();
        $selected  = $this->input->get('country', true);

        $country = $selected ? $this->Number_country_model->find_by_code($selected) : null;
        if (!$country && $countries) $country = $countries[0];

        // Single row, not a list — Wallet_model::for_user() returns one wallet.
        $wallet = $this->Wallet_model->for_user($this->current_user->id);

        $this->view('index', 'Virtual numbers', array(
            'countries' => $countries,
            'country'   => $country,
            'products'  => $country ? $this->Number_product_model->active_for_country($country->id) : array(),
            'wallet'    => $wallet,
            'active'    => $this->active_rentals(),
        ));
    }

    /** POST — rent a number. */
    public function rent() {
        if ($this->input->method() !== 'post') show_404();

        $result = $this->numberservice->reserve($this->current_user, array(
            'country' => $this->input->post('country', true),
            'service' => $this->input->post('service', true),
            // Scoped to the user so one customer's retry key cannot collide
            // with another's, and a double-click cannot double-charge.
            'idempotency_key' => 'num:'.$this->current_user->id.':'
                                 .substr(sha1((string)$this->input->post('form_token', true)), 0, 32),
            'source'  => 'WEB',
        ));

        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error']);
            return redirect('dashboard/numbers');
        }

        $this->session->set_flashdata('success',
            'Number reserved. Send your code to it now — the reservation expires shortly.');
        redirect('dashboard/numbers/'.$result['transaction']->public_id);
    }

    /** The reservation itself: the number, the countdown and any codes. */
    public function detail($public_id) {
        list($tx, $number) = $this->owned($public_id);

        $this->view('detail', 'Virtual number', array(
            'tx'       => $tx,
            'number'   => $number,
            'messages' => $number ? $this->Otp_message_model->for_number($number->id) : array(),
            'country'  => $number && $number->country_id
                ? $this->Number_country_model->find_by_id($number->country_id) : null,
            'service'  => $number && $number->service_id
                ? $this->Number_service_model->find_by_id($number->service_id) : null,
        ));
    }

    /** POST — "has my code arrived?". The one live vendor call a customer makes. */
    public function check($public_id) {
        $number = $this->guard($public_id);

        $res = $this->numberservice->poll($number, 'CUSTOMER');
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
        } elseif (!empty($res['new_messages'])) {
            $this->session->set_flashdata('success',
                $res['new_messages'] == 1 ? 'A code arrived.' : $res['new_messages'].' new messages arrived.');
        } elseif (in_array($res['state'], array('EXPIRED','CANCELLED','BANNED'), true)) {
            $this->session->set_flashdata('error',
                'This reservation ended without a code — you have been refunded.');
        } else {
            $this->session->set_flashdata('success', 'No code yet. Try again in a moment.');
        }
        redirect('dashboard/numbers/'.$public_id);
    }

    /** POST — give up before a code arrives, and get the money back. */
    public function cancel($public_id) {
        $number = $this->guard($public_id);

        $res = $this->numberservice->cancel($number, 'CUSTOMER');
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
        } else {
            $this->session->set_flashdata('success', $res['refunded']
                ? 'Reservation cancelled — '.windels_money($res['refunded']).' returned to your wallet.'
                : 'Reservation cancelled.');
        }
        redirect('dashboard/numbers/'.$public_id);
    }

    /** POST — done with a working number; release the vendor's hold. */
    public function release($public_id) {
        $number = $this->guard($public_id);

        $res = $this->numberservice->release($number, 'CUSTOMER');
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : 'Number released. Thanks — that frees it for reuse.');
        redirect('dashboard/numbers/'.$public_id);
    }

    /** POST — the number was already registered with that service. */
    public function report($public_id) {
        $number = $this->guard($public_id);

        $res = $this->numberservice->ban($number, 'CUSTOMER');
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
        } else {
            $this->session->set_flashdata('success', $res['refunded']
                ? 'Reported as unusable — '.windels_money($res['refunded']).' returned to your wallet.'
                : 'Reported as unusable.');
        }
        redirect('dashboard/numbers/'.$public_id);
    }

    /** Every number this customer has ever rented. */
    public function history() {
        $page = max(1, (int)$this->input->get('page'));
        $offset = ($page - 1) * self::PER_PAGE;
        $filters = array_filter(array(
            'domain' => 'NUMBER',
            'status' => $this->input->get('status', true),
        ));

        $transactions = $this->Service_transaction_model->history_for_user(
            $this->current_user->id, $filters, self::PER_PAGE, $offset);

        // The list shows the number, which lives on the domain row. Fetched in
        // one query rather than one per row: at 15 rows a page that is the
        // difference between two queries and sixteen.
        $numbers = $this->Virtual_number_model->for_transactions(
            array_map(function ($t) { return $t->id; }, $transactions));

        $this->view('history', 'Number history', array(
            'transactions' => $transactions,
            'numbers'      => $numbers,
            'total'        => $this->Service_transaction_model->count_history_for_user(
                $this->current_user->id, $filters),
            'page'         => $page,
            'per_page'     => self::PER_PAGE,
            'filters'      => $filters,
        ));
    }

    /* ------------------------------------------------------------------ */

    /** POST-only + ownership, shared by every mutation. */
    private function guard($public_id) {
        if ($this->input->method() !== 'post') show_404();
        list($tx, $number) = $this->owned($public_id);
        if (!$number) show_404();
        return $number;
    }

    /** A reservation of this customer's, or a 404. Never another customer's. */
    private function owned($public_id) {
        $tx = $this->Service_transaction_model->find_public_for_user(
            $public_id, $this->current_user->id);
        if (!$tx || $tx->service_domain !== 'NUMBER') show_404();
        return array($tx, $this->Virtual_number_model->for_transaction($tx->id));
    }

    /** Reservations still worth showing on the front page. */
    private function active_rentals() {
        $rows = $this->Service_transaction_model->history_for_user(
            $this->current_user->id, array('domain' => 'NUMBER'), self::ACTIVE_LIMIT, 0);

        $numbers = $this->Virtual_number_model->for_transactions(
            array_map(function ($t) { return $t->id; }, $rows));

        $out = array();
        foreach ($rows as $t) {
            $number = $numbers[$t->id] ?? null;
            if (!$number || !in_array($number->status, array('RESERVED','RECEIVED'), true)) continue;
            $out[] = array('tx' => $t, 'number' => $number);
        }
        return $out;
    }

    private function view($view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'dashboard/numbers',
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/numbers/'.$view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
        ), $data));
    }
}
