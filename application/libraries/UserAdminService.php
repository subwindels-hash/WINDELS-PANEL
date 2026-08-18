<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * UserAdminService — the rules behind Admin → Customers and Admin → Staff.
 *
 * Two admin screens sit on this: the customer directory (search, suspend,
 * price group, wallet adjustment) and the staff directory (who holds which
 * role). They share a library because they share the dangerous part — both
 * change what a person can do, and one of them moves money.
 *
 * The rules that matter here are the ones that stop an admin panel becoming
 * the attack surface:
 *
 *   - **Nobody edits their own role or status.** An admin who can demote
 *     themselves can lock the whole team out by accident; an admin who can
 *     promote themselves does not need anyone's approval to become
 *     SUPER_ADMIN. Self-service on your own privileges is always a bug.
 *   - **The last SUPER_ADMIN cannot be demoted, suspended or deleted.** This
 *     is the one that turns a panel into a brick, and it is checked by
 *     counting live rows rather than trusting a flag.
 *   - **Only a SUPER_ADMIN grants SUPER_ADMIN.** Otherwise `staff.manage` is
 *     silently equivalent to full ownership of the panel.
 *   - **Wallet adjustments go through LedgerService, never a balance write.**
 *     A manual correction is still double-entry, still idempotent, still
 *     floored at zero. `actor_id` and `note` record who and why.
 *
 * Password hashes, MFA secrets and API keys are never read by this service.
 * Staff can suspend an account or correct a balance; they cannot become the
 * customer or learn their credentials.
 */
class UserAdminService {

    /** Roles this panel knows, most privileged first. */
    const ROLES = array('SUPER_ADMIN', 'ADMIN', 'STAFF', 'CUSTOMER');

    /** Account states. SUSPENDED can log in nowhere; BANNED is permanent. */
    const STATUSES = array('ACTIVE', 'SUSPENDED', 'BANNED', 'PENDING');

    /** Roles that reach the admin area at all. */
    const STAFF_ROLES = array('SUPER_ADMIN', 'ADMIN', 'STAFF');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('User_model', 'Wallet_model', 'Wallet_transaction_model'));
        $this->ci->load->library('LedgerService');
    }

    /* ------------------------------ reading ----------------------------- */

    /** One page of the customer directory, plus the total for the pager. */
    public function grid(array $filters, $limit = 25, $offset = 0) {
        return array(
            'rows'  => $this->ci->User_model->admin_search($filters, $limit, $offset),
            'total' => $this->ci->User_model->admin_count($filters),
        );
    }

    /** A user with the wallet and lifetime figures the detail screen shows. */
    public function profile($public_id) {
        $user = $this->ci->User_model->find_by_public_id($public_id);
        if (!$user) return null;
        $user->wallet = $this->ci->Wallet_model->for_user($user->id);
        return $user;
    }

    /* ------------------------------ writing ----------------------------- */

    /**
     * Suspend, ban or reinstate an account.
     *
     * Returns the usual array shape: ok, error, code, before, after.
     */
    public function set_status($actor, $user, $status, $reason = null) {
        $status = strtoupper(trim((string)$status));
        if (!in_array($status, self::STATUSES, true)) {
            return $this->err('INVALID', 'Unknown account status "'.$status.'".');
        }
        if ($guard = $this->guard_self($actor, $user, 'status')) return $guard;

        // Taking the last SUPER_ADMIN offline locks everyone out permanently.
        if ($status !== 'ACTIVE' && $this->is_last_super_admin($user)) {
            return $this->err('LAST_ADMIN',
                'This is the only active super admin. Promote someone else before suspending this account.');
        }
        if ((string)$user->status === $status) {
            return $this->err('NOOP', 'This account is already '.strtolower($status).'.');
        }

        $before = array('status' => $user->status);
        $this->ci->User_model->set_status($user->id, $status);

        return array(
            'ok' => true, 'error' => null, 'code' => null,
            'before' => $before,
            'after'  => array('status' => $status, 'reason' => $reason),
            'user'   => $this->ci->User_model->find_by_id($user->id),
        );
    }

    /**
     * Change a user's role.
     *
     * The privilege-escalation guard lives here rather than in the controller
     * because there are two callers (the customer screen promotes; the staff
     * screen re-grades) and a rule enforced in one place only is not a rule.
     */
    public function set_role($actor, $user, $role) {
        $role = strtoupper(trim((string)$role));
        if (!in_array($role, self::ROLES, true)) {
            return $this->err('INVALID', 'Unknown role "'.$role.'".');
        }
        if ($guard = $this->guard_self($actor, $user, 'role')) return $guard;

        // Only an owner may mint another owner. Without this, `staff.manage`
        // quietly grants everything.
        if ($role === 'SUPER_ADMIN' && $actor->role !== 'SUPER_ADMIN') {
            return $this->err('FORBIDDEN', 'Only a super admin can grant the super admin role.');
        }
        if ($user->role === 'SUPER_ADMIN' && $role !== 'SUPER_ADMIN') {
            if ($actor->role !== 'SUPER_ADMIN') {
                return $this->err('FORBIDDEN', 'Only a super admin can change another super admin.');
            }
            if ($this->is_last_super_admin($user)) {
                return $this->err('LAST_ADMIN',
                    'This is the only active super admin. Promote someone else first.');
            }
        }
        if ((string)$user->role === $role) {
            return $this->err('NOOP', 'This account already holds the '.$role.' role.');
        }

        $before = array('role' => $user->role);
        $this->ci->User_model->set_role($user->id, $role);

        return array(
            'ok' => true, 'error' => null, 'code' => null,
            'before' => $before, 'after' => array('role' => $role),
            'user'   => $this->ci->User_model->find_by_id($user->id),
        );
    }

    /** Move a customer onto a different price group (or off one entirely). */
    public function set_price_group($user, $price_group_id) {
        $id = ($price_group_id === null || $price_group_id === '') ? null : (int)$price_group_id;
        if ($id !== null) {
            $group = $this->ci->db->where('id', $id)->get('price_groups')->row();
            if (!$group) return $this->err('INVALID', 'That price group no longer exists.');
        }
        $before = array('price_group_id' => $user->price_group_id);
        $this->ci->User_model->set_price_group($user->id, $id);
        return array(
            'ok' => true, 'error' => null, 'code' => null,
            'before' => $before, 'after' => array('price_group_id' => $id),
            'user'   => $this->ci->User_model->find_by_id($user->id),
        );
    }

    /**
     * Credit or debit a wallet by hand.
     *
     * Every guard that protects an automated movement protects this one too,
     * because LedgerService::adjust() is the same code path a purchase takes:
     * the row is locked, the balance floor holds, and the idempotency key
     * makes a double-submitted form a no-op rather than a double payment.
     *
     * The reason is mandatory. An unexplained balance change is indistinguish-
     * able from theft when someone reads the ledger back six months later.
     */
    public function adjust_wallet($actor, $user, $amount, $direction, $reason, $idempotency_key = null) {
        $direction = strtoupper(trim((string)$direction));
        if (!in_array($direction, array('CREDIT', 'DEBIT'), true)) {
            return $this->err('INVALID', 'Choose whether to credit or debit the wallet.');
        }
        $reason = trim((string)$reason);
        if ($reason === '') {
            return $this->err('NO_REASON', 'A reason is required for a manual balance change.');
        }
        $amount = $this->money($amount);
        if ($amount === null || bccomp($amount, '0', 8) <= 0) {
            return $this->err('INVALID', 'Enter an amount greater than zero.');
        }

        $wallet = $this->ci->Wallet_model->for_user($user->id);
        if (!$wallet) return $this->err('NO_WALLET', 'This customer has no wallet yet.');

        // Fail loudly before touching the ledger, so the operator sees "not
        // enough balance" rather than a generic ledger error.
        if ($direction === 'DEBIT' && bccomp((string)$wallet->balance, $amount, 8) < 0) {
            return $this->err('INSUFFICIENT',
                'That is more than the customer holds ('.windels_money($wallet->balance, $wallet->currency).').');
        }

        $res = $this->ci->ledgerservice->adjust(
            $wallet->id, $amount, $direction, 'AdminAdjustment', (string)$user->id,
            $idempotency_key ?: ('admin:adjust:'.$user->id.':'.bin2hex(random_bytes(8))),
            array('reason' => $reason, 'actor' => (int)$actor->id),
            (int)$actor->id, $reason
        );
        if (empty($res['ok'])) {
            return $this->err('LEDGER', $res['error'] === 'INSUFFICIENT_BALANCE'
                ? 'That is more than the customer holds.'
                : ($res['error'] ?: 'The adjustment could not be recorded.'));
        }
        if (!empty($res['duplicate'])) {
            return $this->err('DUPLICATE', 'That adjustment has already been applied.');
        }

        return array(
            'ok' => true, 'error' => null, 'code' => null,
            'before' => array('balance' => $wallet->balance),
            'after'  => array('balance' => $res['balance_after'], 'direction' => $direction,
                              'amount' => $amount, 'reason' => $reason),
            'wallet' => $this->ci->Wallet_model->for_user($user->id),
        );
    }

    /* ------------------------------ helpers ----------------------------- */

    /**
     * Is this the only ACTIVE super admin left?
     *
     * Counted, not inferred. A flag would drift the first time someone edits
     * the users table directly.
     */
    public function is_last_super_admin($user) {
        if ((string)$user->role !== 'SUPER_ADMIN') return false;
        return $this->ci->User_model->count_active_super_admins((int)$user->id) === 0;
    }

    /** Staff may not edit their own privileges — see the class comment. */
    private function guard_self($actor, $user, $what) {
        if ($actor && (int)$actor->id === (int)$user->id) {
            return $this->err('SELF', 'You cannot change your own '.$what.'. Ask another admin.');
        }
        return null;
    }

    /** Blank is NULL; anything non-numeric is rejected rather than cast to 0. */
    private function money($v) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        return number_format((float)$v, 8, '.', '');
    }

    private function err($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code,
                     'before' => null, 'after' => null);
    }
}
