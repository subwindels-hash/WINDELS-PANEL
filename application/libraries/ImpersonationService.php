<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * A short-lived, read-only support session inside a customer's dashboard.
 *
 * Impersonation is intentionally not a second login mechanism. The original
 * staff identity stays in server-side session state, every page view is
 * attributed to that staff member, and all non-dashboard / non-read requests
 * are rejected by MY_Controller. This lets support reproduce what a customer
 * sees without gaining a path that can spend their wallet or alter their data.
 */
class ImpersonationService {

    const SESSION_KEY = 'customer_impersonation';
    const TTL = 1800; // 30 minutes, hard expiry (not sliding).
    const MIN_REASON_LENGTH = 5;
    const MAX_REASON_LENGTH = 500;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('User_model', 'Permission_model', 'Audit_log_model'));
    }

    /**
     * Start a support session. The service repeats the controller's permission
     * check so a future caller cannot turn this into an unguarded login switch.
     */
    public function start($actor, $target, $reason, $confirmed = false,
                          $ip = null, $user_agent = null, $request_id = null) {
        if ($this->has_context()) {
            return $this->fail('NESTED', 'End the current impersonation session first.');
        }
        if (!$confirmed) {
            return $this->fail('CONFIRMATION_REQUIRED', 'Confirm that this read-only access is required.');
        }

        $actor_id = is_object($actor) && isset($actor->id) ? (int)$actor->id : 0;
        $target_id = is_object($target) && isset($target->id) ? (int)$target->id : 0;
        $actor = $actor_id ? $this->ci->User_model->find_by_id($actor_id) : null;
        $target = $target_id ? $this->ci->User_model->find_by_id($target_id) : null;

        if (!$actor || !$this->is_active_staff($actor)) {
            return $this->fail('ACTOR_FORBIDDEN', 'Only active staff may impersonate a customer.');
        }
        if ((int)$this->ci->session->userdata('user_id') !== (int)$actor->id) {
            return $this->fail('SESSION_MISMATCH', 'The authenticated staff session could not be verified.');
        }
        if (!$this->can_impersonate($actor)) {
            return $this->fail('PERMISSION_DENIED', 'You do not have permission to impersonate customers.');
        }
        if (!$target || (int)$target->id === (int)$actor->id) {
            return $this->fail('BAD_TARGET', 'Choose a different customer account.');
        }
        if ($target->role !== 'CUSTOMER') {
            return $this->fail('BAD_TARGET', 'Staff and administrator accounts cannot be impersonated.');
        }
        if ($target->status !== 'ACTIVE') {
            return $this->fail('TARGET_INACTIVE', 'Only active customer accounts can be impersonated.');
        }

        $reason = trim((string)$reason);
        $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($length < self::MIN_REASON_LENGTH || $length > self::MAX_REASON_LENGTH) {
            return $this->fail('BAD_REASON', 'Give a support reason between 5 and 500 characters.');
        }

        $started = time();
        $original_login_at = filter_var($this->ci->session->userdata('login_at'),
            FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($original_login_at === false || $original_login_at > $started + 60) {
            return $this->fail('SESSION_MISMATCH', 'The authenticated staff login timestamp could not be verified.');
        }
        $context = array(
            'version'          => 1,
            'id'               => bin2hex(random_bytes(16)),
            'actor_id'         => (int)$actor->id,
            'target_id'        => (int)$target->id,
            'target_public_id' => (string)$target->public_id,
            'reason'           => $reason,
            'started_at'       => $started,
            'expires_at'       => $started + self::TTL,
            'original_login_at'=> $original_login_at,
        );

        // Starting without evidence would recreate the exact accountability
        // problem this feature exists to solve, so the start is fail-closed.
        try {
            $logged = $this->ci->Audit_log_model->record(
                (int)$actor->id,
                'user.impersonation.started',
                'users',
                (string)$target->public_id,
                null,
                $this->audit_context($context, array('mode' => 'READ_ONLY')),
                $ip,
                $user_agent,
                $request_id
            );
        } catch (Throwable $e) {
            log_message('error', 'Impersonation start audit failed: '.$e->getMessage());
            $logged = false;
        }
        if (!$logged) {
            return $this->fail('AUDIT_UNAVAILABLE', 'Impersonation cannot start because its audit record could not be written.');
        }

        // Rotate and isolate data at both privilege-boundary crossings. A
        // dashboard GET may legitimately create a form token; carrying that
        // customer-scoped key back into the staff session would blend two
        // identities even though no domain write occurred. We do not call
        // User_model::touch_login(): this is not a customer login and must not
        // overwrite the customer's own last-login evidence.
        $this->clear_session_data();
        $this->ci->session->sess_regenerate(true);
        $this->ci->session->set_userdata(array(
            self::SESSION_KEY => $context,
            'user_id'         => (int)$target->id,
            'role'            => 'CUSTOMER',
            'login_at'        => $started,
        ));

        return array('ok' => true, 'context' => $context, 'actor' => $actor, 'target' => $target);
    }

    /**
     * Validate the original actor, target, permission, identity binding and
     * hard expiry on every authenticated request. Invalid state is ended
     * immediately; a mismatched target destroys the session rather than
     * trusting potentially corrupted context.
     */
    public function enforce($ip = null, $user_agent = null, $request_id = null) {
        $stored = $this->ci->session->userdata(self::SESSION_KEY);
        if ($stored === null) return array('ok' => true, 'active' => false);
        if (!is_array($stored)) {
            $this->ci->session->sess_destroy();
            return array('ok' => false, 'active' => false, 'ended' => true,
                'actor_restored' => false, 'reason' => 'INVALID_CONTEXT');
        }
        $context = $stored;

        if (!$this->context_shape_is_valid($context)) {
            $this->ci->session->sess_destroy();
            return array('ok' => false, 'active' => false, 'ended' => true,
                'actor_restored' => false, 'reason' => 'INVALID_CONTEXT');
        }

        $actor = $this->ci->User_model->find_by_id((int)$context['actor_id']);
        $target = $this->ci->User_model->find_by_id((int)$context['target_id']);
        $current_id = (int)$this->ci->session->userdata('user_id');

        // The public identifier is duplicated deliberately so audit evidence
        // remains useful if the target row is later removed. While the row
        // exists, disagreement between the two identifiers means the context
        // was corrupted; never restore an actor based on partially trusted
        // identity state.
        if ($target && !hash_equals((string)$target->public_id, (string)$context['target_public_id'])) {
            $this->ci->session->sess_destroy();
            return array('ok' => false, 'active' => false, 'ended' => true,
                'actor_restored' => false, 'reason' => 'INVALID_CONTEXT');
        }

        if ($current_id !== (int)$context['target_id']) {
            $this->audit_end($context, $actor, $target, 'SESSION_MISMATCH',
                $ip, $user_agent, $request_id);
            $this->ci->session->sess_destroy();
            return array('ok' => false, 'active' => false, 'ended' => true,
                'actor_restored' => false, 'reason' => 'SESSION_MISMATCH');
        }

        $reason = null;
        if ((int)$context['expires_at'] <= time()) {
            $reason = 'EXPIRED';
        } elseif (!$actor || !$this->is_active_staff($actor)) {
            $reason = 'ACTOR_INACTIVE';
        } elseif (!$this->can_impersonate($actor)) {
            $reason = 'PERMISSION_REVOKED';
        } elseif (!$target || $target->role !== 'CUSTOMER' || $target->status !== 'ACTIVE') {
            $reason = 'TARGET_INACTIVE';
        }

        if ($reason !== null) {
            $ended = $this->end($reason, $ip, $user_agent, $request_id);
            return array_merge(array('ok' => false, 'active' => false, 'ended' => true,
                'reason' => $reason), $ended);
        }

        return array(
            'ok'      => true,
            'active'  => true,
            'context' => $context,
            'actor'   => $actor,
            'target'  => $target,
        );
    }

    /** End and restore the original staff identity when it is still safe. */
    public function end($reason = 'MANUAL', $ip = null, $user_agent = null, $request_id = null) {
        $context = $this->raw_context();
        if (!$context || !$this->context_shape_is_valid($context)) {
            $this->ci->session->sess_destroy();
            return array('ok' => false, 'actor_restored' => false, 'reason' => 'NO_SESSION');
        }

        $actor = $this->ci->User_model->find_by_id((int)$context['actor_id']);
        $target = $this->ci->User_model->find_by_id((int)$context['target_id']);
        $this->audit_end($context, $actor, $target, strtoupper((string)$reason),
            $ip, $user_agent, $request_id);

        $this->clear_session_data();
        $this->ci->session->sess_regenerate(true);

        if ($actor && $this->is_active_staff($actor)) {
            $this->ci->session->set_userdata(array(
                'user_id'  => (int)$actor->id,
                'role'     => (string)$actor->role,
                'login_at' => !empty($context['original_login_at'])
                    ? (int)$context['original_login_at'] : time(),
            ));
            return array('ok' => true, 'actor_restored' => true,
                'actor' => $actor, 'target' => $target);
        }

        $this->ci->session->sess_destroy();
        return array('ok' => true, 'actor_restored' => false, 'target' => $target);
    }

    /** Append one immutable row for each customer-dashboard page viewed. */
    public function record_access(array $state, $method, $path,
                                  $ip = null, $user_agent = null, $request_id = null) {
        if (empty($state['active']) || empty($state['context'])
            || empty($state['actor']) || empty($state['target'])) {
            return false;
        }
        $method = strtoupper((string)$method);
        $path = '/'.ltrim((string)$path, '/');
        if (strlen($path) > 500) $path = substr($path, 0, 500);

        try {
            return (bool)$this->ci->Audit_log_model->record(
                (int)$state['actor']->id,
                'user.impersonation.viewed',
                'users',
                (string)$state['target']->public_id,
                null,
                $this->audit_context($state['context'], array(
                    'method' => substr($method, 0, 8),
                    'path'   => $path,
                )),
                $ip,
                $user_agent,
                $request_id
            );
        } catch (Throwable $e) {
            log_message('error', 'Impersonation view audit failed: '.$e->getMessage());
            return false;
        }
    }

    public function has_context() {
        return $this->ci->session->userdata(self::SESSION_KEY) !== null;
    }

    public function raw_context() {
        $context = $this->ci->session->userdata(self::SESSION_KEY);
        return is_array($context) ? $context : null;
    }

    private function context_shape_is_valid(array $context) {
        foreach (array('version', 'id', 'actor_id', 'target_id', 'target_public_id',
                       'reason', 'started_at', 'expires_at', 'original_login_at') as $key) {
            if (!array_key_exists($key, $context) || is_array($context[$key]) || is_object($context[$key])) {
                return false;
            }
        }
        $version = filter_var($context['version'], FILTER_VALIDATE_INT);
        $actor_id = filter_var($context['actor_id'], FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1)));
        $target_id = filter_var($context['target_id'], FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1)));
        $started_at = filter_var($context['started_at'], FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1)));
        $expires_at = filter_var($context['expires_at'], FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1)));
        $original_login_at = filter_var($context['original_login_at'], FILTER_VALIDATE_INT,
            array('options' => array('min_range' => 1)));
        $reason_length = function_exists('mb_strlen')
            ? mb_strlen((string)$context['reason']) : strlen((string)$context['reason']);

        return $version === 1
            && preg_match('/^[a-f0-9]{32}$/', (string)$context['id']) === 1
            && $actor_id !== false && $target_id !== false && $actor_id !== $target_id
            && preg_match('/^[A-Za-z0-9_-]{3,64}$/', (string)$context['target_public_id']) === 1
            && $reason_length >= self::MIN_REASON_LENGTH && $reason_length <= self::MAX_REASON_LENGTH
            && $started_at !== false && $expires_at !== false
            && $expires_at === $started_at + self::TTL
            && $started_at <= time() + 60
            && $expires_at <= time() + self::TTL + 60
            && $original_login_at !== false
            && $original_login_at <= $started_at + 60;
    }

    private function is_active_staff($user) {
        return $user && $user->status === 'ACTIVE'
            && in_array($user->role, array('SUPER_ADMIN', 'ADMIN', 'STAFF'), true);
    }

    private function can_impersonate($actor) {
        return $actor->role === 'SUPER_ADMIN'
            || $this->ci->Permission_model->role_has($actor->role, 'users.impersonate');
    }

    private function audit_end(array $context, $actor, $target, $reason,
                               $ip = null, $user_agent = null, $request_id = null) {
        if (!$actor || empty($context['target_public_id'])) return false;
        try {
            return $this->ci->Audit_log_model->record(
                (int)$actor->id,
                'user.impersonation.ended',
                'users',
                (string)$context['target_public_id'],
                null,
                $this->audit_context($context, array(
                    'end_reason' => substr((string)$reason, 0, 64),
                    'ended_at'   => gmdate('Y-m-d H:i:s'),
                )),
                $ip,
                $user_agent,
                $request_id
            );
        } catch (Throwable $e) {
            // Identity restoration is safer than stranding the browser in the
            // customer account. Start and every view are fail-closed; ending
            // is therefore best-effort after at least one immutable record.
            log_message('error', 'Impersonation end audit failed: '.$e->getMessage());
            return false;
        }
    }

    private function audit_context(array $context, array $extra = array()) {
        return array_merge(array(
            'impersonation_id' => (string)$context['id'],
            'target_user_id'   => (int)$context['target_id'],
            'reason'           => (string)($context['reason'] ?? ''),
            'started_at'       => gmdate('Y-m-d H:i:s', (int)$context['started_at']),
            'expires_at'       => gmdate('Y-m-d H:i:s', (int)$context['expires_at']),
        ), $extra);
    }

    private function clear_session_data() {
        $data = $this->ci->session->userdata();
        if (!is_array($data)) return;
        foreach (array_keys($data) as $key) {
            $this->ci->session->unset_userdata($key);
        }
    }

    private function fail($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
