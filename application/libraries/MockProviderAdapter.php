<?php
defined('BASEPATH') OR exit('No direct script access allowed');
class MockProviderAdapter implements ProviderAdapterInterface {
    public function getServices(){ return array('ok'=>TRUE,'data'=>array(array('service'=>1,'name'=>'Mock Followers','rate'=>'1.00','min'=>'100','max'=>'10000','type'=>'Default'))); }
    public function createOrder($p){ return array('ok'=>TRUE,'provider_order_id'=>'mock_'.rand(10000,99999)); }
    public function getOrderStatus($id){ return array('ok'=>TRUE,'data'=>array('status'=>'Completed')); }
    public function getMultipleOrderStatus(array $ids){ $d=array(); foreach($ids as $id)$d[$id]=array('status'=>'Completed'); return array('ok'=>TRUE,'data'=>$d); }
    public function getBalance(){ return array('ok'=>TRUE,'data'=>array('balance'=>'1000.00','currency'=>marvy_base_currency())); }
    public function requestRefill($id){ return array('ok'=>TRUE,'provider_refill_id'=>'r_'.rand(1000,9999)); }
    public function getRefillStatus($id){ return array('ok'=>TRUE,'data'=>array('status'=>'Completed')); }
    public function requestCancel($id){ return array('ok'=>TRUE,'data'=>array('status'=>'Canceled')); }
}
