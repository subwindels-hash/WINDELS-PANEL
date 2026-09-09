<?php
use PHPUnit\Framework\TestCase;

/**
 * Reseller API guarantees that cannot be seen from a single HTTP call.
 *
 * Two of them mattered enough to change code:
 *
 *   - the API answered every request with an EMPTY body. CodeIgniter only
 *     flushes its output buffer during teardown, and every response in this
 *     controller ends in `exit`, so clients received a bare status code with
 *     `text/html` and nothing to parse — including every authentication error;
 *   - authentication itself was not rate limited. Counting started only after
 *     a key had been accepted, so API keys could be guessed as fast as the
 *     server would answer.
 *
 * The rate limiter's own storage is also pinned here: counters used to live in
 * the system temp directory, which on shared hosting is neither private nor
 * durable.
 */
class ApiContractTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/libraries/ApiRateLimiter.php';
        require_once self::$root.'/application/libraries/ApiKeyPolicy.php';
    }

    private function limiter($dir = null)
    {
        $GLOBALS['__fake_ci'] = new RlFakeCI($dir);
        return new ApiRateLimiter();
    }

    /* ---------------------------- the envelope --------------------------- */

    public function testEveryApiResponseIsActuallyWritten()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');

        // The bug: set_output() followed by exit, with nothing to flush it.
        $this->assertStringContainsString('->_display()', $src,
            'responses must be flushed explicitly — exit skips CodeIgniter\'s teardown');
        $this->assertMatchesRegularExpression('~respond_json\([\s\S]{0,400}set_content_type\(\'application/json\'\)~', $src,
            'the JSON content type must be set on the response that is actually sent');
        $this->assertSame(2, substr_count($src, '$this->respond_json('),
            'ok() and fail() must both go through the one place that flushes');
    }

    public function testTheServicesQueryDoesNotDuplicateItsFromClause()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        // count_all_results($table, false) already registers FROM; naming the
        // table again in get() cross-joined services with itself and made the
        // endpoint a 500 on every call.
        $this->assertDoesNotMatchRegularExpression(
            "~count_all_results\('services', false\);[\s\S]{0,400}->get\('services'\)~", $src);
    }

    /* --------------------------- brute force ----------------------------- */

    public function testFailedAuthenticationIsCountedBeforeAKeyIsAccepted()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');

        $guard = strpos($src, 'guard_auth_attempts();');
        $auth  = strpos($src, 'apiauthenticator->authenticate()');
        $this->assertNotFalse($guard, 'unauthenticated attempts must be guarded');
        $this->assertLessThan($auth, $guard, 'the guard has to run before authentication, not after');
        $this->assertStringContainsString('record_auth_failure();', $src,
            'a failed authentication must be counted');
        // peek(), not check(): a refusal that also increments would extend its
        // own lockout for as long as the client keeps knocking.
        $this->assertMatchesRegularExpression('~guard_auth_attempts\(\)[\s\S]{0,600}->peek\(~', $src);
    }

    public function testTheOrderRateLimitIsActuallyApplied()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringContainsString("api_orders", $src,
            'rate_limits.api_orders was configured and read by nothing');
        $this->assertSame(2, substr_count($src, 'enforce_order_rate_limit();'),
            'both order-creating endpoints must take the write limit');

        $config = file_get_contents(self::$root.'/application/config/marvy.php');
        $this->assertStringContainsString("'api_auth'", $config,
            'the authentication limit needs a documented, tunable value');
    }

    /* ---------------------------- the limiter ---------------------------- */

    public function testCountersLandInThePanelsOwnPrivateDirectory()
    {
        $dir = sys_get_temp_dir().'/marvy-rl-test-'.uniqid();
        mkdir($dir, 0700, true);
        $limiter = $this->limiter($dir);

        $limiter->check('key:1', 5, 60);
        $files = array_values(array_diff(scandir($dir), array('.', '..')));
        $this->assertNotEmpty($files, 'the configured ratelimit path must be used, not the system temp dir');

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function testALimitIsEnforcedAndReportsWhenToRetry()
    {
        $dir = sys_get_temp_dir().'/marvy-rl-test-'.uniqid();
        mkdir($dir, 0700, true);
        $limiter = $this->limiter($dir);

        for ($i = 1; $i <= 3; $i++) {
            $r = $limiter->check('key:limit', 3, 60);
            $this->assertTrue($r['allowed'], 'request '.$i.' is within the limit');
            $this->assertSame(3 - $i, $r['remaining']);
        }
        $r = $limiter->check('key:limit', 3, 60);
        $this->assertFalse($r['allowed']);
        $this->assertGreaterThan(0, $r['retry_after'], 'a refusal must say when to come back');

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function testTheWindowRollsOver()
    {
        $dir = sys_get_temp_dir().'/marvy-rl-test-'.uniqid();
        mkdir($dir, 0700, true);
        $limiter = $this->limiter($dir);

        $limiter->check('key:roll', 1, 60);
        $this->assertFalse($limiter->check('key:roll', 1, 60)['allowed']);
        // A one-second window has already elapsed by the next call.
        sleep(1);
        $this->assertTrue($limiter->check('key:roll', 1, 1)['allowed'],
            'a new window must start clean');

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function testPeekDoesNotConsumeTheAllowance()
    {
        $dir = sys_get_temp_dir().'/marvy-rl-test-'.uniqid();
        mkdir($dir, 0700, true);
        $limiter = $this->limiter($dir);

        for ($i = 0; $i < 5; $i++) $this->assertTrue($limiter->peek('key:peek', 2, 60)['allowed']);
        $limiter->check('key:peek', 2, 60);
        $limiter->check('key:peek', 2, 60);
        $this->assertFalse($limiter->peek('key:peek', 2, 60)['allowed'],
            'peek reads the same counter check() writes');

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function testAnUnwritableCounterDirectoryDoesNotTakeTheApiDown()
    {
        $limiter = $this->limiter('/proc/definitely-not-writable');
        $r = $limiter->check('key:nowhere', 1, 60);
        $this->assertTrue($r['allowed'], 'a limiter that cannot write must fail open, not 500');
    }

    public function testRedisIsUsedWhenConfiguredAndFallsBackWhenItIsNot()
    {
        $src = file_get_contents(self::$root.'/application/libraries/ApiRateLimiter.php');
        $this->assertStringContainsString('Predis\\\\Client', $src,
            'multi-node deployments need a shared counter');
        $this->assertMatchesRegularExpression('~catch \(Throwable \$e\)[\s\S]{0,300}files~', $src,
            'Redis being down must fall back to files, never fail the request');
    }

    /* ------------------------------ scopes ------------------------------- */

    public function testTheScopeCatalogueIsOfferedToCustomersNotOnlyAdmins()
    {
        $view = file_get_contents(self::$root.'/application/views/dashboard/account/api_keys.php');
        $this->assertStringContainsString('access_mode', $view,
            'a customer must be able to create a read-only key');
        $this->assertStringContainsString('scopes[]', $view);

        $controller = file_get_contents(self::$root.'/application/controllers/dashboard/Account.php');
        $this->assertStringContainsString('ApiKeyPolicy::scopes()', $controller,
            'the offered scopes must come from the policy, not a second hardcoded list');
        // Anything not in the catalogue is dropped rather than stored.
        $this->assertStringContainsString('array_intersect', $controller);
    }

    public function testEveryScopeTheApiRequiresExistsInTheCatalogue()
    {
        $api = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        preg_match_all("~require_scope\('([a-z.]+)'~", $api, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $scope) {
            $this->assertArrayHasKey($scope, ApiKeyPolicy::scopes(),
                'the API requires scope '.$scope.' which no key can be granted');
        }
    }
}

/* -------------------------------- doubles -------------------------------- */

#[AllowDynamicProperties]
class RlFakeCI
{
    public $config;
    public function __construct($dir)
    {
        $this->config = new RlFakeConfig($dir);
        // Env::writable_paths() is consulted first; point it at the test dir.
        if ($dir !== null) putenv('VP_RATELIMIT_PATH='.$dir);
    }
}

class RlFakeConfig
{
    private $dir;
    public function __construct($dir) { $this->dir = $dir; }
    public function item($key)
    {
        if ($key === 'redis') return array('enabled' => false);
        return null;
    }
}
