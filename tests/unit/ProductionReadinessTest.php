<?php
use PHPUnit\Framework\TestCase;

/**
 * Session 20 — production readiness (§20).
 *
 * Deployment defects do not announce themselves: a placeholder encryption key
 * or a truthy "false" reads as working software right up until secrets leak.
 * These tests pin the boot-time guarantees, plus the deployment artefacts that
 * a fresh clone needs in order to come up at all.
 */
class ProductionReadinessTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH')) define('APPPATH', self::$root.'/application/');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/Preflight.php';
    }

    protected function tearDown(): void
    {
        foreach (array('ENCRYPTION_KEY', 'HTTP_ALLOW_PRIVATE_HOSTS', 'APP_DEBUG',
                       'MAIL_LOG', 'DEMO_MODE', 'APP_URL', 'DB_PASSWORD',
                       'APP_KEY', 'CI_ENV', 'DB_NAME', 'DB_USER') as $k) {
            putenv($k);
        }
    }

    /* ======================= env boolean handling ======================== */

    /**
     * The bug this exists to prevent: getenv() hands back the *string*
     * "false", and every non-empty string is truthy. `(bool)getenv(...)` on
     * `HTTP_ALLOW_PRIVATE_HOSTS=false` — the value shipped in .env.example —
     * therefore switched SSRF protection off.
     */
    public function testEnvBoolTreatsFalseyStringsAsFalse()
    {
        foreach (array('false', 'FALSE', 'False', '0', 'no', 'off', 'OFF', '') as $raw) {
            putenv('HTTP_ALLOW_PRIVATE_HOSTS='.$raw);
            $this->assertFalse(
                env_bool('HTTP_ALLOW_PRIVATE_HOSTS'),
                sprintf('%s must be false, and is truthy under a plain (bool) cast', var_export($raw, true))
            );
        }
    }

    public function testEnvBoolAcceptsTheUsualTruthySpellings()
    {
        foreach (array('1', 'true', 'TRUE', 'yes', 'on') as $raw) {
            putenv('APP_DEBUG='.$raw);
            $this->assertTrue(env_bool('APP_DEBUG'), var_export($raw, true).' should be true');
        }
    }

    public function testEnvBoolFallsBackToItsDefaultWhenUnset()
    {
        putenv('DEMO_MODE');
        $this->assertFalse(env_bool('DEMO_MODE'));
        $this->assertTrue(env_bool('DEMO_MODE', true));
    }

    public function testEnvStrTrimsAndFallsBack()
    {
        putenv('APP_URL=  https://panel.example.com  ');
        $this->assertSame('https://panel.example.com', env_str('APP_URL'));
        putenv('APP_URL=');
        $this->assertSame('fallback', env_str('APP_URL', 'fallback'));
    }

    /**
     * Source scan: the cast that caused the bug must not come back. Config and
     * libraries should go through env_bool().
     */
    public function testNoBooleanCastsOnGetenvRemain()
    {
        $offenders = array();
        $files = array_merge(
            glob(self::$root.'/application/config/*.php'),
            glob(self::$root.'/application/libraries/*.php'),
            glob(self::$root.'/application/controllers/*.php')
        );
        foreach ($files as $file) {
            foreach (file($file) as $i => $line) {
                // (bool)getenv(...) or a bare getenv() used as a condition.
                if (preg_match('/\(bool\)\s*getenv\s*\(/', $line)
                    || preg_match('/(if|&&|\|\|)\s*\(?\s*getenv\s*\([^)]*\)\s*\)?\s*[){&|?]/', $line)) {
                    $offenders[] = basename($file).':'.($i + 1).' '.trim($line);
                }
            }
        }
        $this->assertSame(array(), $offenders,
            "getenv() returns strings; \"false\" is truthy. Use env_bool():\n"
            .implode("\n", $offenders));
    }

    /* ========================= encryption key ============================ */

    public function testProductionRefusesToBootWithoutAnEncryptionKey()
    {
        putenv('ENCRYPTION_KEY');
        $this->expectException(RuntimeException::class);
        EncryptionService::resolve_key('production');
    }

    public function testProductionRefusesThePlaceholderKeysFromEnvExample()
    {
        foreach (EncryptionService::REJECTED_KEYS as $placeholder) {
            putenv('ENCRYPTION_KEY='.$placeholder);
            try {
                EncryptionService::resolve_key('production');
                $this->fail('Placeholder key accepted in production: '.$placeholder);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ENCRYPTION_KEY', $e->getMessage());
            }
        }
    }

    public function testProductionRefusesAShortKey()
    {
        putenv('ENCRYPTION_KEY=tooshort');
        $this->expectException(RuntimeException::class);
        EncryptionService::resolve_key('production');
    }

    public function testAGenuineKeyIsAcceptedInProduction()
    {
        $key = base64_encode(str_repeat('k', 32));
        putenv('ENCRYPTION_KEY='.$key);
        $this->assertSame($key, EncryptionService::resolve_key('production'));
    }

    /** Local setup must not require ceremony — but the key is labelled. */
    public function testDevelopmentFallsBackToAClearlyLabelledKey()
    {
        putenv('ENCRYPTION_KEY');
        $key = EncryptionService::resolve_key('development');
        $this->assertStringContainsString('insecure', $key);
        $this->assertStringContainsString('development', $key);
    }

    /** The placeholder must not be reachable as real key material anywhere. */
    public function testNoHardcodedEncryptionKeyFallbackRemains()
    {
        $source = file_get_contents(self::$root.'/application/libraries/EncryptionService.php');
        $this->assertStringNotContainsString(
            "getenv('ENCRYPTION_KEY') ?:", $source,
            'A ?: fallback silently encrypts production secrets with a key that is in the repo.'
        );
        $config = file_get_contents(self::$root.'/application/config/config.php');
        $this->assertStringNotContainsString("?: 'change-me", $config);
    }

    public function testEncryptRoundTripsWithTheResolvedKey()
    {
        putenv('ENCRYPTION_KEY='.base64_encode(str_repeat('z', 32)));
        $svc = new EncryptionService();
        $secret = 'provider-api-key-9f3a';
        $cipher = $svc->encrypt($secret);
        $this->assertNotSame($secret, $cipher);
        $this->assertSame($secret, $svc->decrypt($cipher));
    }

    /* ============================ preflight ============================== */

    public function testPreflightFailsProductionOnAPlaceholderKey()
    {
        putenv('ENCRYPTION_KEY=change-me-32-byte-key-replace-in-env');
        $report = $this->preflight()->run('production');
        $check = $this->named($report, 'encryption_key');
        $this->assertSame(Preflight::FAIL, $check['status']);
        $this->assertFalse($report['ok']);
    }

    public function testPreflightOnlyWarnsOutsideProduction()
    {
        putenv('ENCRYPTION_KEY');
        $report = $this->preflight()->run('development');
        $this->assertSame(Preflight::WARN, $this->named($report, 'encryption_key')['status']);
    }

    public function testPreflightRequiresHttpsInProduction()
    {
        putenv('APP_URL=http://panel.example.com');
        $report = $this->preflight()->run('production');
        $check = $this->named($report, 'https');
        $this->assertSame(Preflight::FAIL, $check['status'],
            'Secure session cookies are not sent over http, so logins fail silently.');

        putenv('APP_URL=https://panel.example.com');
        $this->assertSame(Preflight::OK,
            $this->named($this->preflight()->run('production'), 'https')['status']);
    }

    public function testPreflightRejectsTheDefaultDatabasePasswordInProduction()
    {
        putenv('DB_PASSWORD=windels_secret');
        $this->assertSame(Preflight::FAIL,
            $this->named($this->preflight()->run('production'), 'db_password')['status']);

        // The same value is unremarkable locally.
        $this->assertSame(Preflight::OK,
            $this->named($this->preflight()->run('development'), 'db_password')['status']);
    }

    public function testPreflightFlagsDebugAndDemoModeInProduction()
    {
        putenv('APP_DEBUG=true');
        putenv('DEMO_MODE=true');
        $report = $this->preflight()->run('production');
        $this->assertSame(Preflight::WARN, $this->named($report, 'debug')['status']);
        $this->assertSame(Preflight::WARN, $this->named($report, 'demo_mode')['status']);
    }

    /* ------------ preflight: the checks added with the 30.x hardening ---- */

    public function testPreflightFailsProductionWhenTheDatabaseDoesNotAnswer()
    {
        // No DB handle at all: a production release must refuse to fly.
        $this->assertSame(Preflight::FAIL,
            $this->named($this->preflight()->run('production'), 'db_connectivity')['status']);
        // A handle that errors is the same story.
        $pre = $this->preflight_with(array(
            'query' => function () { throw new Exception('gone away'); },
        ));
        $this->assertSame(Preflight::FAIL,
            $this->named($pre->run('production'), 'db_connectivity')['status']);
        // One that answers SELECT 1 is all we ask.
        $fine = $this->preflight_with(array('query' => function () { return true; }));
        $check = $this->named($fine->run('production'), 'db_connectivity');
        $this->assertSame(Preflight::OK, $check['status']);
        $this->assertSame('SELECT 1 answered', $check['detail']);
    }

    public function testPreflightFailsProductionOnInsecureSessionCookies()
    {
        $pre = $this->preflight_with(array(), array(
            'cookie_httponly' => false, 'cookie_secure' => false, 'cookie_samesite' => 'None',
        ));
        $check = $this->named($pre->run('production'), 'secure_cookies');
        $this->assertSame(Preflight::FAIL, $check['status']);
        $this->assertStringContainsString('cookie_httponly', $check['detail']);

        $good = $this->preflight_with(array(), array(
            'cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Lax',
        ));
        $this->assertSame(Preflight::OK,
            $this->named($good->run('production'), 'secure_cookies')['status']);
    }

    /**
     * An ACTIVE provider row pointing at a MOCK adapter would "fulfil" paid
     * orders without paying any upstream. Deploy must refuse that state in
     * production (the runtime backstop is Provider_manager::assert_mock_allowed).
     */
    public function testPreflightFailsProductionWhenAMockProviderIsActive()
    {
        $counting = array(
            'table_exists' => function ($table) { return true; },
            'query' => function ($sql) {
                if (strpos($sql, "MOCK%") !== false) {
                    return new class { public function row() { return (object)array('n' => 2); } };
                }
                return true; // SELECT 1 connectivity probe
            },
        );
        $check = $this->named($this->preflight_with($counting)->run('production'), 'mock_providers');
        $this->assertSame(Preflight::FAIL, $check['status']);
        $this->assertStringContainsString('2 active', $check['detail']);
    }

    public function testPreflightPassesProductionWithoutActiveMockProviders()
    {
        $counting = array(
            'table_exists' => function ($table) { return true; },
            'query' => function ($sql) {
                if (strpos($sql, "MOCK%") !== false) {
                    return new class { public function row() { return (object)array('n' => 0); } };
                }
                return true;
            },
        );
        $this->assertSame(Preflight::OK,
            $this->named($this->preflight_with($counting)->run('production'), 'mock_providers')['status']);
    }

    public function testPreflightAuditsMockProvidersOnlyInProduction()
    {
        // Outside production the check is a no-op — the demo seed ships an
        // inactive mock provider and must not break a developer's deploy gate.
        $check = $this->named($this->preflight()->run('development'), 'mock_providers');
        $this->assertSame(Preflight::OK, $check['status']);
    }

    public function testPreflightRequiresProductionSecrets()
    {
        putenv('APP_KEY'); putenv('ENCRYPTION_KEY');
        putenv('DB_NAME'); putenv('DB_USER');
        $check = $this->named($this->preflight()->run('production'), 'required_secrets');
        $this->assertSame(Preflight::FAIL, $check['status']);
        $this->assertStringContainsString('APP_KEY', $check['detail']);
        $this->assertStringContainsString('DB_NAME', $check['detail']);

        putenv('APP_KEY=A'.str_repeat('b', 31));
        putenv('DB_NAME=windels'); putenv('DB_USER=windels');
        $this->assertSame(Preflight::OK,
            $this->named($this->preflight()->run('production'), 'required_secrets')['status']);
    }

    public function testPreflightFlagsEnvironmentDisagreement()
    {
        putenv('CI_ENV=production'); putenv('APP_ENV=development');
        $this->assertSame(Preflight::WARN,
            $this->named($this->preflight()->run('production'), 'environment_consistency')['status']);
        putenv('CI_ENV=production'); putenv('APP_ENV=production');
        $this->assertSame(Preflight::OK,
            $this->named($this->preflight()->run('production'), 'environment_consistency')['status']);
    }

    public function testPreflightChecksTheRuntimeDirectories()
    {
        // The list comes from Env, which is also what the application writes
        // through and what the deployment package pre-creates — one source of
        // truth instead of a preflight-only copy that can drift.
        require_once self::$root.'/application/core/Env.php';
        $report = $this->preflight()->run('production');
        foreach (array_keys(Env::writable_report()) as $name) {
            $check = $this->named($report, 'writable:'.$name);
            $this->assertSame(Preflight::OK, $check['status'],
                $name.' must exist and be writable in a fresh clone: '.$check['detail']);
        }
    }

    /** A FAIL has to be actionable, or nobody can fix it at 3am. */
    public function testEveryFailingCheckCarriesAHint()
    {
        putenv('ENCRYPTION_KEY');
        putenv('APP_URL=http://x.test');
        putenv('DB_PASSWORD=root');
        foreach ($this->preflight()->run('production')['results'] as $r) {
            if ($r['status'] === Preflight::FAIL && strpos($r['name'], 'ext:') !== 0) {
                $this->assertNotEmpty($r['hint'], $r['name'].' fails without telling anyone what to do');
            }
        }
    }

    /* ======================= deployment artefacts ======================== */

    public function testRuntimeDirectoriesArePresentInTheRepository()
    {
        // Gitignored contents, tracked directories: CI3 drops log lines
        // silently when storage/logs does not exist.
        foreach (array('storage/logs', 'storage/cache/sessions') as $rel) {
            $this->assertFileExists(self::$root.'/'.$rel.'/.gitignore',
                $rel.' must survive a fresh clone');
        }
    }

    /** docker-compose mounted this file; it did not exist, so mysql failed. */
    public function testComposeMountsThatMustExistDo()
    {
        $compose = file_get_contents(self::$root.'/docker-compose.yml');
        preg_match_all('#\./([A-Za-z0-9_./-]+):/[^:\s]+#', $compose, $m);
        foreach (array_unique($m[1]) as $rel) {
            if ($rel === '' || $rel === '/') continue;
            $this->assertTrue(
                file_exists(self::$root.'/'.$rel),
                'docker-compose.yml mounts ./'.$rel.', which does not exist'
            );
        }
    }

    /** Ten documented cron jobs and nothing in the stack running them. */
    public function testTheComposeStackRunsTheCronJobs()
    {
        $compose = file_get_contents(self::$root.'/docker-compose.yml');
        $this->assertMatchesRegularExpression('/^\s{2}cron:/m', $compose,
            'No worker container: drip-feed, payouts and the email queue never run.');
        $this->assertStringContainsString('crontab.example', $compose);
    }

    public function testEveryCronJobIsScheduled()
    {
        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        require_once self::$root.'/application/config/windels.php';
        // windels.php populates $config; re-read it directly for the job list.
        $jobs = array('dripfeed', 'order_status', 'subscriptions', 'provider_health',
                      'refill_status', 'payment_reconciliation', 'email_queue',
                      'analytics', 'provider_sync', 'affiliate_payouts');
        foreach ($jobs as $job) {
            $this->assertStringContainsString('cron '.$job, $crontab,
                $job.' has no crontab entry');
        }
    }

    /** Web-triggered migrations are forbidden (§66). */
    public function testDeploymentControllersAreCliOnly()
    {
        foreach (array('Deploy', 'Migrate', 'Seed', 'Cron') as $name) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$name.'.php');
            $this->assertMatchesRegularExpression(
                '/extends\s+Cron_Controller/', $src,
                $name.' must extend Cron_Controller, which enforces the CLI guard'
            );
        }
    }

    /* =========================== health probes =========================== */

    public function testLivenessDoesNotTouchDependencies()
    {
        $src = $this->healthMethod('live');
        foreach (array('$this->db', 'load->database', 'new Redis') as $needle) {
            $this->assertStringNotContainsString($needle, $src,
                'Liveness must not check dependencies, or a database blip becomes a restart loop.');
        }
    }

    public function testReadinessActuallyConnectsToRedis()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Health.php');
        $this->assertStringContainsString('new Redis()', $src,
            'Readiness previously reported ok merely because the config file loaded.');
        $this->assertStringContainsString('ping', $src);
    }

    public function testReadinessVerifiesTheSchemaVersion()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Health.php');
        $this->assertStringContainsString('migration_version', $src,
            'An un-migrated instance must not be sent traffic.');
    }

    /** Unauthenticated endpoint: exception text must not reach the client. */
    public function testReadinessDoesNotLeakErrorDetail()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Health.php');
        $this->assertStringNotContainsString("'fail: '.\$e->getMessage()", $src);
        $this->assertStringContainsString('log_message', $src);
    }

    /* ============================== helpers ============================== */

    private function preflight()
    {
        return new Preflight(array('root' => self::$root));
    }

    /**
     * A preflight wired to a fake CI carrying only the members a check uses:
     * $db behaviours (method name => closure) and config items (key => value).
     */
    private function preflight_with(array $db = array(), array $config = array())
    {
        $pre = new Preflight(array('root' => self::$root));
        $fake = new class($db, $config) {
            public $db, $config;
            public function __construct(array $db, array $config) {
                $behaviours = $db;
                $this->db = new class($behaviours) {
                    private $behaviours;
                    public function __construct(array $behaviours) { $this->behaviours = $behaviours; }
                    public function query() {
                        $a = func_get_args();
                        return call_user_func_array($this->behaviours['query'], $a);
                    }
                    // Schema-version check touchpoints: pretend the migrations
                    // table does not exist yet (a WARN/FAIL outcome either way),
                    // so preflight runs independent of a real database — unless a
                    // test supplies its own table_exists behaviour.
                    public function table_exists($table) {
                        return array_key_exists('table_exists', $this->behaviours)
                            ? (bool) call_user_func($this->behaviours['table_exists'], $table)
                            : false;
                    }
                    public function get($table) {
                        return new class { public function row() { return null; } };
                    }
                };
                $items = $config;
                $this->config = new class($items) {
                    private $items;
                    public function __construct(array $items) { $this->items = $items; }
                    public function item($key) { return $this->items[$key] ?? null; }
                };
            }
        };
        // Preflight caches get_instance() in a private property; swap it.
        $ref = new ReflectionProperty(Preflight::class, 'ci');
        $ref->setAccessible(true);
        $ref->setValue($pre, $fake);
        return $pre;
    }

    private function named(array $report, $name)
    {
        foreach ($report['results'] as $r) {
            if ($r['name'] === $name) return $r;
        }
        $this->fail('No preflight check named '.$name);
    }

    private function healthMethod($method)
    {
        $src = file_get_contents(self::$root.'/application/controllers/Health.php');
        if (!preg_match('/function\s+'.$method.'\s*\([^)]*\)\s*\{(.*?)\n    \}/s', $src, $m)) {
            $this->fail('Could not find Health::'.$method.'()');
        }
        return $m[1];
    }
}
