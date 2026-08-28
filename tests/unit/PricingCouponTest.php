<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Pricing and coupons — what the customer is shown, and what they are charged.
 *
 * Three defects, all in the space between "the rule exists" and "the rule is
 * applied":
 *
 *  1. **`usage_limit_per_user` enforced nothing.** The column has been on
 *     `coupons` since the shop shipped, the admin form sets it, it defaults to
 *     1 — and no query ever read it. A "one per customer" code could be
 *     redeemed by the same customer on every order they ever placed. That is
 *     what happens to a public discount code within hours of being posted.
 *
 *  2. **The minimum spend was only checked when the code was typed.** Apply a
 *     coupon needing a ₦100 basket, then remove items down to ₦5, and checkout
 *     still charged the discounted total: the cart re-read the coupon but not
 *     its minimum.
 *
 *  3. **Pricing was resolved one service at a time.** Two point queries per
 *     service, inside loops that render whole catalogues — 49 queries for a
 *     20-service mass-order page, and over a thousand for a 500-service
 *     reseller API call.
 */
class PricingCouponTest extends TestCase
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

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $user = $app->register('shopper', 'shopper@x.test');
        $app->credit($user, '100000');
        $app->library(array('LedgerService', 'PricingService', 'CartService',
                            'TransactionEngine', 'MarketplaceService'));
        $app->model(array('Coupon_model', 'Cart_model', 'Service_model',
                          'Marketplace_listing_model', 'Marketplace_order_model',
                          'Service_transaction_model', 'Wallet_model', 'Setting_model'));
        return array($app, $user);
    }

    private function coupon($app, array $over = array())
    {
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('coupons', array_merge(array(
            'public_id' => 'CPN'.str_pad((string)random_int(1, 99999), 23, '0', STR_PAD_LEFT),
            'code' => 'SAVE'.random_int(100, 999),
            'discount_type' => 'PERCENT', 'discount_value' => '10.00000000',
            'currency' => null, 'min_order_amount' => null, 'max_discount_amount' => null,
            'usage_limit' => null, 'usage_limit_per_user' => 1, 'times_used' => 0,
            'is_active' => 1, 'is_public' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ), $over));
        return $app->db->where('id', $app->db->insert_id())->get('coupons')->row();
    }

    /* ===================== the per-customer usage cap ==================== */

    public function testACouponLimitedToOnePerCustomerIsRefusedTheSecondTime()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));

        $this->assertNotNull($app->Coupon_model->find_valid($coupon->code, $user->id),
            'the first use is allowed');

        $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '10.00000000');

        $this->assertNull($app->Coupon_model->find_valid($coupon->code, $user->id),
            'a one-per-customer code must not work twice for the same customer');
    }

    public function testTheSameCouponStillWorksForADifferentCustomer()
    {
        list($app, $user) = $this->app();
        $other = $app->register('second', 'second@x.test');
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));
        $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '10.00000000');

        $this->assertNotNull($app->Coupon_model->find_valid($coupon->code, $other->id),
            'a per-customer cap is per customer, not global');
    }

    public function testAHigherPerCustomerLimitAllowsThatManyUses()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 3));
        for ($i = 0; $i < 2; $i++) {
            $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '1.00000000');
        }
        $this->assertNotNull($app->Coupon_model->find_valid($coupon->code, $user->id));

        $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '1.00000000');
        $this->assertNull($app->Coupon_model->find_valid($coupon->code, $user->id));
    }

    /** An empty box in the admin form means "no per-customer cap". */
    public function testAnUnsetPerCustomerLimitIsUnlimited()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => null));
        for ($i = 0; $i < 5; $i++) {
            $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '1.00000000');
        }
        $this->assertNotNull($app->Coupon_model->find_valid($coupon->code, $user->id));
    }

    /** The global cap still works, and still applies to everyone. */
    public function testTheGlobalUsageLimitStillStopsEveryone()
    {
        list($app, $user) = $this->app();
        $other = $app->register('third', 'third@x.test');
        $coupon = $this->coupon($app, array('usage_limit' => 1, 'usage_limit_per_user' => 5));

        $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '5.00000000');

        $this->assertNull($app->Coupon_model->find_valid($coupon->code, $other->id),
            'a code capped at one redemption is spent, whoever spends it');
    }

    /* ================== the minimum spend, at charge time ================ */

    public function testTheMinimumSpendIsRecheckedWhenTheCartShrinks()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array(
            'discount_type' => 'PERCENT', 'discount_value' => '50.00000000',
            'min_order_amount' => '5000.00000000', 'usage_limit_per_user' => 9,
        ));
        $cart = $app->Cart_model->find_or_create($user->id);
        $app->Cart_model->set_coupon($cart->id, $coupon->code);

        // Nothing in the cart: the coupon's minimum cannot be met, so the
        // discount must be zero rather than "50% of nothing is fine".
        $view = $app->cartservice->view($user->id);
        $this->assertSame('0.00000000', $view['discount']);
        $this->assertNull($view['coupon'],
            'a coupon whose minimum is no longer met must not be shown as applied');
        $this->assertSame($view['subtotal'], $view['total']);
    }

    public function testACouponAlreadySpentByThisCustomerStopsDiscountingTheCart()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));
        $cart = $app->Cart_model->find_or_create($user->id);
        $app->Cart_model->set_coupon($cart->id, $coupon->code);
        $app->Coupon_model->record_redemption($coupon->id, $user->id, null, '1.00000000');

        $view = $app->cartservice->view($user->id);
        $this->assertNull($view['coupon']);
        $this->assertSame('0.00000000', $view['discount'],
            'the cart total must agree with the rule the checkout will apply');
    }

    /** The discount maths itself is unchanged and still capped. */
    public function testTheDiscountIsCappedByItsCeilingAndBySubtotal()
    {
        list($app,) = $this->app();
        $coupon = (object)array('discount_type' => 'PERCENT', 'discount_value' => '50',
                                'max_discount_amount' => '100.00000000');
        $this->assertSame(0, bccomp($app->cartservice->compute_discount($coupon, '1000'), '100', 8),
            'a ceiling caps a percentage');

        $fixed = (object)array('discount_type' => 'FIXED', 'discount_value' => '500.00000000',
                               'max_discount_amount' => null);
        $this->assertSame(0, bccomp($app->cartservice->compute_discount($fixed, '200'), '200', 8),
            'a fixed discount never exceeds the basket');
    }

    /* ========================== batched pricing ========================== */

    public function testGroupAndCustomerPricesStillWinInThatOrder()
    {
        list($app, $user) = $this->app();
        $service = $app->db->where('id', 1)->get('services')->row();

        $this->assertSame($service->rate,
            $app->pricingservice->rates_for(array($service), $user)[1],
            'the list rate applies when nothing overrides it');

        $app->db->insert('service_prices', array(
            'service_id' => 1, 'price_group_id' => $user->price_group_id,
            'rate' => '1.50000000', 'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->assertSame('1.50000000', $app->pricingservice->rates_for(array($service), $user)[1],
            'a price-group rate beats the list rate');

        $app->db->insert('user_service_prices', array(
            'user_id' => $user->id, 'service_id' => 1, 'rate' => '0.90000000',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
        $this->assertSame('0.90000000', $app->pricingservice->rates_for(array($service), $user)[1],
            'a rate set for this customer beats their group');
        $this->assertSame('0.90000000', $app->pricingservice->price_for($service, $user),
            'and the single-service call agrees with the batch');
    }

    public function testPricingAWholeCatalogueCostsTwoQueriesNotTwoPerService()
    {
        list($app, $user) = $this->app();
        $services = array();
        for ($i = 1; $i <= 25; $i++) {
            $services[] = (object)array('id' => $i, 'rate' => '2.00000000');
        }

        $before = count($app->db->queries);
        $rates = $app->pricingservice->rates_for($services, $user);
        $queries = count($app->db->queries) - $before;

        $this->assertCount(25, $rates);
        $this->assertLessThanOrEqual(2, $queries,
            'one query per price table, however long the catalogue');
    }

    public function testAnAnonymousVisitorGetsListRatesWithoutQuerying()
    {
        list($app,) = $this->app();
        $services = array((object)array('id' => 1, 'rate' => '2.00000000'));
        $before = count($app->db->queries);

        $rates = $app->pricingservice->rates_for($services, null);

        $this->assertSame('2.00000000', $rates[1]);
        $this->assertSame($before, count($app->db->queries),
            'there is nothing to look up for a visitor with no account');
    }

    /** The loops that used to price one at a time. */
    public function testTheCatalogueCallersUseTheBatchedLookup()
    {
        foreach (array('controllers/Api_v1.php', 'controllers/dashboard/Orders.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/'.$rel);
            $this->assertStringContainsString('rates_for(', $src, $rel.' must price in bulk');
        }
    }
}
