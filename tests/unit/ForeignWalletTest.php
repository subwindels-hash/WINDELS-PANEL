<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Multi-currency wallets (module 37).
 *
 * A wallet could only ever hold the base currency: the `currency` column
 * existed on wallets since migration 002 but nothing could set it to anything
 * else, and nothing that charged a wallet knew what to do if it had been.
 * The unfinished-work ledger named the two things missing — "conversion at
 * the ledger boundary and a refund-rate policy."
 *
 * Both live in LedgerService now, the only writer to wallets and the ledger,
 * which is why every purchase domain gets foreign-wallet support without a
 * single engine change. These tests drive the boundary directly and then
 * through the real services:
 *
 *   - the choice: virgin wallets may pick a currency, used ones never may;
 *   - the conversion: a base-currency charge debits the foreign wallet at
 *     the pinned rate and writes a four-legged ledger entry whose books
 *     balance per currency;
 *   - the policy: a refund replays the rate pinned at charge time, so FX
 *     drift can never make a refund create or destroy money;
 *   - the unchanged: a base-currency wallet behaves byte-for-byte as before.
 */
class ForeignWalletTest extends TestCase
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
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
    }

    protected function setUp(): void
    {
        if (class_exists('MockNumberAdapter')) MockNumberAdapter::reset();
        if (class_exists('MockGiftcardAdapter')) MockGiftcardAdapter::reset();
    }

    /** A world with a USD currency row at a known rate. */
    private function app($rate = '0.00064516')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_vtu();
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('currencies', array(
            'code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => $rate, 'is_base' => 0, 'is_active' => 1,
            'updated_at' => $now,
        ));
        $app->library(array('LedgerService', 'OrderService', 'VtuService'));
        $app->model(array('Wallet_model', 'Coupon_model', 'Service_transaction_model'));
        return $app;
    }

    /** A customer whose wallet holds USD, funded by a staff adjustment. */
    private function usd_customer($app, $funded = '100.00000000')
    {
        $user = $app->register('fx'.random_int(1000, 9999), 'fx'.random_int(1000, 9999).'@x.test');
        $wallet = $app->db->where('user_id', $user->id)->get('wallets')->row();
        $res = $app->Wallet_model->choose_currency($user->id, 'USD', $user->id);
        $this->assertTrue($res['ok']);
        if ($funded !== null) {
            // Staff adjustments are typed in the wallet's own currency — the
            // realistic way a foreign wallet gets funded before a gateway
            // ever pays into one.
            $r = $app->ledgerservice->adjust($wallet->id, $funded, 'CREDIT',
                'TestFunding', (string)$user->id, 'fxtest:'.$user->id.':'.bin2hex(random_bytes(4)),
                null, null, 'module-37 test funding');
            $this->assertTrue($r['ok']);
        }
        return array($user, $app->db->where('user_id', $user->id)->get('wallets')->row());
    }

    private function wallet_tx($app, $wallet_id, $direction = null)
    {
        $q = $app->db->where('wallet_id', $wallet_id);
        if ($direction) $q = $q->where('direction', $direction);
        return $q->order_by('id', 'DESC')->limit(1)->get('wallet_transactions')->row();
    }

    /** Debits == credits overall, and per currency: the books must balance in each. */
    private function books_balance($app)
    {
        $per = array();
        foreach ($app->db->all('ledger_entries') as $e) {
            $cur = $e['currency'] ?? '?';
            $per[$cur] = $per[$cur] ?? array('D' => '0', 'C' => '0');
            $per[$cur][$e['direction'] === 'DEBIT' ? 'D' : 'C']
                = bcadd($per[$cur][$e['direction'] === 'DEBIT' ? 'D' : 'C'], (string)$e['amount'], 8);
        }
        foreach ($per as $cur => $s) {
            if (bccomp($s['D'], $s['C'], 8) !== 0) return array(false, $cur.' debits '.$s['D'].' vs credits '.$s['C']);
        }
        return array(true, '');
    }

    /* ========================= choosing the currency ==================== */

    public function testAVirginWalletMayChooseItsCurrencyAndAUsedOneMayNot()
    {
        $app = $this->app();
        $user = $app->register('chooser', 'chooser@x.test');

        $res = $app->Wallet_model->choose_currency($user->id, 'USD', $user->id);
        $this->assertTrue($res['ok']);
        $wallet = $app->db->where('user_id', $user->id)->get('wallets')->row();
        $this->assertSame('USD', $wallet->currency);

        // One movement is enough to freeze the choice forever: re-labelling a
        // wallet with history re-denominates every figure already on it.
        $app->ledgerservice->adjust($wallet->id, '5.00', 'CREDIT',
            'TestFunding', (string)$user->id, 'fxtest:freeze:'.bin2hex(random_bytes(4)),
            null, null, 'first money');
        $frozen = $app->Wallet_model->choose_currency($user->id, 'NGN', $user->id);
        $this->assertFalse($frozen['ok']);
        $this->assertSame('NOT_EMPTY', $frozen['code']);
    }

    public function testTheChoiceRefusesUnknownAndDisabledCurrencies()
    {
        $app = $this->app();
        $user = $app->register('picky', 'picky@x.test');

        $this->assertSame('UNKNOWN_CURRENCY',
            $app->Wallet_model->choose_currency($user->id, 'XYZ', $user->id)['code']);

        $app->db->where('code', 'USD')->update('currencies', array('is_active' => 0));
        $this->assertSame('UNKNOWN_CURRENCY',
            $app->Wallet_model->choose_currency($user->id, 'USD', $user->id)['code']);

        // The base currency is always a valid holding.
        $this->assertTrue($app->Wallet_model->choose_currency($user->id, 'NGN', $user->id)['ok']);
    }

    /* ============================ the boundary =========================== */

    public function testABaseWalletChargesExactlyAsItAlwaysDid()
    {
        $app = $this->app();
        $user = $app->register('naira', 'naira@x.test');
        $app->credit($user, '1000');
        $wallet = $app->db->where('user_id', $user->id)->get('wallets')->row();

        $res = $app->ledgerservice->charge($wallet->id, '200', 'ORDER', 'ORD1', 't1');
        $this->assertTrue($res['ok']);
        $this->assertSame('800.00000000', $res['balance_after']);
        $this->assertNull($res['fx_rate']);
        $this->assertNull($res['base_amount']);

        // Two ledger legs, both naira — the shape the ledger has always had.
        $legs = $app->db->where('wallet_transaction_id',
            $app->db->where('public_id', $res['public_id'])->get('wallet_transactions')->row()->id
        )->get('ledger_entries')->result();
        $this->assertCount(2, $legs);
        $this->assertSame('NGN', $legs[0]->currency);
        $this->assertSame('NGN', $legs[1]->currency);
    }

    public function testAChargeIntoAForeignWalletConvertsAndPinsTheRate()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        // ₦2,000 charge at 0.00064516 USD per naira = $1.29032 exactly.
        $res = $app->ledgerservice->charge($wallet->id, '2000', 'ORDER', 'ORD2', 't2');
        $this->assertTrue($res['ok']);
        $this->assertSame('98.70968000', $res['balance_after']);
        $this->assertSame('1.29032000', $res['wallet_amount']);
        $this->assertSame('0.00064516', $res['fx_rate']);
        $this->assertSame('2000.00000000', $res['base_amount']);

        $tx = $app->db->where('public_id', $res['public_id'])->get('wallet_transactions')->row();
        $this->assertSame('USD', $tx->currency);
        $this->assertSame('1.29032000', $tx->amount);
        $this->assertSame('0.00064516', $tx->fx_rate);
        $this->assertSame('2000.00000000', $tx->base_amount);

        // Four ledger legs: the wallet + fx pair in USD, the fx + revenue
        // pair in naira. Each currency's books balance on their own.
        $legs = $app->db->where('wallet_transaction_id', $tx->id)->get('ledger_entries')->result();
        $this->assertCount(4, $legs);
        $accounts = array();
        foreach ($legs as $leg) $accounts[$leg->account.'|'.$leg->currency] = $leg->direction.' '.$leg->amount;
        $this->assertSame('DEBIT 1.29032000', $accounts['wallet:'.$wallet->id.'|USD']);
        $this->assertSame('CREDIT 1.29032000', $accounts['fx:USD|USD']);
        $this->assertSame('DEBIT 2000.00000000', $accounts['fx:USD|NGN']);
        $this->assertSame('CREDIT 2000.00000000', $accounts['revenue|NGN']);

        list($ok, $why) = $this->books_balance($app);
        $this->assertTrue($ok, $why);
    }

    public function testInsufficientBalanceIsJudgedInTheWalletsOwnCurrency()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '1.00000000'); // ≈ ₦1,550

        $res = $app->ledgerservice->charge($wallet->id, '5000', 'ORDER', 'ORD3', 't3');
        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['error']);
        $this->assertSame('1.00000000',
            $app->db->where('user_id', $user->id)->get('wallets')->row()->balance);
    }

    public function testAMovementRefusesAWalletCurrencyWithNoRate()
    {
        // A foreign wallet whose currency row vanished must never be moved at
        // an invented rate — the movement is refused, loudly.
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '10.00000000');
        $app->db->where('code', 'USD')->delete('currencies');

        $res = $app->ledgerservice->charge($wallet->id, '100', 'ORDER', 'ORD4', 't4');
        $this->assertFalse($res['ok']);
        $this->assertSame('CURRENCY_UNAVAILABLE', $res['error']);
    }

    public function testAStaffAdjustmentIsTypedInTheWalletsOwnCurrency()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        $res = $app->ledgerservice->adjust($wallet->id, '10', 'DEBIT',
            'AdminAdjustment', (string)$user->id, 'adj:'.bin2hex(random_bytes(4)),
            null, 1, 'clawback in dollars');
        $this->assertTrue($res['ok']);
        $this->assertSame('90.00000000', $res['balance_after']);

        // No conversion happened: no pinned rate, and the two ledger legs are
        // both in USD.
        $tx = $this->wallet_tx($app, $wallet->id, 'DEBIT');
        $this->assertNull($tx->fx_rate);
        $this->assertNull($tx->base_amount);
        $legs = $app->db->where('wallet_transaction_id', $tx->id)->get('ledger_entries')->result();
        $this->assertCount(2, $legs);
        foreach ($legs as $leg) $this->assertSame('USD', $leg->currency);
    }

    /* ======================== the refund-rate policy ==================== */

    public function testARefundReplaysTheRatePinnedAtChargeTimeNotTodaysRate()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        $charge = $app->ledgerservice->charge($wallet->id, '2000', 'ORDER', 'ORD5', 't5');
        $this->assertTrue($charge['ok']);

        // The rate moves — a week passes, the naira weakens.
        $app->db->where('code', 'USD')->update('currencies', array('exchange_rate' => '0.00090000'));

        $refund = $app->ledgerservice->refund($wallet->id, '2000', 'ORDER', 'ORD5', 'r5');
        $this->assertTrue($refund['ok']);

        // The customer gets back EXACTLY what was taken — $1.29032, the
        // charge-day amount — not $1.80 at today's rate.
        $this->assertSame('100.00000000', $refund['balance_after']);
        $rtx = $app->db->where('public_id', $refund['public_id'])->get('wallet_transactions')->row();
        $this->assertSame('1.29032000', $rtx->amount);
        $this->assertSame('0.00064516', $rtx->fx_rate);

        list($ok, $why) = $this->books_balance($app);
        $this->assertTrue($ok, $why);
    }

    public function testAPartialRefundReplaysThePinnedRateProportionally()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        $app->ledgerservice->charge($wallet->id, '1000', 'ORDER', 'ORD6', 't6');
        $app->db->where('code', 'USD')->update('currencies', array('exchange_rate' => '0.00090000'));

        // Half of a ₦1,000 charge at the pinned rate is exactly half of
        // $0.64516 — never anything to do with today's rate.
        $refund = $app->ledgerservice->refund($wallet->id, '500', 'ORDER', 'ORD6', 'r6');
        $this->assertTrue($refund['ok']);
        $this->assertSame('0.32258000', $refund['wallet_amount']);
        $this->assertSame('0.00064516', $refund['fx_rate']);
    }

    public function testAnExplicitRateOutranksTheLookupForSameRequestRollbacks()
    {
        // Same-request rollbacks (persist-fail, submit-fail) pass the charge's
        // pinned rate explicitly — their reference never got stamped.
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        $app->ledgerservice->charge($wallet->id, '2000', 'ORDER', 'ORD7', 't7');
        $app->db->where('code', 'USD')->update('currencies', array('exchange_rate' => '0.00090000'));

        $refund = $app->ledgerservice->refund($wallet->id, '2000', 'ORDER', 'ORD7', 'r7', '0.00064516');
        $this->assertTrue($refund['ok']);
        $this->assertSame('100.00000000', $refund['balance_after']);
    }

    public function testAGoodwillRefundWithoutAPriorChargePinsTodaysRate()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        // No charge carries this reference — the refund converts at the
        // current rate and records it, so the answer is on the row.
        $refund = $app->ledgerservice->refund($wallet->id, '1550', 'ORDER', 'NOWHERE', 'r8');
        $this->assertTrue($refund['ok']);
        $this->assertSame('0.00064516', $refund['fx_rate']);
        $this->assertSame('0.99999800', $refund['wallet_amount']); // 1550 × 0.00064516
    }

    /* ===================== the engines, unchanged, working =============== */

    public function testAnSmmOrderFromAUsdWalletChargesConvertedAndStampsTheCharge()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '10.00000000'); // ≈ ₦15,500

        // Service 1 costs ₦2.00 per 1000 (harness seed); 1000 units = ₦2.00.
        $res = $app->orderservice->place($user, array(
            'service' => '1', 'link' => 'https://instagram.com/fx-test', 'quantity' => 1000,
        ), array());
        $this->assertTrue($res['ok']);
        $this->assertSame('2.00000000', $res['order']->charge); // the order is priced in base

        // The wallet moved in DOLLARS at the pinned rate.
        $tx = $this->wallet_tx($app, $wallet->id, 'DEBIT');
        $this->assertSame('USD', $tx->currency);
        $this->assertSame('0.00129032', $tx->amount); // 2 × 0.00064516
        $this->assertSame('2.00000000', $tx->base_amount);

        // The charge is stamped with the order it paid for — that is how a
        // later refund finds the pinned rate to replay.
        $this->assertSame($res['order']->public_id, $tx->reference_id);

        list($ok, $why) = $this->books_balance($app);
        $this->assertTrue($ok, $why);
    }

    public function testARefundOfThatOrderReplaysTheChargeDayRate()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '10.00000000');

        $res = $app->orderservice->place($user, array(
            'service' => '1', 'link' => 'https://instagram.com/fx-refund', 'quantity' => 1000,
        ), array());
        $this->assertTrue($res['ok']);

        // A week later the rate is different, and the order is cancelled —
        // the customer's money comes back through apply_status() exactly as
        // a staff refund would.
        $app->db->where('code', 'USD')->update('currencies', array('exchange_rate' => '0.00090000'));
        $cancelled = $app->orderservice->apply_status($res['order'], 'CANCELED', 'STAFF', 'module-37 refund test');
        $this->assertTrue($cancelled['ok']);

        // Exactly the dollars taken, not the dollars today's rate would give.
        $wallet_after = $app->db->where('user_id', $user->id)->get('wallets')->row();
        $this->assertSame('10.00000000', $wallet_after->balance);
        $rtx = $this->wallet_tx($app, $wallet->id, 'CREDIT');
        $this->assertSame('0.00129032', $rtx->amount);
        $this->assertSame('0.00064516', $rtx->fx_rate);

        list($ok, $why) = $this->books_balance($app);
        $this->assertTrue($ok, $why);
    }

    public function testAPartialRefundOfThatOrderReplaysTheChargeDayRateToo()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '10.00000000');

        $res = $app->orderservice->place($user, array(
            'service' => '1', 'link' => 'https://instagram.com/fx-partial', 'quantity' => 1000,
        ), array());
        $this->assertTrue($res['ok']);

        $app->db->where('code', 'USD')->update('currencies', array('exchange_rate' => '0.00090000'));
        // Half of the run never delivered: half the charge comes back at the
        // charge-day rate, not today's.
        $partial = $app->orderservice->apply_status($res['order'], 'PARTIAL', 'PROVIDER',
            'half delivered', array('remains' => 500));
        $this->assertTrue($partial['ok']);

        $rtx = $this->wallet_tx($app, $wallet->id, 'CREDIT');
        $this->assertSame('0.00064516', $rtx->fx_rate);
        $this->assertSame('0.00064516', $rtx->amount); // 1.00 ₦ of charge × rate
        $wallet_after = $app->db->where('user_id', $user->id)->get('wallets')->row();
        $this->assertSame('9.99935484', $wallet_after->balance); // 10 − 0.00064516

        list($ok, $why) = $this->books_balance($app);
        $this->assertTrue($ok, $why);
    }

    public function testAVtuPurchaseFromAUsdWalletChargesConverted()
    {
        $app = $this->app();
        list($user, $wallet) = $this->usd_customer($app, '100.00000000');

        // ₦1,000 airtime at the seeded 2% vendor discount = ₦980 charged.
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000',
        ));
        $this->assertTrue($res['ok']);

        $tx = $this->wallet_tx($app, $wallet->id, 'DEBIT');
        $this->assertSame('USD', $tx->currency);
        $this->assertSame('0.63225680', $tx->amount); // 980 × 0.00064516
        $this->assertSame('980.00000000', $tx->base_amount);

        list($ok, $why) = $this->books_balance($app);
        $this->assertTrue($ok, $why);
    }

    /* ======================= the admin surface =========================== */

    public function testTotalsAreReportedPerCurrencyAndNeverAddedTogether()
    {
        $app = $this->app();
        $ngn = $app->register('ngnholder', 'ngn@x.test');
        $app->credit($ngn, '1000');
        list($usd, $usd_wallet) = $this->usd_customer($app, '50.00000000');

        $totals = $app->Wallet_model->totals();
        $this->assertSame(0, bccomp('1000.00000000', $totals['by_currency']['NGN']['held'], 8));
        $this->assertSame(0, bccomp('50.00000000', $totals['by_currency']['USD']['held'], 8));
        // The legacy scalar keys keep describing the BASE currency only.
        $this->assertSame(0, bccomp('1000.00000000', $totals['held'], 8));
        $this->assertSame(2, $totals['wallets']);
    }

    public function testTheAdjustFormAndChoiceSurfaceCarryTheRightFiles()
    {
        $root = self::$root;

        // The admin adjust form must label the amount in the wallet's
        // currency, never the base currency — a dollar typed into a naira
        // field is a 1,550× mistake.
        $detail = file_get_contents($root.'/application/views/admin/users/detail.php');
        $this->assertStringContainsString(
            "Amount (<?=htmlspecialchars(\$user->wallet->currency ?? marvy_base_currency())?>)", $detail);

        // The customer's add-funds page offers the one-time choice and says
        // what a foreign wallet is credited with.
        $add = file_get_contents($root.'/application/views/dashboard/wallet/add_funds.php');
        $this->assertStringContainsString("dashboard/wallet/currency", $add);
        $this->assertStringContainsString('credited with the', $add);

        // Every wallet balance shown to a customer must be formatted in the
        // wallet's own currency.
        foreach (array('giftcards/index.php', 'identity/index.php', 'numbers/index.php',
                       'marketplace/listing.php') as $f) {
            $src = file_get_contents($root.'/application/views/dashboard/'.$f);
            $this->assertStringNotContainsString('marvy_money($wallet->balance)', $src,
                $f.' formats the balance without the wallet currency');
        }

        // The engine files stay currency-blind: no engine may compute a rate
        // itself — that is the boundary's job, in exactly one place.
        $ledger = file_get_contents($root.'/application/libraries/LedgerService.php');
        $this->assertStringContainsString('resolve_rate', $ledger);
        $this->assertStringContainsString("'fx:'", $ledger);
        foreach (array('OrderService.php', 'TransactionEngine.php', 'VtuService.php',
                       'MarketplaceService.php') as $f) {
            $src = file_get_contents($root.'/application/libraries/'.$f);
            $this->assertStringNotContainsString('exchange_rate', $src,
                $f.' must not read exchange rates itself — conversion belongs to LedgerService');
        }

        // Refund-rate replay is wired where a charge and its refund share no
        // reference: OrderService stamps the charge, MarketplaceService passes
        // the rate it looked up.
        $orders = file_get_contents($root.'/application/libraries/OrderService.php');
        $this->assertStringContainsString("update('wallet_transactions', array('reference_id' => \$order->public_id))", $orders);
        $market = file_get_contents($root.'/application/libraries/MarketplaceService.php');
        $this->assertStringContainsString('$target, $fx_rate', $market);
    }
}
