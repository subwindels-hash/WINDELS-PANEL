<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RateLimiter — fixed-window rate limiting over the login_attempts table.
 *
 * The production stack has Redis available (and a Redis-backed limiter can slot
 * in behind the same interface), but the identity schema already records every
 * login attempt, so we derive throttling from it: more than `max` failures for
 * an IP/identifier inside `window` seconds locks further attempts.
 *
 * No CI3 assumptions beyond get_instance()->db; keeps the Auth controller thin.
 */
class RateLimiter {

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
     * Whether the given IP/identifier is currently locked out.
     *
     * @param string $ip
     * @param string $identifier
     * @param int    $max      max failed attempts within the window
     * @param int    $window   window in seconds
     * @return bool
     */
    public function too_many_failures($ip, $identifier = '', $max = 5, $window = 900) {
        $since = gmdate('Y-m-d H:i:s', time() - $window);

        // Count failures by IP, OR by identifier if one was supplied (so an
        // attacker rotating IPs against one account is still throttled).
        $this->ci->db->where('success', 0)->where('created_at >=', $since);
        $this->ci->db->group_start()
            ->where('ip', $ip);
        if ($identifier) {
            $this->ci->db->or_where('email', strtolower($identifier));
        }
        $this->ci->db->group_end();
        $count = (int)$this->ci->db->count_all_results('login_attempts');

        return $count >= $max;
    }

    /**
     * Retry-After hint in seconds (window from the latest failure).
     */
    public function retry_after($ip, $identifier = '', $window = 900) {
        $this->ci->db->select('created_at')->where('success', 0)
            ->group_start()->where('ip', $ip);
        if ($identifier) {
            $this->ci->db->or_where('email', strtolower($identifier));
        }
        $this->ci->db->group_end()->order_by('created_at', 'DESC')->limit(1);
        $row = $this->ci->db->get('login_attempts')->row();
        if (!$row) {
            return 0;
        }
        $elapsed = time() - strtotime($row->created_at);
        return max(0, $window - $elapsed);
    }
}
