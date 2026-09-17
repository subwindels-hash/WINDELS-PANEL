<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Deposits charged in the DEFAULT currency, settled in the BASE currency.
 *
 * The panel has always had two currency concepts that never met at the payment
 * gateway. The base currency is what the books are kept in; the default
 * display currency is what every price on the site is quoted in and what the
 * customer thinks in. `Wallet::deposit()` hardcoded the base currency, so a
 * panel keeping its books in USD while serving Nigerian customers showed ₦
 * prices everywhere and then handed Paystack a *dollar* charge — and quoted no
 * rate at all, so ₦1,328 was worth whatever the wallet happened to say
 * afterwards.
 *
 * The behaviour these tests pin:
 *
 *   - the CHARGE is in the pay currency (the default display currency) and is
 *     what payment_transactions carries; an NGN-only rail gets a separately
 *     recorded converted collection leg rather than changing that charge;
 *   - the SETTLEMENT is in the base currency, and the wallet is credited with
 *     that leg — ₦1,328 in, $1 credited;
 *   - the RATE is pinned on the deposit when it is opened, so a rate change
 *     between "Continue" and the webhook cannot re-price an authorised
 *     payment; the customer pays at the rate they were shown;
 *   - limits, fees and bonuses are base-currency configuration and are applied
 *     on the base leg, so a ₦-denominated minimum does not become a $ one;
 *   - a panel where the two currencies coincide (the shipped NGN default)
 *     behaves exactly as it always did.
 */
class DepositCurrencyTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('site_url')) eval('function site_url($p=""){ return "http://panel.test/".$p; }');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
    }

    protected function setUp(): void
    {
        $this->reset_currency_memos();
    }

    protected function tearDown(): void
    {
        $this->reset_currency_memos();
    }

    /**
     * Currency answers are memoised per request for performance; a test
     * process is many "requests", so every world starts from a clean memo or
     * the second test in the file silently runs in the first one's currency.
     */
    private function reset_currency_memos()
    {
        marvy_forget_base_currency();
        marvy_forget_display_currency();
        if (class_exists('Currency_model')) Currency_model::forget();
    }

    /**
     * A world with a base currency, an optional second currency, and a manual
     * payment method that needs no credentials.
     *
     * @param string $base   the accounting currency
     * @param array  $extra  code => rate (units per 1 base), all active
     * @param string|null $display the default display / pay currency
     */
    private function app($base = 'NGN', array $extra = array(), $display = null)
    {
        $app = new IntegrationHarness(array('base_currency' => $base));
        $app->seed_minimal();
        $now = gmdate('Y-m-d H:i:s');

        // seed_minimal() always seeds NGN as the base row. When the test wants
        // a different accounting currency, move the flag rather than inserting
        // a second is_base row — two bases is not a state the panel can be in.
        if (strtoupper($base) !== 'NGN') {
            $app->db->where('code', 'NGN')->update('currencies', array('is_base' => 0));
            $app->db->insert('currencies', array(
                'code' => strtoupper($base), 'name' => $base, 'symbol' => '$',
                'exchange_rate' => '1.00000000', 'is_base' => 1, 'is_active' => 1,
                'updated_at' => $now,
            ));
        }
        foreach ($extra as $code => $rate) {
            $code = strtoupper($code);
            // NGN is always present (seed_minimal inserts it as the shipped
            // base); when the test has moved the base elsewhere, this is the
            // rate that row now needs, not a second row with the same code.
            if ($app->db->where('code', $code)->get('currencies')->row()) {
                $app->db->where('code', $code)->update('currencies',
                    array('exchange_rate' => $rate, 'is_active' => 1, 'updated_at' => $now));
                continue;
            }
            $app->db->insert('currencies', array(
                'code' => $code, 'name' => $code, 'symbol' => $code === 'NGN' ? '₦' : '$',
                'exchange_rate' => $rate, 'is_base' => 0, 'is_active' => 1,
                'updated_at' => $now,
            ));
        }

        $app->db->insert('payment_methods', array(
            'public_id' => 'PM000000000000000000000001',
            'code' => 'manual', 'name' => 'Bank transfer', 'type' => 'MANUAL',
            'is_active' => 1, 'sorting' => 1,
            // Base-currency configuration. Deliberately permissive so a test
            // about conversion is not also a test about bounds; the bounds
            // test raises the minimum to the shipped ₦500 itself.
            'min_amount' => '0.10000000', 'max_amount' => '5000000.00000000',
            'fee_percent' => '0', 'fee_fixed' => '0', 'bonus_percent' => '0',
            'instructions' => 'Pay into the account below.',
            'created_at' => $now, 'updated_at' => $now,
        ));

        $app->model(array('Currency_model', 'Setting_model', 'Wallet_model',
                          'Payment_transaction_model'));
        $app->library(array('LedgerService', 'CurrencyService', 'PaymentService'));

        if ($display !== null) {
            $app->Setting_model->set('default_display_currency', strtoupper($display), 'currency');
        }
        $this->reset_currency_memos();
        if (isset($app->currencyservice)) $app->currencyservice->forget();

        return $app;
    }

    /**
     * A customer whose wallet holds `$holds` (default: the base currency).
     *
     * The harness always creates an NGN wallet, so on a USD panel the default
     * customer would silently be testing the FOREIGN-wallet path as well —
     * LedgerService converting the base credit into the wallet's own currency.
     * That path has its own test; the conversion tests want a wallet that
     * simply holds the accounting currency, so the credit reaching it is the
     * number under test rather than a second conversion of it.
     */
    private function customer($app, $holds = null)
    {
        $holds = strtoupper((string)($holds ?: marvy_base_currency()));
        $u = $app->register('dep'.random_int(1000, 9999), 'dep'.random_int(1000, 9999).'@x.test');
        $app->db->where('user_id', $u->id)->update('wallets', array('currency' => $holds));
        return array($u, $app->db->where('user_id', $u->id)->get('wallets')->row());
    }

    private function deposit($app, $user, $amount, array $extra = array())
    {
        return $app->paymentservice->deposit($user, array_merge(array(
            'payment_method' => 'manual',
            'amount'         => $amount,
        ), $extra));
    }

    /* ================= the panel as shipped: one currency ================ */

    public function testASingleCurrencyPanelIsCompletelyUnchanged()
    {
        $app = $this->app('NGN');
        list($user) = $this->customer($app);

        $res = $this->deposit($app, $user, '5000');
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        $tx = $res['transaction'];
        $this->assertSame('NGN', $tx->currency);
        $this->assertSame('NGN', $tx->base_currency);
        // Charge and settlement are the same leg, at the identity rate.
        $this->assertSame(0, bccomp('5000', (string)$tx->amount, 8));
        $this->assertSame(0, bccomp('5000', (string)$tx->base_amount, 8));
        $this->assertSame(0, bccomp('1', (string)$tx->fx_rate, 8));
        $this->assertSame(0, bccomp((string)$tx->credited_amount,
            (string)$tx->credited_base_amount, 8));
    }

    /* ================= the request: pay NGN, hold USD =================== */

    /** $1 = ₦1,328: paying ₦1,328 credits exactly $1. */
    public function testPayingInNairaCreditsTheBaseCurrencyEquivalent()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        list($user, $wallet) = $this->customer($app);

        $res = $this->deposit($app, $user, '1328');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];

        // The gateway is handed naira — what the customer sees and typed.
        $this->assertSame('NGN', $tx->currency);
        $this->assertSame(0, bccomp('1328', (string)$tx->amount, 8));

        // The wallet is credited in dollars: 1328 ÷ 1328 = 1.
        $this->assertSame('USD', $tx->base_currency);
        $this->assertSame(0, bccomp('1', (string)$tx->base_amount, 8),
            'a ₦1,328 deposit at 1328/USD must settle as exactly $1, got '.$tx->base_amount);
        $this->assertSame(0, bccomp('1328', (string)$tx->fx_rate, 8));
    }

    /**
     * Manual / Bank Transfer is bound to the default currency in the service,
     * not just by a well-behaved controller. A stale or malicious caller that
     * explicitly posts the base currency must not turn an NGN-labelled form
     * into a USD transfer instruction.
     */
    public function testManualBankTransferCannotBeForcedBackToTheBaseCurrency()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        list($user) = $this->customer($app);

        $res = $this->deposit($app, $user, '1328', array('currency' => 'USD'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('NGN', $res['transaction']->currency,
            'Manual / Bank Transfer must follow the panel default, not the posted/base currency');
        $this->assertSame(0, bccomp('1328', (string)$res['transaction']->amount, 8));
        $this->assertSame('USD', $res['transaction']->base_currency);
        $this->assertSame(0, bccomp('1', (string)$res['transaction']->base_amount, 8));
        $this->assertSame('NGN', $res['checkout']['currency']);
    }

    /** Fundsvera uses that same default-currency policy before its adapter runs. */
    public function testFundsveraResolvesToTheDefaultCurrencyNotTheBaseCurrency()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        $method = (object)array('code' => 'fundsvera', 'name' => 'Bank Transfer');

        $this->assertSame('NGN', $app->paymentservice->charge_currency_for($method, 'USD'));
        $this->assertTrue($app->paymentservice->method_supports_currency($method, 'NGN'));
        // A USD charge is fine too — the service converts it into naira at
        // the configured rate before Fundsvera's adapter ever sees it.
        $this->assertTrue($app->paymentservice->method_supports_currency($method, 'USD'),
            'a USD default is served by converting into the NGN rail at the configured rate');
        $this->assertSame('NGN', $app->paymentservice->collect_currency_for($method, 'USD'),
            'the provider is handed naira, never a USD figure it would read as naira');
    }

    /**
     * A USD-default panel (books also in USD) still takes Fundsvera deposits:
     * the typed dollar amount remains the customer-facing payment while its
     * naira equivalent is sent to the provider as a separate collection leg.
     * This fixes the "Fundsvera collects NGN only / default is USD" refusal
     * without falling back to the accounting/base currency.
     */
    public function testFundsveraConvertsAUsdDefaultChargeIntoNaira()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'USD');
        $method = (object)array('code' => 'fundsvera', 'name' => 'Bank Transfer');

        // The method stays offered: USD is collectable via the NGN rail.
        $this->assertTrue($app->paymentservice->method_supports_currency($method, 'USD'));
        foreach (array('base_currency', 'base_amount', 'credited_base_amount', 'fx_rate') as $column) {
            unset($app->db->schema['payment_transactions']['columns'][$column]);
        }
        $this->assertTrue($app->paymentservice->method_supports_currency($method, 'USD'),
            'the Fundsvera option must not disappear just because the SQL migration is pending');

        $rail = $app->paymentservice->rail_charge($method, '10.00000000', 'USD');
        $this->assertTrue($rail['ok'], $rail['error'] ?? '');
        $this->assertSame('NGN', $rail['currency']);
        $this->assertSame(0, bccomp('13280', $rail['amount'], 8),
            '$10 at 1328/USD must be quoted to the bank as exactly ₦13,280, got '.$rail['amount']);
        $this->assertTrue(!empty($rail['converted']));
        $this->assertSame('10.00000000', $rail['typed_amount']);
        $this->assertSame('USD', $rail['typed_currency']);
    }

    /**
     * The stored transaction keeps the default currency while reconciliation
     * checks Fundsvera's separate NGN collection leg. Base currency is not
     * substituted into either side.
     */
    public function testFundsveraKeepsDefaultCurrencySeparateFromItsNgnRail()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'USD');
        $tx = (object)array(
            'amount' => '10.00000000',
            'currency' => 'USD',
            'metadata' => json_encode(array('collection' => array(
                'amount' => '13280.00000000',
                'currency' => 'NGN',
                'rate' => '1328.00000000',
            ))),
        );

        $method = new ReflectionMethod($app->paymentservice, 'provider_expected_money');
        $expected = $method->invoke($app->paymentservice, $tx);

        $this->assertSame('USD', $tx->currency,
            'the payment transaction must remain in the configured default currency');
        $this->assertSame('10.00000000', $tx->amount);
        $this->assertSame('NGN', $expected['currency'],
            'Fundsvera webhooks must be checked against the provider collection currency');
        $this->assertSame('13280.00000000', $expected['amount']);

        // The same deposit can be completed with the card button. An explicit
        // USD card callback covers the stored default-currency payment, not the
        // unused Fundsvera NGN transfer instruction.
        $cardExpected = $method->invoke($app->paymentservice, $tx, 'USD');
        $this->assertSame('USD', $cardExpected['currency']);
        $this->assertSame('10.00000000', $cardExpected['amount']);
    }

    /**
     * Without a usable NGN rate the conversion is refused loudly — a $100
     * deposit must never reach a bank rail that will read it as ₦100.
     */
    public function testFundsveraWithoutAnNgnRateIsRefusedNotMisread()
    {
        $app = $this->app('USD', array(), 'USD'); // NGN row exists but rate is 1:1 leftovers
        // Deactivate NGN entirely: no usable rate.
        $app->db->where('code', 'NGN')->update('currencies', array('is_active' => 0));
        Currency_model::forget();
        $this->reset_currency_memos();

        $method = (object)array('code' => 'fundsvera', 'name' => 'Bank Transfer');
        $this->assertFalse($app->paymentservice->method_supports_currency($method, 'USD'),
            'no NGN rate means no conversion — the method must be hidden, not misread');

        $rail = $app->paymentservice->rail_charge($method, '100.00000000', 'USD');
        $this->assertFalse($rail['ok']);
        $this->assertSame('NO_RATE', $rail['code']);
    }

    /** The credit that reaches the wallet is the base leg, not the charge. */
    public function testConfirmationCreditsTheBaseLegNotTheChargeAmount()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        list($user, $wallet) = $this->customer($app);

        $res = $this->deposit($app, $user, '1328');
        $confirmed = $app->paymentservice->confirm($res['transaction'], 'SYSTEM');
        $this->assertTrue($confirmed['ok'], $confirmed['error'] ?? '');

        $wallet = $app->db->where('id', $wallet->id)->get('wallets')->row();
        $this->assertSame(0, bccomp('1', (string)$wallet->balance, 8),
            'the wallet must hold $1, not ₦1,328 mislabelled as dollars — got '.$wallet->balance);
    }

    /**
     * The pinned rate is what settles, even if the market moves first.
     *
     * This is the difference between quoting a rate and honouring it. A
     * customer shown "$1 = ₦1,328" who pays ₦1,328 gets $1 — not $0.95 because
     * the naira slid while their bank transfer was in flight.
     */
    public function testARateChangeAfterInitiationDoesNotRepriceTheDeposit()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        list($user, $wallet) = $this->customer($app);

        $res = $this->deposit($app, $user, '1328');
        $this->assertSame(0, bccomp('1', (string)$res['transaction']->base_amount, 8));

        // The naira slides to 1,400 before the webhook lands.
        $app->db->where('code', 'NGN')->update('currencies',
            array('exchange_rate' => '1400.00000000'));
        Currency_model::forget();
        $this->reset_currency_memos();

        $tx = $app->Payment_transaction_model->find_by_id($res['transaction']->id);
        $confirmed = $app->paymentservice->confirm($tx, 'WEBHOOK');
        $this->assertTrue($confirmed['ok'], $confirmed['error'] ?? '');

        $wallet = $app->db->where('id', $wallet->id)->get('wallets')->row();
        $this->assertSame(0, bccomp('1', (string)$wallet->balance, 8),
            'the deposit must settle at the rate pinned when it was opened');
    }

    /**
     * A wallet that holds a third currency still settles correctly.
     *
     * PaymentService converts the charge into the base currency; LedgerService
     * then converts the base credit into whatever the wallet holds. The two
     * conversions are separate boundaries and must compose: ₦1,328 paid into a
     * naira wallet on a dollar panel is $1 of value, which is ₦1,328 again.
     */
    public function testAForeignWalletIsCreditedThroughTheLedgerBoundary()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        list($user, $wallet) = $this->customer($app, 'NGN');

        $res = $this->deposit($app, $user, '1328');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        // PaymentService's leg: the wallet is owed $1.
        $this->assertSame(0, bccomp('1', (string)$res['transaction']->base_amount, 8));

        $app->paymentservice->confirm($res['transaction'], 'SYSTEM');

        $wallet = $app->db->where('id', $wallet->id)->get('wallets')->row();
        $this->assertSame('NGN', $wallet->currency);
        $this->assertSame(0, bccomp('1328', (string)$wallet->balance, 8),
            'the naira wallet must hold the naira value of the $1 credited');

        // LedgerService's leg: the movement records what it was worth in base.
        $wt = $app->db->where('wallet_id', $wallet->id)->get('wallet_transactions')->row();
        $this->assertSame(0, bccomp('1', (string)$wt->base_amount, 8));
        $this->assertSame(0, bccomp('1328', (string)$wt->fx_rate, 8));
    }

    /* ========================= limits and fees ========================== */

    /**
     * `min_amount` is a base-currency column, so on a USD panel it means $500 —
     * and a naira customer must be told the naira figure, not refused against
     * a number in a currency they never see.
     */
    public function testDepositBoundsAreAppliedToTheBaseLeg()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        // The shipped configuration: a 500-unit minimum in the base currency.
        $app->db->where('code', 'manual')->update('payment_methods',
            array('min_amount' => '500.00000000'));
        list($user) = $this->customer($app);

        // ₦1,328 = $1, which is below the $500 minimum.
        $res = $this->deposit($app, $user, '1328');
        $this->assertFalse($res['ok']);
        $this->assertSame('AMOUNT_TOO_LOW', $res['code']);
        // Quoted back in what the customer pays in: 500 × 1328 = ₦664,000.
        $this->assertStringContainsString('664,000', $res['error'],
            'the minimum must be quoted in the pay currency, got: '.$res['error']);

        // ₦664,000 = exactly $500 and is accepted.
        $ok = $this->deposit($app, $user, '664000');
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame(0, bccomp('500', (string)$ok['transaction']->base_amount, 8));
    }

    /**
     * A fixed fee is a base-currency amount. Charging it as though it were in
     * the pay currency would turn a $0.30 fee into a ₦0.30 one (or worse, the
     * other way round).
     */
    public function testAFixedFeeIsABaseCurrencyAmountConvertedForTheCharge()
    {
        $app = $this->app('USD', array('NGN' => '1000.00000000'), 'NGN');
        $app->db->where('code', 'manual')->update('payment_methods',
            array('fee_fixed' => '0.30000000'));
        list($user, $wallet) = $this->customer($app);

        // ₦10,000 = $10. Fee $0.30 → credited $9.70, shown as ₦300 / ₦9,700.
        $res = $this->deposit($app, $user, '10000');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];

        $this->assertSame(0, bccomp('9.70', (string)$tx->credited_base_amount, 8),
            'the $0.30 fee must come off the dollar leg, got '.$tx->credited_base_amount);
        $this->assertSame(0, bccomp('300', (string)$tx->fee, 8),
            'the fee shown to the customer must be in naira, got '.$tx->fee);

        $app->paymentservice->confirm($tx, 'SYSTEM');
        $wallet = $app->db->where('id', $wallet->id)->get('wallets')->row();
        $this->assertSame(0, bccomp('9.70', (string)$wallet->balance, 8));
    }

    /* =========================== failing safe =========================== */

    /**
     * A pay currency with no usable rate is refused. Treating a missing rate
     * as 1:1 would credit a ₦1,328 payment as $1,328.
     */
    public function testADepositIsRefusedWhenTheRateIsUnusable()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        $app->db->where('code', 'NGN')->update('currencies', array('exchange_rate' => '0'));
        Currency_model::forget();
        list($user) = $this->customer($app);

        $res = $this->deposit($app, $user, '1328');
        $this->assertFalse($res['ok']);
        $this->assertSame('NO_RATE', $res['code']);
        $this->assertCount(0, $app->rows('payment_transactions'),
            'an unpriceable deposit must not leave a transaction behind');
    }

    /* ======================= what the customer sees ===================== */

    /** The rate sentence every surface quotes is built in exactly one place. */
    public function testTheRateLineReadsTheWayACustomerExpects()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        $this->assertSame('$1.00 = ₦1,328.00', marvy_rate_line('1328', 'NGN', 'USD'));
    }

    /** The pay currency IS the default display currency — not the base one. */
    public function testThePayCurrencyFollowsTheDefaultDisplayCurrency()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        $this->assertSame('USD', marvy_base_currency());
        $this->assertSame('NGN', marvy_pay_currency());
        $this->assertSame('NGN', $app->paymentservice->pay_currency());
    }

    /** With no display currency configured, the two coincide and nothing moves. */
    public function testThePayCurrencyFallsBackToTheBaseCurrency()
    {
        $app = $this->app('NGN');
        $this->assertSame('NGN', marvy_pay_currency());
        $this->assertSame(marvy_base_currency(), $app->paymentservice->pay_currency());
    }

    /**
     * The symbol handed to the client-side running total is the same one the
     * server prints, including in code-display mode — the browser total must
     * not read "$1,328" next to a server-rendered "NGN 1,328.00".
     */
    public function testTheCurrencySymbolHelperMatchesTheFormatter()
    {
        $this->app('NGN');
        $this->assertSame('₦', marvy_currency_symbol('NGN'));
        $this->assertSame('$', marvy_currency_symbol('USD'));
        // Whatever it returns, it must be the literal prefix marvy_money uses.
        $this->assertSame(marvy_money(0, 'NGN'), marvy_currency_symbol('NGN').'0.00');
    }

    /* ==================== the surfaces that must agree =================== */

    /**
     * The controller cannot hardcode the base currency at the point of charge:
     * that single line is the whole bug.
     */
    public function testControllersChargeInThePayCurrency()
    {
        foreach (array('application/controllers/dashboard/Wallet.php',
                       'application/controllers/Payments.php') as $file) {
            $src = file_get_contents(self::$root.'/'.$file);
            $this->assertStringNotContainsString("'currency'        => marvy_base_currency()", $src,
                $file.' still hands the gateway the accounting currency');
            $this->assertStringContainsString('marvy_pay_currency()', $src,
                $file.' must charge in the default display currency');
        }
    }

    /** Add Funds has to hand the view the rate it will actually charge at. */
    public function testAddFundsIsGivenThePayCurrencyAndItsRate()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Wallet.php');
        $this->assertStringContainsString("'pay_currency'", $src);
        $this->assertStringContainsString("'fx_rate'", $src);

        $view = file_get_contents(self::$root.'/application/views/dashboard/wallet/add_funds.php');
        $this->assertStringContainsString('marvy_rate_line', $view,
            'Add Funds must print the conversion rate it charges at');
        $this->assertStringContainsString('ws-credit', $view,
            'Add Funds must show what the typed amount credits in the base currency');
    }

    /** Wallet surfaces show the balance in both currencies, through one partial. */
    public function testWalletSurfacesShowTheBalanceInBothCurrencies()
    {
        $partial = self::$root.'/application/views/partials/wallet_balance.php';
        $this->assertFileExists($partial);
        $src = file_get_contents($partial);
        $this->assertStringContainsString('marvy_pay_currency', $src);
        $this->assertStringContainsString('marvy_base_currency', $src);

        foreach (array('application/views/dashboard/index.php',
                       'application/views/dashboard/wallet/transactions.php') as $file) {
            $this->assertStringContainsString('partials/wallet_balance',
                file_get_contents(self::$root.'/'.$file),
                $file.' must render the balance through the shared partial');
        }
    }

    /**
     * An existing server can receive the PHP files just before its SQL upgrade.
     * The staff queue must stay available in that short window instead of
     * querying nonexistent migration-042 columns and returning HTTP 500.
     */
    public function testAdminPaymentsQueueFallsBackUntilMigration042IsImported()
    {
        $app = $this->app('NGN');
        list($user) = $this->customer($app);
        $res = $this->deposit($app, $user, '500');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $app->Payment_transaction_model->update_status($res['transaction']->id, array('status' => 'PENDING'));

        foreach (array('base_currency', 'base_amount', 'credited_base_amount', 'fx_rate') as $column) {
            unset($app->db->schema['payment_transactions']['columns'][$column]);
            foreach ($app->db->rows['payment_transactions'] as &$row) unset($row[$column]);
            unset($row);
        }
        // A real web request gets a fresh model after the application upload.
        // Replace this request's instance so its earlier successful deposit
        // has not already cached the pre-removal schema state.
        $model = new Payment_transaction_model();
        $model->db = $app->db;
        $app->Payment_transaction_model = $model;

        $this->assertFalse($app->Payment_transaction_model->deposit_currency_schema_ready());
        $totals = $app->Payment_transaction_model->admin_totals();
        $this->assertSame(1, $totals['pending_count']);
        $this->assertSame(0, bccomp('500', $totals['pending_amount'], 8));
    }

    /**
     * The original schema remains usable while migration 042 is deferred, but
     * new deposits still follow the default payment currency rather than being
     * forced back to the accounting/base currency. The settlement leg is
     * carried in metadata until the additive columns are imported.
     */
    public function testNewDepositsOnTheLegacySchemaStillUseTheDefaultCurrency()
    {
        $app = $this->app('USD', array('NGN' => '1328.00000000'), 'NGN');
        list($user, $wallet) = $this->customer($app);
        foreach (array('base_currency', 'base_amount', 'credited_base_amount', 'fx_rate') as $column) {
            unset($app->db->schema['payment_transactions']['columns'][$column]);
        }

        $res = $this->deposit($app, $user, '1328', array('currency' => 'USD'));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];
        $this->assertSame('NGN', $tx->currency,
            'even before migration 042, Manual / Bank Transfer must use the default currency, not base USD');
        $this->assertSame(0, bccomp('1328', (string)$tx->amount, 8));

        $meta = json_decode((string)$tx->metadata, true);
        $this->assertSame('USD', $meta['settlement']['base_currency'] ?? null);
        $this->assertSame(0, bccomp('1', (string)($meta['settlement']['base_amount'] ?? '0'), 8));
        // Rows returned through the model are decorated from that metadata so
        // the rest of the panel can keep reading the migration-042 fields.
        $this->assertSame('USD', $tx->base_currency);
        $this->assertSame(0, bccomp('1', (string)$tx->base_amount, 8));

        $confirmed = $app->paymentservice->confirm($tx, 'SYSTEM');
        $this->assertTrue($confirmed['ok'], $confirmed['error'] ?? '');
        $wallet = $app->db->where('id', $wallet->id)->get('wallets')->row();
        $this->assertSame(0, bccomp('1', (string)$wallet->balance, 8),
            'confirmation must credit the metadata settlement leg, not the NGN charge amount');
    }

    /**
     * Admin totals cannot sum charge amounts: adding ₦1,328 to $1 produces a
     * number in no currency at all.
     */
    public function testAdminTotalsSumTheBaseLeg()
    {
        $src = file_get_contents(self::$root.'/application/models/Payment_transaction_model.php');
        $this->assertStringContainsString("'COALESCE(base_amount, amount)'", $src);
        $this->assertStringContainsString("'COALESCE(credited_base_amount, credited_amount, amount)'", $src);
        $this->assertStringContainsString('deposit_currency_schema_ready', $src);
    }

    /**
     * Redenominating the panel moves only the settlement leg. The amount a
     * customer paid through Manual / Bank Transfer or Fundsvera is historical
     * provider/default-currency fact and must not be converted or relabelled
     * just because the books move to another base currency. The pinned rate is
     * rebased so that unchanged charge still equals the converted settlement.
     */
    public function testChangingTheBaseCurrencyPreservesChargesAndRebasesSettlement()
    {
        $src = file_get_contents(self::$root.'/application/libraries/BaseCurrencyService.php');
        $this->assertStringNotContainsString(
            "'payment_transactions'  => array('cols' => array('amount'", $src,
            'the immutable customer charge must not be part of the base-money conversion map');
        $this->assertStringNotContainsString(
            "'fundsvera_checkouts'   => array('cols'", $src,
            'Fundsvera expected/paid amounts must remain in the bank rail currency');
        $this->assertStringContainsString('`base_amount`          = ROUND(`base_amount` * ?, 8)', $src);
        $this->assertStringContainsString('`fx_rate`              = ROUND(`fx_rate` / ?, 8)', $src);
        $this->assertStringContainsString('WHERE `base_currency` = ?', $src);
    }
}
