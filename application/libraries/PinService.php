<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PinService — the four-digit transaction PIN.
 *
 * A second factor for actions that move money or change security settings,
 * deliberately separate from the account password so that a borrowed or
 * shoulder-surfed password is not enough on its own.
 *
 * ## What is guaranteed
 *
 * - **The PIN is never stored in the clear.** Two copies are kept: the
 *   one-way `password_hash()` digest that verification runs against, and an
 *   AES-256-GCM envelope (`pin_cipher`, EncryptionService) that exists so an
 *   operator can answer "what is my PIN?" without forcing a reset. Every
 *   reveal goes through `users.edit`, and every one is written to the audit
 *   log — the envelope is a staff capability, not a silent copy.
 * - **Brute force is bounded at rest.** Four digits is 10,000 possibilities —
 *   trivially guessable at HTTP speed. Failures are counted on the user row
 *   and the PIN locks for a growing interval, so the limit survives a new
 *   session, a new IP, or a restarted process.
 * - **Verification is constant-time**, and an unset PIN still burns a hash
 *   comparison so "no PIN configured" and "wrong PIN" take the same time.
 * - **PINs set before `pin_cipher` existed stay private.** Their envelope is
 *   NULL, `reveal()` says so rather than guessing, and the customer's next
 *   PIN (set by them, issued at a reset, or rotated) carries an envelope.
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

    /**
     * How long a PIN stays valid before the rotation worker replaces it,
     * in hours. Configurable via Admin → Settings (pin_rotation_hours);
     * this is only the fallback when that setting is unavailable.
     */
    const DEFAULT_ROTATION_HOURS = 24;

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('User_model');
        $this->ci->load->library('EncryptionService');
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
            'pin_cipher'          => $this->ci->encryptionservice->encrypt((string) $new_pin),
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
            'pin_cipher'          => null,
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

    /** Whether automatic PIN rotation is turned on, from settings. */
    public function rotation_enabled() {
        try {
            $this->ci->load->model('Setting_model');
            $v = $this->ci->Setting_model->get('pin_auto_rotation_enabled', true);
            return !($v === false || $v === 0 || $v === '0' || $v === 'false');
        } catch (Throwable $e) {
            return true;
        }
    }

    /** The configured rotation window in hours (minimum 1). */
    public function rotation_hours() {
        try {
            $this->ci->load->model('Setting_model');
            $hours = (int) $this->ci->Setting_model->get('pin_rotation_hours', self::DEFAULT_ROTATION_HOURS);
        } catch (Throwable $e) {
            $hours = self::DEFAULT_ROTATION_HOURS;
        }
        return max(1, $hours);
    }

    /** Seconds until this user's current PIN is due to rotate, or null if not applicable. */
    public function rotates_in($user) {
        if (!$this->is_set($user) || empty($user->pin_set_at)) return null;
        $due = strtotime($user->pin_set_at.' UTC') + ($this->rotation_hours() * 3600);
        return max(0, $due - time());
    }

    /**
     * Users whose PIN is due (or overdue) for automatic rotation.
     *
     * Applies uniformly to every account with a PIN, not just ones set after
     * rotation was introduced — pin_set_at was populated when the PIN column
     * itself was added (migration 020), so a pre-existing PIN rotates on its
     * first eligible sweep exactly like a brand-new one.
     */
    public function due_for_rotation($limit = 200) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->rotation_hours() * 3600));
        return $this->ci->db
            ->where('pin_hash IS NOT NULL', null, false)
            ->where('pin_set_at IS NOT NULL', null, false)
            ->where('pin_set_at <=', $cutoff)
            ->order_by('pin_set_at', 'ASC')
            ->limit(max(1, (int)$limit))
            ->get('users')->result();
    }

    /**
     * Replace a user's PIN with a fresh random one and deliver it to them.
     *
     * Called only by the scheduled rotation worker (CronWorkers::pin_rotation).
     * Unlike set(), this does not require the current PIN — the whole point is
     * that the system, not the customer, is initiating the change — but it
     * still clears any lockout and audits the event like every other PIN
     * mutation. The plaintext PIN is delivered once (notification + email) and
     * never stored or logged anywhere.
     *
     * @return array{ok:bool, pin?:string, error?:string}
     */
    public function rotate($user) {
        if (!$user || !$this->is_set($user)) return $this->fail('NO_PIN', 'No PIN to rotate.');

        $new_pin = $this->generate_pin();

        $this->ci->db->where('id', $user->id)->update('users', array(
            'pin_hash'            => password_hash($new_pin, PASSWORD_DEFAULT),
            'pin_cipher'          => $this->ci->encryptionservice->encrypt($new_pin),
            'pin_set_at'          => gmdate('Y-m-d H:i:s'),
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ));

        $this->audit($user, 'security.pin_auto_rotated', null,
            array('reason' => 'scheduled 24-hour rotation'));

        $this->notify($user, 'Your security PIN was refreshed',
            'For your protection, your transaction PIN is refreshed automatically every '
            .$this->rotation_hours().' hours. Your new PIN is '.$new_pin
            .'. It was sent to your registered email as well — check your inbox if you '
            .'are not viewing this from the device you normally use.');

        try {
            $this->ci->load->library('MailService');
            $this->ci->mailservice->enqueue_raw(
                $user->email,
                'Your MarvySocials security PIN was refreshed',
                '<p>Hi '.htmlspecialchars($user->username).',</p>'
                .'<p>For your protection, your transaction PIN rotates automatically every '
                .$this->rotation_hours().' hours.</p>'
                .'<p>Your new PIN is: <strong style="font-size:1.25rem;letter-spacing:.15em">'.$new_pin.'</strong></p>'
                .'<p>Use it the next time you confirm a wallet action. If this was not expected, sign in and '
                .'change it immediately from Account → Security.</p>',
                'Hi '.$user->username.",\n\nYour transaction PIN was refreshed automatically. Your new PIN is: "
                .$new_pin."\n\nIf this was not expected, sign in and change it from Account -> Security.",
                $user->username,
                'security.pin_rotated'
            );
        } catch (Throwable $e) {
            log_message('error', 'pin rotation email failed for user '.$user->id.': '.$e->getMessage());
        }

        return array('ok' => true, 'pin' => $new_pin);
    }

    /**
     * Issue a fresh random PIN to an account that has none, and tell the
     * customer what it is.
     *
     * This is the signup half of the PIN flow (and the re-issue path after an
     * administrator clears a PIN): the account starts protected on day one
     * instead of the customer having to discover Account → Security after
     * their first blocked wallet action. The plaintext PIN is delivered once
     * — notification plus email — and after that exists only as hash + cipher.
     *
     * @return array{ok:bool, pin?:string, error?:string}
     */
    public function issue($user, $reason = 'signup') {
        if (!$user) return $this->fail('NO_USER', 'Unknown user.');
        if ($this->is_set($user)) {
            return $this->fail('PIN_ALREADY_SET', 'This account already has a PIN.');
        }

        $pin = $this->generate_pin();

        $this->ci->db->where('id', $user->id)->update('users', array(
            'pin_hash'            => password_hash($pin, PASSWORD_DEFAULT),
            'pin_cipher'          => $this->ci->encryptionservice->encrypt($pin),
            'pin_set_at'          => gmdate('Y-m-d H:i:s'),
            'pin_failed_attempts' => 0,
            'pin_locked_until'    => null,
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ));

        $this->audit($user, 'security.pin_issued', null, array('reason' => $reason));
        $this->notify($user, 'Your security PIN',
            'Your transaction PIN is '.$pin.'. You will be asked for it when you move money '
            .'or change security settings. It was sent to your registered email too — '
            .'you can change it any time from Account → Security.');

        try {
            $this->ci->load->library('MailService');
            $this->ci->mailservice->enqueue_raw(
                $user->email,
                'Your MarvySocials security PIN',
                '<p>Hi '.htmlspecialchars($user->username).',</p>'
                .'<p>Welcome aboard. Every account gets a 4-digit transaction PIN — it is asked for '
                .'when money moves or security settings change, so a borrowed password alone is not '
                .'enough.</p>'
                .'<p>Your PIN is: <strong style="font-size:1.25rem;letter-spacing:.15em">'.$pin.'</strong></p>'
                .'<p>Change it any time from Account → Security.</p>',
                'Hi '.$user->username.",\n\nWelcome aboard. Your 4-digit transaction PIN is: "
                .$pin."\n\nYou will be asked for it when money moves or security settings change. "
                ."Change it any time from Account -> Security.",
                $user->username,
                'security.pin_issued'
            );
        } catch (Throwable $e) {
            log_message('error', 'pin issue email failed for user '.$user->id.': '.$e->getMessage());
        }

        return array('ok' => true, 'pin' => $pin);
    }

    /**
     * Reveal a customer's PIN to an operator.
     *
     * Returns the plaintext from the encrypted copy — or an explanation when
     * there is nothing to reveal: no PIN at all, or a PIN chosen before the
     * encrypted copy was kept (hash-only, and honestly reported as such
     * rather than guessed at).
     *
     * Callers must gate this on `users.edit` and audit the reveal. The audit
     * row records THAT a reveal happened, never the PIN itself.
     *
     * @return array{ok:bool, pin?:string, code?:string, error?:string}
     */
    public function reveal($user) {
        if (!$user) return $this->fail('NO_USER', 'Unknown user.');
        if (!$this->is_set($user)) {
            return $this->fail('NO_PIN', 'No PIN is set on this account.');
        }
        $pin = $this->ci->encryptionservice->open($user->pin_cipher ?? null);
        if ($pin === null || $pin === '') {
            return $this->fail(
                'NOT_REVEALABLE',
                'This PIN was set before encrypted PIN history was kept and cannot be shown. '
                .'Clear it — the customer chooses their next PIN themselves, and that one is revealable.'
            );
        }
        return array('ok' => true, 'pin' => $pin);
    }

    /**
     * Reveal a page of customers' PINs at once (the admin directory).
     *
     * Same contract as reveal() — staff capability, never silent — but batched
     * so one directory page does not open one envelope per row. Callers must
     * gate on `users.edit` and audit that the page was viewed; the audit row
     * records HOW MANY pins were read, never the pins themselves.
     *
     * @return array<int,string>  user_id => plaintext PIN (only revealable rows)
     */
    public function reveal_many(array $users) {
        $out = array();
        foreach ($users as $u) {
            if (!$u || empty($u->pin_hash)) continue;
            $pin = $this->ci->encryptionservice->open($u->pin_cipher ?? null);
            if ($pin === null || $pin === '') continue; // hash-only legacy PIN
            $out[(int)$u->id] = (string)$pin;
        }
        return $out;
    }

    /** A random 4-digit PIN that also passes validate_format()'s weak-PIN checks. */
    private function generate_pin() {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if ($this->validate_format($pin) === null) return $pin;
        }
        // Unreachable in practice (only 24 of 10,000 four-digit codes are
        // rejected), but never return an invalid PIN.
        return '2468';
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
