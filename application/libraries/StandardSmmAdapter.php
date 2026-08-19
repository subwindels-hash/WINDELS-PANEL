<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Instantiated with `new` below; CI3 does not autoload plain library
// classes, so require the dependency explicitly.
require_once __DIR__.'/SecureHttpClient.php';

class StandardSmmAdapter implements ProviderAdapterInterface {
    private $provider;
    private $http;
    public function __construct($provider_row, $http=null){
        $this->provider = $provider_row;
        $this->http = $http ?: new SecureHttpClient(array('timeout'=>$provider_row->timeout_ms/1000));
    }
    private function apiKey(){ // decrypt at-rest
        $ci =& get_instance();
        $ci->load->library('EncryptionService');
        return $ci->encryptionservice->decrypt($this->provider->api_key_encrypted);
    }
    private function call($payload){
        $url = rtrim($this->provider->api_url,'/');
        $payload['key'] = $this->apiKey();
        return $this->http->post($url, $payload);
    }
    public function getServices(){
        $res=$this->call(array('action'=>'services'));
        if ($res['http_code']!==200) return array('ok'=>FALSE,'error'=>$res['error'] ?? 'HTTP '.$res['http_code']);
        $data=json_decode($res['body'],TRUE);
        return array('ok'=>TRUE,'data'=>$data);
    }
    public function createOrder($payload){
        // $payload: service, link, quantity, ... per ServiceTypeEngine
        $res=$this->call(array_merge(array('action'=>'add'),$payload));
        if ($res['http_code']!==200) return array('ok'=>FALSE,'error'=>$res['error'] ?? 'HTTP error');
        $data=json_decode($res['body'],TRUE);
        if (isset($data['order'])) return array('ok'=>TRUE,'provider_order_id'=>(string)$data['order']);
        return array('ok'=>FALSE,'error'=>$data['error'] ?? 'Unknown provider error');
    }
    public function getOrderStatus($oid){ return $this->getMultipleOrderStatus(array($oid)); }
    public function getMultipleOrderStatus(array $ids){
        $res=$this->call(array('action'=>'status','orders'=>implode(',',$ids)));
        if ($res['http_code']!==200) return array('ok'=>FALSE,'error'=>$res['error'] ?? 'HTTP');
        $data=json_decode($res['body'],TRUE);
        return array('ok'=>TRUE,'data'=>$data);
    }
    public function getBalance(){
        $res=$this->call(array('action'=>'balance'));
        if ($res['http_code']!==200) return array('ok'=>FALSE,'error'=>$res['error'] ?? 'HTTP');
        $data=json_decode($res['body'],TRUE);
        return array('ok'=>TRUE,'data'=>$data);
    }
    public function requestRefill($oid){
        $res=$this->call(array('action'=>'refill','order'=>$oid));
        $data=json_decode($res['body']??'',TRUE);
        return isset($data['refill'])?array('ok'=>TRUE,'provider_refill_id'=>(string)$data['refill']):array('ok'=>FALSE,'error'=>$data['error']??'refill failed');
    }
    public function getRefillStatus($rid){
        $res=$this->call(array('action'=>'refill_status','refill'=>$rid));
        $data=json_decode($res['body']??'',TRUE);
        return array('ok'=>TRUE,'data'=>$data);
    }
    public function requestCancel($oid){
        $res=$this->call(array('action'=>'cancel','orders'=>is_array($oid)?implode(',',$oid):$oid));
        $data=json_decode($res['body']??'',TRUE);
        return array('ok'=>TRUE,'data'=>$data);
    }
}
