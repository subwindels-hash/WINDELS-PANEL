<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AuthService — the single authority for authentication and authorization.
 *
 * Wires together the identity schema (users / roles / permissions /
 * role_permissions / login_attempts / mfa_methods / audit_logs), the signed
 * token primitive (SignedToken) and TOTP MFA (Totp). The Auth controller and
 * the MY_Controller base classes are thin callers into this service.
 *
 * Security properties:
 *  - passwords hashed with password_hash() (Argon2id when available, bcrypt)
 *  - login is constant-time-ish: always runs password_verify against a dummy
 *    hash for unknown accounts so response timing does not leak user existence
 *  - MFA secret stored encrypted at rest; recovery codes hashed
 *  - every login/out and password/email change is audit-logged
 *  - permissions are loaded per-request and cached; SUPER_ADMIN bypasses
 */
class AuthService {

    const SESSION_USER_KEY   = 'user_id';
    const SESSION_MFA_KEY    = 'mfa_pending';
    const RESET_TTL          = 3600;   // 60 minutes
    const VERIFY_TTL         = 86400;  // 24 hours
    const DUMMY_BCRYPT       = '$2y$10$usesomesillystringforeTb5uOzFf8sJ3bQ5Yp2uq8iQxqKkHq';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('User_model', 'Permission_model', 'Role_model'));
        $this->ci->load->library(array('session', 'SignedToken', 'Totp', 'EncryptionService'));
        // Model classes live under application/models; load the rest lazily.
    }

    /* -------------------------------------------------------------- */
    /* Registration                                                   */
    /* -------------------------------------------------------------- */

    /**
     * Register a new CUSTOMER. Creates user + wallet in a transaction and
     * returns the user row. Caller is responsible for validation and the
     * registration_enabled / blacklist gates.
     *
     * @param array $data username,email,password,first_name?,last_name?,referred_by_code?,ip?
     * @return array{ok:bool,user?:object,error?:string}
     */
    public function register(array $data) {
        $email = strtolower(trim($data['email']));
        $username = trim($data['username']);

        if ($this->ci->User_model->find_by_email($email)) {
            return array('ok' => false, 'error' => 'EMAIL_TAKEN');
        }
        if ($this->ci->User_model->find_by_username($username)) {
            return array('ok' => false, 'error' => 'USERNAME_TAKEN');
        }

        $referred_by = null;
        if (!empty($data['referred_by_code'])) {
            $ref = $this->ci->User_model->find_by_referral_code(trim($data['referred_by_code']));
            if ($ref && $ref->status === 'ACTIVE') {
                $referred_by = $ref->id;
            }
        }

        $require_verify = $this->setting('email_verification_required', false);

        $this->ci->db->trans_start();
        $this->ci->db->insert('users', array(
            'public_id'         => windels_public_id(),
            'username'          => $username,
            'email'             => $email,
            'password_hash'     => $this->hash_password($data['password']),
            'first_name'        => $data['first_name'] ?? null,
            'last_name'         => $data['last_name'] ?? null,
            'status'            => 'ACTIVE',
            'role'              => 'CUSTOMER',
            'referral_code'     => $this->generate_referral_code(),
            'referred_by_id'    => $referred_by,
            'timezone'          => $data['timezone'] ?? 'UTC',
            'locale'            => $data['locale'] ?? 'en',
            'email_verified_at' => $require_verify ? null : gmdate('Y-m-d H:i:s'),
            'mfa_enabled'       => 0,
            'created_at'        => gmdate('Y-m-d H:i:s'),
        ));
        $user_id = $this->ci->db->insert_id();

        // Every customer has a USD wallet (§24).
        $this->ci->db->insert('wallets', array(
            'public_id'  => windels_public_id(),
            'user_id'    => $user_id,
            'balance'    => '0.00000000',
            'currency'   => 'USD',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->ci->db->trans_complete();

        if ($this->ci->db->trans_status() === false) {
            return array('ok' => false, 'error' => 'REGISTRATION_FAILED');
        }

        $user = $this->ci->User_model->find_by_id($user_id);

        // Referral attribution is first-touch and permanent (Session 14). It is
        // deliberately outside the registration transaction: a failure here must
        // never cost the customer their account.
        if ($referred_by && $ref) {
            $this->attribute_referral($ref, $user);
        }

        $this->audit($user_id, 'auth.register', 'users', $user->public_id,
            null, array('email' => $email), $data['ip'] ?? null);
        return array('ok' => true, 'user' => $user);
    }

    /** Link a new signup to its referrer via AffiliateService (never fatal). */
    private function attribute_referral($referrer, $referred) {
        try {
            $this->ci->load->library('AffiliateService');
            if (!isset($this->ci->affiliateservice)
                || !method_exists($this->ci->affiliateservice, 'attribute')) {
                return;
            }
            $res = $this->ci->affiliateservice->attribute($referrer, $referred);
            if (empty($res['ok'])) {
                log_message('info', 'referral not attributed: '.($res['code'] ?? 'UNKNOWN'));
            }
        } catch (Exception $e) {
            log_message('error', 'referral attribution failed: '.$e->getMessage());
        }
    }

    /* -------------------------------------------------------------- */
    /* Login / logout                                                 */
    /* -------------------------------------------------------------- */

    /**
     * Attempt a password login.
     *
     * @return array{ok:bool, mfa_required?:bool, user?:object, error?:string}
     *         ok=true,mfa_required=false  -> session established
     *         ok=true,mfa_required=true   -> pending state, awaiting TOTP
     *         ok=false                    -> error code
     */
    public function attempt_login($identifier, $password, $ip = null, $user_agent = null) {
        $identifier = trim((string)$identifier);
        $user = $this->ci->User_model->find_by_identifier($identifier);

        // Always burn comparable time on the hash check, even with no user.
        $hash = $user ? $user->password_hash : self::DUMMY_BCRYPT;
        $password_ok = password_verify($password, $hash);

        if (!$user || !$password_ok) {
            $this->ci->load->library('RateLimiter');
            $this->ci->ratelimiter->record($identifier, $ip, false, 'INVALID_CREDENTIALS', $user_agent);
            return array('ok' => false, 'error' => 'INVALID_CREDENTIALS');
        }

        if ($user->status !== 'ACTIVE') {
            $this->ci->load->library('RateLimiter');
            $this->ci->ratelimiter->record($identifier, $ip, false, 'ACCOUNT_'.$user->status, $user_agent);
            return array('ok' => false, 'error' => 'ACCOUNT_'.$user->status);
        }

        // Rehash to a stronger algorithm if needed (transparent upgrade).
        if (password_needs_rehash($user->password_hash, PASSWORD_DEFAULT)) {
            $this->ci->db->where('id', $user->id)->update('users', array(
                'password_hash' => $this->hash_password($password),
            ));
        }

        // MFA step — do not establish an authenticated session yet.
        if ((int)$user->mfa_enabled === 1) {
            $this->ci->session->set_userdata(array(
                self::SESSION_MFA_KEY => array(
                    'user_id'    => (int)$user->id,
                    'expires_at' => time() + 300, // 5 minutes to complete MFA
                ),
            ));
            return array('ok' => true, 'mfa_required' => true, 'user' => $user);
        }

        $this->complete_login($user, $ip, $user_agent);
        return array('ok' => true, 'mfa_required' => false, 'user' => $user);
    }

    /**
     * Complete the second MFA factor after a successful password login.
     *
     * @param string $code  6-digit TOTP or a recovery code
     * @return array{ok:bool,error?:string,used_recovery?:bool}
     */
    public function verify_mfa($code, $ip = null, $user_agent = null) {
        $pending = $this->ci->session->userdata(self::SESSION_MFA_KEY);
        if (!$pending || !is_array($pending) || (int)($pending['expires_at'] ?? 0) < time()) {
            return array('ok' => false, 'error' => 'MFA_SESSION_EXPIRED');
        }
        $user = $this->ci->User_model->find_by_id((int)$pending['user_id']);
        if (!$user || (int)$user->mfa_enabled !== 1) {
            return array('ok' => false, 'error' => 'MFA_NOT_ENABLED');
        }

        $method = $this->ci->db->where('user_id', $user->id)
            ->where('type', 'TOTP')->where('verified', 1)->get('mfa_methods')->row();
        if (!$method) {
            return array('ok' => false, 'error' => 'MFA_NOT_CONFIGURED');
        }
        $secret = $this->ci->encryptionservice->decrypt($method->secret);

        if (Totp::verify($secret, $code)) {
            $this->complete_login($user, $ip, $user_agent);
            $this->ci->session->unset_userdata(self::SESSION_MFA_KEY);
            return array('ok' => true);
        }

        // Fall back to a single-use recovery code.
        $remaining = Totp::consume_recovery_code($method->recovery_codes, $code);
        if (is_array($remaining)) {
            $this->ci->db->where('id', $method->id)->update('mfa_methods', array(
                'recovery_codes' => json_encode($remaining),
            ));
            $this->complete_login($user, $ip, $user_agent);
            $this->ci->session->unset_userdata(self::SESSION_MFA_KEY);
            $this->audit($user->id, 'auth.mfa.recovery_used', 'users', $user->public_id, null, null, $ip);
            return array('ok' => true, 'used_recovery' => true);
        }

        $this->ci->load->library('RateLimiter');
        $this->ci->ratelimiter->record($user->email, $ip, false, 'MFA_FAILED', $user_agent);
        return array('ok' => false, 'error' => 'MFA_INVALID_CODE');
    }

    private function complete_login($user, $ip, $user_agent) {
        // Defend against session fixation (§61).
        $this->ci->session->sess_regenerate(true);
        $this->ci->session->set_userdata(array(
            self::SESSION_USER_KEY => (int)$user->id,
            'role'                 => $user->role,
            'login_at'             => time(),
        ));
        $this->ci->User_model->touch_login($user->id, $ip);
        $this->ci->load->library('RateLimiter');
        $this->ci->ratelimiter->record($user->email, $ip, true, null, $user_agent);
        $this->audit($user->id, 'auth.login', 'users', $user->public_id, null, null, $ip, $user_agent);
    }

    public function logout() {
        $uid = $this->ci->session->userdata(self::SESSION_USER_KEY);
        if ($uid) {
            $this->audit($uid, 'auth.logout', 'users', null);
        }
        $this->ci->session->sess_destroy();
    }

    /** Current authenticated user, or null. */
    public function user() {
        static $cached = null;
        $uid = $this->ci->session->userdata(self::SESSION_USER_KEY);
        if (!$uid) {
            return null;
        }
        if ($cached !== null && (int)$cached->id === (int)$uid) {
            return $cached;
        }
        return $cached = $this->ci->User_model->find_by_id($uid);
    }

    public function check() {
        $u = $this->user();
        return $u !== null && $u->status === 'ACTIVE';
    }

    public function id() {
        return (int) $this->ci->session->userdata(self::SESSION_USER_KEY);
    }

    /* -------------------------------------------------------------- */
    /* Role-based access control                                      */
    /* -------------------------------------------------------------- */

    /**
     * Does the current user (or an explicitly passed role) hold a permission?
     * SUPER_ADMIN always passes. Permission keys are cached for the request.
     */
    public function can($perm_key, $role = null) {
        if ($role === null) {
            $user = $this->user();
            if (!$user) {
                return false;
            }
            $role = $user->role;
        }
        if ($role === 'SUPER_ADMIN') {
            return true;
        }
        return $this->ci->Permission_model->role_has($role, $perm_key);
    }

    /** All permission keys for the current user (cached). */
    public function permissions() {
        $user = $this->user();
        if (!$user) {
            return array();
        }
        if ($user->role === 'SUPER_ADMIN') {
            return array('*');
        }
        return $this->ci->Permission_model->keys_for_role($user->role);
    }

    public function has_role($roles) {
        $user = $this->user();
        if (!$user) {
            return false;
        }
        $roles = (array)$roles;
        return in_array($user->role, $roles, true);
    }

    /** Unread notification count for the current user (cached per request). */
    public function unread_count() {
        static $count = null;
        if ($count !== null) return $count;
        $user = $this->user();
        if (!$user) return $count = 0;
        try {
            if (!isset($this->ci->Notification_model)) {
                $this->ci->load->model('Notification_model');
            }
            return $count = (int)$this->ci->Notification_model->count_for_user($user->id, true);
        } catch (Exception $e) {
            return $count = 0;
        }
    }

    /* -------------------------------------------------------------- */
    /* Email verification                                            */
    /* -------------------------------------------------------------- */

    public function issue_verification_token($user) {
        return $this->ci->signedtoken->issue($user->public_id, 'verify-email', self::VERIFY_TTL);
    }

    /**
     * Consume a verify-email token. Returns the user on success.
     */
    public function verify_email($token) {
        $payload = $this->ci->signedtoken->verify($token, 'verify-email');
        if (!$payload) {
            return array('ok' => false, 'error' => 'INVALID_OR_EXPIRED_TOKEN');
        }
        $user = $this->ci->User_model->find_by_public_id($payload['sub']);
        if (!$user) {
            return array('ok' => false, 'error' => 'USER_NOT_FOUND');
        }
        if ($user->email_verified_at) {
            return array('ok' => true, 'user' => $user, 'already_verified' => true);
        }
        $this->ci->db->where('id', $user->id)->update('users', array(
            'email_verified_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->audit($user->id, 'auth.email.verified', 'users', $user->public_id);
        return array('ok' => true, 'user' => $this->ci->User_model->find_by_id($user->id));
    }

    /* -------------------------------------------------------------- */
    /* Password reset                                                 */
    /* -------------------------------------------------------------- */

    /**
     * Begin a password reset. Always returns silently (no user enumeration).
     * Returns the signed token only when a user exists — the caller decides
     * whether to log/email it.
     *
     * @return array{ok:bool, token?:string, user?:object}
     */
    public function begin_password_reset($identifier, $ip = null) {
        $user = $this->ci->User_model->find_by_identifier(trim((string)$identifier));
        if (!$user) {
            // Burn comparable time so the response is indistinguishable.
            $this->ci->signedtoken->issue('nonexistent', 'reset-password', self::RESET_TTL,
                substr(self::DUMMY_BCRYPT, 7, 12));
            return array('ok' => true);
        }
        $token = $this->ci->signedtoken->issue(
            $user->public_id,
            'reset-password',
            self::RESET_TTL,
            $this->password_fingerprint($user)
        );
        $this->audit($user->id, 'auth.password.reset_requested', 'users', $user->public_id, null, null, $ip);
        return array('ok' => true, 'token' => $token, 'user' => $user);
    }

    /**
     * Consume a reset token and set a new password. The token is bound to the
     * user's current password fingerprint, so a second use (or any other
     * outstanding reset) is invalidated as soon as the password changes.
     */
    public function reset_password($token, $new_password, $ip = null) {
        $payload = $this->ci->signedtoken->verify($token, 'reset-password');
        if (!$payload) {
            return array('ok' => false, 'error' => 'INVALID_OR_EXPIRED_TOKEN');
        }
        $user = $this->ci->User_model->find_by_public_id($payload['sub']);
        if (!$user) {
            return array('ok' => false, 'error' => 'USER_NOT_FOUND');
        }
        // The signature mixes in the current password fingerprint; verify again
        // with it bound so any prior reset is invalidated after a change.
        $check = $this->ci->signedtoken->verify($token, 'reset-password',
            $this->password_fingerprint($user));
        if (!$check) {
            return array('ok' => false, 'error' => 'TOKEN_ALREADY_USED');
        }

        $this->ci->db->where('id', $user->id)->update('users', array(
            'password_hash' => $this->hash_password($new_password),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ));
        // Revoke all refresh tokens for the user (forced re-login elsewhere).
        $this->ci->db->where('user_id', $user->id)
            ->where('revoked_at IS NULL')
            ->update('refresh_tokens', array('revoked_at' => gmdate('Y-m-d H:i:s')));

        $this->audit($user->id, 'auth.password.reset', 'users', $user->public_id, null, null, $ip);
        return array('ok' => true, 'user' => $this->ci->User_model->find_by_id($user->id));
    }

    /**
     * Let an authenticated user change their password (requires the current one).
     */
    public function change_password($user, $current_password, $new_password) {
        if (!password_verify($current_password, $user->password_hash)) {
            return array('ok' => false, 'error' => 'INVALID_CURRENT_PASSWORD');
        }
        $this->ci->db->where('id', $user->id)->update('users', array(
            'password_hash' => $this->hash_password($new_password),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ));
        $this->ci->session->sess_regenerate(true);
        $this->audit($user->id, 'auth.password.changed', 'users', $user->public_id);
        return array('ok' => true);
    }

    /* -------------------------------------------------------------- */
    /* MFA enrolment                                                  */
    /* -------------------------------------------------------------- */

    /**
     * Begin TOTP enrolment: generates a secret, stores it unverified/encrypted
     * and returns the provisioning URI (for a QR code) and the raw secret.
     */
    public function setup_mfa($user) {
        $secret = Totp::generate_secret();
        $recovery = Totp::generate_recovery_codes();

        $existing = $this->ci->db->where('user_id', $user->id)->where('type', 'TOTP')
            ->get('mfa_methods')->row();
        $data = array(
            'secret'         => $this->ci->encryptionservice->encrypt($secret),
            'recovery_codes' => json_encode($recovery['hashed']),
            'verified'       => 0,
        );
        if ($existing) {
            // Only allow overwriting an unverified enrolment; a verified one must
            // be disabled first to avoid locking the user out.
            if ((int)$existing->verified === 1) {
                return array('ok' => false, 'error' => 'MFA_ALREADY_ENABLED');
            }
            $this->ci->db->where('id', $existing->id)->update('mfa_methods', $data);
        } else {
            $data['user_id'] = $user->id;
            $data['type']    = 'TOTP';
            $this->ci->db->insert('mfa_methods', $data);
        }

        return array(
            'ok'           => true,
            'secret'        => $secret,
            'otpauth_uri'   => Totp::provisioning_uri($secret, $user->email),
            'recovery_codes'=> $recovery['plain'],
        );
    }

    /**
     * Confirm TOTP enrolment with a code and enable MFA on the user.
     */
    public function confirm_mfa($user, $code) {
        $method = $this->ci->db->where('user_id', $user->id)->where('type', 'TOTP')
            ->get('mfa_methods')->row();
        if (!$method) {
            return array('ok' => false, 'error' => 'MFA_NOT_STARTED');
        }
        $secret = $this->ci->encryptionservice->decrypt($method->secret);
        if (!Totp::verify($secret, $code)) {
            return array('ok' => false, 'error' => 'MFA_INVALID_CODE');
        }
        $this->ci->db->where('id', $method->id)->update('mfa_methods', array('verified' => 1));
        $this->ci->db->where('id', $user->id)->update('users', array('mfa_enabled' => 1));
        $this->audit($user->id, 'auth.mfa.enabled', 'users', $user->public_id);
        return array('ok' => true);
    }

    public function disable_mfa($user, $code = null) {
        $method = $this->ci->db->where('user_id', $user->id)->where('type', 'TOTP')
            ->get('mfa_methods')->row();
        if (!$method) {
            return array('ok' => false, 'error' => 'MFA_NOT_ENABLED');
        }
        if ($code !== null) {
            $secret = $this->ci->encryptionservice->decrypt($method->secret);
            if (!Totp::verify($secret, $code) && !is_array(Totp::consume_recovery_code($method->recovery_codes, $code))) {
                return array('ok' => false, 'error' => 'MFA_INVALID_CODE');
            }
        }
        $this->ci->db->where('id', $method->id)->delete('mfa_methods');
        $this->ci->db->where('id', $user->id)->update('users', array('mfa_enabled' => 0, 'mfa_secret' => null));
        $this->audit($user->id, 'auth.mfa.disabled', 'users', $user->public_id);
        return array('ok' => true);
    }

    /* -------------------------------------------------------------- */
    /* API keys                                                       */
    /* -------------------------------------------------------------- */

    /**
     * Mint a new reseller API key for a user. Returns the raw key once (it is
     * stored only as sha256).
     */
    public function create_api_key($user_id, $name = null, array $opts = array()) {
        $raw = 'wind_' . bin2hex(random_bytes(24));
        $public_id = windels_public_id();
        $this->ci->db->insert('api_keys', array(
            'public_id'              => $public_id,
            'user_id'                => $user_id,
            'name'                   => $name ?: 'API key',
            'key_hash'               => hash('sha256', $raw),
            'prefix'                 => substr($raw, 0, 12),
            'ip_whitelist'           => isset($opts['ip_whitelist']) ? json_encode($opts['ip_whitelist']) : null,
            'scopes'                 => isset($opts['scopes']) ? json_encode($opts['scopes']) : null,
            'rate_limit_per_minute'  => $opts['rate_limit_per_minute'] ?? 60,
            'expires_at'             => $opts['expires_at'] ?? null,
            'created_at'             => gmdate('Y-m-d H:i:s'),
        ));
        $this->audit($user_id, 'api_key.created', 'api_keys', $public_id);
        return array('public_id' => $public_id, 'raw' => $raw);
    }

    /* -------------------------------------------------------------- */
    /* Helpers                                                        */
    /* -------------------------------------------------------------- */

    public function hash_password($plain) {
        // Argon2id when the build supports it, else bcrypt. The constant is
        // only dereferenced after defined() confirms it exists — some PHP
        // builds ship without libargon2.
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash((string)$plain, PASSWORD_ARGON2ID,
                array('memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2));
        }
        return password_hash((string)$plain, PASSWORD_BCRYPT, array('cost' => 12));
    }

    private function password_fingerprint($user) {
        // A non-secret fragment of the hash that changes whenever the password
        // changes — used to bind reset tokens so they single-use.
        return substr($user->password_hash, 7, 12);
    }

    private function generate_referral_code() {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $exists = $this->ci->db->where('referral_code', $code)->count_all_results('users');
        } while ($exists > 0);
        return $code;
    }

    private function setting($key, $default = null) {
        try {
            if (!isset($this->ci->Setting_model)) {
                $this->ci->load->model('Setting_model');
            }
            return $this->ci->Setting_model->get($key, $default);
        } catch (Exception $e) {
            return $default;
        }
    }

    private function audit($actor_id, $action, $resource, $resource_id = null,
                           $before = null, $after = null, $ip = null, $user_agent = null) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id, $action, $resource, $resource_id,
                $before, $after,
                $ip ?: $this->ci->input->ip_address(),
                $user_agent ?: $this->ci->input->user_agent(),
                property_exists($this->ci, 'request_id') ? $this->ci->request_id : null
            );
        } catch (Exception $e) {
            // Audit must never break the request; log and continue.
            log_message('error', 'audit failed: ' . $e->getMessage());
        }
    }
}
