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
 *     silently equivalent to full ownership of the panel. The same rule
 *     guards create_admin(), because minting an owner is exactly as powerful
 *     as promoting one.
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
     * Create a new administrator account (Admin → Administrators → Add).
     *
     * The role-minting rules mirror set_role(): only a SUPER_ADMIN may create
     * another SUPER_ADMIN, and only the two administrator roles are creatable
     * here — ordinary staff accounts are promoted on their own user file, and
     * customers still register themselves.
     *
     * The creator sets the initial password and must hand it over privately;
     * the audit row records who minted the account, never the password. The
     * address is marked verified because the operator — not the newcomer —
     * vouched for it at creation time.
     */
    public function create_admin($actor, array $input) {
        if (!is_object($actor) || !in_array((string)$actor->role, self::STAFF_ROLES, true)) {
            return $this->err('FORBIDDEN', 'Only staff may create administrator accounts.');
        }

        $role = strtoupper(trim((string)($input['role'] ?? '')));
        if (!in_array($role, array('ADMIN', 'SUPER_ADMIN'), true)) {
            return $this->err('INVALID', 'Choose the ADMIN or SUPER_ADMIN role.');
        }
        // Same rule as set_role(): without it, staff.manage quietly grants
        // full ownership of the panel.
        if ($role === 'SUPER_ADMIN' && (string)$actor->role !== 'SUPER_ADMIN') {
            return $this->err('FORBIDDEN', 'Only a super admin can create another super admin.');
        }

        $username = trim((string)($input['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $username)) {
            return $this->err('INVALID_USERNAME',
                'Usernames are 3–64 letters, numbers, dashes or underscores.');
        }
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return $this->err('INVALID_EMAIL', 'Give a valid email address.');
        }
        $password = (string)($input['password'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 72) {
            return $this->err('INVALID_PASSWORD', 'Passwords are 8–72 characters.');
        }
        if ($this->ci->User_model->find_by_username($username)) {
            return $this->err('USERNAME_TAKEN', 'That username is already taken.');
        }
        if ($this->ci->User_model->find_by_email($email)) {
            return $this->err('EMAIL_TAKEN', 'That email address is already in use.');
        }

        // Reuse AuthService's hasher so the account verifies with the same
        // algorithm family (Argon2id when available) as every other login.
        $this->ci->load->library('AuthService');
        $now = gmdate('Y-m-d H:i:s');
        $default_group = $this->ci->db->where('is_default', 1)->limit(1)->get('price_groups')->row();
        $price_group_id = $default_group ? (int)$default_group->id : null;

        $this->ci->db->trans_start();
        $this->ci->db->insert('users', array(
            'public_id'         => marvy_public_id(),
            'username'          => $username,
            'email'             => $email,
            'password_hash'     => $this->ci->authservice->hash_password($password),
            'status'            => 'ACTIVE',
            'role'              => $role,
            'price_group_id'    => $price_group_id,
            'user_code'         => marvy_allocate_user_code($this->ci->db),
            'timezone'          => 'UTC',
            'locale'            => 'en',
            'email_verified_at' => $now, // the creating operator vouches for it
            'mfa_enabled'       => 0,
            'created_at'        => $now,
        ));
        $user_id = $this->ci->db->insert_id();
        // Same shape as every other account: a wallet row exists even for
        // administrators, so the user file and ledger queries never have to
        // special-case them. It starts at zero and nothing credits it.
        $this->ci->db->insert('wallets', array(
            'public_id'  => marvy_public_id(),
            'user_id'    => $user_id,
            'balance'    => '0.00000000',
            'currency'   => marvy_base_currency(),
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return $this->err('CREATE_FAILED', 'The account could not be created. Nothing was saved.');
        }

        $user = $this->ci->User_model->find_by_id($user_id);
        $after = array('username' => $username, 'email' => $email, 'role' => $role);

        // Audit here rather than in the controller so the evidence row is
        // written even if a future caller forgets to. The password never
        // appears in it. Best-effort, matching AuthService::register().
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                (int)$actor->id,
                'staff.admin_created',
                'users',
                (string)$user->public_id,
                null,
                $after,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'admin creation audit failed: '.$e->getMessage());
        }

        return array('ok' => true, 'error' => null, 'code' => null,
            'before' => null, 'after' => $after, 'user' => $user);
    }

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
                'That is more than the customer holds ('.marvy_money($wallet->balance, $wallet->currency).').');
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

    /**
     * Revoke every unrevoked API key for this user.
     */
    public function revoke_api_keys($actor, $user) {
        $now = gmdate('Y-m-d H:i:s');
        $this->ci->db->where('user_id', $user->id)->where('revoked_at IS NULL', null, false)
            ->update('api_keys', array('revoked_at' => $now));
        return array('ok' => true, 'error' => null, 'code' => null,
            'before' => null, 'after' => array('revoked_at' => $now, 'actor' => (int)$actor->id));
    }

    /**
     * Force logout: revoke refresh tokens so other devices must sign in again.
     * PHP file sessions cannot be enumerated reliably on shared hosting.
     */
    public function force_logout($actor, $user) {
        $now = gmdate('Y-m-d H:i:s');
        if ($this->ci->db->table_exists('refresh_tokens')) {
            $this->ci->db->where('user_id', $user->id)->where('revoked_at IS NULL', null, false)
                ->update('refresh_tokens', array('revoked_at' => $now));
        }
        return array('ok' => true, 'error' => null, 'code' => null,
            'before' => null, 'after' => array('forced_at' => $now, 'actor' => (int)$actor->id));
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
