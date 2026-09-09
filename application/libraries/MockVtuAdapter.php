<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/VtuProviderInterface.php';

/**
 * MockVtuAdapter — offline VTU provider for development and tests.
 *
 * Behaves like a well-mannered real provider: returns references, resolves
 * meter/smartcard names, and rejects rather than throws. Recognises a few
 * trigger values so failure paths can be exercised without network access:
 *
 *   recipient ending 0000  → provider rejection
 *   recipient ending 9999  → async PROCESSING
 */
class MockVtuAdapter implements VtuProviderInterface {

    private $provider;

    public function __construct($provider = null) { $this->provider = $provider; }

    public function airtime(array $p)     { return $this->purchase($p, 'airtime'); }
    public function data(array $p)        { return $this->purchase($p, 'data'); }
    public function cable(array $p)       { return $this->purchase($p, 'cable'); }
    public function electricity(array $p) {
        $res = $this->purchase($p, 'electricity');
        if (!empty($res['ok'])) {
            $res['detail'] = array(
                'token' => sprintf('%04d-%04d-%04d-%04d',
                    rand(1000,9999), rand(1000,9999), rand(1000,9999), rand(1000,9999)),
                'units' => number_format((float)($p['amount'] ?? 0) / 50, 1).' kWh',
            );
        }
        return $res;
    }
    public function education(array $p) {
        $res = $this->purchase($p, 'education');
        if (!empty($res['ok'])) {
            $res['detail'] = array('token' => strtoupper(bin2hex(random_bytes(6))));
        }
        return $res;
    }

    public function verify(array $p) {
        $target = (string)($p['meter'] ?? $p['smartcard'] ?? '');
        if ($target === '' || substr($target, -4) === '0000') {
            return array('ok' => false, 'error' => 'Not found');
        }
        return array('ok' => true, 'name' => 'MOCK CUSTOMER', 'address' => '1 Test Road');
    }

    public function status($reference) {
        return array('ok' => true, 'status' => 'SUCCESSFUL', 'reference' => $reference);
    }

    public function balance() {
        return array('ok' => true, 'balance' => '100000.00', 'currency' => 'NGN');
    }

    private function purchase(array $p, $kind) {
        $target = (string)($p['msisdn'] ?? $p['meter'] ?? $p['smartcard'] ?? '');
        if ($target !== '' && substr($target, -4) === '0000') {
            return array('ok' => false, 'error' => 'Provider declined the transaction');
        }
        $out = array(
            'ok'        => true,
            'reference' => 'MOCK-'.strtoupper($kind).'-'.strtoupper(bin2hex(random_bytes(4))),
        );
        if ($target !== '' && substr($target, -4) === '9999') {
            $out['status'] = 'PROCESSING';
        }
        return $out;
    }
}
