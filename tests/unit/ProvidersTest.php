<?php
use PHPUnit\Framework\TestCase;

/**
 * Provider management tests (Session 08) — sync normalization, validation,
 * route/controller guarantees, and security rules (encrypted keys, TLS, POST-only).
 */
class ProvidersTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) {
            eval('function get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('windels_public_id')) {
            require_once self::$root.'/application/helpers/windels_helper.php';
        }
        if (!function_exists('windels_request_id')) {
            require_once self::$root.'/application/helpers/windels_helper.php';
        }
        require_once self::$root.'/application/libraries/SecureHttpClient.php';
        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/MockProviderAdapter.php';
        require_once self::$root.'/application/libraries/StandardSmmAdapter.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
    }

    /* ----------------------------- routing ---------------------------- */

    public function testAdminProviderRoutesExist()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array(
            "'admin/providers'",
            "'admin/providers/create'",
            'admin/providers/(:any)/test',
            'admin/providers/(:any)/sync',
            'admin/providers/(:any)/sync-balance',
        ) as $r) {
            $this->assertStringContainsString($r, $routes, "missing route fragment {$r}");
        }
    }

    public function testControllerExistsAndIsPermissionGated()
    {
        $file = self::$root.'/application/controllers/admin/Providers.php';
        $this->assertFileExists($file);
        $src = file_get_contents($file);
        $this->assertStringContainsString('extends Admin_Controller', $src);
        $this->assertStringContainsString("require_perm('providers.manage')", $src);
        foreach (array('index','create','detail','test','sync','sync_balance') as $m) {
            $this->assertStringContainsString('function '.$m, $src, "missing method {$m}");
        }
        // All mutating actions must be POST-guarded.
        $this->assertStringContainsString('guard_post', $src);
        $this->assertSame(3, substr_count($src, '$this->guard_post()'));
    }

    public function testProviderDetailRouteIsAfterActionRoutes()
    {
        // The (:any) catch-all must not shadow /create, /test, /sync etc.
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $posCreate = strpos($routes, "'admin/providers/create'");
        $posDetail = strpos($routes, "'admin/providers/(:any)'");
        $this->assertNotFalse($posCreate);
        $this->assertNotFalse($posDetail);
        $this->assertLessThan($posDetail, $posCreate, 'create route must precede the catch-all detail route');
    }

    /* ----------------------- normalization --------------------------- */

    public function testNormalizeServiceAcceptsStandardShape()
    {
        $ci = new ProvidersFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new ProviderSyncService();

        $out = $svc->normalize_service(array(
            'service' => '1001', 'name' => 'IG Followers', 'rate' => '1.20',
            'min' => 50, 'max' => 100000, 'type' => 'Default', 'category' => 'instagram',
            'refill' => 1, 'cancel' => 0,
        ));
        $this->assertSame('1001', $out['provider_service_id']);
        $this->assertSame('1.20000000', $out['rate']);
        $this->assertSame(50, $out['min_quantity']);
        $this->assertSame(100000, $out['max_quantity']);
        $this->assertSame('DEFAULT', $out['service_type']);
        $this->assertTrue($out['refill']);
        $this->assertFalse($out['cancel']);
    }

    public function testNormalizeServiceToleratesAlternateKeys()
    {
        $ci = new ProvidersFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new ProviderSyncService();

        $out = $svc->normalize_service(array(
            'ID' => 'abc', 'name' => 'X', 'cost' => 0.5, 'minimum' => 100, 'maximum' => 500,
        ));
        $this->assertSame('abc', $out['provider_service_id']);
        $this->assertSame('0.50000000', $out['rate']);
        $this->assertSame(100, $out['min_quantity']);
        $this->assertSame(500, $out['max_quantity']);
    }

    public function testNormalizeServiceRejectsInvalidRows()
    {
        $ci = new ProvidersFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new ProviderSyncService();
        $this->assertNull($svc->normalize_service(array('service'=>1,'name'=>'no rate')));
        $this->assertNull($svc->normalize_service(array('service'=>1,'name'=>'bad','rate'=>'-5')));
        $this->assertNull($svc->normalize_service('not an array'));
        $this->assertNull($svc->normalize_service(array()));
    }

    public function testTypeMappingIsWhitelisted()
    {
        $ci = new ProvidersFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new ProviderSyncService();
        $this->assertSame('SUBSCRIPTION', $svc->normalize_service(array('service'=>1,'name'=>'s','rate'=>'1','type'=>'subscriptions'))['service_type']);
        $this->assertSame('CUSTOM_COMMENTS', $svc->normalize_service(array('service'=>1,'name'=>'s','rate'=>'1','type'=>'custom-comments'))['service_type']);
        $this->assertSame('DEFAULT', $svc->normalize_service(array('service'=>1,'name'=>'s','rate'=>'1','type'=>'weird-type'))['service_type']);
    }

    /* ----------------------- create validation ----------------------- */

    public function testCreateValidatesAndEncryptsKey()
    {
        $ci = new ProvidersFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new ProviderSyncService();

        $res = $svc->create_provider(array(
            'name' => 'Acme',
            'api_url' => 'https://acme.example/api/v2',
            'api_key' => 'super-secret-key',
            'api_type' => 'STANDARD_SMM',
        ));
        $this->assertTrue($res['ok']);
        $provider = $res['provider'];
        $this->assertSame('Acme', $provider->name);
        // Key must be encrypted, not equal to plaintext.
        $this->assertNotSame('super-secret-key', $provider->api_key_encrypted);
        $this->assertStringNotContainsString('super-secret-key', $provider->api_key_encrypted);
        // Round-trip decrypt returns the original.
        $this->assertSame('super-secret-key', $ci->enc->decrypt($provider->api_key_encrypted));
    }

    public function testCreateRejectsInvalidInput()
    {
        $ci = new ProvidersFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new ProviderSyncService();

        $this->assertFalse($svc->create_provider(array('name'=>'','api_url'=>'','api_key'=>''))['ok']);
        $this->assertFalse($svc->create_provider(array('name'=>'X','api_url'=>'not-a-url','api_key'=>'k'))['ok']);
        $this->assertFalse($svc->create_provider(array('name'=>'X','api_url'=>'https://x','api_key'=>'k','timeout_ms'=>'500'))['ok']);
        $this->assertFalse($svc->create_provider(array('name'=>'X','api_url'=>'https://x','api_key'=>'k','rate_multiplier'=>'0'))['ok']);
    }

    public function testNoSecretsLeakInViews()
    {
        foreach (array(
            self::$root.'/application/views/admin/providers/index.php',
            self::$root.'/application/views/admin/providers/detail.php',
        ) as $file) {
            $src = file_get_contents($file);
            // Stored encrypted key / decrypt must never be used in a view.
            $this->assertStringNotContainsString('api_key_encrypted', $src, basename($file));
            $this->assertStringNotContainsString('->decrypt', $src, basename($file));
            // The list/detail must not echo a provider's key value.
            $this->assertStringNotContainsString('$p->api_key', $src, basename($file));
            $this->assertStringNotContainsString('$provider->api_key', $src, basename($file));
        }
    }

    public function testSecureHttpClientNeverDisablesVerification()
    {
        $src = file_get_contents(self::$root.'/application/libraries/SecureHttpClient.php');
        $this->assertStringContainsString('CURLOPT_SSL_VERIFYPEER', $src);
        $this->assertStringContainsString('TRUE', $src);
        // No insecure override in provider code paths.
        $this->assertStringNotContainsString('VERIFYPEER, false', $src);
        $this->assertStringNotContainsString('VERIFYPEER,FALSE', $src);
        $this->assertStringNotContainsString('VERIFYHOST, 0', $src);
    }

    public function testModelUpsertsAreIdempotent()
    {
        // Provider_service_model::upsert_service must insert once then update.
        $src = file_get_contents(self::$root.'/application/models/Provider_service_model.php');
        $this->assertStringContainsString('function upsert_service', $src);
        $this->assertStringContainsString('find_provider_service', $src);
        $this->assertSame(1, preg_match('/INSERT INTO/i', $src));
    }

    /* ------------- mock adapters are dev/test/demo only -------------- */

    public function testMockAdapterIsRefusedInProduction()
    {
        $manager = $this->providerManager();
        $provider = (object)array('api_type' => 'MOCK');
        foreach (array('production', 'prod') as $env) {
            putenv('CI_ENV='.$env);
            try {
                $manager->adapter($provider, 'SMM');
                $this->fail('mock adapter constructed under CI_ENV='.$env);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('disabled in production', $e->getMessage());
            } finally {
                putenv('CI_ENV');
            }
        }
    }

    public function testMockAdapterGuardFallsBackToAppEnv()
    {
        putenv('CI_ENV'); putenv('APP_ENV=production');
        try {
            Provider_manager::assert_mock_allowed('MOCK');
            $this->fail('APP_ENV=production must also block mock adapters');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('disabled in production', $e->getMessage());
        } finally {
            putenv('CI_ENV'); putenv('APP_ENV');
        }
    }

    public function testCiEnvWinsOverAppEnvForTheMockGuard()
    {
        // A stray APP_ENV must never talk a production kernel into a mock.
        putenv('CI_ENV=production'); putenv('APP_ENV=development');
        try {
            $this->expectException(RuntimeException::class);
            Provider_manager::assert_mock_allowed('MOCK');
        } finally {
            putenv('CI_ENV'); putenv('APP_ENV');
        }
    }

    public function testMockAdaptersRemainAvailableOutsideProduction()
    {
        $manager = $this->providerManager();
        $provider = (object)array('api_type' => 'MOCK');
        foreach (array('development', 'testing', 'demo') as $env) {
            putenv('CI_ENV'); putenv('APP_ENV='.$env);
            try {
                $this->assertInstanceOf('MockProviderAdapter', $manager->adapter($provider, 'SMM'));
            } finally {
                putenv('CI_ENV'); putenv('APP_ENV');
            }
        }
    }

    private function providerManager()
    {
        require_once self::$root.'/application/libraries/Provider_manager.php';
        $GLOBALS['__fake_ci'] = new ProvidersFakeCI();
        return new Provider_manager();
    }
}

/* -------------------------------- doubles ------------------------------- */

#[AllowDynamicProperties]
class ProvidersFakeCI {
    public $db;
    public $load;
    public $enc;
    public $input;
    public $auth;
    public $Provider_model;
    public $Audit_log_model;
    public $request_id = 'test';
    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        $this->db = new ProvidersFakeDb();
        // CI3 exposes a loaded library under its lower-cased class name;
        // ->enc is just a convenience alias for the assertions below.
        $this->encryptionservice = new EncryptionService();
        $this->enc = $this->encryptionservice;
        $this->input = new ProvidersFakeInput();
        $this->auth = new ProvidersFakeAuth();
        $this->Provider_model = new ProvidersFakeProviderModel($this);
        $this->Audit_log_model = new ProvidersFakeAuditModel();
        $this->load = new ProvidersFakeLoader($this);
    }
}
class ProvidersFakeProviderModel {
    private $ci;
    public $row;
    public function __construct($ci){ $this->ci=$ci; }
    public function create($data){
        $this->row = (object)array_merge(array('id'=>1),$data);
        return $this->row;
    }
    public function record_health(){}
    public function record_sync(){}
}
class ProvidersFakeAuditModel {
    public function record(){}
}
class ProvidersFakeLoader {
    private $ci;
    public function __construct($ci){ $this->ci=$ci; }
    public function library($n){ return $this; }
    public function model($n){ return $this; }
}
class ProvidersFakeInput {
    public function ip_address(){ return '127.0.0.1'; }
    public function user_agent(){ return 'PHPUnit'; }
}
class ProvidersFakeAuth {
    public function id(){ return 1; }
}
class ProvidersFakeDb {
    public $inserted = array();
    public function where($k,$v=null){ return $this; }
    public function order_by($k,$d='ASC'){ return $this; }
    public function limit($l,$o=0){ return $this; }
    public function trans_start(){}
    public function trans_complete(){}
    public function insert($t,$d){ $this->inserted[$t][]=$d; return true; }
    public function insert_id(){ return 1; }
    public function update($t,$d){ return true; }
    public function get($t=null){ return new ProvidersFakeResult($this->rowFor($t)); }
    private function rowFor($t){
        if ($t==='providers') return (object)array(
            'id'=>1,'public_id'=>'01PROVIDER','name'=>'Acme','api_url'=>'https://x',
            'api_key_encrypted'=>'enc','api_type'=>'STANDARD_SMM','status'=>'ACTIVE',
            'currency'=>'NGN','balance'=>null,'timeout_ms'=>15000,'sync_interval_minutes'=>60,
            'rate_multiplier'=>'1.00000000','markup'=>'0.00000000',
        );
        return null;
    }
}
class ProvidersFakeResult {
    private $row;
    public function __construct($row){ $this->row=$row; }
    public function row(){ return $this->row; }
    public function result(){ return $this->row ? array($this->row) : array(); }
}
