<?php
use PHPUnit\Framework\TestCase;

/**
 * Seed tests — verify the seed data itself (catalogs, permission matrix, settings)
 * and the safety rails around the demo seeder. No database required: the seeders
 * expose their catalogs as static arrays.
 */
class SeedTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) { eval('class CI_Model {}'); }
        if (!class_exists('Seeder')) {
            // Load the real base class with a stubbed get_instance().
            if (!function_exists('get_instance')) {
                eval('function &get_instance() { static $i; if (!$i) $i = new stdClass(); return $i; }');
            }
            if (!function_exists('log_message')) { eval('function log_message($l, $m) {}'); }
            if (!function_exists('windels_public_id')) {
                require_once self::$root.'/application/helpers/windels_helper.php';
            }
            require_once self::$root.'/application/libraries/Seeder.php';
        }
        require_once self::$root.'/application/seeds/Core_seeder.php';
        require_once self::$root.'/application/seeds/Demo_seeder.php';
    }

    /* ---------------------------- core seed --------------------------- */

    public function testPermissionCatalogIsWellFormed()
    {
        $catalog = Core_seeder::permission_catalog();
        $this->assertNotEmpty($catalog);
        $all = array();
        foreach ($catalog as $category => $keys) {
            $this->assertIsString($category);
            foreach ($keys as $key) {
                $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $key, "malformed permission key: {$key}");
                $all[] = $key;
            }
        }
        $this->assertSame(array_unique($all), $all, 'duplicate permission keys');
        foreach (array('orders.view','orders.refund','providers.manage','settings.manage','audit.view') as $required) {
            $this->assertContains($required, $all);
        }
    }

    public function testRoleMatrixOnlyUsesKnownPermissions()
    {
        $known = array();
        foreach (Core_seeder::permission_catalog() as $keys) { $known = array_merge($known, $keys); }
        foreach (Core_seeder::role_matrix() as $role => $keys) {
            if ($keys === '*') continue;
            foreach ($keys as $key) {
                $this->assertContains($key, $known, "role {$role} references unknown permission {$key}");
            }
        }
    }

    public function testRoleMatrixCoversTheFourCanonicalRoles()
    {
        $roles = array_keys(Core_seeder::role_matrix());
        $this->assertSame(array('SUPER_ADMIN','ADMIN','STAFF','CUSTOMER'), $roles);
        $this->assertSame('*', Core_seeder::role_matrix()['SUPER_ADMIN']);
        $this->assertSame(array(), Core_seeder::role_matrix()['CUSTOMER'], 'customers hold no admin permissions');
    }

    public function testStaffCannotRefundOrChangeSettings()
    {
        $staff = Core_seeder::role_matrix()['STAFF'];
        $this->assertNotContains('orders.refund', $staff);
        $this->assertNotContains('wallets.adjust', $staff);
        $this->assertNotContains('settings.manage', $staff);
        $this->assertContains('tickets.reply', $staff);
    }

    public function testDefaultSettingsIncludeHomepageAndCurrency()
    {
        $settings = array();
        foreach (Core_seeder::default_settings() as $s) { $settings[$s[0]] = $s; }

        $this->assertArrayHasKey('active_homepage', $settings);
        $this->assertSame('AURORA', $settings['active_homepage'][1], 'default homepage is AURORA');
        $this->assertArrayHasKey('base_currency', $settings);
        $this->assertSame('NGN', $settings['base_currency'][1]);

        // Deposit bounds must be denominated in the base currency. The old
        // 5 / 10000 pair was dollars; leaving it would cap deposits at ₦10k.
        $this->assertSame('500.00000000', $settings['min_deposit'][1]);
        $this->assertSame('5000000.00000000', $settings['max_deposit'][1]);
        $this->assertArrayHasKey('maintenance_mode', $settings);
        $this->assertFalse($settings['maintenance_mode'][1]);

        foreach (Core_seeder::default_settings() as $s) {
            $this->assertCount(4, $s, 'setting rows are [key, value, category, is_public]');
            $this->assertIsString($s[0]);
            $this->assertIsString($s[2]);
            $this->assertContains($s[3], array(0, 1), 'is_public must be 0 or 1');
        }
    }

    public function testNoSecretsInSeededSettings()
    {
        foreach (Core_seeder::default_settings() as $s) {
            $key = strtolower($s[0]);
            foreach (array('secret','password','private_key','api_key') as $banned) {
                $this->assertStringNotContainsString($banned, $key, 'secrets belong in env, not the settings seed');
            }
            if ($s[3] === 1 && is_string($s[1])) {
                $this->assertStringNotContainsString('sk_', $s[1]);
            }
        }
    }

    /* ---------------------------- demo seed --------------------------- */

    public function testServiceCatalogIsConsistent()
    {
        $categories = array();
        foreach (Demo_seeder::category_catalog() as $c) { $categories[$c[1]] = true; }

        $slugs = array();
        foreach (Demo_seeder::service_catalog() as $s) {
            list($name,$slug,$cat,$type,$rate,$min,$max,$avg,$avgmin,$psid,$prate,$flags) = $s;
            $this->assertArrayHasKey($cat, $categories, "service {$slug} references unknown category {$cat}");
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug, "bad slug: {$slug}");
            $slugs[] = $slug;

            $this->assertLessThan($max, $min, "{$slug}: min must be < max");
            $this->assertGreaterThan(0, $min);
            $this->assertMatchesRegularExpression('/^\d+\.\d{8}$/', $rate, "{$slug}: rate must be a DECIMAL(20,8) string");
            $this->assertMatchesRegularExpression('/^\d+\.\d{8}$/', $prate, "{$slug}: provider rate must be a DECIMAL(20,8) string");
            $this->assertCount(5, $flags);
        }
        $this->assertSame(array_unique($slugs), $slugs, 'duplicate service slugs');
    }

    public function testEverySellPriceIsAboveProviderCost()
    {
        // Margin protection (§56): the panel must never sell below the frozen provider cost.
        foreach (Demo_seeder::service_catalog() as $s) {
            $slug = $s[1]; $rate = $s[4]; $prate = $s[10];
            $this->assertSame(1, bccomp($rate, $prate, 8), "{$slug} sells at or below provider cost ({$rate} vs {$prate})");
        }
    }

    public function testProviderServiceIdsAreUnique()
    {
        $ids = array();
        foreach (Demo_seeder::service_catalog() as $s) { $ids[] = $s[9]; }
        $this->assertSame(array_unique($ids), $ids, 'duplicate provider_service_id in demo catalog');
    }

    public function testCategorySlugsAreUnique()
    {
        $slugs = array();
        foreach (Demo_seeder::category_catalog() as $c) { $slugs[] = $c[1]; }
        $this->assertSame(array_unique($slugs), $slugs);
    }

    public function testDemoSeedIsBlockedInProduction()
    {
        $seed = file_get_contents(self::$root.'/application/controllers/Seed.php');
        $this->assertStringContainsString("'demo' => array('Demo_seeder', 'Demo_seeder.php', FALSE)", $seed);
        $this->assertStringContainsString('env_allows_demo', $seed);
        $this->assertStringContainsString("array('development', 'testing', 'demo')", $seed);
    }

    public function testSeedAndMigrateAreCliOnly()
    {
        foreach (array('Seed.php', 'Migrate.php') as $file) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$file);
            $this->assertStringContainsString('extends Cron_Controller', $src, $file.' must extend the CLI-guarded base controller');
        }
        // Cron_Controller enforces is_cli via require_cli()
        $core = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        $this->assertStringContainsString('$this->require_cli();', $core);
    }

    public function testDemoSeederDoesNotHardcodeAPassword()
    {
        $src = file_get_contents(self::$root.'/application/seeds/Demo_seeder.php');
        $this->assertStringContainsString("getenv('DEMO_PASSWORD')", $src);
        $this->assertStringContainsString('random_bytes', $src);
        $this->assertDoesNotMatchRegularExpression("/password\s*=\s*'(admin|password|123456)/i", $src);
    }

    public function testDemoWalletCreditsGoThroughLedgerNotBareUpdate()
    {
        // §24/25 — even seeds must write wallet_transactions + ledger_entries.
        $src = file_get_contents(self::$root.'/application/seeds/Demo_seeder.php');
        $this->assertStringContainsString("insert('wallet_transactions'", $src);
        $this->assertStringContainsString("insert('ledger_entries'", $src);
        $this->assertStringContainsString('balance_before', $src);
        $this->assertStringContainsString('balance_after', $src);
    }

    public function testSeedersAreIdempotentByConstruction()
    {
        // Every write in a seeder must go through insert_once()/upsert().
        foreach (array('Core_seeder.php', 'Demo_seeder.php') as $file) {
            $src = file_get_contents(self::$root.'/application/seeds/'.$file);
            preg_match_all('/\$this->ci->db->insert\(/', $src, $raw);
            preg_match_all('/(insert_once|upsert)\(/', $src, $guarded);
            $this->assertGreaterThan(count($raw[0]), count($guarded[0]), $file.' should prefer insert_once()/upsert()');
        }
    }
}
