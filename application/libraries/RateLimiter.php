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
        $this->ci->db->insert('login_attempts', array(
            'email'      => $identifier ? strtolower($identifier) : null,
            'ip'         => $ip,
            'success'    => $success ? 1 : 0,
            'reason'     => $reason,
            'user_agent' => $user_agent ? substr($user_agent, 0, 500) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
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
        $since = gmdate('Y-m-d H:i:s', time() - $window);

        // Per-account: the strict limit. Checked first because it is the one
        // that actually stops a targeted attack.
        if ($identifier !== '' && $identifier !== null) {
            if ($this->count('email', strtolower($identifier), $since) >= $max) {
                return true;
            }
        }

        // Per-network: looser, so shared addresses are not collateral damage.
        return $this->count('ip', $ip, $since) >= ($max * self::IP_MULTIPLIER);
    }

    /**
     * Retry-After hint in seconds, measured from the newest failure in
     * whichever bucket is actually locked.
     */
    public function retry_after($ip, $identifier = '', $window = 900, $max = 5) {
        $since = gmdate('Y-m-d H:i:s', time() - $window);

        $newest = null;
        if ($identifier !== '' && $identifier !== null
            && $this->count('email', strtolower($identifier), $since) >= $max) {
            $newest = $this->newest('email', strtolower($identifier), $since);
        }
        if ($newest === null && $this->count('ip', $ip, $since) >= ($max * self::IP_MULTIPLIER)) {
            $newest = $this->newest('ip', $ip, $since);
        }
        if ($newest === null) return 0;

        return max(0, $window - (time() - strtotime($newest)));
    }

    /** Failures for one column value inside the window. */
    private function count($column, $value, $since) {
        return (int)$this->ci->db
            ->where('success', 0)
            ->where('created_at >=', $since)
            ->where($column, $value)
            ->count_all_results('login_attempts');
    }

    /** Timestamp of the newest failure for one column value. */
    private function newest($column, $value, $since) {
        $row = $this->ci->db->select('created_at')
            ->where('success', 0)
            ->where('created_at >=', $since)
            ->where($column, $value)
            ->order_by('created_at', 'DESC')->limit(1)
            ->get('login_attempts')->row();
        return $row ? $row->created_at : null;
    }
}
