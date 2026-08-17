<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/VtuProviderInterface.php';

/**
 * StandardVtuAdapter — HTTP adapter for the common Nigerian VTU API shape
 * (VTpass-style: POST with a request_id, `code` 000 for success).
 *
 * Endpoint paths and field names vary between vendors, so they come from the
 * provider row's `retry_policy` JSON blob under a `vtu` key rather than being
 * hardcoded. A provider whose API differs structurally gets its own adapter
 * class registered in Provider_manager; this one covers the common case.
 *
 * All traffic goes through SecureHttpClient, which enforces the SSRF and
 * protocol restrictions from Session 17. The API key is decrypted per call and
 * never logged.
 */
class StandardVtuAdapter implements VtuProviderInterface {

    /** Provider result codes that mean "accepted, still settling". */
    private static $pending_codes = array('099', 'pending', 'PENDING', 'processing');

    private $provider;
    private $http;
    private $paths;

    public function __construct($provider_row, $http = null) {
        $this->provider = $provider_row;
        $timeout = isset($provider_row->timeout_ms) ? $provider_row->timeout_ms / 1000 : 15;
        $this->http = $http ?: new SecureHttpClient(array('timeout' => $timeout));

        $cfg = array();
        if (!empty($provider_row->retry_policy)) {
            $decoded = json_decode($provider_row->retry_policy, true);
            if (is_array($decoded) && isset($decoded['vtu']) && is_array($decoded['vtu'])) {
                $cfg = $decoded['vtu'];
            }
        }
        $this->paths = array_merge(array(
            'airtime'     => '/pay',
            'data'        => '/pay',
            'cable'       => '/pay',
            'electricity' => '/pay',
            'education'   => '/pay',
            'verify'      => '/merchant-verify',
            'status'      => '/requery',
            'balance'     => '/balance',
        ), $cfg);
    }

    /* ----------------------------- purchases ---------------------------- */

    public function airtime(array $p) {
        return $this->purchase('airtime', array(
            'serviceID' => $this->service_id($p, 'network_code'),
            'amount'    => $p['amount'] ?? null,
            'phone'     => $p['msisdn'] ?? null,
        ), $p);
    }

    public function data(array $p) {
        return $this->purchase('data', array(
            'serviceID'       => $this->service_id($p, 'network_code'),
            'billersCode'     => $p['msisdn'] ?? null,
            'variation_code'  => $p['variation_code'] ?? null,
            'phone'           => $p['msisdn'] ?? null,
        ), $p);
    }

    public function cable(array $p) {
        return $this->purchase('cable', array(
            'serviceID'      => $this->service_id($p, 'provider_code'),
            'billersCode'    => $p['smartcard'] ?? null,
            'variation_code' => $p['variation_code'] ?? null,
            'phone'          => $p['phone'] ?? null,
        ), $p);
    }

    public function electricity(array $p) {
        return $this->purchase('electricity', array(
            'serviceID'      => $this->service_id($p, 'disco_code'),
            'billersCode'    => $p['meter'] ?? null,
            'variation_code' => $p['meter_type'] ?? null,
            'amount'         => $p['amount'] ?? null,
            'phone'          => $p['phone'] ?? null,
        ), $p);
    }

    public function education(array $p) {
        return $this->purchase('education', array(
            'serviceID'      => $this->service_id($p, 'exam_code'),
            'variation_code' => $p['variation_code'] ?? null,
            'quantity'       => $p['quantity'] ?? 1,
            'phone'          => $p['phone'] ?? null,
        ), $p);
    }

    /* ------------------------------ lookups ----------------------------- */

    public function verify(array $p) {
        $res = $this->call($this->paths['verify'], array(
            'serviceID'   => $this->service_id($p, 'disco_code'),
            'billersCode' => $p['meter'] ?? $p['smartcard'] ?? null,
            'type'        => $p['meter_type'] ?? null,
        ));
        if (empty($res['ok'])) return $res;

        $data = $res['data'];
        $inner = isset($data['content']) && is_array($data['content']) ? $data['content'] : $data;
        $name = $inner['Customer_Name'] ?? $inner['customer_name'] ?? $inner['name'] ?? null;
        if (!$name) {
            return array('ok' => false, 'error' => 'Could not resolve that account');
        }
        return array(
            'ok'      => true,
            'name'    => $name,
            'address' => $inner['Address'] ?? $inner['address'] ?? null,
            'raw'     => $inner,
        );
    }

    public function status($reference) {
        $res = $this->call($this->paths['status'], array('request_id' => $reference));
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        $inner = isset($data['content']['transactions']) ? $data['content']['transactions'] : $data;
        $raw = (string)($inner['status'] ?? '');
        return array(
            'ok'        => true,
            'status'    => $this->map_status($raw),
            'reference' => $reference,
            'raw'       => $raw,
        );
    }

    public function balance() {
        $res = $this->call($this->paths['balance'], array(), 'GET');
        if (empty($res['ok'])) return $res;
        $data = $res['data'];
        $balance = $data['contents']['balance'] ?? $data['balance'] ?? null;
        if ($balance === null) return array('ok' => false, 'error' => 'No balance in response');
        return array(
            'ok'       => true,
            'balance'  => (string)$balance,
            'currency' => $this->provider->currency ?? 'NGN',
        );
    }

    /* ------------------------------ internals ---------------------------- */

    /**
     * A purchase always carries the engine's reference as request_id, which is
     * what makes a provider-side retry idempotent.
     */
    private function purchase($kind, array $fields, array $p) {
        $fields['request_id'] = $p['reference'] ?? null;
        $res = $this->call($this->paths[$kind], array_filter($fields, function ($v) {
            return $v !== null && $v !== '';
        }));
        if (empty($res['ok'])) return $res;

        $data = $res['data'];
        $code = (string)($data['code'] ?? '');
        $inner = isset($data['content']['transactions']) ? $data['content']['transactions'] : array();

        if ($code !== '' && $code !== '000') {
            return array(
                'ok'    => false,
                'error' => $data['response_description'] ?? ('Provider code '.$code),
            );
        }

        $status = $this->map_status((string)($inner['status'] ?? 'delivered'));
        $out = array(
            'ok'        => true,
            'reference' => (string)($inner['transactionId'] ?? $data['requestId'] ?? $fields['request_id']),
            'status'    => $status,
        );
        if (isset($inner['amount'])) $out['cost'] = (string)$inner['amount'];

        // Electricity tokens and exam PINs arrive inline.
        $detail = array();
        $content = $data['content'] ?? array();
        foreach (array('token' => array('token', 'Token', 'purchased_code'),
                       'units' => array('units', 'Units')) as $key => $candidates) {
            foreach ($candidates as $c) {
                if (!empty($content[$c])) { $detail[$key] = (string)$content[$c]; break; }
                if (!empty($data[$c]))    { $detail[$key] = (string)$data[$c]; break; }
            }
        }
        if ($detail) $out['detail'] = $detail;

        return $out;
    }

    private function map_status($raw) {
        $raw = strtolower(trim($raw));
        if (in_array($raw, array('delivered', 'successful', 'success', 'completed'), true)) {
            return 'SUCCESSFUL';
        }
        if (in_array(strtolower($raw), array_map('strtolower', self::$pending_codes), true)
            || in_array($raw, array('initiated', 'pending', 'processing'), true)) {
            return 'PROCESSING';
        }
        if (in_array($raw, array('failed', 'reversed', 'declined'), true)) {
            return 'FAILED';
        }
        return 'PROCESSING';
    }

    private function service_id(array $p, $key) {
        return $p[$key] ?? $p['network_code'] ?? null;
    }

    private function api_key() {
        $ci =& get_instance();
        $ci->load->library('EncryptionService');
        return $ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
    }

    /**
     * @return array{ok:bool,data?:array,error?:string,http_code?:int}
     */
    private function call($path, array $payload, $method = 'POST') {
        $url = rtrim($this->provider->api_url, '/').'/'.ltrim($path, '/');
        $headers = array(
            'Authorization: Bearer '.$this->api_key(),
            'Accept: application/json',
        );
        try {
            $res = $method === 'GET'
                ? $this->http->get($url, $headers)
                : $this->http->post($url, $payload, $headers);
        } catch (Exception $e) {
            // Never leak the key or URL into logs.
            log_message('error', 'VTU adapter transport error: '.$e->getMessage());
            return array('ok' => false, 'error' => 'Provider unreachable');
        }

        $code = isset($res['http_code']) ? (int)$res['http_code'] : 0;
        if ($code !== 200) {
            return array(
                'ok' => false,
                'error' => $res['error'] ?? ('HTTP '.$code),
                'http_code' => $code,
            );
        }
        $data = json_decode(isset($res['body']) ? $res['body'] : '', true);
        if (!is_array($data)) {
            return array('ok' => false, 'error' => 'Malformed provider response');
        }
        return array('ok' => true, 'data' => $data);
    }
}
