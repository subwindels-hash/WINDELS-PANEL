<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/ProviderAdapterInterface.php';
require_once __DIR__.'/VtuProviderInterface.php';
require_once __DIR__.'/NumberProviderInterface.php';
require_once __DIR__.'/IdentityProviderInterface.php';

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
        require_once __DIR__.'/'.$file;

        // MOCK adapters are offline doubles (MOCK, MOCK_NUMBER, ...). Some
        // predate this registry and take no constructor argument at all, so
        // match what they declare rather than assuming the two-arg shape.
        if (strpos($type, 'MOCK') === 0) {
            $ref = new ReflectionClass($class);
            $ctor = $ref->getConstructor();
            return ($ctor && $ctor->getNumberOfParameters() > 0)
                ? new $class($provider)
                : new $class();
        }
        return new $class($provider, $this->ci->securehttpclient);
    }
}
