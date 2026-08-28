<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RateLimiter — fixed-window rate limiting over the login_attempts table.
 *
 * The production stack has Redis available (and a Redis-backed limiter can slot
 * in behind the same interface), but the identity schema already records every
 * login attempt, so we derive throttling from it.
 *
 * ## Buckets are counted separately (Session 17)
 *
 * The obvious implementation — `WHERE ip = ? OR email = ?` — is wrong in both
 * directions, and this class used to do exactly that:
 *
 *   - **Too strict.** The two arms share one counter, so failures against
 *     *other* accounts from a shared IP (office NAT, mobile CGNAT) push a
 *     blameless user over the limit. With a constant pseudo-identifier such as
 *     `'pwreset'` the email arm matches every row any user ever wrote, so a
 *     handful of requests locks the feature for the whole site.
 *   - **Too lax.** A single counter cannot express "5 per account but 15 per
 *     network", so whichever threshold you choose is wrong for the other axis.
 *
 * So the IP and the identifier get their own counters, and a lockout happens
 * when *either* trips. The identifier limit is the strict one ($max); the IP
 * limit is deliberately looser (IP_MULTIPLIER x $max) because one address can
 * legitimately carry many users, while still stopping a spray attack.
 *
 * ## Counters are also separated by feature (Session 41)
 *
 * Every throttled feature writes to this one table: sign-in, admin sign-in,
 * MFA, registration, password reset and the on-site assistant. Each namespaced
 * its *identifier* — `assistant:1.2.3.4`, `pwreset:someone@example.com` — so
 * the per-account counters stayed apart. **The per-IP counter did not.** It
 * counted every row for the address whoever wrote it, so the features shared
 * one budget:
 *
 *   sixteen *answered* questions to the help widget put the visitor's IP over
 *   the login lockout (5 x 3), and the login page then told them "Too many
 *   failed attempts. Try again in 15 minutes."
 *
 * Nothing had failed. A visitor who used the assistant locked themselves — and
 * everyone behind the same office or mobile NAT — out of signing in, and a
 * handful of password-reset requests did the same. Both counters are now
 * filtered by `scope`, derived from the identifier prefix, so a limit can only
 * ever be spent by the feature it belongs to.
 *
 * Callers that throttle something other than login must namespace their
 * identifier with scope() so the counter cannot collide with a real email
 * address.
 *
 * No CI3 assumptions beyond get_instance()->db; keeps the Auth controller thin.
 */
class RateLimiter {

    /**
     * How much more tolerant the per-IP limit is than the per-identifier one.
     * One address can legitimately be many users behind NAT.
     */
    const IP_MULTIPLIER = 3;

    /** @var object */
    private $ci;

    /**
     * Memo for "does login_attempts have the scope column" (migration 028).
     *
     * Per instance, not static: the schema cannot change inside one request,
     * but a long-lived process that outlives a migration — a test runner, a
     * worker — would otherwise cache the answer from before it ran.
     */
    private $scope_column = null;

    /**
     * The features that throttle through this table.
     *
     * `scope()` builds `<feature>:<key>` identifiers; this is the list that
     * makes a prefix mean something. Anything unrecognised is treated as a
     * sign-in, which is exactly the behaviour that existed before scoping —
     * so forgetting to add a name here is a missed improvement, never a
     * missing limit.
     */
    const SCOPES = array('login', 'admin_login', 'mfa', 'register', 'pwreset', 'assistant', 'chat');

    public function __construct() {
        $this->ci =& get_instance();
    }

    /**
     * Record an attempt.
     *
     * @param string   $identifier email or username (nullable)
     * @param string   $ip
     * @param bool     $success
     * @param string   $reason
     * @param string   $user_agent
     */
    public function record($identifier, $ip, $success, $reason = null, $user_agent = null) {
        if (!marvy_load_database()) {
            return;
        }
        $scope = self::scope_of($identifier);
        // A fresh install can have MySQL but not yet have the login_attempts
        // table (migrations not run). Failing a side-effectful rate-limit row
        // must never turn an otherwise working assistant request into a 500.
        try {
            $row = array(
                'email'      => $identifier ? strtolower($identifier) : null,
                'ip'         => $ip,
                'success'    => $success ? 1 : 0,
                'reason'     => $reason,
                'user_agent' => $user_agent ? substr($user_agent, 0, 500) : null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            );
            if ($this->has_scope_column()) $row['scope'] = $scope;
            $this->ci->db->insert('login_attempts', $row);
        } catch (Throwable $e) {
            log_message('error', 'ratelimit: record failed: '.$e->getMessage());
        }
    }

    /**
     * Namespace a non-login counter so it cannot collide with a real email.
     *
     * `scope('pwreset', $email)` gives each account its own reset budget;
     * without the scope every account would share one bucket.
     */
    public static function scope($name, $key = '') {
        return $key === '' || $key === null
            ? $name.':*'
            : $name.':'.strtolower(trim($key));
    }

    /**
     * Which feature an identifier belongs to.
     *
     * `scope()` already encodes it as a prefix; a bare email or username is a
     * sign-in. Deriving it here rather than adding a parameter means every
     * existing caller is classified correctly without being touched — and a
     * caller that forgets cannot silently land in the login budget.
     */
    public static function scope_of($identifier) {
        $identifier = strtolower((string)$identifier);
        $colon = strpos($identifier, ':');
        if ($colon === false || $colon === 0) return 'login';
        $prefix = substr($identifier, 0, $colon);
        // Matched against the known list rather than trusted: an unrecognised
        // prefix falls back to the sign-in budget, which is the conservative
        // answer (it is what every feature shared before). A new throttled
        // feature adds its name here, and its counter stops competing with
        // people trying to log in.
        return in_array($prefix, self::SCOPES, true) ? $prefix : 'login';
    }

    /**
     * Whether the given IP or identifier is currently locked out.
     *
     * The two are counted independently — see the class docblock for why.
     *
     * @param string $ip
     * @param string $identifier  email, username, or a scope() string
     * @param int    $max         max failures per identifier within the window
     * @param int    $window      window in seconds
     * @return bool
     */
    public function too_many_failures($ip, $identifier = '', $max = 5, $window = 900) {
        if (!marvy_load_database()) {
            return false;
        }
        try {
            $since = gmdate('Y-m-d H:i:s', time() - $window);
            $scope = self::scope_of($identifier);

            // Per-account: the strict limit. Checked first because it is the one
            // that actually stops a targeted attack.
            if ($identifier !== '' && $identifier !== null) {
                if ($this->count('email', strtolower($identifier), $since, $scope) >= $max) {
                    return true;
                }
            }

            // Per-network: looser, so shared addresses are not collateral
            // damage — and scoped, so one feature cannot spend another's
            // budget.
            return $this->count('ip', $ip, $since, $scope) >= ($max * self::IP_MULTIPLIER);
        } catch (Throwable $e) {
            log_message('error', 'ratelimit: check failed: '.$e->getMessage());
            return false;
        }
    }

    /**
     * Retry-After hint in seconds, measured from the newest failure in
     * whichever bucket is actually locked.
     */
    public function retry_after($ip, $identifier = '', $window = 900, $max = 5) {
        try {
            $since = gmdate('Y-m-d H:i:s', time() - $window);

            $scope  = self::scope_of($identifier);
            $newest = null;
            if ($identifier !== '' && $identifier !== null
                && $this->count('email', strtolower($identifier), $since, $scope) >= $max) {
                $newest = $this->newest('email', strtolower($identifier), $since, $scope);
            }
            if ($newest === null
                && $this->count('ip', $ip, $since, $scope) >= ($max * self::IP_MULTIPLIER)) {
                $newest = $this->newest('ip', $ip, $since, $scope);
            }
            if ($newest === null) return 0;

            return max(0, $window - (time() - strtotime($newest)));
        } catch (Throwable $e) {
            log_message('error', 'ratelimit: retry_after failed: '.$e->getMessage());
            return 0;
        }
    }

    /** Failures for one column value inside the window, within one feature. */
    private function count($column, $value, $since, $scope = 'login') {
        $this->scoped($scope);
        return (int)$this->ci->db
            ->where('success', 0)
            ->where('created_at >=', $since)
            ->where($column, $value)
            ->count_all_results('login_attempts');
    }

    /** Timestamp of the newest failure for one column value. */
    private function newest($column, $value, $since, $scope = 'login') {
        $this->scoped($scope);
        $row = $this->ci->db->select('created_at')
            ->where('success', 0)
            ->where('created_at >=', $since)
            ->where($column, $value)
            ->order_by('created_at', 'DESC')->limit(1)
            ->get('login_attempts')->row();
        return $row ? $row->created_at : null;
    }

    /**
     * Restrict the next query to one feature's rows.
     *
     * On a database that has not yet run migration 028 the column does not
     * exist, and the counters stay shared exactly as they were before — the
     * old behaviour, for the minutes between deploying the code and running
     * the migration. Emulating the split with a LIKE over the identifier was
     * tried and rejected: an unindexed scan over the busiest table in the
     * panel, to cover a window that ends when `php index.php migrate` does.
     */
    private function scoped($scope) {
        if ($this->has_scope_column()) $this->ci->db->where('scope', $scope);
    }

    /** Memoised: does this database have the scope column yet? */
    private function has_scope_column() {
        if ($this->scope_column !== null) return $this->scope_column;
        try {
            foreach ($this->ci->db->field_data('login_attempts') as $field) {
                if ($field->name === 'scope') return $this->scope_column = true;
            }
        } catch (Throwable $e) {
            return $this->scope_column = false;
        }
        return $this->scope_column = false;
    }
}
