<?php
use PHPUnit\Framework\TestCase;

/**
 * Withdrawal removal gates (Session 30.x).
 *
 * The withdrawals feature is gone at every level — routes, controllers,
 * services, models, views, migrations dedicated exclusively to it, settings,
 * permissions, navigation and wallet ledger plumbing — while the wallet
 * itself and every supported purchase flow remain intact. These gates make
 * sure the feature cannot quietly re-materialise.
 */
class WithdrawalRemovalTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
    }

    public function testNoWithdrawalRoutesSurvive()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringNotContainsStringIgnoringCase('withdrawal', $routes);
        // The wallet routes customers still need are present.
        $this->assertStringContainsString('dashboard/add-funds', $routes);
        $this->assertStringContainsString('dashboard/wallet/deposit', $routes);
        $this->assertStringContainsString('dashboard/transactions', $routes);
    }

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
            $this->assertStringNotContainsStringIgnoringCase(
                'withdrawal', strtolower(basename($file)),
                'migration dedicated to withdrawals must stay deleted: '.basename($file));
        }
        sort($names);
        // Renumbered sequence stays gap-free after removing 016_withdrawals.
        $this->assertContains('016_mass_orders.php', $names);
        $this->assertContains('017_marketplace_catalogue.php', $names);
    }

    public function testApplicationCodeHasNoWithdrawalReferences()
    {
        foreach (array(
            'application/libraries/LedgerService.php',
            'application/libraries/SettingsService.php',
            'application/libraries/DashboardStats.php',
            'application/seeds/Core_seeder.php',
            'application/views/layouts/app.php',
        ) as $path) {
            $code = file_get_contents(self::$root.'/'.$path);
            $this->assertStringNotContainsStringIgnoringCase('withdrawal', $code,
                $path.' must not reference withdrawals');
        }

        // Specifically: no ledger counter-account or settings keys.
        $ledger = file_get_contents(self::$root.'/application/libraries/LedgerService.php');
        $this->assertStringNotContainsString('reserve_withdrawal', $ledger);
        $this->assertStringNotContainsString('refund_withdrawal', $ledger);
        $this->assertStringNotContainsString('withdrawal_payable', $ledger);

        $seed = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        $this->assertStringNotContainsString('withdrawals.', $seed);
        $this->assertStringNotContainsString("'withdrawals'", $seed);
    }

    public function testNoWithdrawalNavOrPermissionWiringSurvivesAnywhere()
    {
        // Repo-wide sweep over executable app code (docs may carry the
        // historical record).
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
                if (stripos(file_get_contents($name), 'withdrawal') !== false) {
                    $offenders[] = $name;
                }
            }
        }
        $this->assertSame(array(), $offenders, 'withdrawal references must not survive in app code');
    }

    public function testTheWalletItselfAndItsPurchaseFlowsAreUntouched()
    {
        // The wallet stays: controller, model, ledger money movement and the
        // routes customers use to view it.
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
