<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PinService — the optional four-digit transaction PIN.
 *
 * A second factor for actions that move money or change security settings,
 * deliberately separate from the account password so that a borrowed or
 * shoulder-surfed password is not enough on its own.
 *
 * ## What is guaranteed
 *
 * - **The PIN is never readable.** It is stored as a `password_hash()` digest,
 *   exactly like the password. There is no decrypt path, no "show PIN" in the
 *   admin panel, and no code here that returns it. An administrator can only
 *   *clear* it, which forces the customer to set a new one.
 * - **Brute force is bounded at rest.** Four digits is 10,000 possibilities —
 *   trivially guessable at HTTP speed. Failures are counted on the user row
 *   and the PIN locks for a growing interval, so the limit survives a new
 *   session, a new IP, or a restarted process.
 * - **Verification is constant-time**, and an unset PIN still burns a hash
 *   comparison so "no PIN configured" and "wrong PIN" take the same time.
 *
 * ## What it deliberately does not do
 *
 * It is not a replacement for authentication: every caller must already have
 * an authenticated session. It gates an action, it does not establish identity.
 */
class PinService {

    /** Wrong attempts before the PIN locks. */
    const MAX_ATTEMPTS = 5;

    /** Lock durations in seconds, indexed by how many times the cap was hit. */
    const LOCK_STEPS = array(300, 900, 3600, 86400);

    /** A hash to compare against when no PIN is set, so timing cannot leak. */
    const DUMMY_HASH = '$2y$10$usesomesillystringfoeX9L5dU0JQAr9dEQi/YuG1Sdle4uD2vG';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('User_model');
    }

    /** Whether this account has a PIN configured. */
    public function is_set($user) {
        return $user && !empty($user->pin_hash);
    }

    /**
     * Set or replace the PIN.
     *
     * Replacing an existing PIN requires the current one: without that check,
     * anyone who walks up to an unlocked session could silently swap the PIN
     * and lock the real owner out of their own funds.
     *
     * @return array{ok:bool, error?:string, code?:string}
     */
    public function set($user, $new_pin, $current_pin = null) {
        if (!$user) return $this->fail('NO_USER', 'Not signed in.');

        $error = $this->validate_format($new_pin);
        if ($error) return $error;

        if ($this->is_set($user)) {
            $check = $this->verify($user, $current_pin);
            if (empty($check['ok'])) {
                return $this->fail('CURRENT_PIN_INVALID', 'Your current PIN is incorrect.');
            }
        }

        $this->ci->db->where('id', $user->id)->update('users', array(
            'pin_hash'            => password_hash((string) $new_pin, PASSWORD_DEFAULT),
            'pin_set_at'          => gmdate('Y-m-d H:i:s'),
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ));

        $this->audit($user, 'security.pin_set');
        $this->notify($user, 'Security PIN updated',
            'Your transaction PIN was changed. If this was not you, contact support immediately.');

        return array('ok' => true);
    }

    /**
     * Check a PIN.
     *
     * @return array{ok:bool, error?:string, code?:string, retry_after?:int}
     */
    public function verify($user, $pin) {
        if (!$user) return $this->fail('NO_USER', 'Not signed in.');

        $locked = $this->locked_for($user);
        if ($locked > 0) {
            return array(
                'ok' => false,
                'code' => 'PIN_LOCKED',
                'error' => 'Too many incorrect attempts. Try again in '.$this->human_time($locked).'.',
                'retry_after' => $locked,
            );
        }

        // Always hash-compare, even with no PIN set, so both paths cost the same.
        $hash = $this->is_set($user) ? $user->pin_hash : self::DUMMY_HASH;
        $ok = password_verify((string) $pin, $hash) && $this->is_set($user);

        if (!$ok) {
            return $this->record_failure($user);
        }

        if ((int) $user->pin_failed_attempts !== 0) {
            $this->ci->db->where('id', $user->id)->update('users', array(
                'pin_failed_attempts' => 0,
                'pin_locked_until'    => null,
            ));
        }
        return array('ok' => true);
    }

    /**
     * Clear the PIN as an administrator.
     *
     * This is a reset, never a reveal: the customer sets a new PIN themselves.
     * The event is audited against the acting staff member.
     */
    public function admin_reset($user, $actor, $reason = null) {
        if (!$user) return $this->fail('NO_USER', 'Unknown user.');

        $this->ci->db->where('id', $user->id)->update('users', array(
            'pin_hash'            => null,
            'pin_set_at'          => null,
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ));

        $this->audit($user, 'security.pin_admin_reset', $actor, array('reason' => $reason));
        $this->notify($user, 'Security PIN cleared',
            'An administrator cleared your transaction PIN. Set a new one from Account → Security.');

        return array('ok' => true);
    }

    /** Clear only the lockout, leaving the PIN itself in place. */
    public function admin_unlock($user, $actor) {
        if (!$user) return $this->fail('NO_USER', 'Unknown user.');
        $this->ci->db->where('id', $user->id)->update('users', array(
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
        ));
        $this->audit($user, 'security.pin_unlocked', $actor);
        return array('ok' => true);
    }

    /** Seconds remaining on the lock, or 0 when the PIN is usable. */
    public function locked_for($user) {
        if (!$user || empty($user->pin_locked_until)) return 0;
        $until = strtotime($user->pin_locked_until.' UTC');
        $remaining = $until - time();
        return $remaining > 0 ? $remaining : 0;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Reject anything that is not exactly four digits, and refuse the handful
     * of sequences that make a PIN worthless.
     */
    private function validate_format($pin) {
        $pin = (string) $pin;
        if (!preg_match('/^\d{4}$/', $pin)) {
            return $this->fail('PIN_FORMAT', 'Your PIN must be exactly four digits.');
        }
        if (preg_match('/^(\d)\1{3}$/', $pin)) {
            return $this->fail('PIN_WEAK', 'That PIN is too easy to guess — do not repeat one digit.');
        }
        $sequences = array('0123', '1234', '2345', '3456', '4567', '5678', '6789',
                           '9876', '8765', '7654', '6543', '5432', '4321', '3210');
        if (in_array($pin, $sequences, true)) {
            return $this->fail('PIN_WEAK', 'That PIN is too easy to guess — avoid runs of consecutive digits.');
        }
        return null;
    }

    /** Count a wrong attempt and lock the PIN once the cap is reached. */
    private function record_failure($user) {
        $attempts = (int) $user->pin_failed_attempts + 1;
        $update = array('pin_failed_attempts' => $attempts);

        $remaining = self::MAX_ATTEMPTS - $attempts;
        if ($remaining <= 0) {
            // Each further cap-hit locks for longer, so a patient attacker
            // cannot simply wait out a fixed penalty 2,000 times.
            $step = min((int) floor($attempts / self::MAX_ATTEMPTS) - 1, count(self::LOCK_STEPS) - 1);
            $seconds = self::LOCK_STEPS[max($step, 0)];
            $update['pin_locked_until'] = gmdate('Y-m-d H:i:s', time() + $seconds);

            $this->ci->db->where('id', $user->id)->update('users', $update);
            $this->audit($user, 'security.pin_locked', null, array('attempts' => $attempts));
            $this->notify($user, 'Security PIN locked',
                'Your transaction PIN was locked after repeated incorrect attempts.');

            return array(
                'ok' => false,
                'code' => 'PIN_LOCKED',
                'error' => 'Too many incorrect attempts. Try again in '.$this->human_time($seconds).'.',
                'retry_after' => $seconds,
            );
        }

        $this->ci->db->where('id', $user->id)->update('users', $update);
        return array(
            'ok' => false,
            'code' => 'PIN_INVALID',
            'error' => 'Incorrect PIN. '.$remaining.' attempt'.($remaining === 1 ? '' : 's').' remaining.',
        );
    }

    private function human_time($seconds) {
        if ($seconds < 60) return $seconds.' seconds';
        if ($seconds < 3600) return ceil($seconds / 60).' minutes';
        return ceil($seconds / 3600).' hours';
    }

    private function fail($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }

    /**
     * Record a PIN event.
     *
     * The PIN and its hash are never part of $meta — an audit trail that
     * contains the secret it is auditing is worse than no audit trail.
     */
    private function audit($user, $action, $actor = null, array $meta = array()) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor ? $actor->id : $user->id,
                $action,
                'users',
                $user->public_id,
                null,
                $meta ?: null,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'pin audit failed: '.$e->getMessage());
        }
    }

    private function notify($user, $title, $body) {
        try {
            $this->ci->db->insert('notifications', array(
                'public_id'  => marvy_public_id(),
                'user_id'    => $user->id,
                'type'       => 'security.pin',
                'channel'    => 'IN_APP',
                'title'      => $title,
                'body'       => $body,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        } catch (Throwable $e) {
            log_message('error', 'pin notification failed: '.$e->getMessage());
        }
    }
}
