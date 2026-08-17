<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ProviderSyncService — adapter factory, connection test, service sync and
 * balance sync for providers (Session 08).
 *
 * The service never logs the decrypted API key, always goes through
 * SecureHttpClient (TLS verify ON), and writes structured sync/health logs.
 */
class ProviderSyncService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Provider_model','Provider_service_model'));
        $this->ci->load->library(array('EncryptionService','SecureHttpClient'));
    }

    /**
     * Build the adapter for a stored provider row.
     * MOCK providers use the offline adapter; everything else uses StandardSmmAdapter.
     */
    public function adapter($provider) {
        $type = strtoupper($provider->api_type ?? 'STANDARD_SMM');
        if ($type === 'MOCK') {
            return new MockProviderAdapter();
        }
        return new StandardSmmAdapter($provider, $this->ci->securehttpclient);
    }

    /**
     * Live connectivity + auth check via the adapter's getBalance().
     *
     * @return array{ok:bool,balance?:string,currency?:string,latency_ms?:int,error?:string,http_code?:int}
     */
    public function test_connection($provider) {
        $start = microtime(true);
        try {
            $adapter = $this->adapter($provider);
            $res = $adapter->getBalance();
            $latency = (int)round((microtime(true) - $start) * 1000);
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }

        if (empty($res['ok'])) {
            $err = $res['error'] ?? 'Connection failed';
            $this->ci->Provider_model->record_health($provider->id, 'OFFLINE', $latency, $err);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $data = $res['data'] ?? array();
        // Standard SMM panel returns {balance, currency}; mock returns the same shape.
        $balance = isset($data['balance']) ? (string)$data['balance'] : null;
        $currency = $data['currency'] ?? ($provider->currency ?? 'USD');

        $this->ci->db->trans_start();
        $this->ci->Provider_model->record_health($provider->id, 'ONLINE', $latency, null);
        if ($balance !== null) {
            $this->ci->Provider_model->update_provider($provider->id, array(
                'balance' => number_format((float)$balance, 8, '.', ''),
                'currency' => $currency,
            ));
        }
        $this->ci->db->trans_complete();

        return array(
            'ok' => true,
            'balance' => $balance,
            'currency' => $currency,
            'latency_ms' => $latency,
        );
    }

    /**
     * Pull the provider's service list and upsert into provider_services.
     *
     * @return array{ok:bool,inserted?:int,updated?:int,total?:int,error?:string,latency_ms?:int}
     */
    public function sync_services($provider) {
        $start = microtime(true);
        try {
            $res = $this->adapter($provider)->getServices();
        } catch (Exception $e) {
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $e->getMessage(), 0, $latency);
            return array('ok' => false, 'error' => $e->getMessage(), 'latency_ms' => $latency);
        }

        if (empty($res['ok']) || !is_array($res['data'] ?? null)) {
            $err = $res['error'] ?? 'Malformed response from provider';
            $latency = (int)round((microtime(true) - $start) * 1000);
            $this->ci->Provider_model->record_sync($provider->id, 'services', 'FAILED', $err, 0, $latency);
            return array('ok' => false, 'error' => $err, 'latency_ms' => $latency);
        }

        $inserted = 0; $updated = 0;
        $this->ci->db->trans_start();
        foreach ($res['data'] as $row) {
            $normalized = $this->normalize_service($row);
            if (!$normalized) continue;
            $outcome = $this->ci->Provider_service_model->upsert_service($provider->id, $normalized);
            if ($outcome === 'inserted') $inserted++;
            elseif ($outcome === 'updated') $updated++;
        }
        $this->ci->Provider_model->record_sync(
            $provider->id, 'services', 'SUCCESS', null,
            $inserted + $updated,
            (int)round((microtime(true) - $start) * 1000)
        );
        $this->ci->db->trans_complete();

        return array(
            'ok' => true,
            'inserted' => $inserted,
            'updated' => $updated,
            'total' => $inserted + $updated,
            'latency_ms' => (int)round((microtime(true) - $start) * 1000),
        );
    }

    /**
     * Pull the current provider balance and update the row.
     */
    public function sync_balance($provider) {
        $res = $this->test_connection($provider);
        if (!$res['ok']) return $res;
        return array(
            'ok' => true,
            'balance' => $res['balance'],
            'currency' => $res['currency'],
            'latency_ms' => $res['latency_ms'],
        );
    }

    /**
     * Normalize a provider service row into our canonical shape.
     * Tolerates the common SMM panel fields (service/service/ID, name, rate,
     * min/max, type, category) and ignores unknown columns.
     */
    public function normalize_service($row) {
        if (!is_array($row)) return null;
        // Panels differ in both key spelling and casing ('ID', 'Service',
        // 'minimum'), so match case-insensitively across the known aliases.
        $lower = array();
        foreach ($row as $k => $v) $lower[strtolower((string)$k)] = $v;
        $pick = function(array $keys) use ($lower) {
            foreach ($keys as $k) {
                if (isset($lower[$k]) && $lower[$k] !== '') return $lower[$k];
            }
            return null;
        };

        $id   = $pick(array('service', 'service_id', 'serviceid', 'id'));
        $name = $pick(array('name', 'title', 'service_name'));
        $rate = $pick(array('rate', 'cost', 'price', 'rate_per_1000'));
        if ($id === null || $name === null || $rate === null) return null;
        if (!is_numeric($rate) || $rate < 0) return null;

        return array(
            'provider_service_id' => (string)$id,
            'name'               => mb_substr((string)$name, 0, 255),
            'category'           => $pick(array('category', 'category_name')),
            'rate'               => number_format((float)$rate, 8, '.', ''),
            'min_quantity'       => (int)($pick(array('min', 'minimum', 'min_order')) ?? 1),
            'max_quantity'       => (int)($pick(array('max', 'maximum', 'max_order')) ?? 0),
            'service_type'       => $this->map_type($pick(array('type', 'service_type')) ?? 'DEFAULT'),
            'cancel'             => $this->flag($row, 'cancel'),
            'refill'             => $this->flag($row, 'refill'),
            'dripfeed'           => $this->flag($row, 'dripfeed'),
            'raw'                => $row,
        );
    }

    private function map_type($raw) {
        $t = strtoupper(str_replace(array('-', ' '), '_', (string)$raw));
        $allowed = array('DEFAULT','CUSTOM_COMMENTS','CUSTOM','PACKAGE','SUBSCRIPTION',
                         'MENTIONS_USER_FOLLOWERS','MENTIONS_HASHTAG','MENTIONS',
                         'COMMENT_LIKES','POLL_VOTES');
        // Common provider spellings that mean one of the canonical types.
        $aliases = array(
            'SUBSCRIPTIONS'          => 'SUBSCRIPTION',
            'DRIP_FEED'              => 'DEFAULT',
            'COMMENTS'               => 'CUSTOM_COMMENTS',
            'CUSTOM_COMMENT'         => 'CUSTOM_COMMENTS',
            'CUSTOM_COMMENTS_PACKAGE'=> 'CUSTOM_COMMENTS',
            'MENTIONS_CUSTOM_LIST'   => 'MENTIONS',
            'MENTIONS_USER_FOLLOWER' => 'MENTIONS_USER_FOLLOWERS',
            'MENTIONS_HASHTAGS'      => 'MENTIONS_HASHTAG',
            'POLL_VOTE'              => 'POLL_VOTES',
            'COMMENT_LIKE'           => 'COMMENT_LIKES',
            'PACKAGES'               => 'PACKAGE',
        );
        if (isset($aliases[$t])) $t = $aliases[$t];
        return in_array($t, $allowed, true) ? $t : 'DEFAULT';
    }

    private function flag($row, $key) {
        foreach (array($key, $key.'_supported') as $k) {
            if (isset($row[$k])) {
                $v = $row[$k];
                if (is_bool($v)) return $v;
                return in_array(strtolower((string)$v), array('1','true','yes','on'), true);
            }
        }
        return false;
    }

    /**
     * Create a provider from form input, encrypting the API key at rest.
     *
     * @return array{ok:bool,provider?:object,error?:string,errors?:array}
     */
    public function create_provider(array $input) {
        $errors = $this->validate($input);
        if ($errors) return array('ok' => false, 'errors' => $errors);

        $data = array(
            'public_id'         => windels_public_id(),
            'name'              => trim($input['name']),
            'api_url'           => rtrim(trim($input['api_url']), '/'),
            'api_key_encrypted' => $this->ci->encryptionservice->encrypt($input['api_key']),
            'api_type'          => strtoupper($input['api_type'] ?? 'STANDARD_SMM'),
            'status'            => $input['status'] ?? 'ACTIVE',
            'currency'          => $input['currency'] ?? 'USD',
            'timeout_ms'        => (int)($input['timeout_ms'] ?? 15000),
            'sync_interval_minutes' => max(1, (int)($input['sync_interval_minutes'] ?? 60)),
            'rate_multiplier'   => number_format((float)($input['rate_multiplier'] ?? 1.0), 8, '.', ''),
            'markup'            => number_format((float)($input['markup'] ?? 0.0), 8, '.', ''),
            'notes'             => $input['notes'] ?? null,
            'created_at'        => gmdate('Y-m-d H:i:s'),
        );
        $provider = $this->ci->Provider_model->create($data);
        $this->ci->load->model('Audit_log_model');
        $this->ci->Audit_log_model->record($this->ci->auth ? $this->ci->auth->id() : null,
            'provider.create', 'providers', $provider->public_id,
            null, array('name'=>$provider->name,'api_url'=>$provider->api_url),
            $this->ci->input->ip_address(), $this->ci->input->user_agent(),
            property_exists($this->ci,'request_id') ? $this->ci->request_id : null);
        return array('ok' => true, 'provider' => $provider);
    }

    private function validate(array $input) {
        $errors = array();
        if (empty($input['name']) || mb_strlen($input['name']) < 2) $errors[] = 'Name is required.';
        if (empty($input['api_url']) || !filter_var($input['api_url'], FILTER_VALIDATE_URL)) $errors[] = 'A valid API URL is required.';
        if (empty($input['api_key'])) $errors[] = 'API key is required.';
        if (isset($input['api_type']) && !in_array(strtoupper($input['api_type']), array('STANDARD_SMM','MOCK'), true)) {
            $errors[] = 'API type must be STANDARD_SMM or MOCK.';
        }
        if (isset($input['timeout_ms']) && ((int)$input['timeout_ms'] < 1000 || (int)$input['timeout_ms'] > 60000)) {
            $errors[] = 'Timeout must be between 1000 and 60000 ms.';
        }
        if (isset($input['rate_multiplier']) && $input['rate_multiplier'] !== '' && (float)$input['rate_multiplier'] <= 0) {
            $errors[] = 'Rate multiplier must be greater than zero.';
        }
        if (isset($input['markup']) && (float)$input['markup'] < 0) {
            $errors[] = 'Markup cannot be negative.';
        }
        return $errors;
    }
}
