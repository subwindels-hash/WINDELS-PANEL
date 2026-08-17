<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Blacklist_model — lookups against blacklisted_emails / blacklisted_ips /
 * blacklisted_links (§61). Used by the auth flow before issuing sessions.
 */
class Blacklist_model extends MY_Model {

    public function is_email_blacklisted($email) {
        if (!$email) {
            return false;
        }
        return $this->db->where('email', strtolower(trim($email)))
            ->count_all_results('blacklisted_emails') > 0;
    }

    public function is_ip_blacklisted($ip) {
        if (!$ip) {
            return false;
        }
        return $this->db->where('ip', trim($ip))
            ->count_all_results('blacklisted_ips') > 0;
    }

    /**
     * A registration/contact payload is blocked if any of its links match a
     * blacklisted domain or pattern. Patterns are treated as case-insensitive
     * substrings (domain entries); entries wrapped in `/.../` are treated as
     * regex. Kept deliberately simple to avoid ReDoS from untrusted patterns —
     * only staff may add entries.
     */
    public function text_contains_blacklisted_link($text) {
        if (!$text) {
            return false;
        }
        $patterns = $this->db->get('blacklisted_links')->result();
        foreach ($patterns as $p) {
            $pat = $p->pattern;
            if (preg_match('#^/.*/[a-z]*$#i', $pat)) {
                if (@preg_match($pat, $text)) {
                    return true;
                }
            } elseif (stripos($text, $pat) !== false) {
                return true;
            }
        }
        return false;
    }
}
