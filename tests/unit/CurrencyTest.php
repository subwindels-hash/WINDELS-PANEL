<?php
use PHPUnit\Framework\TestCase;

/**
 * Base-currency contract.
 *
 * The panel is denominated in Naira. That decision is spread across the config,
 * the money helper, the migration defaults and the seed data, and it is exactly
 * the kind of thing that rots when someone copies an old fixture. These tests
 * pin the contract at each layer so a regression fails loudly rather than
 * showing a customer a dollar sign on a naira balance.
 */
class CurrencyTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        require_once self::$root.'/application/helpers/marvy_helper.php';
        // Migrations extend CI_Migration; stub it so they can be loaded without
        // booting CodeIgniter (same approach as SchemaTest / export_schema).
        if (!class_exists('CI_Migration')) { eval('class CI_Migration { public $db; }'); }
        require_once self::$root.'/application/migrations/011_base_currency_ngn.php';
    }

    private function configArray()
    {
        // The config file assigns into $config; evaluate it in a local scope.
        $config = array();
        require self::$root.'/application/config/marvy.php';
        return $config;
    }

    /* ------------------------------ config ------------------------------ */

    public function testConfiguredBaseCurrencyIsNaira()
    {
        $config = $this->configArray();
        $this->assertSame('NGN', $config['marvy']['base_currency']);
    }

    /* ------------------------------ helper ------------------------------ */

    public function testMoneyHelperDefaultsToNairaOutsideOfCodeIgniter()
    {
        // No CI instance in this context, so the helper must fall back to the
        // panel's own currency rather than guessing a foreign one.
        $this->assertSame('NGN', marvy_base_currency());
        $this->assertSame('₦1,500.50', marvy_money('1500.5'));
        $this->assertSame('₦0.00', marvy_money(0));
    }

    public function testMoneyHelperStillHonoursAnExplicitCurrency()
    {
        // Provider balances and foreign-denominated invoices pass a currency
        // explicitly; those must not be relabelled as naira.
        $this->assertSame('$20.00', marvy_money('20', 'USD'));
        $this->assertSame('£20.00', marvy_money('20', 'GBP'));
        $this->assertSame('€20.00', marvy_money('20', 'EUR'));
    }

    public function testMoneyHelperFallsBackToTheCodeForUnknownCurrencies()
    {
        $this->assertSame('ZAR 20.00', marvy_money('20', 'ZAR'));
    }

    public function testMoneyHelperFormatsWithThousandsSeparatorsAndTwoDecimals()
    {
        // Naira amounts run large; grouping is what makes ₦5,000,000 readable.
        $this->assertSame('₦5,000,000.00', marvy_money('5000000'));
        $this->assertSame('₦1,234,567.89', marvy_money('1234567.891'));
    }

    /* ---------------------------- migrations ---------------------------- */

    public function testNoMigrationStillDefaultsACurrencyColumnToUsd()
    {
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            $sql = file_get_contents($file);
            // 011 mentions 'USD' in its down() and its docblock by design.
            if (basename($file) === '011_base_currency_ngn.php') continue;
            $this->assertStringNotContainsString(
                "DEFAULT 'USD'", $sql,
                basename($file).' still defaults a currency column to USD'
            );
        }
    }

    public function testEveryCurrencyColumnDefaultsToNaira()
    {
        $found = 0;
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            if (basename($file) === '011_base_currency_ngn.php') continue;
            $sql = file_get_contents($file);
            if (preg_match_all('/currency CHAR\(3\)[^,\n]*/', $sql, $m)) {
                foreach ($m[0] as $decl) {
                    if (strpos($decl, 'DEFAULT') === FALSE) continue; // NOT NULL, caller supplies
                    $found++;
                    $this->assertStringContainsString("DEFAULT 'NGN'", $decl, $decl);
                }
            }
        }
        // wallets, wallet transactions, providers, orders, drip-feed
        // subscriptions, service transactions and Fundsvera checkouts. (An
        // eighth used to be the withdrawals table, which no longer exists.)
        //
        // The count is asserted as a floor rather than an exact number: the
        // property under test is "every currency column that carries a default
        // defaults to naira", which the loop above already enforces for each
        // one it finds. Pinning an exact total only means a later migration
        // fails this test for adding a correctly-defaulted column.
        $this->assertGreaterThanOrEqual(6, $found,
            'the defaulted currency columns are all naira');
    }

    /* ----------------------- application source ------------------------- */

    public function testApplicationCodeDoesNotHardcodeUsdAsAFallbackCurrency()
    {
        $offenders = array();
        $dirs = array('libraries', 'controllers', 'models', 'views', 'seeds');
        foreach ($dirs as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$root.'/application/'.$dir)
            );
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') continue;
                foreach (file($f->getPathname()) as $n => $line) {
                    // A bare 'USD' literal assigned to a currency field is the
                    // bug; the word appearing in prose or a currency table is not.
                    if (preg_match("/'currency'\s*=>\s*'USD'/", $line)
                        || preg_match("/\?\?\s*'USD'/", $line)
                        || preg_match("/:\s*'USD'/", $line)) {
                        $offenders[] = str_replace(self::$root.'/', '', $f->getPathname()).':'.($n + 1);
                    }
                }
            }
        }
        $this->assertSame(array(), $offenders, "hardcoded USD fallback(s):\n".implode("\n", $offenders));
    }

    public function testNoViewPrintsAHardcodedDollarSignForALiveTotal()
    {
        // The live price calculators used to concatenate '$' in JavaScript,
        // which survived every server-side currency change.
        $offenders = array();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application/views')
        );
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            foreach (file($f->getPathname()) as $n => $line) {
                if (preg_match("/textContent\s*=\s*'\\\$'/", $line)) {
                    $offenders[] = str_replace(self::$root.'/', '', $f->getPathname()).':'.($n + 1);
                }
            }
        }
        $this->assertSame(array(), $offenders, "hardcoded \$ in live total(s):\n".implode("\n", $offenders));
    }

    public function testAutomaticCurrencyRateUpdatesAreWiredEndToEnd()
    {
        $config = file_get_contents(self::$root.'/application/config/marvy.php');
        $this->assertMatchesRegularExpression("~'currency_rates'\s*=>\s*'0 \* \* \* \*'~", $config,
            'currency rates must run hourly from the shared cron schedule');

        $controller = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $this->assertStringContainsString('public function currency_rates()', $controller);

        $registry = file_get_contents(self::$root.'/application/libraries/CronRegistry.php');
        $this->assertStringContainsString("'currency_rates',", $registry,
            'the CLI, auto-run heartbeat and admin Run now must resolve the same worker');

        $workers = file_get_contents(self::$root.'/application/libraries/CronWorkers.php');
        foreach (array('public function currency_rates()', 'SecureHttpClient', 'open.er-api.com',
                       'currency_rates_from_payload', 'base mismatch', 'set_rate(', 'refresh_base_rate(') as $needle) {
            $this->assertStringContainsString($needle, $workers);
        }
        $this->assertStringContainsString('NGN (the base row) must update too', $workers);

        $model = file_get_contents(self::$root.'/application/models/Currency_model.php');
        $this->assertStringContainsString('public function refresh_base_rate(', $model);
        $this->assertStringContainsString("'exchange_rate'      => '1.00000000'", $model,
            'the automatic update may refresh NGN metadata, but the base rate itself stays pinned');

        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('cron currency_rates', $crontab);

        $env = file_get_contents(self::$root.'/.env.example');
        $this->assertStringContainsString('VP_CURRENCY_RATE_API_URL', $env);
        $this->assertStringContainsString('VP_CURRENCY_RATE_TIMEOUT', $env);

        $view = file_get_contents(self::$root.'/application/views/admin/currencies/index.php');
        $this->assertStringContainsString('Automatic rate updates are on', $view);
        $this->assertStringContainsString('including NGN', $view);
        $this->assertStringContainsString('Update all currencies at once', $view);
        $this->assertSame(1, substr_count($view, "site_url('admin/currencies/update-all')"),
            'the admin page should have one clear button for refreshing all currencies at once');

    }

    /* ------------------- manual base (NGN) rate box --------------------- */

    public function testTheBaseCurrencyRowHasItsOwnManualRateBox()
    {
        $view = file_get_contents(self::$root.'/application/views/admin/currencies/index.php');
        $this->assertStringContainsString("site_url('admin/currencies/base-rate')", $view,
            'NGN needs a manual rate box of its own, like every other currency');
        $this->assertStringContainsString('0.00075300', $view);

        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString(
            '$route[\'admin/currencies/base-rate\'] = \'admin/currencies/set_base_rate\';', $routes);

        $controller = file_get_contents(self::$root.'/application/controllers/admin/Currencies.php');
        $this->assertStringContainsString('public function set_base_rate()', $controller);
        $this->assertStringContainsString('$this->guard();', $controller);

        $service = file_get_contents(self::$root.'/application/libraries/CurrencyService.php');
        foreach (array('public function set_base_rate(', 'public function base_quote_code(',
                       'public function base_rate(', 'currency.base_rate_set') as $needle) {
            $this->assertStringContainsString($needle, $service);
        }

        $model = file_get_contents(self::$root.'/application/models/Currency_model.php');
        $this->assertStringContainsString('if ((int)$row->is_base === 1) return false;', $model,
            'the stored base rate itself must stay pinned at 1.0 whatever the box writes');
    }

    /* ----------------- rate changes reach product prices ---------------- */

    public function testEveryProductSurfaceRendersThroughTheSharedPriceHelper()
    {
        $helper = file_get_contents(self::$root.'/application/helpers/marvy_helper.php');
        foreach (array('function marvy_price(', 'function marvy_price_text(', 'function marvy_display_rate(',
                       'function marvy_display_currency(') as $needle) {
            $this->assertStringContainsString($needle, $helper);
        }

        // A price shown anywhere a customer shops must go through the helper,
        // otherwise a rate change in Admin → Currencies reaches some screens
        // and not others and the same product shows two prices.
        $surfaces = array(
            'application/views/public/services/index.php',
            'application/views/public/services/detail.php',
            'application/views/dashboard/services/index.php',
            'application/views/public/shop/index.php',
            'application/views/public/shop/product.php',
            'application/views/dashboard/marketplace/index.php',
            'application/views/dashboard/marketplace/listing.php',
            'application/views/homepages/aurora/index.php',
            'application/views/homepages/nexus/index.php',
            'application/views/homepages/pulse/index.php',
        );
        foreach ($surfaces as $rel) {
            $this->assertStringContainsString('marvy_price(', file_get_contents(self::$root.'/'.$rel),
                $rel.' must price through marvy_price() so currency changes reach it');
        }
    }

    public function testLiveTotalsUseTheServerRenderedRate()
    {
        foreach (array('application/views/public/services/detail.php',
                       'application/views/dashboard/orders/new_order.php') as $rel) {
            $view = file_get_contents(self::$root.'/'.$rel);
            $this->assertStringContainsString('marvy_display_rate()', $view,
                $rel.' must take its conversion rate from the server, not hardcode one');
            $this->assertStringContainsString('ws-total-approx', $view);
        }
    }

    public function testCheckoutTotalsStayDenominatedInTheBaseCurrency()
    {
        // The converted figure is an estimate beside the real charge; the
        // charge itself must still be the base-currency total.
        foreach (array('application/views/public/shop/cart.php',
                       'application/views/public/shop/checkout.php') as $rel) {
            $view = file_get_contents(self::$root.'/'.$rel);
            $this->assertStringContainsString('marvy_money($total, $currency)', $view,
                $rel.' must keep charging the base-currency total');
            $this->assertStringContainsString('marvy_base_currency()', $view);
        }
    }

    public function testRateWritesDropTheCurrencyMemo()
    {
        $service = file_get_contents(self::$root.'/application/libraries/CurrencyService.php');
        // Four mutations (active, default, rate, base refresh) each invalidate
        // the per-request memo, so a price rendered after a change is truthful.
        $this->assertSame(4, substr_count($service, '$this->forget();'),
            'every currency mutation must drop the memo so prices re-read the new rate');
    }

    /* -------------------------- migration 011 --------------------------- */

    public function testTheRedenominationMigrationCreatesNoTables()
    {
        $this->assertSame(array(), Migration_Base_currency_ngn::tables());
    }

    public function testTheRedenominationMigrationRelabelsWithoutTouchingAmounts()
    {
        foreach (Migration_Base_currency_ngn::statements() as $sql) {
            if (strpos($sql, 'UPDATE') !== 0) continue;
            // Money columns must never be arithmetically rewritten here: this
            // migration moves labels, it does not convert balances.
            $this->assertDoesNotMatchRegularExpression(
                '/SET\s+(balance|amount|charge|cost)\s*=/i', $sql, $sql
            );
        }
    }

    public function testTheRedenominationMigrationMakesNgnTheSingleBase()
    {
        $sql = implode("\n", Migration_Base_currency_ngn::statements());
        $this->assertStringContainsString("UPDATE currencies SET is_base = 0", $sql);
        $this->assertMatchesRegularExpression("/is_base = 1.*code = 'NGN'/", $sql);
    }
}
