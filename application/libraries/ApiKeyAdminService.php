<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Administrative API-key policy mutations with immutable revocation. */
class ApiKeyAdminService {
    private $CI;
    private $policy;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->load->model(array('Api_key_model', 'Audit_log_model'));
        $this->CI->load->library('ApiKeyPolicy');
        $this->policy = $this->CI->apikeypolicy;
    }

    public function update_policy($key, array $input, $actor_id, $ip, $user_agent) {
        if (!$key) return $this->bad('NOT_FOUND', 'API key not found.');
        if (!empty($key->revoked_at)) {
            return $this->bad('IMMUTABLE', 'Revoked API keys cannot be changed or restored.');
        }

        $validation = $this->policy->validate_update($input);
        if (empty($validation['ok'])) return $this->bad('VALIDATION', $validation['error']);

        $before = $this->snapshot($key);
        $data = $validation['data'];
        $this->CI->db->where('id', (int)$key->id)
            ->where('revoked_at IS NULL', null, false)
            ->update('api_keys', $data);

        // MySQL reports zero affected rows for a no-op update, so verify the
        // persisted policy rather than treating that count alone as a race.
        $updated = $this->CI->Api_key_model->safe_admin_detail($key->public_id);
        if (!$updated || !empty($updated->revoked_at)
            || !$this->same_policy($this->snapshot($updated), $data)) {
            return $this->bad('CONFLICT', 'The key changed while it was being updated. Reload and try again.');
        }
        $this->audit((int)$actor_id, 'api_key.policy_updated', $key->public_id,
            $before, $this->snapshot($updated), $ip, $user_agent);
        return array('ok'=>true, 'key'=>$updated);
    }

    /** Revocation is idempotent and can never be reversed by this service. */
    public function revoke($key, $actor_id, $ip, $user_agent) {
        if (!$key) return $this->bad('NOT_FOUND', 'API key not found.');
        if (!empty($key->revoked_at)) return array('ok'=>true, 'already_revoked'=>true, 'key'=>$key);

        $before = $this->snapshot($key);
        $revoked_at = gmdate('Y-m-d H:i:s');
        $this->CI->db->where('id', (int)$key->id)
            ->where('revoked_at IS NULL', null, false)
            ->update('api_keys', array('revoked_at'=>$revoked_at));
        if ($this->CI->db->affected_rows() < 1) {
            $current = $this->CI->Api_key_model->safe_admin_detail($key->public_id);
            if ($current && !empty($current->revoked_at)) {
                return array('ok'=>true, 'already_revoked'=>true, 'key'=>$current);
            }
            return $this->bad('CONFLICT', 'The key changed while it was being revoked. Reload and try again.');
        }

        $updated = $this->CI->Api_key_model->safe_admin_detail($key->public_id);
        $this->audit((int)$actor_id, 'api_key.revoked', $key->public_id,
            $before, $this->snapshot($updated), $ip, $user_agent);
        return array('ok'=>true, 'already_revoked'=>false, 'key'=>$updated);
    }

    private function snapshot($key) {
        if (!$key) return null;
        return array(
            'public_id'=>(string)$key->public_id,
            'user_id'=>(int)$key->user_id,
            'name'=>(string)$key->name,
            'prefix'=>(string)$key->prefix,
            'ip_whitelist'=>$this->decoded_list($key->ip_whitelist),
            'scopes'=>$key->scopes === null ? null : $this->decoded_list($key->scopes),
            'rate_limit_per_minute'=>(int)$key->rate_limit_per_minute,
            'expires_at'=>$key->expires_at,
            'revoked_at'=>$key->revoked_at,
        );
    }

    private function decoded_list($json) {
        if ($json === null || $json === '') return array();
        $value = json_decode($json, true);
        return is_array($value) ? array_values($value) : array();
    }

    private function same_policy(array $before, array $data) {
        $after_ips = $data['ip_whitelist'] === null ? array() : $this->decoded_list($data['ip_whitelist']);
        $after_scopes = $data['scopes'] === null ? null : $this->decoded_list($data['scopes']);
        return $before['name'] === $data['name']
            && $before['ip_whitelist'] === $after_ips
            && $before['scopes'] === $after_scopes
            && $before['rate_limit_per_minute'] === (int)$data['rate_limit_per_minute']
            && $before['expires_at'] === $data['expires_at'];
    }

    private function audit($actor_id, $action, $public_id, $before, $after, $ip, $user_agent) {
        $this->CI->Audit_log_model->record($actor_id, $action, 'api_key', $public_id,
            $before, $after, $ip, $user_agent);
    }

    private function bad($code, $message) {
        return array('ok'=>false, 'code'=>$code, 'error'=>$message);
    }
}
