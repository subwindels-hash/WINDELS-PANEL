<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SecureHttpClient — centralized TLS-verified HTTP client (§62/63).
 * NEVER disables peer/host verification in production.
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
        $request_id = $options['request_id'] ?? windels_request_id();
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
}
