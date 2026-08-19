<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/ProviderAdapterInterface.php';
require_once __DIR__.'/VtuProviderInterface.php';
require_once __DIR__.'/NumberProviderInterface.php';
require_once __DIR__.'/IdentityProviderInterface.php';
require_once __DIR__.'/GiftcardProviderInterface.php';

/**
 * Provider_manager — one registry over every provider family (§14).
 *
 * The spec asks for a Provider Manager that fronts SMM, VTU, number, identity,
 * gift card and payment providers. Those families do not share a method set
 * (an airtime top-up has no refill), so the registry maps
 * (family, api_type) → adapter class rather than forcing one interface.
 *
 * Adding a provider integration means registering it here; no controller or
 * service should ever construct an adapter directly.
 */
class Provider_manager {

    const FAMILY_SMM    = 'SMM';
    const FAMILY_VTU    = 'VTU';
    const FAMILY_NUMBER = 'NUMBER';
    const FAMILY_IDENTITY = 'IDENTITY';
    const FAMILY_GIFTCARD = 'GIFTCARD';

    /** family => [api_type => [class, file]] */
    private static $registry = array(
        self::FAMILY_SMM => array(
            'STANDARD_SMM' => array('StandardSmmAdapter', 'StandardSmmAdapter.php'),
            'MOCK'         => array('MockProviderAdapter', 'MockProviderAdapter.php'),
        ),
        self::FAMILY_VTU => array(
            'MOCK'         => array('MockVtuAdapter', 'MockVtuAdapter.php'),
            'STANDARD_VTU' => array('StandardVtuAdapter', 'StandardVtuAdapter.php'),
            'VTPASS'       => array('VtpassAdapter', 'VtpassAdapter.php'),
        ),
        self::FAMILY_NUMBER => array(
            'MOCK_NUMBER' => array('MockNumberAdapter', 'MockNumberAdapter.php'),
            'FIVESIM'     => array('FiveSimAdapter', 'FiveSimAdapter.php'),
        ),
        self::FAMILY_IDENTITY => array(
            'MOCK_IDENTITY' => array('MockIdentityAdapter', 'MockIdentityAdapter.php'),
            'DOJAH'         => array('DojahAdapter', 'DojahAdapter.php'),
        ),
        self::FAMILY_GIFTCARD => array(
            'MOCK_GIFTCARD' => array('MockGiftcardAdapter', 'MockGiftcardAdapter.php'),
            'RELOADLY'      => array('ReloadlyAdapter', 'ReloadlyAdapter.php'),
        ),
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->library('SecureHttpClient');
    }

    /** api_types this build can talk to, for admin validation. */
    public static function supported_types($family) {
        $family = strtoupper($family);
        return isset(self::$registry[$family]) ? array_keys(self::$registry[$family]) : array();
    }

    public static function families() {
        return array_keys(self::$registry);
    }

    /**
     * Build the adapter for a stored provider row.
     *
     * @param object $provider row from `providers`
     * @param string $family   SMM|VTU
     * @throws RuntimeException when the api_type has no adapter in this build
     */
    public function adapter($provider, $family = self::FAMILY_SMM) {
        $family = strtoupper($family);
        $type   = strtoupper(isset($provider->api_type) ? $provider->api_type : '');

        if (!isset(self::$registry[$family])) {
            throw new RuntimeException('Unknown provider family: '.$family);
        }
        if (!isset(self::$registry[$family][$type])) {
            throw new RuntimeException(
                'No '.$family.' adapter for api_type "'.$type.'". Known: '
                .implode(', ', self::supported_types($family))
            );
        }

        list($class, $file) = self::$registry[$family][$type];

        // MOCK adapters are offline doubles (MOCK, MOCK_NUMBER, ...) for
        // development, testing and demo data. In production one of these
        // behind an active provider would silently "fulfil" paid orders
        // without calling any upstream, so refuse to build it at all.
        if (strpos($type, 'MOCK') === 0) {
            self::assert_mock_allowed($type);
        }

        require_once __DIR__.'/'.$file;

        if (strpos($type, 'MOCK') === 0) {
            // Some predate this registry and take no constructor argument at
            // all, so match what they declare rather than assuming the
            // two-arg shape.
            $ref = new ReflectionClass($class);
            $ctor = $ref->getConstructor();
            return ($ctor && $ctor->getNumberOfParameters() > 0)
                ? new $class($provider)
                : new $class();
        }
        return new $class($provider, $this->ci->securehttpclient);
    }

    /**
     * Mock providers must only be usable outside production. Deploy-time the
     * same rule is enforced by Preflight's mock_providers check; this is the
     * runtime backstop for every code path that builds an adapter (orders,
     * VTU purchases, numbers, identity, gift cards).
     *
     * CI_ENV wins over APP_ENV, matching Preflight::check_environment —
     * a production kernel must not be talked into a mock by a stray env var.
     *
     * @throws RuntimeException in production
     */
    public static function assert_mock_allowed($type) {
        $env = getenv('CI_ENV');
        if ($env === false || trim($env) === '') $env = getenv('APP_ENV');
        $env = strtolower(trim((string)$env));
        if ($env === 'production' || $env === 'prod') {
            throw new RuntimeException(
                'Mock provider adapter "'.$type.'" is disabled in production. '
                .'Reconfigure this provider against a real upstream or disable it.'
            );
        }
    }
}
