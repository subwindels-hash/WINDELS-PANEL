<?php
use PHPUnit\Framework\TestCase;

/**
 * Runtime contract tests.
 *
 * Every check here corresponds to a bug that shipped and was only found by
 * actually booting the app against MySQL. The existing suite was green while
 * all of them were present, because each one lives in the gap between "the
 * file parses" and "CodeIgniter executes it": a call to a framework method
 * that does not exist, a property read that PHP forbids at runtime, a config
 * key that is absent until its file is loaded. Static assertions about source
 * text cannot see any of that, so these tests encode the runtime rules
 * directly.
 */
class RuntimeContractTest extends TestCase
{
    private static $root;
    private static $ci_loader_methods = array(
        // CI_Loader's public API (CodeIgniter 3.1.13). Views run with $this
        // bound to the loader, so a view may only call these on $this.
        'library', 'driver', 'model', 'database', 'dbforge', 'dbutil',
        'view', 'vars', 'clear_vars', 'get_var', 'get_vars', 'file',
        'helper', 'helpers', 'language', 'config', 'is_loaded',
        'add_package_path', 'get_package_paths', 'remove_package_path',
        'initialize',
    );

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    private function views()
    {
        $out = array();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application/views')
        );
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Inside a view, $this is CI_Loader. CI_Loader defines no __get, so
     * $this->Some_model is null and calling a method on it is a fatal error.
     * This shipped in layouts/public.php and took down every public page.
     */
    public function testViewsDoNotResolveModelsThroughThis()
    {
        foreach ($this->views() as $file) {
            $src = file_get_contents($file);
            // $this->Foo_model / $this->Foo_Model — model-ish property reads.
            $this->assertSame(
                0,
                preg_match('/\$this->[A-Z][A-Za-z0-9]*_model\b/', $src),
                basename($file).': $this is CI_Loader in a view and has no __get,'
                .' so $this->Xxx_model is null. Use $CI =& get_instance().'
            );
        }
    }

    /**
     * CI_Loader copies controller properties into view scope with
     * get_object_vars() from outside the class, which sees public properties
     * only. MY_Controller::$auth is protected, so $this->auth in a view is
     * always undefined — it shipped that way in faq.php and services/detail.php.
     * The app already shares $current_user with every view for this purpose.
     */
    public function testViewsDoNotUseProtectedControllerProperties()
    {
        $protected = $this->protectedControllerProperties();
        $this->assertNotEmpty($protected, 'expected to find protected props on MY_Controller');

        foreach ($this->views() as $file) {
            $src = file_get_contents($file);
            foreach ($protected as $prop) {
                $this->assertSame(
                    0,
                    preg_match('/\$this->'.preg_quote($prop, '/').'\b/', $src),
                    basename($file).': $'.$prop.' is protected on MY_Controller,'
                    .' so CI_Loader never copies it into view scope.'
                );
            }
        }
    }

    /** Protected property names declared on the base controllers. */
    private function protectedControllerProperties()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        preg_match_all('/^\s*protected\s+\$([a-zA-Z_][a-zA-Z0-9_]*)\s*;/m', $src, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * property_exists() returns TRUE for protected properties, but reading one
     * from outside the class raises an Error. Libraries reached for
     * $this->ci->request_id behind exactly that guard, so the guard passed and
     * the read threw. AuthService swallowed it as a 500 on successful logins;
     * ProviderSyncService had no guard at all.
     */
    public function testLibrariesDoNotReadProtectedControllerPropertiesOffCi()
    {
        $protected = $this->protectedControllerProperties();
        foreach (glob(self::$root.'/application/libraries/*.php') as $file) {
            $src = file_get_contents($file);
            foreach ($protected as $prop) {
                $this->assertSame(
                    0,
                    preg_match('/\$this->ci->'.preg_quote($prop, '/').'\b(?!\s*\()/', $src),
                    basename($file).': $'.$prop.' is protected on the controller;'
                    .' reading it off $this->ci raises an Error at runtime.'
                    .' Use a public accessor.'
                );
            }
        }
    }

    /**
     * Audit logging is explicitly "must never break the request". catch
     * (Exception) does not catch Error, which is what a bad property read
     * throws, so the safety net had a hole in it.
     */
    public function testAuditFailuresCannotEscapeAsErrors()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        $pos = strpos($src, 'private function audit(');
        $this->assertNotFalse($pos, 'AuthService::audit() not found');
        $body = substr($src, $pos, 2000);
        $this->assertStringContainsString(
            'catch (Throwable',
            $body,
            'audit() must catch Throwable: an Error here 500s a request whose'
            .' real work already succeeded.'
        );
    }

    /**
     * Views and controllers may only call methods CI_Loader actually defines
     * on $this. Home::index() called $this->load->views_exist(), which does
     * not exist in CodeIgniter — a guaranteed fatal on the site's front page.
     */
    public function testCodeOnlyCallsRealLoaderMethods()
    {
        $files = array_merge($this->views(), $this->phpFiles('/application/controllers'));
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if (!preg_match_all('/\$this->load->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $method) {
                $this->assertContains(
                    $method,
                    self::$ci_loader_methods,
                    basename($file).': CI_Loader has no method '.$method.'()'
                );
            }
        }
    }

    private function phpFiles($rel)
    {
        $out = array();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$root.$rel));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /**
     * migration.php is not in the autoloaded config set, so its keys are absent
     * from the registry until something loads it. Health::check_schema() read
     * migration_version cold, got NULL, and compared it against the recorded
     * version — so readiness could never return ok and every deploy would be
     * held out of the load balancer.
     */
    public function testHealthLoadsMigrationConfigBeforeReadingIt()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Health.php');
        $pos = strpos($src, 'function check_schema(');
        $this->assertNotFalse($pos, 'Health::check_schema() not found');
        $body = substr($src, $pos, 1500);

        $this->assertStringContainsString(
            "config->load('migration'",
            $body,
            'check_schema() must load migration.php: its keys are not in the'
            .' registry by default, so the expected version reads NULL.'
        );
        $this->assertSame(
            0,
            preg_match("/config->item\(\s*'migration_version'\s*\)/", $body),
            'migration_version is not a top-level config item; read it from the'
            .' loaded migration array.'
        );
    }

    /**
     * A readiness probe that cannot distinguish "no version configured" from
     * "version matches" would report ready on a blank database.
     */
    public function testHealthTreatsMissingMigrationVersionAsNotReady()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Health.php');
        $pos = strpos($src, 'function check_schema(');
        $body = substr($src, $pos, 1500);
        $this->assertMatchesRegularExpression(
            '/\$expected\s*===\s*0.*return\s+\'fail\'/s',
            $body,
            'check_schema() must fail when no expected version is configured.'
        );
    }

    /**
     * The config files the app relies on at boot must parse and define what
     * the code reads back. migration_version drives both the runner and the
     * readiness probe.
     */
    public function testMigrationConfigDeclaresAVersionMatchingTheMigrationFiles()
    {
        $config = array();
        $path = self::$root.'/application/config/migration.php';
        $src = file_get_contents($path);
        $this->assertNotFalse(strpos($src, "\$config['migration_version']"), 'no migration_version set');

        preg_match("/\\\$config\['migration_version'\]\s*=\s*(\d+)/", $src, $m);
        $this->assertNotEmpty($m, 'migration_version must be an integer literal');
        $version = (int) $m[1];

        $files = glob(self::$root.'/application/migrations/*.php');
        $highest = 0;
        foreach ($files as $f) {
            if (preg_match('/^(\d+)_/', basename($f), $mm)) {
                $highest = max($highest, (int) $mm[1]);
            }
        }
        $this->assertSame(
            $highest,
            $version,
            'migration_version must match the highest migration file, or the'
            .' readiness probe reports a healthy instance as unready.'
        );
    }

    /**
     * Session 32 removed the last scaffold: views/dashboard/placeholder.php
     * survived the era when dashboard screens were stubbed ("ships fully in
     * Session N") but no controller routed to it any more — dead weight a
     * future regression could silently revive. Every routed screen renders a
     * real view now, and DashboardTest proves routes resolve to real methods;
     * this pins the scaffold itself as gone and unreachable.
     */
    public function testNoScaffoldPlaceholderViewRemains()
    {
        $this->assertFileDoesNotExist(
            self::$root.'/application/views/dashboard/placeholder.php',
            'the "screen is scaffolded" placeholder view must stay deleted'
        );
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application/controllers')
        );
        foreach ($it as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') continue;
            $src = file_get_contents($f->getPathname());
            $this->assertSame(
                0,
                preg_match("/->view\\(\\s*['\"](?:dashboard\\/)?placeholder['\"]/", $src),
                $f->getFilename().' renders the removed scaffold placeholder view'
            );
        }
    }
}
