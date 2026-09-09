<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/IdentityProviderInterface.php';

/**
 * MockIdentityAdapter — offline identity vendor for tests and APP_ENV=demo.
 *
 * Deterministic on the identifier so a test can ask for a specific outcome
 * without scripting an HTTP double:
 *
 *   ending 0000  → the vendor is down (ok:false)
 *   ending 9999  → no such record     (ok:true, found:false)
 *   anything else→ a verified identity
 *
 * The fake entity deliberately includes a `photo` key. Nothing in the panel
 * should ever persist it, and having the mock emit one means IdentityService's
 * stripping is exercised by every test that runs a successful lookup, not only
 * by the DojahAdapter unit tests.
 */
class MockIdentityAdapter implements IdentityProviderInterface {

    /** Identifiers this run has been asked about, for assertions. */
    public static $calls = array();

    /** Force every lookup to fail, to exercise the refund path. */
    public static $force_error = null;

    public static function reset() {
        self::$calls = array();
        self::$force_error = null;
    }

    public function __construct($provider_row = null) {}

    public function lookup(array $p) {
        $identifier = preg_replace('/[\s-]+/', '', (string)($p['identifier'] ?? ''));
        self::$calls[] = array(
            'id_type'      => strtoupper((string)($p['id_type'] ?? 'NIN')),
            'lookup_field' => strtoupper((string)($p['lookup_field'] ?? 'IDENTIFIER')),
            'identifier'   => $identifier,
            'reference'    => $p['reference'] ?? null,
        );

        if (self::$force_error !== null) {
            return array('ok' => false, 'error' => self::$force_error);
        }
        if (substr($identifier, -4) === '0000') {
            return array('ok' => false, 'error' => 'The identity vendor is unavailable');
        }
        if (substr($identifier, -4) === '9999') {
            return array('ok' => true, 'found' => false, 'entity' => array(),
                         'reference' => 'mock-'.substr(sha1($identifier), 0, 12), 'error' => null);
        }

        return array(
            'ok'        => true,
            'found'     => true,
            'reference' => 'mock-'.substr(sha1($identifier), 0, 12),
            'entity'    => array(
                'first_name'    => 'Ada',
                'middle_name'   => 'Ngozi',
                'last_name'     => 'Okafor',
                'date_of_birth' => '1990-04-12',
                'gender'        => 'Female',
                'phone_number'  => '08031234567',
                'nationality'   => 'Nigerian',
                // Present on purpose — must never survive into storage.
                'photo'         => '/9j/4AAQSkZJRgABAgAAAQABAAD-mock-base64-portrait',
            ),
            'cost'  => null,
            'error' => null,
        );
    }

    public function balance() {
        return array('ok' => true, 'balance' => '25000.00000000', 'currency' => 'NGN');
    }
}
