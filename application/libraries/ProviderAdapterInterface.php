<?php
defined('BASEPATH') OR exit('No direct script access allowed');

interface ProviderAdapterInterface {
    public function getServices();
    public function createOrder($payload);
    public function getOrderStatus($provider_order_id);
    public function getMultipleOrderStatus(array $provider_order_ids);
    public function getBalance();
    public function requestRefill($provider_order_id);
    public function getRefillStatus($provider_refill_id);
    public function requestCancel($provider_order_id);
}
