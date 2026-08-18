<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/GiftcardProviderInterface.php';

/**
 * MockGiftcardAdapter — offline gift card vendor for tests and APP_ENV=demo.
 *
 * Deterministic on the vendor product id, so a test can ask for a specific
 * outcome without scripting an HTTP double:
 *
 *   product id ending 0  → the vendor rejects the order (ok:false)
 *   product id ending 7  → the order is accepted but the codes are never
 *                          ready, which is the case the retry worker and the
 *                          give-up-and-refund rule exist for
 *   anything else        → accepted, and codes available on the next call
 *
 * Note what the default is *not*: codes are never returned by order(). The
 * two-step delivery is the shape of this domain — real vendors mint the card
 * after accepting the order — and a mock that handed everything back at once
 * would let a broken GiftcardService pass every test and then fail against
 * Reloadly on the first live purchase.
 */
class MockGiftcardAdapter implements GiftcardProviderInterface {

    /** Orders placed this run, for assertions. */
    public static $calls = array();

    /** Force every order to fail, to exercise the refund path. */
    public static $force_error = null;

    /** Orders whose codes are ready, keyed by vendor reference. */
    private static $ready = array();

    private static $counter = 0;

    public static function reset() {
        self::$calls = array();
        self::$force_error = null;
        self::$ready = array();
        self::$counter = 0;
    }

    public function __construct($provider_row = null) {}

    public function order(array $p) {
        $product = (string)($p['product_id'] ?? '');
        self::$calls[] = array(
            'product_id' => $product,
            'quantity'   => (int)($p['quantity'] ?? 1),
            'unit_price' => $p['unit_price'] ?? null,
            'reference'  => $p['reference'] ?? null,
            'recipient_email' => $p['recipient_email'] ?? null,
        );

        if (self::$force_error !== null) {
            return array('ok' => false, 'error' => self::$force_error);
        }
        if (substr($product, -1) === '0') {
            return array('ok' => false, 'error' => 'That card is out of stock at the vendor');
        }

        $reference = 'mock-gc-'.(++self::$counter).'-'.substr(sha1($product.'|'.($p['reference'] ?? '')), 0, 8);

        // '...7' products stay undeliverable for the whole run.
        if (substr($product, -1) !== '7') {
            self::$ready[$reference] = array(
                'quantity'   => max(1, (int)($p['quantity'] ?? 1)),
                'product_id' => $product,
            );
        }

        return array(
            'ok'        => true,
            'reference' => $reference,
            'status'    => 'PLACED',
            'cost'      => null,
            'error'     => null,
        );
    }

    public function codes($reference) {
        $reference = (string)$reference;
        if (!isset(self::$ready[$reference])) {
            // Accepted, not yet issued. Not an error.
            return array('ok' => true, 'ready' => false, 'cards' => array(), 'error' => null);
        }

        $meta = self::$ready[$reference];
        $cards = array();
        for ($i = 1; $i <= $meta['quantity']; $i++) {
            $number = str_pad((string)(6120200345140000 + (self::$counter * 100) + $i), 16, '0', STR_PAD_LEFT);
            $cards[] = array(
                'card_number'    => $number,
                'pin'            => strtoupper(substr(sha1($reference.':'.$i), 0, 10)),
                'redemption_url' => 'https://redeem.mock.test/'.$reference.'/'.$i,
                'expires_on'     => gmdate('Y-m-d', time() + (365 * 86400)),
            );
        }
        return array('ok' => true, 'ready' => true, 'cards' => $cards, 'error' => null);
    }

    public function order_status($reference) {
        return array(
            'ok'        => true,
            'status'    => isset(self::$ready[(string)$reference]) ? 'PLACED' : 'PENDING',
            'reference' => (string)$reference,
            'cost'      => null,
            'error'     => null,
        );
    }

    public function products($country = null) {
        $country = strtoupper((string)($country ?: 'US'));
        $products = array();
        foreach (array(array('11', 'Amazon', '25'), array('12', 'Amazon', '50'),
                       array('13', 'Steam', '20')) as $row) {
            list($id, $brand, $face) = $row;
            $products[] = array(
                'provider_product_id' => $id,
                'name'                => $brand.' '.$country.' $'.$face,
                'brand_id'            => $id,
                'brand_name'          => $brand,
                'country_code'        => $country,
                'denomination_type'   => 'FIXED',
                'recipient_currency'  => 'USD',
                'face_value'          => number_format((float)$face, 8, '.', ''),
                'min_face_value'      => null,
                'max_face_value'      => null,
                'cost'                => number_format((float)$face * 1500, 8, '.', ''),
                'logo_url'            => null,
                'redeem_instructions' => 'Redeem this code at the '.$brand.' checkout.',
            );
        }
        return array('ok' => true, 'products' => $products);
    }

    public function balance() {
        return array('ok' => true, 'balance' => '1500000.00000000', 'currency' => 'NGN');
    }
}
