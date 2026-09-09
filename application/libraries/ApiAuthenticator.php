<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ApiAuthenticator — resolves an X-Api-Key to a user/key, enforces IP
 * whitelist and expiry (Session 12). The raw key is hashed (sha256) before
 * lookup and never logged.
 */
class ApiAuthenticator {

    private $ci;
    private $last_error = null;
    private $resolved_key = null;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Api_key_model','User_model'));
    }

    /**
     * Authenticate the current request from the X-Api-Key header.
     *
     * @return object|null  api_key row on success, null on failure
     */
    public function authenticate() {
        $raw = $this->extract_key();
        if (!$raw) {
            $this->last_error = array('code' => 'MISSING_API_KEY', 'http' => 401,
                'message' => 'Provide your API key in the X-Api-Key header.');
            return null;
        }
        $key = $this->ci->Api_key_model->find_valid_by_key($raw);
        if (!$key) {
            $this->last_error = array('code' => 'INVALID_API_KEY', 'http' => 401,
                'message' => 'The API key is invalid, expired, or revoked.');
            return null;
        }
        $this->resolved_key = $key;
        // Exact-IP allowlist. Malformed stored JSON fails closed rather than
        // silently disabling a restriction that an operator intended.
        $allowed = $this->whitelist($key);
        if ($allowed === false || ($allowed !== null && !in_array($this->client_ip(), $allowed, true))) {
            $this->last_error = array('code' => 'IP_NOT_ALLOWED', 'http' => 403,
                'message' => 'Your IP address is not permitted for this key.');
            return null;
        }
        $user = $this->ci->User_model->find_by_id($key->user_id);
        if (!$user || $user->status !== 'ACTIVE') {
            $this->last_error = array('code' => 'ACCOUNT_INACTIVE', 'http' => 403,
                'message' => 'The account for this API key is not active.');
            return null;
        }
        $key->user = $user;
        $this->touch($key);
        return $key;
    }

    public function last_error() { return $this->last_error; }

    /** Key whose verifier matched, even when an IP/account policy denied it. */
    public function resolved_key() { return $this->resolved_key; }

    /**
     * Legacy NULL/blank scopes retain full access. Once a JSON scope array is
     * stored, it is an exact allow-list; malformed policy fails closed.
     */
    public function allows_scope($key, $required_scope) {
        if (!$key) return false;
        if ($key->scopes === null || $key->scopes === '') return true;
        $scopes = json_decode($key->scopes, true);
        if (!is_array($scopes) || array_values($scopes) !== $scopes) return false;
        foreach ($scopes as $scope) if (!is_string($scope)) return false;
        return in_array((string)$required_scope, $scopes, true);
    }

    private function extract_key() {
        $key = $this->ci->input->get_request_header('X-Api-Key', true);
        if (!$key) {
            // Some panels use `key` query param; support it for compatibility
            // but the header is preferred.
            $key = $this->ci->input->get('key', true);
        }
        return is_string($key) ? trim($key) : null;
    }

    private function whitelist($key) {
        if (empty($key->ip_whitelist)) return null;
        $list = json_decode($key->ip_whitelist, true);
        if (!is_array($list) || array_values($list) !== $list) return false;
        $valid = array();
        foreach ($list as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
            $packed = @inet_pton($ip);
            $canonical = $packed === false ? false : @inet_ntop($packed);
            if ($canonical === false) return false;
            $valid[] = $canonical;
        }
        return $valid;
    }

    private function client_ip() {
        $ip = $this->ci->input->ip_address();
        if ($ip === '0.0.0.0') $ip = '127.0.0.1';
        $packed = @inet_pton($ip);
        $canonical = $packed === false ? false : @inet_ntop($packed);
        return $canonical === false ? $ip : $canonical;
    }

    private function touch($key) {
        $this->ci->db->where('id', $key->id)->update('api_keys', array(
            'last_used_at' => gmdate('Y-m-d H:i:s'),
            'last_used_ip' => substr($this->client_ip(), 0, 45),
        ));
    }
}
