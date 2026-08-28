<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Coupons stop being a shop-only feature (module 36).
 *
 * Until now a coupon could only be redeemed against a marketplace checkout —
 * the redemption row carried exactly one link, `marketplace_order_id`, and
 * only ShopCheckoutService ever took one. The unfinished-work ledger said it
 * plainly: "An operator expecting a site-wide promo code will not find one."
 *
 * Every purchase domain now asks the same question through
 * CouponService::quote() and gets the same bookkeeping: reserve the
 * race-safe slot BEFORE anything charges, charge the discounted amount,
 * attach the redemption to what the customer actually bought (domain +
 * public_id reference), and release the slot when the customer's money came
 * back — exactly the sequence the shop checkout has carried since the
 * module-18 race fix.
 *
 * These tests drive one domain per story through the real stack against the
 * migration-derived schema: SMM orders, VTU, number rentals, identity checks
 * and gift cards.
 */
class CouponDomainsTest extends TestCase
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
        // The mock adapters keep state statically (delivered codes, seen
        // reservations); every test starts from a fresh vendor.
        if (class_exists('MockNumberAdapter')) MockNumberAdapter::reset();
        if (class_exists('MockGiftcardAdapter')) MockGiftcardAdapter::reset();
    }

    /** A world with the whole purchase surface available to one customer. */
    private function app($balance = '1000000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_vtu();
        $app->seed_numbers();
        $app->seed_identity();
        $app->seed_giftcards();
        $user = $app->register('coupons', 'coupons@x.test');
        $app->credit($user, $balance);
        $app->library(array('CouponService', 'OrderService', 'VtuService',
                            'NumberService', 'IdentityService', 'GiftcardService'));
        $app->model(array('Coupon_model', 'Service_transaction_model'));
        return array($app, $user);
    }

    private function coupon($app, array $over = array())
    {
        $now = gmdate('Y-m-d H:i:s');
        $code = 'DOM'.random_int(100, 999);
        $app->db->insert('coupons', array_merge(array(
            'public_id' => 'CPN'.str_pad((string)random_int(1, 99999), 23, '0', STR_PAD_LEFT),
            'code' => $code,
            'discount_type' => 'PERCENT', 'discount_value' => '10.00000000',
            'currency' => null, 'min_order_amount' => null, 'max_discount_amount' => null,
            'usage_limit' => null, 'usage_limit_per_user' => 1, 'times_used' => 0,
            'is_active' => 1, 'is_public' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ), $over));
        $row = $app->db->where('id', $app->db->insert_id())->get('coupons')->row();
        return $row;
    }

    private function redemptions($app, $coupon, $user)
    {
        return $app->db->where('coupon_id', (int)$coupon->id)->where('user_id', (int)$user->id)
            ->get('coupon_redemptions')->result();
    }

    /* ======================= the shared quote rules ===================== */

    /**
     * CouponService::quote() must answer exactly what the cart's apply path
     * answered — the other domains did not deserve a weaker validator.
     */
    public function testQuoteRepeatsEveryRuleTheCartEnforced()
    {
        list($app, $user) = $this->app();

        $this->assertSame('NO_CODE',
            $app->couponservice->quote($user, '  ', '1000', 'VTU')['code']);
        $this->assertSame('INVALID_COUPON',
            $app->couponservice->quote($user, 'NOSUCH', '1000', 'VTU')['code']);

        $inactive = $this->coupon($app, array('code' => 'DEAD', 'is_active' => 0));
        $this->assertSame('INVALID_COUPON',
            $app->couponservice->quote($user, 'DEAD', '1000', 'VTU')['code']);

        $spent = $this->coupon($app, array('code' => 'SPENT', 'usage_limit' => 1, 'times_used' => 1));
        $this->assertSame('INVALID_COUPON',
            $app->couponservice->quote($user, 'SPENT', '1000', 'VTU')['code']);

        $floor = $this->coupon($app, array('code' => 'FLOOR', 'min_order_amount' => '5000.00000000'));
        $this->assertSame('BELOW_MINIMUM',
            $app->couponservice->quote($user, 'FLOOR', '1000', 'VTU')['code']);

        $once = $this->coupon($app, array('code' => 'ONCE'));
        $app->db->insert('coupon_redemptions', array(
            'coupon_id' => $once->id, 'user_id' => $user->id,
            'marketplace_order_id' => null, 'discount_amount' => '1.00000000',
            'redemption_slot' => 1, 'domain' => 'SHOP', 'reference' => 'X1',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->assertSame('ALREADY_USED',
            $app->couponservice->quote($user, 'ONCE', '1000', 'VTU')['code'],
            'a redemption on the SHOP domain still counts everywhere');

        // Percent with an absolute cap.
        $capped = $this->coupon($app, array('code' => 'CAPPED', 'max_discount_amount' => '50.00000000'));
        $q = $app->couponservice->quote($user, 'CAPPED', '1000', 'VTU');
        $this->assertTrue($q['ok']);
        $this->assertSame('50.00000000', $q['discount']);
        $this->assertSame('950.00000000', $q['total']);

        // FIXED larger than the purchase is capped at the purchase.
        $huge = $this->coupon($app, array(
            'code' => 'HUGE', 'discount_type' => 'FIXED', 'discount_value' => '5000.00000000'));
        $q = $app->couponservice->quote($user, 'HUGE', '1000', 'VTU');
        $this->assertTrue($q['ok']);
        $this->assertSame('1000.00000000', $q['discount'], 'a coupon cannot pay the customer');
        $this->assertSame('0.00000000', $q['total']);

        // Codes are matched case-insensitively.
        $plain = $this->coupon($app, array('code' => 'LOWER'));
        $this->assertTrue($app->couponservice->quote($user, 'lower', '1000', 'VTU')['ok']);
    }

    /* ============================ SMM orders ============================ */

    public function testAnSmmOrderRedeemsACouponAndTheLedgerReflectsIt()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app); // 10%

        $placed = $app->orderservice->place($user, array(
            'service'     => 1,
            'link'        => 'https://instagram.com/promo',
            'quantity'    => 1000,
            'coupon_code' => $coupon->code,
        ));

        $this->assertTrue($placed['ok'], $placed['error'] ?? '');
        $order = $placed['order'];
        // Rate 2.00 per 1000 → charge 2.00, coupon takes 10% → 1.80.
        $this->assertSame('1.80000000', $order->charge);
        $this->assertSame('999998.20000000', $app->balance($user));
        $this->assertSame($coupon->code, $placed['coupon_code']);
        $this->assertSame('0.20000000', $placed['discount']);

        $rows = $this->redemptions($app, $coupon, $user);
        $this->assertCount(1, $rows);
        $this->assertSame('SMM', $rows[0]->domain);
        $this->assertSame($order->public_id, $rows[0]->reference);
        $this->assertSame('0.20000000', $rows[0]->discount_amount);
        $this->assertNull($rows[0]->marketplace_order_id, 'an SMM order is not a marketplace order');

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c, 'the ledger still balances');
    }

    public function testAnOrderThatCannotBeChargedReleasesTheCouponSlot()
    {
        list($app, $user) = $this->app('1.00000000'); // cannot cover 1.80
        $coupon = $this->coupon($app);

        $res = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://instagram.com/x', 'quantity' => 1000,
            'coupon_code' => $coupon->code,
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
        $this->assertCount(0, $this->redemptions($app, $coupon, $user),
            'a refused charge must not burn the coupon slot');
        $this->assertSame(0, (int)$app->db->where('id', $coupon->id)->get('coupons')->row()->times_used);
    }

    public function testAnInvalidCodeRefusesTheOrderBeforeAnythingCharges()
    {
        list($app, $user) = $this->app();
        $balance = $app->balance($user);

        $res = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://instagram.com/x', 'quantity' => 1000,
            'coupon_code' => 'GHOST',
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('INVALID_COUPON', $res['code']);
        $this->assertSame($balance, $app->balance($user),
            'silently ignoring the code and charging full price is the one wrong answer');
    }

    /* =============================== VTU ================================ */

    public function testAVtuPurchaseRedeemsACoupon()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app); // 10%

        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000',
            'coupon_code' => $coupon->code,
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];
        // ₦1,000 face at the product's 2% discount = ₦980; the coupon's 10%
        // comes off that: ₦882.
        $this->assertSame('882.00000000', $tx->amount);
        $this->assertSame('999118.00000000', $app->balance($user));
        $this->assertSame($coupon->code, $res['coupon_code']);
        $this->assertSame('98.00000000', $res['discount']);

        $meta = json_decode((string)$tx->metadata, true);
        $this->assertSame($coupon->code, $meta['coupon_code']);
        $this->assertSame('98.00000000', $meta['coupon_discount']);

        $rows = $this->redemptions($app, $coupon, $user);
        $this->assertCount(1, $rows);
        $this->assertSame('VTU', $rows[0]->domain);
        $this->assertSame($tx->public_id, $rows[0]->reference);

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /* ==================== one limit across all domains =================== */

    public function testAPerUserLimitTravelsAcrossDomains()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app); // usage_limit_per_user = 1

        $first = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://instagram.com/x', 'quantity' => 1000,
            'coupon_code' => $coupon->code,
        ));
        $this->assertTrue($first['ok'], $first['error'] ?? '');

        $balance = $app->balance($user);
        $second = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000',
            'coupon_code' => $coupon->code,
        ));

        $this->assertFalse($second['ok']);
        $this->assertSame('ALREADY_USED', $second['code'],
            'the limit is the customer\'s, not the domain\'s');
        $this->assertSame($balance, $app->balance($user), 'nothing was charged');
        $this->assertCount(1, $this->redemptions($app, $coupon, $user));
    }

    /* ================= vendor rejection releases the slot ================ */

    public function testAVendorOutageReleasesTheCouponSlotWithTheRefund()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app);

        // The mock identity vendor fails identifiers ending 0000 — an outage,
        // not a "no such person" — and refunds in full.
        $res = $app->identityservice->verify($user, array(
            'product' => 'NIN_BASIC', 'identifier' => '70123450000',
            'consent' => true, 'consent_ip' => '102.89.1.7',
            'coupon_code' => $coupon->code,
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('1000000.00000000', $app->balance($user),
            'the discounted charge was refunded');
        $this->assertCount(0, $this->redemptions($app, $coupon, $user),
            'a purchase that never landed must not burn the coupon slot');
        $this->assertSame(0, (int)$app->db->where('id', $coupon->id)->get('coupons')->row()->times_used);
    }

    /* ============================ gift cards ============================ */

    public function testAGiftcardPurchaseRedeemsACoupon()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('discount_value' => '5.00000000'));

        $res = $app->giftcardservice->purchase($user, array(
            'product' => 'AMAZON-US-25', 'quantity' => 1,
            'coupon_code' => $coupon->code,
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];
        // ₦42,000 less 5% = ₦39,900.
        $this->assertSame('39900.00000000', $tx->amount);
        $this->assertSame('960100.00000000', $app->balance($user));

        $rows = $this->redemptions($app, $coupon, $user);
        $this->assertCount(1, $rows);
        $this->assertSame('GIFTCARD', $rows[0]->domain);
        $this->assertSame($tx->public_id, $rows[0]->reference);
        $this->assertSame('2100.00000000', $rows[0]->discount_amount);

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /* ========================== number rentals ========================== */

    public function testANumberRentalRedeemsACoupon()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('discount_value' => '20.00000000'));

        $res = $app->numberservice->reserve($user, array(
            'country' => 'NG', 'service' => 'WHATSAPP',
            'coupon_code' => $coupon->code,
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];
        // WHATSAPP on NG is seeded at ₦450; 20% off = ₦360.
        $this->assertSame('360.00000000', $tx->amount);
        $this->assertSame('999640.00000000', $app->balance($user));

        $rows = $this->redemptions($app, $coupon, $user);
        $this->assertCount(1, $rows);
        $this->assertSame('NUMBER', $rows[0]->domain);
        $this->assertSame($tx->public_id, $rows[0]->reference);

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /* ====================== a free purchase is still real ================ */

    public function testAOneHundredPercentCouponChargesNothingAndStillPurchases()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('discount_value' => '100.00000000'));

        $res = $app->identityservice->verify($user, array(
            'product' => 'NIN_BASIC', 'identifier' => '70123456781',
            'consent' => true, 'consent_ip' => '102.89.1.7',
            'coupon_code' => $coupon->code,
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertTrue($res['found']);
        $tx = $res['transaction'];
        $this->assertSame('SUCCESSFUL', $tx->status);
        $this->assertSame('0.00000000', $tx->amount);
        $this->assertNull($tx->wallet_transaction_id,
            'a zero-value ledger entry is noise, not accounting');
        $this->assertSame('1000000.00000000', $app->balance($user), 'nothing left the wallet');

        $rows = $this->redemptions($app, $coupon, $user);
        $this->assertCount(1, $rows, 'a free purchase still consumes the coupon');
        $this->assertSame('250.00000000', $rows[0]->discount_amount);
    }

    /* ============ the admin surface and the old shop behaviour =========== */

    /**
     * The shop checkout is unchanged: its redemptions keep the marketplace
     * order foreign key, now with the domain written alongside it, and the
     * historical rows read as SHOP.
     */
    public function testAttachStillWritesTheMarketplaceLinkForShopRedemptions()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app);

        $reservation = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $this->assertTrue($reservation['ok']);
        $app->Coupon_model->attach_redemption($reservation['id'], 4242, '12.00000000');

        $row = $app->db->where('id', $reservation['id'])->get('coupon_redemptions')->row();
        $this->assertSame('SHOP', $row->domain);
        $this->assertSame(4242, (int)$row->marketplace_order_id);
        $this->assertNull($row->reference);
    }

    /** The forms and the engine are wired, or the feature is decoration. */
    public function testEveryPurchaseFormAndServiceCarriesTheCouponField()
    {
        $files = array(
            'application/views/dashboard/orders/new_order.php',
            'application/views/dashboard/vtu/airtime.php',
            'application/views/dashboard/vtu/data.php',
            'application/views/dashboard/vtu/cable.php',
            'application/views/dashboard/vtu/electricity.php',
            'application/views/dashboard/vtu/education.php',
            'application/views/dashboard/numbers/index.php',
            'application/views/dashboard/identity/index.php',
            'application/views/dashboard/giftcards/index.php',
        );
        foreach ($files as $f) {
            $this->assertFileExists(self::$root.'/'.$f);
            $this->assertStringContainsString('name="coupon_code"', file_get_contents(self::$root.'/'.$f),
                $f.' must offer the coupon field');
        }

        // The controllers forward it; the engine and the order service own
        // the money sequence. The reseller API deliberately does NOT — it is
        // a different price list, not a checkout form.
        $engine = file_get_contents(self::$root.'/application/libraries/TransactionEngine.php');
        $this->assertStringContainsString("resolve_coupon", $engine);
        $this->assertStringContainsString("release_coupon", $engine);
        $this->assertStringContainsString("reserve_redemption", $engine);
        $order = file_get_contents(self::$root.'/application/libraries/OrderService.php');
        $this->assertStringContainsString("couponservice->quote", $order);
        $api = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $this->assertStringNotContainsString('coupon_code', $api,
            'the reseller API is a different price list, not a coupon surface');
    }
}
