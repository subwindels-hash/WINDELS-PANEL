<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SecureHttpClient — centralized TLS-verified HTTP client (§62/63).
 * NEVER disables peer/host verification in production.
 *
 * Session 17 also hardened this against SSRF. Provider API URLs are supplied by
 * an admin and stored in the database, so this client is the one place where
 * server-side code fetches an attacker-influenceable URL. Three guards:
 *
 *   1. Only http/https are allowed, on the request *and* on redirects
 *      (CURLOPT_PROTOCOLS/REDIR_PROTOCOLS). Without this, curl would happily
 *      follow a redirect to file:///etc/passwd or gopher:// and hand the body
 *      back to the caller.
 *   2. Hosts that resolve to private, loopback or link-local addresses are
 *      refused, which is what stops a provider URL from being pointed at
 *      169.254.169.254 (cloud metadata) or an internal admin service.
 *   3. Credentials in the URL (user:pass@) are rejected outright.
 *
 * The private-range check is deliberately allowed to be disabled via
 * `$config['http_allow_private_hosts']`, because self-hosted deployments do
 * legitimately run providers on a LAN address. It defaults to off.
 */
class SecureHttpClient {
    private $ci;
    private $timeout;
    private $connect_timeout;
    private $max_retries;

    public function __construct($params=array()){
        $this->ci =& get_instance();
        $this->timeout = $params['timeout'] ?? 15;
        $this->connect_timeout = $params['connect_timeout'] ?? 5;
        $this->max_retries = $params['max_retries'] ?? 3;
    }

    public function get($url, $headers=array(), $options=array()){ return $this->request('GET',$url,null,$headers,$options); }
    public function post($url, $data=null, $headers=array(), $options=array()){ return $this->request('POST',$url,$data,$headers,$options); }

    public function request($method, $url, $data=null, $headers=array(), $options=array()){
        $attempt=0; $last_error=''; $backoffs=array(500,1500,4000);
        $request_id = $options['request_id'] ?? marvy_request_id();

        $rejection = $this->reject_url($url);
        if ($rejection !== null) {
            log_message('error', 'SecureHttpClient blocked '.$method.' -> '.$rejection.' rid='.$request_id);
            return array('http_code'=>0, 'body'=>null, 'error'=>$rejection, 'request_id'=>$request_id);
        }
        do {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => TRUE,
                CURLOPT_FOLLOWLOCATION => TRUE,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $options['timeout'] ?? $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? $this->connect_timeout,
                CURLOPT_SSL_VERIFYPEER => TRUE,
                CURLOPT_SSL_VERIFYHOST => 2,
                // A redirect must not be able to escape into file://, gopher://
                // or dict:// — curl follows those by default.
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => array_merge(array('X-Request-Id: '.$request_id), $headers),
            ));
            if (strtoupper($method)==='POST') {
                curl_setopt($ch, CURLOPT_POST, TRUE);
                if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data)?http_build_query($data):$data);
            } elseif (strtoupper($method)!=='GET') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data)?http_build_query($data):$data);
            }
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno===0 && $http_code < 500) {
                log_message('debug', 'SecureHttpClient '.$method.' '.$url.' -> '.$http_code.' rid='.$request_id);
                return array('http_code'=>$http_code,'body'=>$body,'request_id'=>$request_id);
            }
            $last_error = $error ?: ('HTTP '.$http_code);
            log_message('error', 'SecureHttpClient retry '.$attempt.' '.$method.' '.$url.' error='.$last_error.' rid='.$request_id);
            if ($attempt < $this->max_retries) usleep(($backoffs[$attempt] ?? 4000)*1000);
        } while (++$attempt <= $this->max_retries);

        // Circuit-breaker hook (future: mark provider degraded)
        return array('http_code'=>0,'body'=>null,'error'=>$last_error,'request_id'=>$request_id);
    }

    /**
     * Why this URL must not be fetched, or null if it is acceptable.
     *
     * Returns a reason string rather than throwing so a bad provider URL
     * degrades to a failed sync instead of a 500.
     */
    private function reject_url($url) {
        $parts = parse_url((string)$url);
        if ($parts === false || empty($parts['host'])) {
            return 'Malformed URL';
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'Unsupported scheme: '.($scheme ?: 'none');
        }
        // user:pass@host in a stored URL is almost always credential smuggling.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Credentials in URL are not permitted';
        }
        if ($this->allow_private_hosts()) {
            return null;
        }
        foreach ($this->resolve($parts['host']) as $ip) {
            if (!$this->is_public_ip($ip)) {
                // Do not echo the resolved address back to the caller; that
                // would turn the error message into a port scanner.
                return 'Host resolves to a non-public address';
            }
        }
        return null;
    }

    /** Every address the host resolves to (a name can have several). */
    private function resolve($host) {
        if (filter_var($host, FILTER_VALIDATE_IP)) return array($host);

        $ips = array();
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = $v4;

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        // Unresolvable: let curl fail normally rather than guessing.
        return $ips;
    }

    private function is_public_ip($ip) {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function allow_private_hosts() {
        $flag = $this->ci && isset($this->ci->config)
            ? $this->ci->config->item('http_allow_private_hosts')
            : null;
        return (bool)$flag;
    }
}
