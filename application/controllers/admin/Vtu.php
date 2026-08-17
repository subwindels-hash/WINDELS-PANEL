<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Vtu — the operational VTU queue (Session 23).
 *
 * VTU permissions were seeded in Session 21 but nothing rendered them: an
 * admin could see a customer's airtime purchase stuck in PROCESSING and had
 * no way to settle or refund it, even though TransactionEngine has supported
 * both since it shipped. This is that missing surface.
 *
 * Read requires `vtu.view`; each mutation requires its own permission
 * (`vtu.manage` to re-check a purchase against the provider, `vtu.refund` to
 * return the money) and is POST-only, CSRF-protected and audit-logged.
 *
 * Every state change goes through TransactionEngine::transition(), so the
 * refund-through-LedgerService rule, the append-only status history and the
 * refunded_amount cap are identical to the cron and customer paths. This
 * controller never writes a status column or touches a wallet directly.
 */
class Vtu extends Admin_Controller {

    const PER_PAGE = 25;
    const DOMAIN   = 'VTU';

    /** Service types a VTU transaction can carry, for the filter dropdown. */
    private $types = array('AIRTIME', 'DATA', 'CABLE', 'ELECTRICITY', 'EXAM_PIN');

    public function __construct() {
        parent::__construct();
        $this->require_perm('vtu.view');
        $this->load->library(array('TransactionEngine', 'Provider_manager', 'DashboardStats'));
        $this->load->model(array(
            'Service_transaction_model', 'Vtu_transaction_model',
            'Service_transaction_status_history_model', 'Provider_transaction_model',
            'Provider_model', 'Audit_log_model',
        ));
    }

    public function index() {
        $filters = array(
            'domain' => self::DOMAIN,
            'status' => $this->input->get('status', true),
            'type'   => $this->input->get('type', true),
            'search' => $this->input->get('q', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;

        $total = $this->Service_transaction_model->admin_count($filters);

        $this->load->view('layouts/app', array(
            'title'        => 'VTU',
            'nav_active'   => 'admin/vtu',
            'content_view' => 'admin/vtu/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'transactions' => $this->Service_transaction_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'counts'       => $this->Service_transaction_model->status_counts(self::DOMAIN),
            'types'        => $this->types,
            'filters'      => $filters,
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function detail($public_id) {
        $tx = $this->Service_transaction_model->admin_find($public_id, self::DOMAIN);
        if (!$tx) show_404();

        $this->load->view('layouts/app', array(
            'title'          => 'VTU '.$tx->public_id,
            'nav_active'     => 'admin/vtu',
            'content_view'   => 'admin/vtu/detail',
            'current_user'   => $this->current_user,
            'permissions'    => $this->auth->permissions(),
            'unread'         => $this->dashboardstats->unread_count($this->current_user->id),
            'tx'             => $tx,
            'detail'         => $this->Vtu_transaction_model->for_transaction($tx->id),
            'history'        => $this->Service_transaction_status_history_model->for_transaction($tx->id),
            'provider_calls' => $this->Provider_transaction_model->for_transaction($tx->id),
        ));
    }

    /**
     * POST /admin/vtu/:id/recheck — ask the provider what actually happened.
     *
     * The same requery the vtu_status cron runs, on demand, for the case that
     * actually reaches support: one purchase stuck in PROCESSING while the
     * customer is on the phone. The provider's answer is applied through
     * transition(), so a FAILED result refunds exactly as the worker would.
     */
    public function recheck($public_id) {
        $tx = $this->guard($public_id, 'vtu.manage');

        if ($tx->status !== 'PROCESSING') {
            return $this->fail($tx, 'Only a PROCESSING purchase can be re-checked.');
        }
        if (!$tx->provider_reference || !$tx->provider_id) {
            return $this->fail($tx, 'This purchase has no provider reference to re-check.');
        }
        $provider = $this->Provider_model->find_by_id($tx->provider_id);
        if (!$provider) return $this->fail($tx, 'The provider for this purchase no longer exists.');

        $started = microtime(true);
        try {
            $adapter = $this->provider_manager->adapter($provider, Provider_manager::FAMILY_VTU);
            $res = $adapter->status($tx->provider_reference);
        } catch (Exception $e) {
            log_message('error', 'admin vtu recheck: '.$e->getMessage());
            return $this->fail($tx, 'Could not reach the provider: '.$e->getMessage());
        }
        $latency = (int)round((microtime(true) - $started) * 1000);

        $this->Provider_transaction_model->record(array(
            'provider_id'            => $provider->id,
            'service_transaction_id' => $tx->id,
            'action'                 => 'STATUS',
            'provider_reference'     => $tx->provider_reference,
            'status'                 => !empty($res['ok']) ? 'SUCCESS' : 'FAILED',
            'latency_ms'             => $latency,
            'error'                  => $res['error'] ?? null,
        ));

        if (empty($res['ok']) || empty($res['status'])) {
            return $this->fail($tx, 'The provider did not return a status: '.($res['error'] ?? 'unknown response').'.');
        }

        $status = strtoupper($res['status']);
        if ($status === $tx->status) {
            $this->audit('vtu.rechecked', $tx, array('status' => $tx->status), array('status' => $status));
            $this->session->set_flashdata('success', 'The provider still reports '.$status.'.');
            return redirect('admin/vtu/'.$tx->public_id);
        }
        if (!in_array($status, array('SUCCESSFUL', 'FAILED'), true)) {
            return $this->fail($tx, 'The provider reported an unusable status: '.$status.'.');
        }

        // FAILED refunds automatically inside the engine.
        $before = array('status' => $tx->status);
        $result = $this->transactionengine->transition(
            $tx->id, $status, 'ADMIN',
            $status === 'FAILED' ? 'Provider reported failure on manual re-check' : null
        );
        if (empty($result['ok'])) {
            return $this->fail($tx, $result['error'] ?? 'Could not settle this purchase.');
        }

        $this->audit('vtu.rechecked', $tx, $before, array('status' => $status));
        $this->session->set_flashdata('success', 'Settled as '.$status.
            ($status === 'FAILED' ? ' — the charge was refunded.' : '.'));
        redirect('admin/vtu/'.$tx->public_id);
    }

    /** POST /admin/vtu/:id/refund — return the charge to the customer's wallet. */
    public function refund($public_id) {
        $tx     = $this->guard($public_id, 'vtu.refund');
        $reason = trim((string)$this->input->post('reason', true));

        $before = array('status' => $tx->status, 'refunded_amount' => $tx->refunded_amount);
        $result = $this->transactionengine->transition(
            $tx->id, 'REFUNDED', 'ADMIN', $reason ?: 'Refunded by staff'
        );
        if (empty($result['ok'])) {
            return $this->fail($tx, $result['error'] ?? 'Could not refund this purchase.');
        }

        $refunded = $result['refunded'] ?? null;
        $this->audit('vtu.refunded', $tx, $before,
            array('status' => 'REFUNDED', 'refunded' => $refunded, 'reason' => $reason));
        $this->session->set_flashdata('success', $refunded
            ? 'Purchase refunded — '.windels_money($refunded).' returned to the wallet.'
            : 'Purchase marked refunded. No money moved: nothing was charged.');
        redirect('admin/vtu/'.$tx->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

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
        redirect('admin/vtu/'.$tx->public_id);
    }

    private function audit($action, $tx, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'service_transactions', (string)$tx->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
