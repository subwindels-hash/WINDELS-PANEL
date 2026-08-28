<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MockProviderAdapter — the SMM provider used in development and tests.
 *
 * Its answers deliberately mirror StandardSmmAdapter's shapes exactly
 * (status keyed by provider order id, catalogue as a list, balance as
 * {balance, currency}). A mock whose shapes drift from the real adapter makes
 * every dry run and every test prove nothing — the code passes against the
 * mock and breaks against the panel.
 *
 * Provider_manager refuses to build this adapter in production.
 */
class MockProviderAdapter implements ProviderAdapterInterface {

    public function getServices() {
        return array('ok' => true, 'error' => null, 'data' => array(
            array('service' => 1, 'name' => 'Mock Followers', 'rate' => '1.00',
                  'min' => '100', 'max' => '10000', 'type' => 'Default', 'category' => 'Mock'),
            array('service' => 2, 'name' => 'Mock Likes', 'rate' => '0.50',
                  'min' => '50', 'max' => '50000', 'type' => 'Default', 'category' => 'Mock'),
        ));
    }

    public function createOrder($p) {
        return array('ok' => true, 'error' => null,
                     'provider_order_id' => 'mock_'.random_int(10000, 99999),
                     'charge' => null, 'currency' => null);
    }

    public function getOrderStatus($id) {
        return array('ok' => true, 'error' => null,
                     'data' => array((string)$id => $this->status_payload()));
    }

    public function getMultipleOrderStatus(array $ids) {
        $data = array();
        foreach ($ids as $id) $data[(string)$id] = $this->status_payload();
        return array('ok' => true, 'error' => null, 'data' => $data);
    }

    public function getBalance() {
        return array('ok' => true, 'error' => null,
                     'data' => array('balance' => '1000.00', 'currency' => marvy_base_currency()));
    }

    public function requestRefill($id) {
        return array('ok' => true, 'error' => null, 'provider_refill_id' => 'r_'.random_int(1000, 9999));
    }

    public function getRefillStatus($id) {
        return array('ok' => true, 'error' => null, 'data' => array('status' => 'Completed'));
    }

    public function requestCancel($id) {
        return array('ok' => true, 'error' => null, 'data' => array('status' => 'Canceled'));
    }

    private function status_payload() {
        return array('status' => 'Completed', 'charge' => '0.00', 'start_count' => '0', 'remains' => '0');
    }
}
