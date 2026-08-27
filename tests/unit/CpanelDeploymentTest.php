<?php
use PHPUnit\Framework\TestCase;

/**
 * Portable cPanel deployment (no terminal, no Composer, no CLI installer).
 *
 * The contract these tests defend is one sentence: **upload the files, create
 * the database, import `database/marvysocials.sql`, edit `.env`, open the
 * domain.** Everything below is a way that contract has historically been
 * broken —
 *
 *   - a config file reading a value an installer generated rather than `.env`
 *   - a schema change that landed in a migration but never in marvysocials.sql,
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
        self::$sql = file_get_contents(self::$root.'/database/marvysocials.sql');
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
            'VP_MAIL_FROM_NAME=MarvySocials   # trailing comment',
            'VP_SESSION_SAVE_PATH=${VP_BASE_URL}/x',
            'not a variable line',
        )));

        $this->assertSame('cpaneluser_panel', $parsed['VP_DB_NAME']);
        $this->assertSame('cpaneluser_admin', $parsed['VP_DB_USER'], 'export prefix must be tolerated');
        $this->assertSame('p@ss word#not-a-comment', $parsed['VP_DB_PASS'],
            'a # inside a quoted value is part of the password, not a comment');
        $this->assertSame('https://example.test', $parsed['VP_BASE_URL']);
        $this->assertSame('MarvySocials', $parsed['VP_MAIL_FROM_NAME'],
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
        $this->assertDoesNotMatchRegularExpression('#/home/[^/\s]+/public_html#', $src,
            'index.php must not hard-code a development-server absolute path');
        $this->assertStringContainsString("\$system_path = 'system';", $src,
            'the primary framework location after a cPanel extract is ./system');
    }

    public function testHttpBaseUrlIsUpgradedWhenTheRequestIsHttps()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'www.marvysocials.com';
        $this->withEnv(array(
            'VP_BASE_URL' => 'http://www.marvysocials.com',
            'VP_DB_NAME'  => 'x',
        ), function () {
            $this->assertSame('https://www.marvysocials.com', getenv('APP_URL'),
                'an http:// VP_BASE_URL on an HTTPS request must not emit mixed-content links');
        });
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST']);
    }

    public function testHttpBaseUrlIsLeftAloneOnCli()
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REQUEST_SCHEME']);
        $_SERVER['SERVER_PORT'] = '80';
        $this->withEnv(array(
            'VP_BASE_URL' => 'http://www.marvysocials.com',
            'VP_DB_NAME'  => 'x',
        ), function () {
            $this->assertSame('http://www.marvysocials.com', getenv('APP_URL'),
                'cron must not rewrite VP_BASE_URL just because the operator has not switched to https yet');
        });
        unset($_SERVER['SERVER_PORT']);
    }

    public function testMariaDbJsonIsCompatibleWithLongtext()
    {
        require_once self::$root.'/application/libraries/SchemaManifest.php';
        $this->assertTrue(SchemaManifest::types_compatible('json', 'longtext'),
            'MariaDB reports JSON columns as longtext — that is not a schema mismatch');
        $this->assertTrue(SchemaManifest::types_compatible('JSON', 'LONGTEXT'));
        $this->assertTrue(SchemaManifest::types_compatible('datetime', 'timestamp'));
        $this->assertFalse(SchemaManifest::types_compatible('int', 'varchar'));
        $this->assertFalse(SchemaManifest::types_compatible('int', 'bigint'));
    }

    public function testDeployVerifyRequiresTheThreeFrameworkPaths()
    {
        $src = file_get_contents(self::$root.'/application/libraries/InstallCheck.php');
        foreach (array(
            'system/core/CodeIgniter.php',
            'vendor/autoload.php',
            'vendor/codeigniter/framework/system/core/CodeIgniter.php',
            'types_compatible',
        ) as $needle) {
            $this->assertStringContainsString($needle, $src, "InstallCheck must check {$needle}");
        }
        $verify = file_get_contents(self::$root.'/deploy-verify.php');
        $this->assertStringContainsString('check_framework', $verify);
        $this->assertStringContainsString('check_composer', $verify);
        $this->assertStringContainsString('check_schema', $verify);
    }

    public function testDeploymentPackageWorkflowIsActive()
    {
        $wf = self::$root.'/.github/workflows/deployment-package.yml';
        $staged = self::$root.'/docs/github-actions/deployment-package.yml';
        // GitHub Apps without the `workflows` permission cannot push files
        // under .github/workflows/. The identical YAML ships at
        // docs/github-actions/deployment-package.yml for a one-paste activate.
        $this->assertTrue(is_file($wf) || is_file($staged),
            'the packaging pipeline YAML must exist (as a GitHub Actions workflow, or at docs/github-actions/ until the workflows permission is granted)');
        $src = file_get_contents(is_file($wf) ? $wf : $staged);
        $this->assertStringContainsString('workflow_dispatch', $src);
        $this->assertStringContainsString("tags: ['v*']", $src);
        $this->assertStringContainsString('composer install --no-dev', $src);
        $this->assertStringContainsString('npm run build:css', $src);
        $this->assertStringContainsString('tools/build_deployment_package.sh', $src);
        $this->assertStringContainsString('tools/verify_deployment_package.sh', $src);
        $this->assertStringContainsString('application-deployment.zip', $src);
        $this->assertStringContainsString('actions/upload-artifact', $src);
        $this->assertFileDoesNotExist(self::$root.'/deployment-package.yml.workflow-ready',
            'do not leave the workflow as a .workflow-ready file in the repository root');
    }

    /* ===================== runtime directories ============================ */

    public function testRuntimeDirectoriesAreCreatedWithoutACommand()
    {
        $root = sys_get_temp_dir().'/marvy-deploy-'.bin2hex(random_bytes(4));
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
                "marvysocials.sql is missing {$table} — a fresh import would boot into a broken schema");
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
                    "permission {$key} is defined in the seeder but missing from marvysocials.sql");
            }
        }
        foreach (Core_seeder::default_settings() as $setting) {
            $this->assertStringContainsString("'{$setting[0]}'", self::$sql,
                "setting {$setting[0]} is missing from marvysocials.sql");
        }
        foreach (array('feature_flags', 'payment_methods', 'email_templates', 'faqs',
                       'currencies', 'price_groups', 'vtu_networks', 'vtu_products',
                       'number_countries', 'number_services', 'identity_products',
                       'giftcard_brands', 'marketplace_categories') as $table) {
            $this->assertStringContainsString("INSERT INTO `{$table}`", self::$sql,
                "{$table} has no seeded rows in marvysocials.sql");
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

    public function testProductionSqlShipsACustomerAndStaffAccount()
    {
        $this->assertMatchesRegularExpression("/INSERT INTO `users`.*'demo'.*'CUSTOMER'/s", self::$sql,
            'a CUSTOMER account must exist so /login can open the dashboard');
        $this->assertMatchesRegularExpression("/INSERT INTO `users`.*'staff'.*'STAFF'/s", self::$sql,
            'a STAFF account must exist so /admin/login has a non-owner operator');
        $this->assertStringContainsString("'admin_mfa_required', '{\"value\":false}'", self::$sql,
            'mandatory staff MFA would bounce first login to /dashboard/security');
        $this->assertStringContainsString("'email_verification_required', '{\"value\":false}'", self::$sql,
            'email verification would block a fresh import that has no mailer yet');
    }

    public function testFirstLoginPasswordsVerifyAgainstTheSeededHashes()
    {
        preg_match_all('/--\s+password:\s+(\S+)/', self::$sql, $pws);
        preg_match_all('/(\$2y\$\d\d\$[.\/A-Za-z0-9]{53})/', self::$sql, $hashes);
        $this->assertGreaterThanOrEqual(3, count($pws[1]), 'admin, demo and staff passwords must be documented');
        $this->assertGreaterThanOrEqual(3, count($hashes[1]), 'admin, demo and staff hashes must be present');
        $this->assertTrue(password_verify($pws[1][0], $hashes[1][0]),
            'the first documented password must verify against the first bcrypt hash (SUPER_ADMIN)');
        $this->assertTrue(password_verify($pws[1][1], $hashes[1][1]),
            'the demo password must verify against its bcrypt hash');
        $this->assertTrue(password_verify($pws[1][2], $hashes[1][2]),
            'the staff password must verify against its bcrypt hash');
    }

    public function testLiveAccountsSqlIsIdempotentAndDoesNotOverwritePasswords()
    {
        $path = self::$root.'/database/first_login_accounts.sql';
        $this->assertFileExists($path,
            'an existing live database cannot re-import marvysocials.sql; this file is the phpMyAdmin paste');
        $sql = file_get_contents($path);
        $this->assertStringContainsString('WHERE NOT EXISTS', $sql);
        $this->assertStringNotContainsStringIgnoringCase('DROP TABLE', $sql);
        $this->assertStringNotContainsStringIgnoringCase('CREATE DATABASE', $sql);
        $this->assertStringContainsString('admin_mfa_required', $sql);
        $this->assertDoesNotMatchRegularExpression('/UPDATE `users`\s+SET[^;]*password_hash/is', $sql,
            'never reset a live password the operator may already have changed');
        $this->assertStringContainsString("'demo'", $sql);
        $this->assertStringContainsString("'staff'", $sql);
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

        foreach (array('index.php', 'application', 'assets', 'database/marvysocials.sql',
                       'database/first_login_accounts.sql',
                       '.env.example', '.htaccess') as $needed) {
            $this->assertStringContainsString($needed, $src, "the package must contain {$needed}");
        }
        $this->assertStringContainsString('system', $src,
            'CodeIgniter itself must be in the package — it is the only hard dependency');
        $this->assertStringContainsString('materialize_tree', $src,
            'system/ must be copied as real files (never a preserved symlink)');
        $this->assertStringContainsString('system/core/CodeIgniter.php', $src,
            'the build must look for the CodeIgniter front controller, not just a system/ directory');
        $this->assertStringContainsString('vendor/autoload.php', $src);
        $this->assertStringContainsString('vendor/codeigniter/framework/system/core/CodeIgniter.php', $src);
        $this->assertStringContainsString('validate_deployment_zip.sh', $src,
            'the zip itself must be extract-validated before the build is marked complete');
        $this->assertStringContainsString('rm -rf "${STAGE}/application/seeds"', $src,
            'the demo seeder must never ship to a live panel');
        $this->assertStringContainsString('build_production_sql.php', $src);
        $this->assertStringContainsString('--check', $src,
            'a package built from a stale marvysocials.sql is a broken deployment');
    }

    public function testZipValidatorRefusesAPackageWithoutTheFramework()
    {
        $script = self::$root.'/tools/validate_deployment_zip.sh';
        $this->assertFileExists($script);
        $src = file_get_contents($script);
        foreach (array(
            'system/core/CodeIgniter.php',
            'vendor/autoload.php',
            'vendor/codeigniter/framework/system/core/CodeIgniter.php',
            'CI_VERSION',
        ) as $needle) {
            $this->assertStringContainsString($needle, $src,
                "validate_deployment_zip.sh must require {$needle}");
        }
        $this->assertStringContainsString('type l', $src,
            'the validator must reject a package that stores symlinks');
    }

    public function testSchemaManifestDoesNotTreatConstraintsAsColumns()
    {
        require_once self::$root.'/application/libraries/SchemaManifest.php';
        $manifest = SchemaManifest::from_file(self::$root.'/database/marvysocials.sql');
        $this->assertNull($manifest['error'], $manifest['error'] ?: '');
        $this->assertArrayHasKey('wallets', $manifest['tables']);
        $this->assertArrayNotHasKey('CONSTRAINT', $manifest['tables']['wallets']['columns'],
            'CHECK / FOREIGN KEY clauses must not become a fake CONSTRAINT column');
        $this->assertArrayNotHasKey('FULLTEXT', $manifest['tables']['services']['columns'],
            'FULLTEXT INDEX clauses must not become a fake FULLTEXT column');
        $this->assertArrayHasKey('ft_svc_search', $manifest['tables']['services']['indexes']);
        $this->assertArrayHasKey('users', $manifest['tables']);
        $this->assertArrayHasKey('username', $manifest['tables']['users']['unique'],
            'inline UNIQUE must use the MySQL index name (the column), not uniq_*');
        $this->assertArrayNotHasKey('uniq_username', $manifest['tables']['users']['unique']);
        $this->assertNotEmpty($manifest['tables']['role_permissions']['fks'],
            'inline CONSTRAINT ... FOREIGN KEY must be recorded as a foreign key');
    }

    public function testTheCommittedPackageIsNotStale()
    {
        $zip_path = self::$root.'/application-deployment.zip';
        if (!is_file($zip_path)) {
            $this->markTestSkipped(
                'application-deployment.zip is a build artifact (gitignored); '
                .'produce and gate it with: bash tools/verify_deployment_package.sh'
            );
        }

        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ext-zip not available');
        }
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zip_path) === true);

        foreach (array('index.php', '.htaccess', '.env.example', 'system/core/CodeIgniter.php',
                       'database/marvysocials.sql', 'README-DEPLOYMENT.txt') as $entry) {
            $this->assertNotFalse($zip->locateName($entry), "the package is missing {$entry}");
        }
        $this->assertFalse($zip->locateName('composer.json'), 'nothing to install on the host');
        $this->assertFalse($zip->locateName('package.json'), 'nothing to build on the host');
        $this->assertFalse($zip->locateName('application/seeds/Demo_seeder.php'),
            'demo data must never ship to a live panel');

        // Staleness: every application file the package is supposed to carry
        // must match the tree it was built from. Only checking the SQL dump and
        // index.php used to let a new view or a layout change ship two versions
        // apart — the package would extract fine and quietly serve old markup.
        $expected = $this->expected_deployment_files();
        foreach ($expected as $entry => $sha1) {
            $this->assertNotFalse($zip->locateName($entry), "the package is missing {$entry}");
            $this->assertSame(
                $sha1,
                sha1($zip->getFromName($entry)),
                "application-deployment.zip is out of date for {$entry} — "
                ."rebuild it with: bash tools/build_deployment_package.sh");
        }

        // And nothing else may have leaked in under the source prefixes. The
        // only additions allowed are the bundled framework (system/) and the
        // generated README-DEPLOYMENT.txt the build script writes.
        $extra = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if ($entry === '' || substr($entry, -1) === '/') {
                continue;
            }
            if ($entry === 'README-DEPLOYMENT.txt'
                || strpos($entry, 'system/') === 0
                || strpos($entry, 'vendor/') === 0) {
                continue;
            }
            if (isset($expected[$entry])) {
                continue;
            }
            $extra[] = $entry;
        }
        $this->assertSame(array(), $extra,
            "application-deployment.zip carries files that are not in the deployment tree — "
            ."remove them from the package or delete them from the repo. Rebuild with: "
            ."bash tools/build_deployment_package.sh\n".implode("\n", array_slice($extra, 0, 20)));
        $zip->close();
    }

    /**
     * The exact file set `tools/build_deployment_package.sh` is expected to
     * stage from the repository (framework and the generated operator README
     * are handled separately in the staleness test).
     *
     * Runtime contents (logs, sessions, caches, uploads) are excluded: only
     * the stub guards ship with the package. Development-only directories and
     * generated artefacts are excluded too.
     */
    private function expected_deployment_files()
    {
        $out = array();
        $skip_prefix = array('application/seeds/', 'vendor/', 'system/');
        $skip_suffix = array('.gitignore', '.gitkeep', '.map');

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen(self::$root) + 1);
            $rel = str_replace('\\', '/', $rel);

            foreach ($skip_prefix as $prefix) {
                if (strpos($rel, $prefix) === 0) {
                    continue 2;
                }
            }
            foreach ($skip_suffix as $suffix) {
                if (substr($rel, -strlen($suffix)) === $suffix) {
                    continue 2;
                }
            }

            // Only the directories a running panel reads are packaged.
            $included_prefix = array(
                'application/', 'assets/', 'storage/', 'cron/', 'database/', 'docs/cpanel-deployment.md',
            );
                $included_root = array('.htaccess', '.env.example', 'index.php', 'deploy-verify.php');
            $root_ok = in_array($rel, $included_root, true);
            if (!$root_ok) {
                $prefix_ok = false;
                foreach ($included_prefix as $prefix) {
                    if (strpos($rel, $prefix) === 0) {
                        $prefix_ok = true;
                        break;
                    }
                }
                if (!$prefix_ok) {
                    continue;
                }
            }

            // Runtime directories ship empty; the guards are the only files in
            // them. A cache/log/session file from a local run must never be a
            // deployment surprise.
            if (preg_match('#^(application/cache|storage/(logs|cache|cache/sessions|cache/ratelimit))/.*$#', $rel)) {
                $base = basename($rel);
                if ($base !== '.htaccess' && $base !== 'index.html') {
                    continue;
                }
            }
            if (strpos($rel, 'assets/uploads/') === 0) {
                $base = basename($rel);
                if ($base === '.gitignore') {
                    continue;
                }
            }

            $out[$rel] = sha1_file(self::$root.'/'.$rel);
        }
        return $out;
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
                       'database/marvysocials.sql', '.env') as $needle) {
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
            'database/marvysocials.sql contains the schema and the admin hash');
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
        $root = $root ?: sys_get_temp_dir().'/marvy-env-'.bin2hex(random_bytes(4));
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
            'VP_BASE_URL',
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
