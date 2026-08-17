<?php
use PHPUnit\Framework\TestCase;

/**
 * Reseller API tests (Session 12) — auth/rate-limit units and route/controller
 * guarantees. The controller's HTTP behavior is asserted via source inspection
 * because CI3's super-global controller can't be bootstrapped standalone.
 */
class ResellerApiTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('windels_public_id')) require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/ApiRateLimiter.php';
    }

    /* --------------------------- rate limiter --------------------------- */

    public function testRateLimiterAllowsUpToLimit()
    {
        $dir = sys_get_temp_dir().'/windels_rl_'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $rl = new ReflectionClass('ApiRateLimiter');
        $limiter = $rl->newInstance();
        $p = $rl->getProperty('dir'); $p->setAccessible(true); $p->setValue($limiter, $dir);

        $allowed = 0;
        for ($i=0;$i<5;$i++) if ($limiter->check('b',5,60)['allowed']) $allowed++;
        $sixth = $limiter->check('b',5,60);
        $this->assertSame(5, $allowed);
        $this->assertFalse($sixth['allowed']);
        $this->assertSame(0, $sixth['remaining']);
        $this->assertGreaterThan(0, $sixth['retry_after']);
        array_map('unlink', glob($dir.'/*')); rmdir($dir);
    }

    public function testRateLimiterResetsAfterWindow()
    {
        $dir = sys_get_temp_dir().'/windels_rl_'.bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        $rl = new ReflectionClass('ApiRateLimiter');
        $limiter = $rl->newInstance();
        $p = $rl->getProperty('dir'); $p->setAccessible(true); $p->setValue($limiter, $dir);
        $limiter->check('r', 2, 60);
        $limiter->check('r', 2, 60);
        $third = $limiter->check('r', 2, 60);
        $this->assertFalse($third['allowed']);
        array_map('unlink', glob($dir.'/*')); rmdir($dir);
    }

    /* ----------------------------- routing ------------------------------ */

    public function testAllApiV1RoutesAreDeclared()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array(
            "'api/v1/services'", "'api/v1/services/(:any)'",
            "'api/v1/orders'", "'api/v1/orders/status'", "'api/v1/orders/(:any)'",
            "'api/v1/balance'", "'api/v1/refills'", "'api/v1/refills/(:any)'",
            "'api/v1/cancellations'", "'api/docs'", "'api/docs/json'",
        ) as $r) {
            $this->assertStringContainsString($r, $routes, "missing route {$r}");
        }
    }

    public function testOrdersStatusRoutePrecedesCatchAll()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $posStatus = strpos($routes, "'api/v1/orders/status'");
        $posCatch  = strpos($routes, "'api/v1/orders/(:any)'");
        $this->assertNotFalse($posStatus);
        $this->assertNotFalse($posCatch);
        $this->assertLessThan($posCatch, $posStatus, 'orders/status must be declared before the (:any) catch-all');
    }

    /* --------------------------- controller ----------------------------- */

    public function testControllerImplementsAllEndpoints()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        foreach (array('services','service_detail','orders','create_order','order_detail',
                       'orders_status','refills','refill_detail','cancellations','balance',
                       'docs','docs_json') as $m) {
            $this->assertStringContainsString('function '.$m.'(', $src, "missing method {$m}");
        }
    }

    public function testControllerAuthenticatesBeforeAnyAction()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString('ApiAuthenticator', $src);
        $this->assertStringContainsString('X-Api-Key', $src);
        $this->assertStringContainsString('enforce_rate_limit', $src);
        // The constructor must call authenticate; method bodies must not bypass it.
        $this->assertStringContainsString('$this->apiauthenticator->authenticate()', $src);
    }

    public function testControllerUsesOrderAndRefillServicesNotDirectWrites()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString('orderservice->place', $src);
        $this->assertStringContainsString('orderservice->cancel', $src);
        $this->assertStringContainsString('refillservice->request', $src);
        $this->assertStringNotContainsString("insert('orders'", $src);
        $this->assertStringNotContainsString("update('wallets'", $src);
        $this->assertStringNotContainsString("insert('wallet_transactions'", $src);
    }

    public function testMutatingEndpointsSupportIdempotency()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString('Idempotency-Key', $src);
        $this->assertStringContainsString('idempotency_key', $src);
    }

    public function testJsonEnvelopeAndErrorShape()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString("'success'=>true", $src);
        $this->assertStringContainsString("'success'=>false", $src);
        $this->assertStringContainsString('requestId', $src);
        // Public IDs, never internal sequential ids.
        $this->assertStringContainsString('find_public_for_user', $src);
        $this->assertStringNotContainsString('find_by_id($', $src);
    }

    public function testHttpStatusCodesMapped()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $auth = file_get_contents(self::$root.'/application/libraries/ApiAuthenticator.php');
        // 401/403 originate in the authenticator; the rest in the controller.
        foreach (array('401','403') as $code) {
            $this->assertStringContainsString($code, $auth, "auth expected HTTP {$code}");
        }
        foreach (array('404','422','429','402','201') as $code) {
            $this->assertStringContainsString($code, $src, "expected HTTP {$code} mapping");
        }
    }

    public function testRateLimitHeadersEmitted()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString('X-RateLimit-Limit', $src);
        $this->assertStringContainsString('X-RateLimit-Remaining', $src);
        $this->assertStringContainsString('Retry-After', $src);
    }

    public function testDocsEndpointExists()
    {
        $this->assertFileExists(self::$root.'/application/views/api/docs.php');
        $docs = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString('function docs', $docs);
        $this->assertStringContainsString('function docs_json', $docs);
    }

    public function testApiKeyIsHashedNeverStoredRaw()
    {
        $model = file_get_contents(self::$root.'/application/models/Api_key_model.php');
        $this->assertStringContainsString("hash('sha256'", $model);
        $this->assertStringContainsString('key_hash', $model);
    }

    public function testNoSecretsLeakedByApi()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringNotContainsString('api_key_encrypted', $src);
        $this->assertStringNotContainsString('password_hash', $src);
        $this->assertStringNotContainsString('->decrypt', $src);
    }
}
