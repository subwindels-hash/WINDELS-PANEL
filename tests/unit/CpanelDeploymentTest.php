<?php
use PHPUnit\Framework\TestCase;

/**
 * Portable cPanel deployment (no terminal, no Composer, no CLI installer).
 *
 * The contract these tests defend is one sentence: **upload the files, create
 * the database, import `database/production.sql`, edit `.env`, open the
 * domain.** Everything below is a way that contract has historically been
 * broken —
 *
 *   - a config file reading a value an installer generated rather than `.env`
 *   - a schema change that landed in a migration but never in production.sql,
 *     so a fresh import comes up missing a table
 *   - seed data that only exists in the CLI seeder, so a freshly imported
 *     panel has no roles and nobody can log in
 *   - a runtime directory that only the `deploy storage` command created
 *   - a deployment package that quietly needs `composer install` to boot
 *
 * — and each one is a source/data assertion here, because that is what catches
 * it being reintroduced.
 */
class CpanelDeploymentTest extends TestCase
{
    private static $root;
    private static $sql;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('APPPATH'))  define('APPPATH', self::$root.'/application/');
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        require_once self::$root.'/application/core/Env.php';
        self::$sql = file_get_contents(self::$root.'/database/production.sql');
    }

    /* ===================== .env is the only configuration ================= */

    public function testEnvParserHandlesWhatAHandEditedFileContains()
    {
        $parsed = Env::parse(implode("\n", array(
            '# a comment',
            '',
            'VP_DB_NAME=cpaneluser_panel',
            'export VP_DB_USER=cpaneluser_admin',
            "VP_DB_PASS='p@ss word#not-a-comment'",
            'VP_BASE_URL="https://example.test"',
            'VP_MAIL_FROM_NAME=WINDELS PANEL   # trailing comment',
            'VP_SESSION_SAVE_PATH=${VP_BASE_URL}/x',
            'not a variable line',
        )));

        $this->assertSame('cpaneluser_panel', $parsed['VP_DB_NAME']);
        $this->assertSame('cpaneluser_admin', $parsed['VP_DB_USER'], 'export prefix must be tolerated');
        $this->assertSame('p@ss word#not-a-comment', $parsed['VP_DB_PASS'],
            'a # inside a quoted value is part of the password, not a comment');
        $this->assertSame('https://example.test', $parsed['VP_BASE_URL']);
        $this->assertSame('WINDELS PANEL', $parsed['VP_MAIL_FROM_NAME'],
            'an unquoted value ends at the trailing comment');
        $this->assertSame('https://example.test/x', $parsed['VP_SESSION_SAVE_PATH'],
            '${VAR} interpolation');
        $this->assertArrayNotHasKey('not a variable line', $parsed);
    }

    public function testPortableNamesResolveOntoTheNamesTheAppReads()
    {
        $this->withEnv(array(
            'VP_BASE_URL'       => 'https://panel.example',
            'VP_DB_NAME'        => 'cpaneluser_panel',
            'VP_DB_PASS'        => 'secret-value',
            'VP_ENCRYPTION_KEY' => str_repeat('k', 40),
            'VP_AUTH_SECRET'    => str_repeat('a', 40),
            'VP_MAIL_HOST'      => 'mail.example',
            'VP_SESSION_DRIVER' => 'files',
            'VP_STRIPE_SECRET_KEY' => 'sk_test_123',
        ), function () {
            // Canonical names, which is what every existing getenv() call in
            // the libraries and config files uses.
            $this->assertSame('https://panel.example', getenv('APP_URL'));
            $this->assertSame('cpaneluser_panel', getenv('DB_NAME'));
            $this->assertSame('secret-value', getenv('DB_PASSWORD'));
            $this->assertSame(str_repeat('k', 40), getenv('ENCRYPTION_KEY'));
            $this->assertSame(str_repeat('a', 40), getenv('APP_KEY'));
            $this->assertSame('mail.example', getenv('SMTP_HOST'));
            $this->assertSame('files', getenv('SESS_DRIVER'));
            // The generic rule covers every unmapped key.
            $this->assertSame('sk_test_123', getenv('STRIPE_SECRET_KEY'));
        });
    }

    public function testAnExistingUnprefixedValueWins()
    {
        putenv('DB_NAME=legacy_name');
        $_ENV['DB_NAME'] = 'legacy_name';
        $this->withEnv(array('VP_DB_NAME' => 'new_name'), function () {
            $this->assertSame('legacy_name', getenv('DB_NAME'),
                'a deployment already running on DB_NAME must not be hijacked by a stale VP_DB_NAME');
        });
        putenv('DB_NAME');
        unset($_ENV['DB_NAME'], $_SERVER['DB_NAME']);
    }

    public function testDefaultsAreSharedHostingAnswers()
    {
        $this->withEnv(array('VP_DB_NAME' => 'x'), function () {
            $this->assertSame('localhost', getenv('DB_HOST'),
                'shared hosting has MySQL locally; a container hostname makes the first page load a DNS failure');
            $this->assertSame('files', getenv('SESS_DRIVER'), 'no Redis on cPanel');
            $this->assertSame('file', getenv('CACHE_DRIVER'));
            $this->assertSame('local', getenv('STORAGE_DRIVER'), 'no S3 bucket on cPanel');
            $this->assertSame('3306', getenv('DB_PORT'));
        });
    }

    public function testBaseUrlIsDerivedFromTheRequestWhenUnset()
    {
        $_SERVER['HTTP_HOST'] = 'panel.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->assertSame('https://panel.example/', Env::detect_base_url());

        $_SERVER['SCRIPT_NAME'] = '/panel/index.php';
        unset($_SERVER['HTTPS']);
        $this->assertSame('http://panel.example/panel/', Env::detect_base_url(),
            'a panel in a subdirectory must still link to itself correctly');
        unset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME']);
    }

    public function testConfigFilesReadEnvAndNotAnInstallerGeneratedFile()
    {
        foreach (array('config', 'database', 'storage', 'redis', 'email') as $file) {
            $src = file_get_contents(self::$root."/application/config/{$file}.php");
            $this->assertStringContainsString('Env::bootstrap', $src,
                "application/config/{$file}.php must resolve its values through .env");
        }

        $db = file_get_contents(self::$root.'/application/config/database.php');
        foreach (array('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD') as $key) {
            $this->assertStringContainsString($key, $db);
        }

        // Nothing may depend on a secrets file an installer wrote.
        foreach (glob(self::$root.'/application/config/*.php') as $path) {
            $this->assertStringNotContainsString('.secrets.php', file_get_contents($path),
                basename($path).' must not depend on an installer-generated secrets file');
        }
        $this->assertFileDoesNotExist(self::$root.'/install',
            'there is no install/ directory: deployment is upload + import + .env');
    }

    public function testIndexPhpBootsWithoutComposer()
    {
        $src = file_get_contents(self::$root.'/index.php');
        $this->assertStringContainsString("require_once __DIR__ . '/application/core/Env.php'", $src,
            '.env must be read by code that ships with the app, not by a composer package');
        $this->assertMatchesRegularExpression(
            '~if \(file_exists\(__DIR__ \. \'/vendor/autoload\.php\'\)\)~', $src,
            'vendor/ must be optional, not required');
        $this->assertStringNotContainsString('Dotenv\\Dotenv', $src,
            'phpdotenv was the one hard composer dependency in the request path');
    }

    /* ===================== runtime directories ============================ */

    public function testRuntimeDirectoriesAreCreatedWithoutACommand()
    {
        $root = sys_get_temp_dir().'/windels-deploy-'.bin2hex(random_bytes(4));
        mkdir($root.'/application', 0775, true);

        $this->withEnv(array('VP_STORAGE_PATH' => $root.'/storage'), function () use ($root) {
            // Simulate what index.php does on the first request after upload.
            $paths = Env::writable_paths();
            foreach (array('logs', 'cache', 'sessions', 'uploads') as $key) {
                $this->assertArrayHasKey($key, $paths);
            }
        }, $root);

        $this->assertDirectoryExists($root.'/storage/logs');
        $this->assertDirectoryExists($root.'/storage/cache/sessions');
        $this->assertDirectoryExists($root.'/assets/uploads');

        $this->assertStringContainsString('Require all denied',
            file_get_contents($root.'/storage/logs/.htaccess'),
            'logs sit inside the document root on cPanel and must never be fetchable');
        $uploads = file_get_contents($root.'/assets/uploads/.htaccess');
        $this->assertStringContainsString('php_flag engine off', $uploads);
        $this->assertStringNotContainsString('Require all denied', $uploads,
            'uploads are served to browsers — they get "data, never code", not a blanket deny');

        $this->rmrf($root);
    }

    public function testTheRepositoryShipsTheWritableDirectoriesItNeeds()
    {
        foreach (array('storage/logs', 'storage/cache', 'storage/cache/sessions',
                       'application/cache', 'assets/uploads') as $dir) {
            $this->assertDirectoryExists(self::$root.'/'.$dir,
                "{$dir} must exist in the package so nobody has to create it in File Manager");
            $this->assertFileExists(self::$root.'/'.$dir.'/.htaccess',
                "{$dir} must carry its own access guard");
        }
    }

    /* ===================== the one importable database ==================== */

    public function testProductionSqlContainsEveryTableTheMigrationsCreate()
    {
        $expected = array();
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? `?([a-z0-9_]+)`?/i',
                file_get_contents($file), $m);
            foreach ($m[1] as $table) $expected[$table] = true;
        }
        // Tables a later migration deliberately retires.
        foreach (array('withdrawals', 'withdrawal_methods', 'marketplace_vendors',
                       'vendor_payouts', 'vendor_applications') as $retired) {
            unset($expected[$retired]);
        }
        $this->assertNotEmpty($expected);

        foreach (array_keys($expected) as $table) {
            $this->assertMatchesRegularExpression(
                '/CREATE TABLE(?: IF NOT EXISTS)? `?'.preg_quote($table, '/').'`?/i',
                self::$sql,
                "production.sql is missing {$table} — a fresh import would boot into a broken schema");
        }
    }

    public function testProductionSqlRecordsTheSchemaVersionSoNoMigrationIsReplayed()
    {
        $latest = 0;
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            $latest = max($latest, (int)substr(basename($file), 0, 3));
        }
        $this->assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS migrations/i', self::$sql);
        $this->assertStringContainsString(
            "INSERT INTO migrations (version) VALUES ({$latest});", self::$sql,
            'without this row a later `migrate` would try to re-create every table');
    }

    public function testProductionSqlCarriesTheCoreSeedData()
    {
        // Roles and the permission catalogue: no rows here means nobody can be
        // authorised for anything after the import.
        require_once self::$root.'/application/libraries/Seeder.php';
        require_once self::$root.'/application/seeds/Core_seeder.php';

        foreach (array('SUPER_ADMIN', 'ADMIN', 'STAFF', 'CUSTOMER') as $role) {
            $this->assertStringContainsString("'{$role}'", self::$sql);
        }
        foreach (Core_seeder::permission_catalog() as $keys) {
            foreach ($keys as $key) {
                $this->assertStringContainsString("'{$key}'", self::$sql,
                    "permission {$key} is defined in the seeder but missing from production.sql");
            }
        }
        foreach (Core_seeder::default_settings() as $setting) {
            $this->assertStringContainsString("'{$setting[0]}'", self::$sql,
                "setting {$setting[0]} is missing from production.sql");
        }
        foreach (array('feature_flags', 'payment_methods', 'email_templates', 'faqs',
                       'currencies', 'price_groups', 'vtu_networks', 'vtu_products',
                       'number_countries', 'number_services', 'identity_products',
                       'giftcard_brands', 'marketplace_categories') as $table) {
            $this->assertStringContainsString("INSERT INTO `{$table}`", self::$sql,
                "{$table} has no seeded rows in production.sql");
        }
    }

    public function testProductionSqlShipsAnAdministratorNobodyHasToCreateFromACommand()
    {
        $this->assertMatchesRegularExpression("/INSERT INTO `users`.*'SUPER_ADMIN'/s", self::$sql,
            'the whole point: no `php install.php --users-only` step');
        $this->assertMatchesRegularExpression('/\$2y\$\d\d\$[.\/A-Za-z0-9]{53}/', self::$sql,
            'the seeded password must be a real bcrypt hash password_verify() accepts');
        $this->assertStringContainsString('INSERT INTO `wallets`', self::$sql,
            'the admin needs the wallet row the admin screens join against');
        $this->assertMatchesRegularExpression('/--\s+password:\s+\S+/', self::$sql,
            'the credentials must be documented in the file the operator imports');
    }

    public function testProductionSqlImportsIntoADatabaseTheOperatorAlreadyCreated()
    {
        $this->assertStringNotContainsStringIgnoringCase('CREATE DATABASE', self::$sql,
            'phpMyAdmin imports into an existing database; CREATE DATABASE would just fail');
        $this->assertStringNotContainsStringIgnoringCase('DROP DATABASE', self::$sql);
        $this->assertStringNotContainsStringIgnoringCase('CREATE USER', self::$sql,
            'the database user is created in cPanel, not by the dump');
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', self::$sql,
            'foreign keys are declared before their targets exist in migration order');
    }

    /* ===================== packaging and documentation ==================== */

    public function testTheDeploymentPackageIsBuiltWithoutTheDeveloperMachineMattering()
    {
        $script = self::$root.'/tools/build_deployment_package.sh';
        $this->assertFileExists($script);
        $src = file_get_contents($script);

        foreach (array('index.php', 'application', 'assets', 'database/production.sql',
                       '.env.example', '.htaccess') as $needed) {
            $this->assertStringContainsString($needed, $src, "the package must contain {$needed}");
        }
        $this->assertStringContainsString('system', $src,
            'CodeIgniter itself must be in the package — it is the only hard dependency');
        $this->assertStringContainsString('rm -rf "${STAGE}/application/seeds"', $src,
            'the demo seeder must never ship to a live panel');
        $this->assertStringContainsString('build_production_sql.php', $src);
        $this->assertStringContainsString('--check', $src,
            'a package built from a stale production.sql is a broken deployment');
    }

    public function testTheCommittedPackageIsNotStale()
    {
        $zip_path = self::$root.'/application-deployment.zip';
        $this->assertFileExists($zip_path,
            'the repository ships a ready package: an operator with no terminal cannot build one');

        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ext-zip not available');
        }
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);

        foreach (array('index.php', '.htaccess', '.env.example', 'system/core/CodeIgniter.php',
                       'database/production.sql', 'README-DEPLOYMENT.txt') as $entry) {
            $this->assertNotFalse($zip->locateName($entry), "the package is missing {$entry}");
        }
        $this->assertFalse($zip->locateName('composer.json'), 'nothing to install on the host');
        $this->assertFalse($zip->locateName('package.json'), 'nothing to build on the host');
        $this->assertFalse($zip->locateName('application/seeds/Demo_seeder.php'),
            'demo data must never ship to a live panel');

        // Staleness: the two files that decide whether a deployment works must
        // match the tree they were built from.
        foreach (array('database/production.sql', 'index.php') as $entry) {
            $this->assertSame(
                sha1_file(self::$root.'/'.$entry),
                sha1($zip->getFromName($entry)),
                "application-deployment.zip is out of date for {$entry} — "
                ."rebuild it with: bash tools/build_deployment_package.sh");
        }
        $zip->close();
    }

    public function testEnvExampleDocumentsExactlyWhatAMigrationChanges()
    {
        $env = file_get_contents(self::$root.'/.env.example');
        foreach (array('CI_ENV=production', 'VP_BASE_URL', 'VP_DB_HOST', 'VP_DB_PORT',
                       'VP_DB_NAME', 'VP_DB_USER', 'VP_DB_PASS',
                       'VP_ENCRYPTION_KEY', 'VP_AUTH_SECRET') as $key) {
            $this->assertStringContainsString($key, $env);
        }
        $this->assertStringContainsString('VP_SETUP_TOKEN', $env);
        $this->assertMatchesRegularExpression('/carry|copy these|unchanged/i', $env,
            'the file must say that the secrets travel with the database');
    }

    public function testTheDeploymentGuideIsTheFiveStepProcess()
    {
        $guide = self::$root.'/docs/cpanel-deployment.md';
        $this->assertFileExists($guide);
        $text = file_get_contents($guide);
        foreach (array('File Manager', 'MySQL Databases', 'phpMyAdmin',
                       'database/production.sql', '.env') as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/^\s*composer install/mi', $text,
            'the guide must not ask for composer');
        $this->assertDoesNotMatchRegularExpression('/^\s*npm (install|ci)/mi', $text,
            'the guide must not ask for npm');
    }

    public function testSensitiveDeploymentFilesAreNotServableOverHttp()
    {
        $ht = file_get_contents(self::$root.'/.htaccess');
        $this->assertMatchesRegularExpression('/\bdatabase\b/', $ht,
            'database/production.sql contains the schema and the admin hash');
        $this->assertStringContainsString('\\.sql', $ht);
        $this->assertStringContainsString('(^|/)\\.', $ht, 'dotfiles, including .env');
    }

    /* ===================== the browser setup page ========================= */

    public function testTheSetupPageIsClosedUnlessTheOperatorOpensIt()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Setup.php');
        $this->assertStringContainsString("Env::get('SETUP_TOKEN'", $src);
        $this->assertStringContainsString('hash_equals(', $src, 'constant-time token comparison');
        $this->assertStringContainsString('show_404()', $src,
            'no token means the route does not exist, not that it prompts');
        $this->assertStringContainsString('too_many_failures', $src, 'rate limited like the login screen');
        $this->assertStringContainsString('strlen($expected) < 16', $src,
            'a short token is refused rather than silently trusted');

        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("\$route['setup']", $routes);
        $this->assertStringContainsString("\$route['setup/admin']", $routes);
    }

    public function testNothingInTheRequestPathNeedsACliInstaller()
    {
        // The CLI controllers may still exist for maintainers, but a fresh
        // deployment must not depend on them: no controller may refuse to
        // serve because an installer has not run.
        foreach (glob(self::$root.'/application/controllers/*.php') as $file) {
            $src = file_get_contents($file);
            $this->assertStringNotContainsString('install.php', $src,
                basename($file).' must not reference an installer script');
        }
        $readme = file_get_contents(self::$root.'/README.md');
        $this->assertStringContainsString('cpanel-deployment.md', $readme,
            'the README must point at the terminal-free path');
    }

    /* ============================== helpers =============================== */

    /**
     * Run $fn with a temporary .env in place, then put the process environment
     * back exactly as it was.
     */
    private function withEnv(array $vars, callable $fn, $root = null)
    {
        $root = $root ?: sys_get_temp_dir().'/windels-env-'.bin2hex(random_bytes(4));
        $created = !is_dir($root);
        if ($created) mkdir($root, 0775, true);

        $body = '';
        foreach ($vars as $k => $v) $body .= $k.'='.$v."\n";
        file_put_contents($root.'/.env', $body);

        $touched = array_merge(array_keys($vars), array(
            'APP_URL', 'DB_NAME', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT', 'DB_USER',
            'ENCRYPTION_KEY', 'APP_KEY', 'SMTP_HOST', 'SESS_DRIVER', 'CACHE_DRIVER',
            'STORAGE_DRIVER', 'STRIPE_SECRET_KEY', 'MAIL_DRIVER', 'APP_TIMEZONE',
            'DB_CHARSET', 'DB_COLLATION', 'DB_DRIVER', 'STORAGE_PATH', 'CI_ENV', 'APP_ENV',
        ));
        $saved = array();
        foreach ($touched as $key) {
            $saved[$key] = array_key_exists($key, $_ENV) ? $_ENV[$key] : null;
        }

        $this->resetEnv();
        try {
            Env::bootstrap($root, $root.'/.env');
            $fn();
        } finally {
            foreach ($touched as $key) {
                if ($saved[$key] === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv($key.'='.$saved[$key]);
                    $_ENV[$key] = $_SERVER[$key] = $saved[$key];
                }
            }
            $this->resetEnv();
            @unlink($root.'/.env');
            if ($created && $root !== null && strpos($root, sys_get_temp_dir()) === 0) {
                $this->rmrf($root);
            }
        }
    }

    /** Env::bootstrap() is once-per-process; tests need it more than once. */
    private function resetEnv()
    {
        $ref = new ReflectionClass('Env');
        foreach (array('booted' => false, 'root' => null) as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    private function rmrf($path)
    {
        if (!is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }
}
