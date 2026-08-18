<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * API-key persistence.
 *
 * Authentication is the only read that selects the stored key hash. Browser
 * screens use the explicit safe projections below, so a later view/debug dump
 * cannot accidentally disclose the credential verifier.
 */
class Api_key_model extends MY_Model {
    protected $table = 'api_keys';

    const SAFE_KEY_COLUMNS = 'api_keys.id, api_keys.public_id, api_keys.user_id, '
        .'api_keys.name, api_keys.prefix, api_keys.last_used_at, api_keys.last_used_ip, '
        .'api_keys.ip_whitelist, api_keys.scopes, api_keys.rate_limit_per_minute, '
        .'api_keys.expires_at, api_keys.revoked_at, api_keys.created_at';

    /** Authentication-only hash lookup. The raw key is never persisted. */
    public function find_valid_by_key($raw) {
        $hash = hash('sha256', $raw);
        $row = $this->db->where('key_hash', $hash)
            ->where('revoked_at IS NULL', null, false)
            ->get($this->table)->row();
        if ($row && $row->expires_at && strtotime($row->expires_at) <= time()) return null;
        return $row;
    }

    /** Safe customer-dashboard listing. */
    public function for_user_safe($user_id, $limit = 100) {
        return $this->db->select(self::SAFE_KEY_COLUMNS)
            ->where('api_keys.user_id', (int)$user_id)
            ->order_by('api_keys.created_at', 'DESC')
            ->limit(max(1, min(100, (int)$limit)))
            ->get($this->table)->result();
    }

    /** One safely projected key owned by a customer. */
    public function safe_for_user($public_id, $user_id) {
        return $this->db->select(self::SAFE_KEY_COLUMNS)
            ->where('api_keys.public_id', (string)$public_id)
            ->where('api_keys.user_id', (int)$user_id)
            ->get($this->table)->row();
    }

    /** Bounded admin queue, joined only to non-secret customer identity. */
    public function admin_list(array $filters, $limit = 25, $offset = 0) {
        $this->admin_query($filters);
        return $this->db->select(self::SAFE_KEY_COLUMNS
                .', users.public_id AS user_public_id, users.username, users.email, users.status AS user_status')
            ->order_by('api_keys.created_at', 'DESC')
            ->limit(max(1, min(100, (int)$limit)), max(0, (int)$offset))
            ->get()->result();
    }

    public function count_admin(array $filters) {
        $this->admin_query($filters);
        return (int)$this->db->count_all_results();
    }

    /** One admin detail row; never selects key_hash or any user credential. */
    public function safe_admin_detail($public_id) {
        return $this->db->select(self::SAFE_KEY_COLUMNS
                .', users.public_id AS user_public_id, users.username, users.email, users.status AS user_status')
            ->from('api_keys')
            ->join('users', 'users.id = api_keys.user_id', 'inner')
            ->where('api_keys.public_id', (string)$public_id)
            ->get()->row();
    }

    /** Global key and recent request counts for the admin summary cards. */
    public function admin_totals() {
        $now = gmdate('Y-m-d H:i:s');
        $day = gmdate('Y-m-d H:i:s', time() - 86400);
        $total = (int)$this->db->count_all_results('api_keys');
        $revoked = (int)$this->db->where('revoked_at IS NOT NULL', null, false)
            ->count_all_results('api_keys');
        $expired = (int)$this->db->where('revoked_at IS NULL', null, false)
            ->where('expires_at IS NOT NULL', null, false)
            ->where('expires_at <=', $now)->count_all_results('api_keys');
        $requests = (int)$this->db->where('created_at >=', $day)
            ->count_all_results('api_usage_logs');
        return array(
            'total' => $total,
            'active' => max(0, $total - $revoked - $expired),
            'revoked' => $revoked,
            'expired' => $expired,
            'requests_24h' => $requests,
        );
    }

    /** Recent calls for a key. Internal key IDs never cross the controller URL. */
    public function usage_for_key($key_id, $limit = 50) {
        return $this->db->select('endpoint, method, ip, status, duration_ms, created_at')
            ->where('api_key_id', (int)$key_id)
            ->order_by('created_at', 'DESC')
            ->limit(max(1, min(100, (int)$limit)))
            ->get('api_usage_logs')->result();
    }

    public function usage_summary($key_id) {
        $day = gmdate('Y-m-d H:i:s', time() - 86400);
        $total = (int)$this->db->where('api_key_id', (int)$key_id)
            ->count_all_results('api_usage_logs');
        $successful = (int)$this->db->where('api_key_id', (int)$key_id)
            ->where('status <', 400)->count_all_results('api_usage_logs');
        $failed = (int)$this->db->where('api_key_id', (int)$key_id)
            ->where('status >=', 400)->count_all_results('api_usage_logs');
        $recent = (int)$this->db->where('api_key_id', (int)$key_id)
            ->where('created_at >=', $day)->count_all_results('api_usage_logs');
        return array('total'=>$total, 'successful'=>$successful, 'failed'=>$failed, 'requests_24h'=>$recent);
    }

    /** Per-route request totals, capped to keep a long-lived key detail bounded. */
    public function endpoint_usage($key_id, $limit = 20) {
        return $this->db->select('endpoint, method, COUNT(*) AS requests')
            ->where('api_key_id', (int)$key_id)
            ->group_by(array('endpoint', 'method'))
            ->order_by('requests', 'DESC')
            ->limit(max(1, min(50, (int)$limit)))
            ->get('api_usage_logs')->result();
    }

    private function admin_query(array $filters) {
        $this->db->from('api_keys')
            ->join('users', 'users.id = api_keys.user_id', 'inner');

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        $now = gmdate('Y-m-d H:i:s');
        if ($status === 'REVOKED') {
            $this->db->where('api_keys.revoked_at IS NOT NULL', null, false);
        } elseif ($status === 'EXPIRED') {
            $this->db->where('api_keys.revoked_at IS NULL', null, false)
                ->where('api_keys.expires_at IS NOT NULL', null, false)
                ->where('api_keys.expires_at <=', $now);
        } elseif ($status === 'ACTIVE') {
            $this->db->where('api_keys.revoked_at IS NULL', null, false)
                ->group_start()
                    ->where('api_keys.expires_at IS NULL', null, false)
                    ->or_where('api_keys.expires_at >', $now)
                ->group_end();
        }

        $owner = trim((string)($filters['user'] ?? ''));
        if ($owner !== '') $this->db->where('users.public_id', $owner);

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $this->db->group_start()
                ->like('api_keys.name', $search)
                ->or_like('api_keys.prefix', $search)
                ->or_like('users.username', $search)
                ->or_like('users.email', $search)
            ->group_end();
        }
        return $this->db;
    }
}
