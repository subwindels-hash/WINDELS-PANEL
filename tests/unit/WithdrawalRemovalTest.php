<?php
use PHPUnit\Framework\TestCase;

/**
 * Withdrawal removal gates (Session 30).
 *
 * The withdrawals feature is gone at every level — routes, controllers,
 * services, models, views, migrations dedicated exclusively to it, settings,
 * permissions, navigation, assets and the reseller API — while the wallet
 * itself and every supported purchase flow remain intact. Migration 018 is
 * the upgrade path for databases created while the feature existed. These
 * gates make sure the feature cannot quietly re-materialise, so CI is the
 * reintroduction guard the workorder demands.
 */
class WithdrawalRemovalTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!class_exists('CI_Migration')) eval('class CI_Migration { public $db; }');
        require_once self::$root.'/application/migrations/018_remove_withdrawals.php';
    }

    /* -------------------------- routes / URLs ---------------------------- */

    public function testNoWithdrawalRoutesSurvive()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');

        // What must not exist is a route that cashes out the *deposit wallet*.
        // /api/withdrawals pays out the separate earnings ledger and is a
        // different feature entirely — see EarningsPayoutIsolationTest, which
        // proves it cannot reach a wallet balance.
        foreach (array(
            'dashboard/withdraw', 'dashboard/withdrawals', 'wallet/withdraw',
            'admin/withdrawals', 'api/v1/withdrawals', 'withdrawal_requests',
        ) as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $routes,
                "route {$forbidden} would reintroduce wallet withdrawals");
        }

        // The wallet routes customers still need are present.
        $this->assertStringContainsString('dashboard/add-funds', $routes);
        $this->assertStringContainsString('dashboard/wallet/deposit', $routes);
        $this->assertStringContainsString('dashboard/transactions', $routes);
    }

    public function testLegacyWithdrawalUrlsHaveNoRouteAndTherefore404()
    {
        // CI3 falls through to show_404 for anything with no custom route and
        // no controller. The workorder's legacy URLs — plus historical
        // variants — must have neither, and must never be redirected to some
        // other financial action.
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array(
            'dashboard/withdrawals',
            'dashboard/withdrawals/create',
            'dashboard/withdrawals/(:any)',
            'dashboard/withdrawals/(:any)/cancel',
            'admin/withdrawals',
        ) as $url) {
            $this->assertStringNotContainsString("'".$url."'", $routes,
                'legacy withdrawal URL must stay unrouted (404 not redirect): '.$url);
        }
        $this->assertFileDoesNotExist(self::$root.'/application/controllers/dashboard/Withdrawals.php');
        $this->assertFileDoesNotExist(self::$root.'/application/controllers/admin/Withdrawals.php');
    }

    /* ------------------------- source excision --------------------------- */

    public function testNoWithdrawalSourceFilesSurvive()
    {
        foreach (array(
            'application/controllers/dashboard/Withdrawals.php',
            'application/controllers/admin/Withdrawals.php',
            'application/libraries/WithdrawalService.php',
            'application/models/Withdrawal_model.php',
            'application/views/dashboard/withdrawals/index.php',
            'application/views/dashboard/withdrawals/detail.php',
            'application/views/admin/withdrawals/index.php',
            'application/views/admin/withdrawals/detail.php',
            'tests/unit/WithdrawalTest.php',
        ) as $path) {
            $this->assertFileDoesNotExist(self::$root.'/'.$path, $path.' must stay deleted');
        }
        $this->assertFalse(is_dir(self::$root.'/application/views/dashboard/withdrawals'),
            'dashboard withdrawals view directory must stay deleted');
        $this->assertFalse(is_dir(self::$root.'/application/views/admin/withdrawals'),
            'admin withdrawals view directory must stay deleted');
    }

    public function testNoWithdrawalDedicatedMigrationSurvives()
    {
        $names = array();
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            $names[] = basename($file);
            if (basename($file) === '018_remove_withdrawals.php') continue; // the sanctioned retrofit
            $this->assertStringNotContainsStringIgnoringCase(
                'withdrawal', strtolower(basename($file)),
                'migration dedicated to withdrawals must stay deleted: '.basename($file));
        }
        sort($names);
        // Renumbered sequence stays gap-free; 018 is the upgrade retrofit.
        $this->assertContains('016_mass_orders.php', $names);
        $this->assertContains('017_marketplace_catalogue.php', $names);
        $this->assertContains('018_remove_withdrawals.php', $names);
    }

    public function testApplicationCodeHasNoWithdrawalReferences()
    {
        // The intentional exceptions are migration 018 (whose entire purpose is
        // to erase the feature's rows from upgraded databases) and a small set
        // of user-facing copy files that explain the platform deliberately has
        // NO withdrawals. The knowledge base and legal pages must say this or
        // customers would be misled — those are statements about an absent
        // feature, not references to a withdrawal feature.
        $copy_only = array(
            'application/libraries/SiteOperatorEngine.php',
            'application/libraries/SiteOperatorKnowledge.php',
            'application/views/public/terms.php',
            'application/views/public/refund_policy.php',
            'application/views/public/styleguide.php',
            'application/views/public/pricing.php',
        );

        // The *earnings* payout feature is a different thing from the removed
        // wallet withdrawal, and is allowed to use the word. What 018 removed
        // was cashing out a topped-up deposit balance; paying a referral
        // commission the platform owes is ordinary settlement. The structural
        // separation between the two is pinned by
        // EarningsPayoutIsolationTest, which fails if a payout can ever reach
        // the deposit wallet — that is the guarantee that matters, not the
        // absence of a string.
        $earnings_payouts = array(
            'application/controllers/Referral_api.php',
            'application/controllers/admin/Payouts.php',
            'application/controllers/dashboard/Earnings.php',
            'application/libraries/PayoutService.php',
            'application/libraries/EarningsService.php',
            'application/models/Payout_request_model.php',
            'application/views/dashboard/earnings/index.php',
            'application/views/admin/payouts/index.php',
            // Declares /api/withdrawals and /admin/payouts, both earnings-only.
            'application/config/routes.php',
        );
        $copy_only = array_merge($copy_only, $earnings_payouts);
        $clean = array(
            'application/controllers', 'application/core', 'application/libraries',
            'application/models', 'application/seeds', 'application/views',
            'application/config/routes.php',
        );
        $offenders = array();
        foreach ($clean as $path) {
            $full = self::$root.'/'.$path;
            $files = is_dir($full) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full)) : array($full);
            foreach ($files as $file) {
                $name = (string)$file;
                if (substr($name, -4) !== '.php') continue;
                $rel = ltrim(str_replace(self::$root.'/', '', $name), '/');
                if (in_array($rel, $copy_only, true)) continue;
                if (stripos(file_get_contents($name), 'withdrawal') !== false) {
                    $offenders[] = $name;
                }
            }
        }
        $this->assertSame(array(), $offenders, 'withdrawal references must not survive in app code');
    }

    public function testLedgerSettingsSeedsAndLayoutAreClean()
    {
        $ledger = file_get_contents(self::$root.'/application/libraries/LedgerService.php');
        $this->assertStringNotContainsString('reserve_withdrawal', $ledger);
        $this->assertStringNotContainsString('refund_withdrawal', $ledger);
        $this->assertStringNotContainsString('withdrawal_payable', $ledger);

        $seed = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        $this->assertStringNotContainsString('withdrawals.', $seed);
        $this->assertStringNotContainsString("'withdrawals'", $seed);

        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringNotContainsStringIgnoringCase('withdrawal', $layout);

        $stats = file_get_contents(self::$root.'/application/libraries/DashboardStats.php');
        $this->assertStringNotContainsString('WITHDRAWAL', $stats);
    }

    public function testResellerApiHasNoWithdrawalEndpointOrScope()
    {
        $api = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringNotContainsStringIgnoringCase('withdrawal', $api,
            'Api_v1 must not expose withdrawal endpoints or scopes');
        $docs = glob(self::$root.'/application/views/api/*.php') ?: array();
        foreach ($docs as $file) {
            $this->assertStringNotContainsStringIgnoringCase('withdrawal',
                file_get_contents($file), basename($file).' must not document withdrawals');
        }
    }

    public function testNoAssetOrUploadReferencesWithdrawals()
    {
        foreach (array('assets/js', 'assets/css') as $dir) {
            if (!is_dir(self::$root.'/'.$dir)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $this->assertStringNotContainsStringIgnoringCase(
                    'withdraw', file_get_contents((string)$file),
                    (string)$file.' contains a withdrawal reference');
            }
        }
    }

    /* -------------- upgrade path for existing installations -------------- */

    public function testMigration018IsTheSafeUpgradeForExistingDatabases()
    {
        // Drops the historical feature tables, child first, idempotently.
        $this->assertSame(array('withdrawal_events', 'withdrawal_requests'),
            Migration_Remove_withdrawals::dropped_tables());
        // …while tables() keeps the "tables this migration creates" contract.
        $this->assertSame(array(), Migration_Remove_withdrawals::tables());
        $file = self::$root.'/application/migrations/018_remove_withdrawals.php';
        $src = file_get_contents($file);
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $src);
        // Child-first order is asserted behaviourally by
        // testMigration018ActuallyRunsAgainstARealDatabaseShape below.

        // Feature rows removed from RBAC and settings on upgraded databases.
        foreach (array('withdrawals.view', 'withdrawals.process', 'withdrawals.reveal') as $perm) {
            $this->assertStringContainsString($perm, $src);
        }
        foreach (array('withdrawal_min_amount', 'withdrawal_max_amount',
                       'withdrawal_fee_fixed', 'withdrawal_fee_percent',
                       'withdrawal_require_verified_identity') as $setting) {
            $this->assertStringContainsString($setting, $src);
        }
        $this->assertStringContainsString('DELETE rp FROM role_permissions rp', $src);
        $this->assertStringContainsString('DELETE FROM permissions', $src);
        $this->assertStringContainsString('DELETE FROM settings', $src);

        // No statements() output — the generated fresh-install dump must not
        // carry drop-retrofit SQL.
        $this->assertSame(array(), Migration_Remove_withdrawals::statements());

        // The migration chain target accounts for the retrofit.
        $config = file_get_contents(self::$root.'/application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $mv);
        $version = (int)$mv[1];
        $this->assertSame(count(glob(self::$root.'/application/migrations/*.php')), $version,
            'sequential migration count must match migration_version');
        $this->assertGreaterThanOrEqual(18, $version,
            'the withdrawal-removal migration must be inside the chain');
    }

    public function testMigration018ActuallyRunsAgainstARealDatabaseShape()
    {
        // Rehearse BOTH shapes the upgrade matrix promises:
        //  (a) existing database WITH the feature's tables and seeded rows
        //  (b) fresh install where none of them exist
        foreach (array(true, false) as $with_history) {
            $mig = new Migration_Remove_withdrawals();
            $db = new Migration018FakeDb($with_history);
            $mig->db = $db;
            $mig->up(); // must not throw in either shape
            $this->assertSame(array('withdrawal_events', 'withdrawal_requests'),
                $db->dropped,
                'drops are issued in child-first order in both install shapes');
            $this->assertSame(array(), $db->rows('role_permissions'),
                'withdrawal grants removed');
            $this->assertSame($with_history ? 0 : 1, count($db->rows('permissions')),
                'feature permissions removed, everything else kept');
            $this->assertSame($with_history ? 0 : 1, count($db->rows('settings')),
                'feature settings removed, everything else kept');
            // Wallet, ledger and audit rows are never touched.
            $this->assertSame(1, count($db->rows('wallet_transactions')),
                'historical wallet rows are preserved');
            $this->assertSame(1, count($db->rows('audit_logs')));
        }
    }

    /* ------------------ the wallet must be fully intact ------------------- */

    public function testTheWalletItselfAndItsPurchaseFlowsAreUntouched()
    {
        $this->assertFileExists(self::$root.'/application/controllers/dashboard/Wallet.php');
        $this->assertFileExists(self::$root.'/application/models/Wallet_model.php');

        $ledger = file_get_contents(self::$root.'/application/libraries/LedgerService.php');
        foreach (array('public function charge(', 'public function credit(', 'public function refund(') as $needle) {
            $this->assertStringContainsString($needle, $ledger,
                'wallet ledger must keep: '.$needle);
        }
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString('dashboard/wallet/deposit', $routes);
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString('dashboard/add-funds', $layout,
            'wallet navigation must remain for customers');
    }
}

/**
 * Minimal stub DB driving migration 018's up() in both install shapes.
 * Implements exactly the query patterns the migration issues and enforces
 * that nothing unexpected (read: wallet/ledger/audit data) is queried at all.
 */
class Migration018FakeDb {
    public $dropped = array();
    private $store = array();
    private static $perm_keys = array('withdrawals.view', 'withdrawals.process', 'withdrawals.reveal');
    private static $setting_keys = array('withdrawal_min_amount', 'withdrawal_max_amount',
        'withdrawal_fee_fixed', 'withdrawal_fee_percent', 'withdrawal_require_verified_identity');

    public function __construct($with_history) {
        $this->store = array(
            'permissions' => $with_history
                ? array_map(function ($k) { return array('perm_key' => $k); }, self::$perm_keys)
                : array(array('perm_key' => 'orders.view')),
            'role_permissions' => $with_history ? array(array('permission_id' => 9)) : array(),
            'settings' => $with_history
                ? array_map(function ($k) { return array('setting_key' => $k); }, self::$setting_keys)
                : array(array('setting_key' => 'site_name')),
            'wallet_transactions' => array(array('type' => 'WITHDRAWAL', 'amount' => '100.00000000')),
            'audit_logs' => array(array('action' => 'withdrawal.requested')),
        );
    }

    public function query($sql) {
        if (preg_match('/DROP TABLE IF EXISTS (\w+)/i', $sql, $m)) {
            $this->dropped[] = $m[1];
            unset($this->store[$m[1]]);
            return true;
        }
        if (stripos($sql, 'DELETE rp FROM role_permissions') === 0) {
            $this->store['role_permissions'] = array();
            return true;
        }
        if (preg_match('/DELETE FROM (\w+) WHERE (\w+) IN \((.*)\)/i', $sql, $m)) {
            $table = $m[1]; $keycol = $m[2];
            preg_match_all("/'([^']+)'/", $m[3], $mm);
            $kill = $mm[1];
            $this->store[$table] = array_values(array_filter($this->store[$table],
                function ($row) use ($keycol, $kill) {
                    return !in_array($row[$keycol], $kill, true);
                }));
            return true;
        }
        throw new RuntimeException('Migration018FakeDb: unexpected SQL: '.$sql);
    }

    public function rows($table) {
        return isset($this->store[$table]) ? $this->store[$table] : array();
    }
}
