<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The coupon redemption race (module 18).
 *
 * Module 14 made `usage_limit_per_user` real, and enforced it with a
 * `COUNT(*)` taken moments before the redemption row was written. That is
 * check-then-act: two checkouts by the same customer a few milliseconds apart
 * both count zero, both decide they are inside the limit, and both write a
 * row. A "one per customer" launch code is then worth two — and nothing in the
 * panel looks wrong afterwards, because two redemptions is a perfectly
 * consistent state.
 *
 * A few milliseconds is a double-clicked Pay button, a retried request on a
 * flaky connection, or two tabs. Only the database can settle it, so migration
 * 030 adds `redemption_slot` and a UNIQUE index over
 * `(coupon_id, user_id, redemption_slot)`, and the slot is taken **before**
 * any money moves.
 *
 * These tests drive `FakeDb`, which models that unique index, so "the second
 * one loses" is enforced by the constraint here exactly as it is in MySQL.
 */
class CouponRedemptionRaceTest extends TestCase
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
        $user = $app->register('racer', 'racer@x.test');
        $app->model(array('Coupon_model'));
        return array($app, $user);
    }

    private function coupon($app, array $over = array())
    {
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('coupons', array_merge(array(
            'public_id' => 'CPN'.str_pad((string)random_int(1, 99999), 23, '0', STR_PAD_LEFT),
            'code' => 'RACE'.random_int(100, 999),
            'discount_type' => 'PERCENT', 'discount_value' => '10.00000000',
            'currency' => null, 'min_order_amount' => null, 'max_discount_amount' => null,
            'usage_limit' => null, 'usage_limit_per_user' => 1, 'times_used' => 0,
            'is_active' => 1, 'is_public' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ), $over));
        return $app->db->where('id', $app->db->insert_id())->get('coupons')->row();
    }

    private function rows($app, $coupon, $user)
    {
        return $app->db->where('coupon_id', $coupon->id)->where('user_id', $user->id)
            ->get('coupon_redemptions')->result();
    }

    private function times_used($app, $coupon)
    {
        return (int)$app->db->where('id', $coupon->id)->get('coupons')->row()->times_used;
    }

    /* ========================= the race itself ========================== */

    /**
     * The headline case, written the way the race actually happens: BOTH
     * checkouts read the world before either writes. Under the old
     * count-then-insert code both would have written a row.
     */
    public function testTwoSimultaneousCheckoutsCannotBothRedeemAOnePerCustomerCoupon()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));

        // Interleaved: both see zero redemptions, then both try to take one.
        $seen_by_first  = $app->Coupon_model->redemptions_by($coupon->id, $user->id);
        $seen_by_second = $app->Coupon_model->redemptions_by($coupon->id, $user->id);
        $this->assertSame(0, $seen_by_first);
        $this->assertSame(0, $seen_by_second);

        $first  = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $second = $app->Coupon_model->reserve_redemption($coupon, $user->id);

        $this->assertTrue($first['ok'], 'the first checkout must succeed');
        $this->assertFalse($second['ok'], 'the second must be refused by the database, not by a count');
        $this->assertSame('PER_USER_LIMIT', $second['code']);
        $this->assertSame('You have already used this coupon.', $second['error']);

        $this->assertCount(1, $this->rows($app, $coupon, $user),
            'exactly one redemption may exist for this customer');
        $this->assertSame(1, $this->times_used($app, $coupon),
            'and the global counter counts it exactly once');
    }

    /** The slot numbers are what the unique index is built on. */
    public function testEachRedemptionTakesTheNextSlot()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 3));

        $slots = array();
        for ($i = 0; $i < 3; $i++) {
            $res = $app->Coupon_model->reserve_redemption($coupon, $user->id);
            $this->assertTrue($res['ok']);
            $slots[] = $res['slot'];
        }
        $this->assertSame(array(1, 2, 3), $slots);

        $fourth = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $this->assertFalse($fourth['ok']);
        $this->assertSame('PER_USER_LIMIT', $fourth['code']);
    }

    /** Another customer is unaffected: the index is per (coupon, user). */
    public function testTheLimitIsPerCustomerNotGlobal()
    {
        list($app, $user) = $this->app();
        $other  = $app->register('other', 'other@x.test');
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));

        $this->assertTrue($app->Coupon_model->reserve_redemption($coupon, $user->id)['ok']);
        $this->assertTrue($app->Coupon_model->reserve_redemption($coupon, $other->id)['ok'],
            'a different customer has their own slot 1');
        $this->assertSame(2, $this->times_used($app, $coupon));
    }

    /**
     * An unlimited-per-customer coupon still needs a distinct slot each time;
     * the retry walks up to the next free one instead of failing.
     */
    public function testAnUnlimitedCouponKeepsWorkingAfterTheFirstRedemption()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => null));

        for ($i = 1; $i <= 4; $i++) {
            $res = $app->Coupon_model->reserve_redemption($coupon, $user->id);
            $this->assertTrue($res['ok'], 'redemption '.$i.' must be allowed');
            $this->assertSame($i, $res['slot']);
        }
        $this->assertSame(0, count(array_filter($this->rows($app, $coupon, $user), function ($r) {
            return (int)$r->redemption_slot < 1;
        })));
    }

    /** Zero is the admin form's "no per-customer cap", not "nobody may use it". */
    public function testZeroPerUserMeansUnlimitedNotBlocked()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 0));

        $this->assertTrue($app->Coupon_model->reserve_redemption($coupon, $user->id)['ok']);
        $this->assertTrue($app->Coupon_model->reserve_redemption($coupon, $user->id)['ok']);
    }

    /* ==================== reserve / attach / release ==================== */

    /**
     * A reservation is not a redemption until the charge lands: the row exists
     * with no order and no discount, and `attach()` completes it.
     */
    public function testAReservationIsCompletedByTheChargeThatFollowsIt()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app);

        $res = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $row = $this->rows($app, $coupon, $user)[0];
        $this->assertNull($row->marketplace_order_id);
        $this->assertSame('0.00000000', (string)$row->discount_amount);

        $app->Coupon_model->attach_redemption($res['id'], 4242, '150.00000000');
        $row = $this->rows($app, $coupon, $user)[0];
        $this->assertSame(4242, (int)$row->marketplace_order_id);
        $this->assertSame('150.00000000', (string)$row->discount_amount);
    }

    /**
     * A checkout that reserves a slot and then fails must give it back.
     * Otherwise a declined card burns the customer's single use of a launch
     * code on an order that never existed — the most annoying possible way to
     * lose a sale.
     */
    public function testAFailedCheckoutGivesTheSlotBack()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));

        $res = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $this->times_used($app, $coupon));

        $app->Coupon_model->release_redemption($res['id'], $coupon->id);

        $this->assertCount(0, $this->rows($app, $coupon, $user));
        $this->assertSame(0, $this->times_used($app, $coupon), 'the global counter is given back too');

        $again = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $this->assertTrue($again['ok'], 'the customer may try again with the same code');
        $this->assertSame(1, $again['slot']);
    }

    /** A double release must not make a spent coupon look fresh. */
    public function testReleasingTwiceCannotDriveTheCounterNegative()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app);

        $res = $app->Coupon_model->reserve_redemption($coupon, $user->id);
        $app->Coupon_model->release_redemption($res['id'], $coupon->id);
        $app->Coupon_model->release_redemption($res['id'], $coupon->id);

        $this->assertSame(0, $this->times_used($app, $coupon));
    }

    /**
     * The compatibility wrapper still works, and now answers the question the
     * race could not: false when the limit is already taken.
     */
    public function testRecordRedemptionRefusesOnceTheLimitIsTaken()
    {
        list($app, $user) = $this->app();
        $coupon = $this->coupon($app, array('usage_limit_per_user' => 1));

        $this->assertTrue($app->Coupon_model->record_redemption($coupon->id, $user->id, 11, '10.00000000'));
        $this->assertFalse($app->Coupon_model->record_redemption($coupon->id, $user->id, 12, '10.00000000'));
        $this->assertCount(1, $this->rows($app, $coupon, $user));
    }

    /* ========================== the wiring ============================== */

    /**
     * Checkout must reserve before it charges. Recording afterwards is what
     * made the race expensive: by the time the second row was refused, the
     * money had already moved at the discounted price.
     */
    public function testCheckoutReservesBeforeItCharges()
    {
        $src = file_get_contents(self::$root.'/application/libraries/ShopCheckoutService.php');

        $reserve = strpos($src, 'reserve_redemption');
        $charge  = strpos($src, 'marketplaceservice->purchase');
        $attach  = strpos($src, 'attach_redemption');

        $this->assertNotFalse($reserve, 'checkout must reserve the coupon slot');
        $this->assertNotFalse($charge);
        $this->assertNotFalse($attach);
        $this->assertLessThan($charge, $reserve, 'the slot is taken before any money moves');
        $this->assertGreaterThan($charge, $attach, 'and completed only once a charge has landed');
        $this->assertStringContainsString('release_redemption', $src,
            'a checkout that charges nothing must give the slot back');
    }

    /** The index, the column and the backfill all have to ship. */
    public function testTheMigrationAddsTheConstraintAndIsRegistered()
    {
        $src = file_get_contents(self::$root.'/application/migrations/030_coupon_redemption_slots.php');

        $this->assertStringContainsString('ADD COLUMN redemption_slot', $src);
        $this->assertStringContainsString('CREATE UNIQUE INDEX uq_couponredeem_slot', $src);
        $this->assertStringContainsString('ON coupon_redemptions (coupon_id, user_id, redemption_slot)', $src);
        $this->assertStringContainsString('UPDATE coupon_redemptions', $src,
            'existing rows must be numbered or the index cannot be created');

        // At least 30: later modules add migrations, and an unrelated one
        // must not read as a coupon regression.
        $config = file_get_contents(self::$root.'/application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $v);
        $this->assertGreaterThanOrEqual(30, (int)($v[1] ?? 0),
            'the constraint has to actually be applied on a real install');
    }

    /**
     * The shipped SQL is what a real install runs. If the index is missing
     * there, every fresh panel has the race the migration was written to
     * close.
     */
    public function testTheShippedSqlCarriesTheUniqueIndex()
    {
        $sql = file_get_contents(self::$root.'/database/marvysocials.sql');
        $this->assertStringContainsString('redemption_slot', $sql);
        $this->assertStringContainsString('uq_couponredeem_slot', $sql);
    }
}
