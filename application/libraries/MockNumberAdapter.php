<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/NumberProviderInterface.php';

/**
 * MockNumberAdapter — offline virtual-number vendor for development and tests.
 *
 * Behaves like a well-mannered real vendor: reserves a number with a real
 * deadline, delivers a code on a later poll rather than instantly, and
 * rejects rather than throws.
 *
 * Trigger values, following the MockVtuAdapter convention of encoding the
 * failure paths in the request rather than needing a fixture per case. The
 * trigger is the *service* code, because that is what the customer picks:
 *
 *   service NOSTOCK  → vendor rejection ("no free phones")
 *   service SLOW     → reserved, but no SMS ever arrives (drives expiry)
 *   service EXPENSIVE→ rejection when a max_price is supplied
 *   anything else    → reserved, and a code lands on the second poll
 *
 * "Second poll" matters: a mock that answers with the code immediately would
 * let a caller that never polls at all pass its tests, which is precisely the
 * bug this domain is prone to.
 */
class MockNumberAdapter implements NumberProviderInterface {

    /** Deadline the mock hands out when the caller does not ask for one. */
    const DEFAULT_TTL_MINUTES = 15;

    private $provider;

    /** reference => reservation state, so status() can evolve across calls. */
    private static $orders = array();

    public function __construct($provider = null) { $this->provider = $provider; }

    /** Tests that want a clean slate between cases. */
    public static function reset() { self::$orders = array(); }

    public function reserve(array $p) {
        $service = strtoupper((string)($p['service'] ?? ''));
        $country = strtoupper((string)($p['country'] ?? 'NG'));

        if ($service === 'NOSTOCK') {
            return array('ok' => false, 'error' => 'no free phones');
        }
        if ($service === 'EXPENSIVE' && isset($p['max_price'])) {
            return array('ok' => false, 'error' => 'no free phones at that price');
        }

        $reference = 'MOCK-NUM-'.strtoupper(bin2hex(random_bytes(4)));
        $ttl = (int)($p['ttl_minutes'] ?? self::DEFAULT_TTL_MINUTES);
        if ($ttl < 1) $ttl = self::DEFAULT_TTL_MINUTES;

        $expires = gmdate('Y-m-d H:i:s', time() + ($ttl * 60));
        self::$orders[$reference] = array(
            'service' => $service,
            'polls'   => 0,
            'state'   => 'RESERVED',
            'expires' => $expires,
        );

        return array(
            'ok'         => true,
            'reference'  => $reference,
            'msisdn'     => $this->msisdn($country),
            'operator'   => 'mock',
            'cost'       => isset($p['cost']) ? (string)$p['cost'] : null,
            'expires_at' => $expires,
            'state'      => 'RESERVED',
            'messages'   => array(),
        );
    }

    public function status($reference) {
        if (!isset(self::$orders[$reference])) {
            // An unknown reference is a vendor answer, not a crash: a
            // reservation the mock has forgotten reads as expired.
            return array('ok' => true, 'reference' => $reference,
                         'state' => 'EXPIRED', 'messages' => array());
        }
        $order = &self::$orders[$reference];
        $order['polls']++;

        $terminal = in_array($order['state'], array('COMPLETED','CANCELLED','BANNED','EXPIRED'), true);
        if (!$terminal && strtotime($order['expires']) <= time()) {
            $order['state'] = 'EXPIRED';
            $terminal = true;
        }
        if ($terminal) {
            return array('ok' => true, 'reference' => $reference,
                         'state' => $order['state'], 'expires_at' => $order['expires'],
                         'messages' => array());
        }
        if ($order['service'] === 'SLOW' || $order['polls'] < 2) {
            return array('ok' => true, 'reference' => $reference, 'state' => 'RESERVED',
                         'expires_at' => $order['expires'], 'messages' => array());
        }

        $order['state'] = 'RECEIVED';
        return array(
            'ok'         => true,
            'reference'  => $reference,
            'state'      => 'RECEIVED',
            'expires_at' => $order['expires'],
            'messages'   => array(array(
                'id'          => $reference.'-1',
                'sender'      => $order['service'] ?: 'MOCK',
                'text'        => 'Your verification code is 471925',
                'code'        => '471925',
                'received_at' => gmdate('Y-m-d H:i:s'),
            )),
        );
    }

    public function finish($reference) { return $this->close($reference, 'COMPLETED'); }
    public function cancel($reference) { return $this->close($reference, 'CANCELLED'); }
    public function ban($reference)    { return $this->close($reference, 'BANNED'); }

    public function products($country) {
        return array('ok' => true, 'products' => array(
            array('service' => 'WHATSAPP', 'provider_product' => 'whatsapp',
                  'operator' => 'any', 'cost' => '250.00000000', 'stock' => 812),
            array('service' => 'TELEGRAM', 'provider_product' => 'telegram',
                  'operator' => 'any', 'cost' => '300.00000000', 'stock' => 415),
            array('service' => 'FACEBOOK', 'provider_product' => 'facebook',
                  'operator' => 'any', 'cost' => '190.00000000', 'stock' => 133),
        ));
    }

    public function balance() {
        return array('ok' => true, 'balance' => '250000.00', 'currency' => 'NGN');
    }

    /* ------------------------------------------------------------------ */

    private function close($reference, $state) {
        if (!isset(self::$orders[$reference])) {
            return array('ok' => false, 'error' => 'order not found');
        }
        if (in_array(self::$orders[$reference]['state'],
                     array('COMPLETED','CANCELLED','BANNED'), true)) {
            return array('ok' => false, 'error' => 'order already closed');
        }
        self::$orders[$reference]['state'] = $state;
        return array('ok' => true, 'reference' => $reference, 'state' => $state,
                     'messages' => array());
    }

    private function msisdn($country) {
        $prefix = array('NG' => '+234', 'GB' => '+44', 'US' => '+1');
        return ($prefix[$country] ?? '+99').str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }
}
