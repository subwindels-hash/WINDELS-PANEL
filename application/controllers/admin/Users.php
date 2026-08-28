<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Users — the customer directory and one customer's file.
 *
 * This screen was routed and permissioned from Session 15 (`admin/customers`
 * sits in the sidebar and `users.view` / `users.edit` were seeded into the
 * role matrix) but never built, so the nav entry 404'd for every operator and
 * `users.view` gated nothing. Support had no way to answer "why is this
 * customer's balance wrong" or to suspend a fraudulent account without SQL.
 *
 * Read requires `users.view`. Changing an account requires `users.edit`;
 * moving money requires `wallets.adjust`, which is a separate permission
 * because reading a customer's file and reaching into their wallet are
 * different levels of trust.
 *
 * What this controller deliberately cannot do:
 *   - **Write a balance.** Adjustments go through UserAdminService →
 *     LedgerService, so a manual correction is double-entry and idempotent
 *     like every other movement.
 *   - **Read or reset credentials.** No password hash, MFA secret or API key
 *     is loaded here. The one exception is the transaction PIN: at the
 *     operator's request it can be *revealed* (`pin_reveal`, `users.edit`,
 *     POST-only, audited per reveal) from its encrypted copy. Explicitly
 *     permitted staff may open a short-lived, audited, read-only dashboard
 *     view; that session cannot submit changes.
 *   - **Delete anyone.** Accounts carry ledger history; they are suspended or
 *     banned, never removed.
 */
class Users extends Admin_Controller {

    const PER_PAGE = 25;

    /** Rows of recent activity shown on one customer's file. */
    const RECENT = 10;

    public function __construct() {
        parent::__construct();
        $this->require_perm('users.view');
        $this->load->library(array('UserAdminService', 'ImpersonationService', 'DashboardStats'));
        $this->load->model(array(
            'User_model', 'Wallet_model', 'Wallet_transaction_model',
            'Order_model', 'Service_transaction_model', 'Audit_log_model',
            'Earning_model', 'Payout_request_model',
        ));
    }

    public function index() {
        redirect('admin/customers');
    }

    /** GET /admin/customers — the directory. */
    public function customers() {
        $filters = $this->filters();
        $filters['customers_only'] = !$this->input->get('role');

        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $grid  = $this->useradminservice->grid($filters, $limit, ($page - 1) * $limit);
        $total = (int)$grid['total'];

        $this->render('Customers', 'admin/users/index', array(
            'users'       => $grid['rows'],
            'counts'      => $this->User_model->status_counts(
                                 array('customers_only' => !empty($filters['customers_only']))),
            'filters'     => $filters,
            'groups'      => $this->db->order_by('name', 'ASC')->get('price_groups')->result(),
            'roles'       => UserAdminService::ROLES,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ));
    }

    /**
     * GET /admin/wallets — every wallet, richest first.
     *
     * A separate view of the same table rather than a separate screen: the
     * question "who is holding our float" is asked during reconciliation, and
     * sorting the customer directory by balance answers it.
     */
    public function wallets() {
        $filters = $this->filters();
        $page    = max(1, (int)$this->input->get('page'));
        $limit   = self::PER_PAGE;
        $grid    = $this->useradminservice->grid($filters, $limit, ($page - 1) * $limit);

        $this->render('Wallets', 'admin/users/wallets', array(
            'users'       => $grid['rows'],
            'filters'     => $filters,
            'totals'      => $this->Wallet_model->totals(),
            'page'        => $page,
            'total'       => (int)$grid['total'],
            'total_pages' => max(1, (int)ceil((int)$grid['total'] / $limit)),
        ));
    }

    /** GET /admin/customers/:id — one customer's file. */
    public function detail($public_id) {
        $user = $this->useradminservice->profile($public_id);
        if (!$user) show_404();

        // A customer's file shows recent activity, not their whole history —
        // a five-year-old account would otherwise load thousands of rows to
        // render ten. The full lists live behind the per-domain queues.
        $limit = self::RECENT;

        // The affiliate/earnings summary is only worth a query when the
        // viewing operator can actually act on it (Admin → Payouts is gated
        // on earnings.view/payouts.review); everyone else's file loads
        // without it rather than paying for a permission-denied panel.
        $perms = $this->auth->permissions();
        $can_view_earnings = in_array('*', $perms, true)
            || in_array('earnings.view', $perms, true)
            || in_array('payouts.review', $perms, true);
        $earnings_summary = null;
        $earning_rows = array();
        $payout_rows = array();
        if ($can_view_earnings) {
            $this->load->library('EarningsService');
            $earnings_summary = $this->earningsservice->balance($user->id);
            $earning_rows = $this->Earning_model->for_user($user->id, $limit);
            $payout_rows = $this->Payout_request_model->for_user($user->id, $limit);
        }

        $this->render($user->username, 'admin/users/detail', array(
            'user'         => $user,
            'movements'    => $this->Wallet_transaction_model->for_wallet($user->wallet->id, $limit),
            'orders'       => $this->Order_model->admin_search(array('user_id' => $user->id), $limit),
            'services'     => $this->Service_transaction_model->admin_search(array('user_id' => $user->id), $limit),
            'groups'       => $this->db->order_by('name', 'ASC')->get('price_groups')->result(),
            'roles'        => UserAdminService::ROLES,
            'statuses'     => UserAdminService::STATUSES,
            'is_last_admin'=> $this->useradminservice->is_last_super_admin($user),
            'can_view_earnings' => $can_view_earnings,
            'earnings_summary'  => $earnings_summary,
            'earning_rows'      => $earning_rows,
            'payout_rows'       => $payout_rows,
        ));
    }

    /* ------------------------------ actions ----------------------------- */

    /** POST /admin/customers/:id/status — suspend, ban or reinstate. */
    public function status($public_id) {
        $user   = $this->guard($public_id, 'users.edit');
        $status = $this->input->post('status', true);
        $reason = trim((string)$this->input->post('reason', true));

        $res = $this->useradminservice->set_status($this->current_user, $user, $status, $reason ?: null);
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        $this->audit('user.status_changed', $user, $res['before'], $res['after']);
        $this->done($user, 'Account is now '.strtolower($status).'.');
    }

    /** POST /admin/customers/:id/role — promote or demote. */
    public function role($public_id) {
        $user = $this->guard($public_id, 'staff.manage');
        $role = $this->input->post('role', true);

        $res = $this->useradminservice->set_role($this->current_user, $user, $role);
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        $this->audit('user.role_changed', $user, $res['before'], $res['after']);
        $this->done($user, $user->username.' is now '.$role.'.');
    }

    /** POST /admin/customers/:id/price-group — move onto custom pricing. */
    public function price_group($public_id) {
        $user = $this->guard($public_id, 'pricing.manage');

        $res = $this->useradminservice->set_price_group($user, $this->input->post('price_group_id', true));
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        $this->audit('user.price_group_changed', $user, $res['before'], $res['after']);
        $this->done($user, 'Price group updated.');
    }

    /**
     * POST /admin/customers/:id/adjust — correct a balance by hand.
     *
     * Gated on `wallets.adjust` rather than `users.edit`: the ability to
     * suspend an account and the ability to mint balance are different jobs.
     */
    public function adjust($public_id) {
        $user = $this->guard($public_id, 'wallets.adjust');

        $res = $this->useradminservice->adjust_wallet(
            $this->current_user, $user,
            $this->input->post('amount', true),
            $this->input->post('direction', true),
            $this->input->post('reason', true),
            // One key per rendered form, so a double-submit cannot pay twice.
            'admin:adjust:'.$user->id.':'.substr((string)$this->input->post('nonce', true), 0, 40)
        );
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        $this->audit('wallet.adjusted', $user, $res['before'], $res['after']);
        $this->done($user, 'Wallet adjusted. New balance '
            .marvy_money($res['wallet']->balance, $res['wallet']->currency).'.');
    }

    /**
     * POST /admin/customers/:id/impersonate — enter a read-only support lens.
     *
     * The service independently repeats the role, permission, target, reason
     * and session checks. This controller gate keeps the route conventional;
     * it is not the only thing protecting the identity switch.
     */
    public function impersonate($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $user = $this->guard($public_id, 'users.impersonate');
        $res = $this->impersonationservice->start(
            $this->current_user,
            $user,
            $this->input->post('reason', true),
            $this->input->post('confirm', true) === '1',
            $this->input->ip_address(),
            $this->input->user_agent(),
            $this->request_id
        );
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        $this->session->set_flashdata('success',
            'Read-only customer impersonation started. End it from the persistent banner when finished.');
        redirect('dashboard');
    }

    /**
     * POST /admin/customers/:id/pin-reset — clear a customer's security PIN.
     *
     * A reset, never a reveal. The stored value is a one-way hash, so there is
     * nothing to show even if an operator asked: the customer sets a new PIN
     * themselves from Account → Security.
     */
    public function pin_reset($public_id) {
        $user = $this->guard($public_id, 'users.edit');

        $this->load->library('PinService');
        $res = $this->pinservice->admin_reset(
            $user,
            $this->current_user,
            trim((string)$this->input->post('reason', true)) ?: null
        );
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        // The audit payload records that a reset happened, never the secret.
        $this->audit('user.pin_reset', $user, array('pin_set' => true), array('pin_set' => false));
        $this->done($user, 'Security PIN cleared. The customer must set a new one before their next PIN-protected action.');
    }

    /**
     * POST /admin/customers/:id/pin-reveal — show the customer's security PIN.
     *
     * A deliberate change of contract, made at the operator's request: the
     * PIN now also lives in an AES-256-GCM envelope so support can answer
     * "what is my PIN?" without forcing a reset every time. The guarantees
     * that keep that survivable are here — `users.edit` (same permission as
     * clearing it), POST-only, and a reveal that is audited naming the staff
     * member while the PIN itself never touches the audit trail. The
     * plaintext is shown once, in the flash message, and nowhere else.
     */
    public function pin_reveal($public_id) {
        $user = $this->guard($public_id, 'users.edit');

        $this->load->library('PinService');
        $res = $this->pinservice->reveal($user);
        if (empty($res['ok'])) {
            return $this->fail($user, $res['error']);
        }

        // THAT a PIN was revealed is the audit fact. The PIN is not part of it.
        $this->audit('user.pin_revealed', $user, null, array('revealed' => true));
        $this->done($user,
            'Security PIN: '.$res['pin']
            .' — shown once; the reveal is recorded in the audit log.');
    }

    /**
     * POST /admin/customers/:id/pin-unlock — lift a PIN lockout.
     *
     * Separate from the reset because they answer different questions: the
     * customer who mistyped five times still knows their PIN and only needs
     * the lock lifted.
     */
    public function pin_unlock($public_id) {
        $user = $this->guard($public_id, 'users.edit');

        $this->load->library('PinService');
        $res = $this->pinservice->admin_unlock($user, $this->current_user);
        if (empty($res['ok'])) return $this->fail($user, $res['error']);

        $this->audit('user.pin_unlocked', $user, null, null);
        $this->done($user, 'PIN lockout cleared.');
    }

    /**
     * POST /admin/customers/:id/password-reset — send a reset link.
     *
     * Deliberately issues the customer's own reset flow rather than setting a
     * password the operator would then know. Staff never handle a customer
     * credential.
     */
    public function force_logout($public_id) {
        $user = $this->guard($public_id, 'users.edit');
        $res = $this->useradminservice->force_logout($this->current_user, $user);
        if (empty($res['ok'])) return $this->fail($user, $res['error']);
        $this->audit('user.force_logout', $user, null, $res['after']);
        $this->done($user, 'Refresh tokens revoked. The customer must sign in again on other devices.');
    }

    public function revoke_keys($public_id) {
        $user = $this->guard($public_id, 'users.edit');
        $res = $this->useradminservice->revoke_api_keys($this->current_user, $user);
        if (empty($res['ok'])) return $this->fail($user, $res['error']);
        $this->audit('user.api_keys_revoked', $user, null, $res['after']);
        $this->done($user, 'All API keys for this account have been revoked.');
    }

    public function password_reset($public_id) {
        $user = $this->guard($public_id, 'users.edit');

        $res = $this->auth->begin_password_reset($user->email, $this->input->ip_address());
        if (empty($res['ok'])) {
            return $this->fail($user, $res['error'] ?? 'Could not start a password reset.');
        }

        $this->audit('user.password_reset_sent', $user, null, array('email' => $user->email));
        $this->done($user, 'A password-reset link has been emailed to '.$user->email.'.');
    }

    /* ------------------------------ helpers ----------------------------- */

    private function filters() {
        return array(
            'status'         => $this->input->get('status', true),
            'role'           => $this->input->get('role', true),
            'search'         => $this->input->get('q', true),
            'price_group_id' => (int)$this->input->get('group'),
        );
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app_theme', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/customers',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $user = $this->User_model->find_by_public_id($public_id);
        if (!$user) show_404();
        return $user;
    }

    private function fail($user, $message) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/customers/'.$user->public_id);
    }

    private function done($user, $message) {
        $this->session->set_flashdata('success', $message);
        redirect('admin/customers/'.$user->public_id);
    }

    private function audit($action, $user, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'users', (string)$user->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
