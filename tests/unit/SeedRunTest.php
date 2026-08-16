<?php
use PHPUnit\Framework\TestCase;

/**
 * Seed execution tests — actually RUN both seeders against an in-memory database
 * built from the real migration DDL (tests/_support/FakeDb.php).
 *
 * This catches column typos, NOT NULL violations, UNIQUE collisions, wrong table
 * names and non-idempotent writes without needing a MySQL server.
 */
class SeedRunTest extends TestCase
{
    private static $root;
    /** @var FakeDb */
    private static $db;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Migration')) { eval('class CI_Migration { public $db; public $dbforge; }'); }
        if (!function_exists('log_message')) { eval('function log_message($l, $m) {}'); }

        require_once self::$root.'/tests/_support/FakeDb.php';
        require_once self::$root.'/application/helpers/windels_helper.php';

        // Build the in-memory schema from every migration's DDL.
        $statements = array();
        $files = glob(self::$root.'/application/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            require_once $file;
            $class = 'Migration_'.ucfirst(preg_replace('/^\d+_/', '', basename($file, '.php')));
            $statements = array_merge($statements, call_user_func(array($class, 'statements')));
        }
        self::$db = new FakeDb($statements);

        $ci = new FakeCI(self::$db);
        if (!function_exists('get_instance')) {
            $GLOBALS['__fake_ci'] = $ci;
            eval('function get_instance() { return $GLOBALS["__fake_ci"]; }');
        } else {
            $GLOBALS['__fake_ci'] = $ci;
        }

        require_once self::$root.'/application/libraries/Seeder.php';
        require_once self::$root.'/application/seeds/Core_seeder.php';
        require_once self::$root.'/application/seeds/Demo_seeder.php';

        putenv('DEMO_PASSWORD=SeedTest!Password1');

        // Run both seeds twice — idempotency must hold.
        foreach (array(1, 2) as $pass) {
            $core = new Core_seeder(array('verbose' => false));
            $core->run();
            $demo = new Demo_seeder(array('verbose' => false));
            $demo->run();
        }
    }

    private function rows($table) { return self::$db->all($table); }

    private function firstWhere($table, $column, $value)
    {
        foreach ($this->rows($table) as $row) {
            if (isset($row[$column]) && (string)$row[$column] === (string)$value) return $row;
        }
        return null;
    }

    /* ------------------------- schema coverage ------------------------- */

    public function testInMemorySchemaBuiltFromMigrations()
    {
        $this->assertGreaterThan(50, count(self::$db->list_tables()), 'FakeDb should mirror the full schema');
        foreach (array('users','wallets','services','orders','settings','permissions') as $t) {
            $this->assertTrue(self::$db->table_exists($t), "missing table {$t}");
        }
    }

    /* ---------------------------- core seed ---------------------------- */

    public function testCoreSeedCreatesRolesAndPermissions()
    {
        $this->assertCount(4, $this->rows('roles'));
        $expected = 0;
        foreach (Core_seeder::permission_catalog() as $keys) { $expected += count($keys); }
        $this->assertCount($expected, $this->rows('permissions'));
        $this->assertNotEmpty($this->rows('role_permissions'));
    }

    public function testSuperAdminGetsEveryPermission()
    {
        $role = $this->firstWhere('roles', 'name', 'SUPER_ADMIN');
        $this->assertNotNull($role);
        $granted = 0;
        foreach ($this->rows('role_permissions') as $rp) {
            if ((int)$rp['role_id'] === (int)$role['id']) $granted++;
        }
        $this->assertSame(count($this->rows('permissions')), $granted);
    }

    public function testCustomerRoleHasNoPermissions()
    {
        $role = $this->firstWhere('roles', 'name', 'CUSTOMER');
        foreach ($this->rows('role_permissions') as $rp) {
            $this->assertNotSame((int)$role['id'], (int)$rp['role_id'], 'CUSTOMER must hold no admin permissions');
        }
    }

    public function testSettingsSeededWithJsonValues()
    {
        $homepage = $this->firstWhere('settings', 'setting_key', 'active_homepage');
        $this->assertNotNull($homepage);
        $decoded = json_decode($homepage['setting_value'], true);
        $this->assertSame('AURORA', $decoded['value']);
        $this->assertSame('homepage', $homepage['category']);
    }

    public function testCurrenciesSeededWithSingleBase()
    {
        $base = 0;
        foreach ($this->rows('currencies') as $c) { $base += (int)$c['is_base']; }
        $this->assertSame(1, $base, 'exactly one base currency');
        $this->assertNotNull($this->firstWhere('currencies', 'code', 'USD'));
    }

    public function testAllPaymentGatewaysExceptManualStartDisabled()
    {
        foreach ($this->rows('payment_methods') as $m) {
            if ($m['code'] === 'manual') {
                $this->assertSame(1, (int)$m['is_active']);
            } else {
                $this->assertSame(0, (int)$m['is_active'], $m['code'].' must ship disabled');
            }
            $this->assertTrue(empty($m['config_encrypted']), 'no credentials may be seeded');
        }
    }

    public function testEmailTemplatesAndFaqsSeeded()
    {
        $this->assertGreaterThanOrEqual(6, count($this->rows('email_templates')));
        $this->assertGreaterThanOrEqual(5, count($this->rows('faqs')));
    }

    /* ---------------------------- demo seed ---------------------------- */

    public function testDemoSeedCreatesCatalog()
    {
        $this->assertCount(count(Demo_seeder::category_catalog()), $this->rows('service_categories'));
        $this->assertCount(count(Demo_seeder::service_catalog()), $this->rows('services'));
        $this->assertCount(count(Demo_seeder::service_catalog()), $this->rows('provider_services'));
        $this->assertCount(1, $this->rows('providers'));
    }

    public function testEveryServiceLinksToACategoryAndProvider()
    {
        $category_ids = array();
        foreach ($this->rows('service_categories') as $c) { $category_ids[(int)$c['id']] = true; }
        $provider = $this->rows('providers')[0];

        foreach ($this->rows('services') as $s) {
            $this->assertArrayHasKey((int)$s['category_id'], $category_ids, $s['slug'].' has a dangling category_id');
            $this->assertSame((int)$provider['id'], (int)$s['provider_id']);
            $this->assertNotEmpty($s['provider_service_id']);
            $this->assertSame(1, bccomp($s['rate'], $s['provider_rate'], 8), $s['slug'].' must sell above cost');
        }
    }

    public function testTieredPricesAreCheaperThanBaseRate()
    {
        $this->assertNotEmpty($this->rows('service_prices'));
        $services = array();
        foreach ($this->rows('services') as $s) { $services[(int)$s['id']] = $s; }
        foreach ($this->rows('service_prices') as $p) {
            $base = $services[(int)$p['service_id']]['rate'];
            $this->assertSame(-1, bccomp($p['rate'], $base, 8), 'group price must undercut the base rate');
        }
    }

    public function testProviderApiKeyIsEncryptedNotPlaintext()
    {
        $provider = $this->rows('providers')[0];
        $this->assertNotEmpty($provider['api_key_encrypted']);
        $this->assertStringNotContainsString('mock-api-key', $provider['api_key_encrypted'], 'provider key must be encrypted at rest');
    }

    public function testDemoUsersHaveHashedPasswords()
    {
        $this->assertCount(4, $this->rows('users'));
        foreach ($this->rows('users') as $u) {
            $this->assertNotEmpty($u['password_hash']);
            $this->assertMatchesRegularExpression('/^\$(argon2id|2y)\$/', $u['password_hash'], 'passwords must be hashed with argon2id/bcrypt');
            $this->assertStringNotContainsString('SeedTest!Password1', $u['password_hash']);
            $this->assertTrue(password_verify('SeedTest!Password1', $u['password_hash']));
        }
    }

    public function testExactlyOneSuperAdminIsSeeded()
    {
        $supers = 0;
        foreach ($this->rows('users') as $u) { if ($u['role'] === 'SUPER_ADMIN') $supers++; }
        $this->assertSame(1, $supers);
    }

    public function testEveryUserHasAWallet()
    {
        $this->assertCount(count($this->rows('users')), $this->rows('wallets'));
        foreach ($this->rows('wallets') as $w) {
            $this->assertSame(1, bccomp($w['balance'], '-0.00000001', 8), 'balance may never go negative');
        }
    }

    public function testWalletBalancesReconcileWithLedger()
    {
        // §24/25 — wallet balance must equal the sum of its transactions.
        $expected = array();
        foreach ($this->rows('wallet_transactions') as $tx) {
            $wid = (int)$tx['wallet_id'];
            if (!isset($expected[$wid])) $expected[$wid] = '0.00000000';
            $expected[$wid] = ($tx['direction'] === 'CREDIT')
                ? bcadd($expected[$wid], $tx['amount'], 8)
                : bcsub($expected[$wid], $tx['amount'], 8);
        }
        foreach ($this->rows('wallets') as $w) {
            $sum = isset($expected[(int)$w['id']]) ? $expected[(int)$w['id']] : '0.00000000';
            $this->assertSame(0, bccomp($w['balance'], $sum, 8), 'wallet '.$w['id'].' balance drifts from its transactions');
        }
    }

    public function testEveryWalletTransactionIsDoubleEntryBalanced()
    {
        $by_tx = array();
        foreach ($this->rows('ledger_entries') as $e) {
            $id = (int)$e['wallet_transaction_id'];
            if (!isset($by_tx[$id])) $by_tx[$id] = array('CREDIT' => '0.00000000', 'DEBIT' => '0.00000000');
            $by_tx[$id][$e['direction']] = bcadd($by_tx[$id][$e['direction']], $e['amount'], 8);
        }
        $this->assertNotEmpty($by_tx, 'seeded deposits must produce ledger entries');
        foreach ($this->rows('wallet_transactions') as $tx) {
            $id = (int)$tx['id'];
            $this->assertArrayHasKey($id, $by_tx, 'wallet transaction without ledger entries');
            $this->assertSame(0, bccomp($by_tx[$id]['CREDIT'], $by_tx[$id]['DEBIT'], 8), 'ledger entries must balance');
            $this->assertSame(0, bccomp($by_tx[$id]['CREDIT'], $tx['amount'], 8));
        }
    }

    public function testTransactionsRecordBalanceBeforeAndAfter()
    {
        foreach ($this->rows('wallet_transactions') as $tx) {
            $expected = ($tx['direction'] === 'CREDIT')
                ? bcadd($tx['balance_before'], $tx['amount'], 8)
                : bcsub($tx['balance_before'], $tx['amount'], 8);
            $this->assertSame(0, bccomp($tx['balance_after'], $expected, 8));
            $this->assertNotEmpty($tx['idempotency_key'], 'seeded money movements need idempotency keys');
        }
    }

    public function testOrdersFreezeChargeAndProviderCost()
    {
        $this->assertNotEmpty($this->rows('orders'));
        $services = array();
        foreach ($this->rows('services') as $s) { $services[(int)$s['id']] = $s; }

        foreach ($this->rows('orders') as $o) {
            $service = $services[(int)$o['service_id']];
            $expected = bcmul(bcdiv($service['rate'], '1000', 8), (string)$o['quantity'], 8);
            $this->assertSame(0, bccomp($o['charge'], $expected, 8), 'charge must equal rate/1000 * quantity');
            $this->assertSame(0, bccomp($o['rate_at_order'], $service['rate'], 8), 'rate must be frozen at order time');
            $this->assertNotEmpty($o['provider_charge'], 'provider cost must be frozen (§56)');
            $this->assertSame(1, bccomp($o['charge'], $o['provider_charge'], 8), 'order must be profitable');
        }
    }

    public function testOrderQuantitiesRespectServiceLimits()
    {
        $services = array();
        foreach ($this->rows('services') as $s) { $services[(int)$s['id']] = $s; }
        foreach ($this->rows('orders') as $o) {
            $s = $services[(int)$o['service_id']];
            $this->assertGreaterThanOrEqual((int)$s['min_quantity'], (int)$o['quantity'], $s['slug'].': below min');
            $this->assertLessThanOrEqual((int)$s['max_quantity'], (int)$o['quantity'], $s['slug'].': above max');
        }
    }

    public function testEveryOrderHasStatusHistoryEndingAtItsStatus()
    {
        $history = array();
        foreach ($this->rows('order_status_history') as $h) {
            $history[(int)$h['order_id']][] = $h;
        }
        foreach ($this->rows('orders') as $o) {
            $id = (int)$o['id'];
            $this->assertArrayHasKey($id, $history, 'order '.$o['public_id'].' has no status history (§26/29)');
            $last = end($history[$id]);
            $this->assertSame($o['status'], $last['new_status'], 'history must end at the current status');
            foreach ($history[$id] as $h) {
                $this->assertContains($h['source'], array('SYSTEM','ADMIN','PROVIDER','CUSTOMER','CRON','WORKER'));
            }
        }
    }

    public function testOrderStatusTransitionsAreLegal()
    {
        require_once self::$root.'/application/libraries/OrderStateMachine.php';
        $history = array();
        foreach ($this->rows('order_status_history') as $h) { $history[(int)$h['order_id']][] = $h; }

        foreach ($history as $order_id => $entries) {
            foreach ($entries as $h) {
                if (empty($h['previous_status'])) continue;
                $this->assertTrue(
                    OrderStateMachine::can($h['previous_status'], $h['new_status']),
                    "illegal transition {$h['previous_status']} -> {$h['new_status']} on order {$order_id}"
                );
            }
        }
    }

    public function testPartialOrdersRecordRemainsAndRefund()
    {
        $found = false;
        foreach ($this->rows('orders') as $o) {
            if ($o['status'] !== 'PARTIAL') continue;
            $found = true;
            $this->assertGreaterThan(0, (int)$o['remains']);
            $this->assertSame(1, bccomp($o['refunded_amount'], '0', 8), 'PARTIAL orders must refund the undelivered part');
        }
        $this->assertTrue($found, 'demo data should include a PARTIAL order');
    }

    public function testPendingOrdersHaveNoProviderOrderId()
    {
        foreach ($this->rows('orders') as $o) {
            if ($o['status'] === 'PENDING') {
                $this->assertTrue(empty($o['provider_order_id']), 'PENDING orders are not submitted yet');
            }
        }
    }

    public function testReferralGraphIsConsistent()
    {
        $this->assertCount(1, $this->rows('referral_accounts'));
        $this->assertCount(1, $this->rows('referrals'));
        $referral = $this->rows('referrals')[0];
        $this->assertNotSame((int)$referral['referrer_id'], (int)$referral['referred_id'], 'nobody may refer themselves');
    }

    public function testDemoContentSeeded()
    {
        $this->assertGreaterThanOrEqual(3, count($this->rows('blog_posts')));
        $this->assertNotEmpty($this->rows('announcements'));
        $this->assertNotEmpty($this->rows('tickets'));
        $this->assertGreaterThanOrEqual(2, count($this->rows('ticket_messages')));
    }

    public function testPublicIdsAreUniqueAcrossSeededRows()
    {
        foreach (self::$db->list_tables() as $table) {
            $ids = array();
            foreach (self::$db->all($table) as $row) {
                if (!isset($row['public_id'])) continue;
                $this->assertNotContains($row['public_id'], $ids, "duplicate public_id in {$table}");
                $ids[] = $row['public_id'];
            }
        }
    }

    /* --------------------------- idempotency --------------------------- */

    public function testRunningBothSeedsTwiceCreatedNoDuplicates()
    {
        // setUpBeforeClass ran core+demo twice; counts below prove the second pass was a no-op.
        $this->assertCount(4, $this->rows('users'));
        $this->assertCount(4, $this->rows('wallets'));
        $this->assertCount(count(Demo_seeder::service_catalog()), $this->rows('services'));
        $this->assertCount(5, $this->rows('orders'));
        $this->assertCount(count(Core_seeder::default_settings()), $this->rows('settings'));
        $this->assertCount(4, $this->rows('price_groups'));
    }

    public function testSecondSeedPassDidNotDoubleCreditWallets()
    {
        $deposits = 0;
        foreach ($this->rows('wallet_transactions') as $tx) {
            if ($tx['type'] === 'DEPOSIT') $deposits++;
        }
        $this->assertSame(2, $deposits, 'only demo + reseller get an opening balance, once each');
    }
}
