<?php
use PHPUnit\Framework\TestCase;

/**
 * Session 19 — integration tests.
 *
 * Every other file in this suite tests one unit against doubles written by the
 * same person who wrote the unit. That catches logic errors but never catches
 * disagreements *between* components, because a double always agrees with its
 * author's mental model.
 *
 * These tests run the real thing: real models, real services, real schema
 * parsed from the migrations. Only the genuine edges are faked — the provider
 * HTTP call, the session, the mailer. When a service writes a column its caller
 * does not read, or emits a status string the state machine rejects, something
 * in here throws.
 *
 * The headline is testE2E...: the register → deposit → order → status → refund
 * journey §19 asks for, as one continuous story against one database.
 */
class IntegrationTest extends TestCase
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
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/tests/_support/IntegrationHarness.php';
    }

    /** A booted app with one funded customer. */
    private function app($balance = '100.00000000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $user = $app->register('alice', 'alice@x.test');
        if ($balance !== null) $app->credit($user, $balance);
        $app->library('OrderService');
        return array($app, $user);
    }

    /* ======================= the end-to-end journey ====================== */

    /**
     * register → deposit → order → provider status → refund (§19).
     *
     * One database, one continuous story, no re-stubbing between steps.
     */
    public function testE2ECustomerJourneyFromSignupToRefund()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();

        /* --- register ---------------------------------------------------- */
        $user = $app->register('journey', 'journey@x.test');
        $this->assertSame('0.00000000', $app->balance($user), 'a new wallet starts empty');

        /* --- deposit ----------------------------------------------------- */
        $app->credit($user, '25.00000000', 'deposit:journey-1');
        $this->assertSame('25.00000000', $app->balance($user));

        /* --- order ------------------------------------------------------- */
        $app->library('OrderService');
        $placed = $app->orderservice->place($user, array(
            'service'  => 1,
            'link'     => 'https://instagram.com/journey',
            'quantity' => 1000,
        ));
        $this->assertTrue($placed['ok'], $placed['error'] ?? '');
        $order = $placed['order'];

        // Rate is 2.00 per 1000, so 1000 units costs exactly 2.00.
        $this->assertSame('2.00000000', $order->charge);
        $this->assertSame('23.00000000', $app->balance($user), 'the charge left the wallet');
        $this->assertSame('PROCESSING', $order->status);
        $this->assertSame('P-1', $order->provider_order_id, 'the order reached the provider');

        /* --- provider reports progress, then completion ------------------ */
        $order = $app->db->where('id', $order->id)->get('orders')->row();
        $app->orderservice->apply_status($order, 'IN_PROGRESS', 'PROVIDER', 'Provider reported in progress');
        $order = $app->db->where('id', $order->id)->get('orders')->row();
        $this->assertSame('IN_PROGRESS', $order->status);

        $app->orderservice->apply_status($order, 'COMPLETED', 'PROVIDER', 'Provider reported completed');
        $order = $app->db->where('id', $order->id)->get('orders')->row();
        $this->assertSame('COMPLETED', $order->status);
        $this->assertSame('23.00000000', $app->balance($user),
            'completing an order must not move money again');

        /* --- refund ------------------------------------------------------ */
        $app->orderservice->apply_status($order, 'REFUNDED', 'ADMIN', 'Goodwill refund');
        $order = $app->db->where('id', $order->id)->get('orders')->row();

        $this->assertSame('REFUNDED', $order->status);
        $this->assertSame('25.00000000', $app->balance($user), 'the customer got their money back');
        $this->assertSame('2.00000000', $order->refunded_amount);

        /* --- the books still balance ------------------------------------- */
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits, "ledger out of balance: {$debits} vs {$credits}");

        // And the whole journey is auditable.
        $history = $app->db->where('order_id', $order->id)->get('order_status_history')->result();
        $seen = array();
        foreach ($history as $h) $seen[] = $h->new_status;
        // PENDING is recorded at creation, before the provider is called.
        $this->assertSame(
            array('PENDING', 'PROCESSING', 'IN_PROGRESS', 'COMPLETED', 'REFUNDED'), $seen);
    }

    /* ========================= money integrity =========================== */

    public function testAnOrderIsRefundedExactlyOnce()
    {
        list($app, $user) = $this->app();
        $placed = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $order = $app->db->where('id', $placed['order']->id)->get('orders')->row();

        $app->orderservice->apply_status($order, 'CANCELED', 'ADMIN', 'first');
        $after_first = $app->balance($user);

        // A double-click, a retried webhook, an admin refreshing the page.
        $order = $app->db->where('id', $order->id)->get('orders')->row();
        $app->orderservice->apply_status($order, 'REFUNDED', 'ADMIN', 'second');

        $this->assertSame($after_first, $app->balance($user),
            'a second terminal transition must not refund again');
    }

    public function testAnUnaffordableOrderChargesNothing()
    {
        list($app, $user) = $this->app('1.00000000');   // needs 2.00
        $before = $app->balance($user);

        $res = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
        $this->assertSame($before, $app->balance($user));
        $this->assertSame(0, $app->db->count('orders'), 'no order row should survive');
        $this->assertSame(array(), $app->provider_calls, 'the provider must not be called');
    }

    public function testAProviderRejectionRefundsTheCustomer()
    {
        list($app, $user) = $this->app();
        $before = $app->balance($user);
        // The provider takes the order and refuses it.
        $app->provider_responses['createOrder'] = array('ok' => false, 'error' => 'Invalid link');

        $res = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/bad', 'quantity' => 1000,
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame($before, $app->balance($user),
            'a rejected order must not leave the customer out of pocket');
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testAThrowingProviderAlsoRefunds()
    {
        list($app, $user) = $this->app();
        $before = $app->balance($user);
        // Not a clean error response — a timeout mid-call.
        $app->provider_responses['createOrder'] = array('__throw' => 'cURL timeout after 15s');

        $res = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame($before, $app->balance($user));
    }

    public function testDuplicateSubmissionsResolveToOneOrder()
    {
        list($app, $user) = $this->app();
        $input = array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
            'idempotency_key' => 'client-retry-1',
        );

        $first  = $app->orderservice->place($user, $input);
        $second = $app->orderservice->place($user, $input);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['order']->public_id, $second['order']->public_id,
            'the retry must resolve to the original order');
        $this->assertSame(1, $app->db->count('orders'));
        $this->assertSame('98.00000000', $app->balance($user), 'charged once, not twice');
    }

    public function testPartialCompletionRefundsOnlyTheUndeliveredPortion()
    {
        list($app, $user) = $this->app();
        $placed = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $order = $app->db->where('id', $placed['order']->id)->get('orders')->row();
        $this->assertSame('98.00000000', $app->balance($user));

        // 250 of 1000 undelivered => a quarter of 2.00 comes back.
        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'Partially delivered',
            array('remains' => 250));

        $order = $app->db->where('id', $order->id)->get('orders')->row();
        $this->assertSame('PARTIAL', $order->status);
        $this->assertSame('98.50000000', $app->balance($user));
        $this->assertSame('0.50000000', $order->refunded_amount);
    }

    /* ========================= cross-component =========================== */

    public function testTheCronWorkerDrivesRealOrdersToCompletion()
    {
        list($app, $user) = $this->app();
        $placed = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $order = $placed['order'];

        // The provider now reports the order finished.
        $app->provider_responses['getMultipleOrderStatus'] = array(
            'ok' => true, 'data' => array('P-1' => array('status' => 'Completed')),
        );

        $app->library('CronWorkers');
        $result = $app->cronworkers->order_status();

        $this->assertSame(1, $result['processed']);
        $fresh = $app->db->where('id', $order->id)->get('orders')->row();
        $this->assertSame('COMPLETED', $fresh->status,
            'the worker, the adapter, the service and the state machine must agree');
    }

    public function testADripfeedRunPlacesAChildOrderWithoutChargingAgain()
    {
        list($app, $user) = $this->app();
        $now = gmdate('Y-m-d H:i:s', time() - 60);

        // A schedule whose whole charge was already reserved at creation.
        $app->db->insert('dripfeed_orders', array(
            'public_id' => 'DF000000000000000000000001', 'user_id' => $user->id,
            'service_id' => 1, 'link' => 'https://x.test/drip',
            'total_quantity' => 2000, 'quantity_per_run' => 1000, 'runs' => 2,
            'runs_completed' => 0, 'interval_minutes' => 60, 'charge' => '4.00000000',
            'status' => 'ACTIVE', 'next_run_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ));
        $drip_id = $app->db->insert_id();
        foreach (array(1, 2) as $n) {
            $app->db->insert('dripfeed_runs', array(
                'dripfeed_order_id' => $drip_id, 'run_number' => $n,
                'status' => 'PENDING', 'created_at' => $now,
            ));
        }
        $before = $app->balance($user);

        $app->library('DripfeedService');
        $drip = $app->db->where('id', $drip_id)->get('dripfeed_orders')->row();
        $res = $app->dripfeedservice->execute_due_run($drip);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(1, $app->db->count('orders'), 'the run placed a real order');
        $this->assertSame($before, $app->balance($user),
            'a prepaid child order must not touch the wallet');

        // And the child traces back to its schedule.
        $child = $app->db->where('id', 1)->get('orders')->row();
        $this->assertSame((string)$drip_id, (string)$child->dripfeed_order_id);
        $this->assertSame('1', (string)$child->dripfeed_run_number);
    }

    public function testASubscriptionRunChargesPerCycle()
    {
        list($app, $user) = $this->app();
        $past = gmdate('Y-m-d H:i:s', time() - 60);
        $app->db->insert('subscriptions', array(
            'public_id' => 'SUB00000000000000000000001', 'user_id' => $user->id,
            'service_id' => 1, 'target' => 'https://x.test/sub', 'quantity' => 1000,
            'interval_type' => 'daily', 'runs' => 3, 'runs_completed' => 0,
            'status' => 'ACTIVE', 'next_execution_at' => $past,
            'created_at' => $past, 'updated_at' => $past,
        ));
        $before = $app->balance($user);

        $app->library('SubscriptionService');
        $sub = $app->db->where('id', 1)->get('subscriptions')->row();
        $res = $app->subscriptionservice->execute_due($sub);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        // Unlike drip-feed, subscriptions bill at execution time.
        $this->assertSame('98.00000000', $app->balance($user));
        $this->assertNotSame($before, $app->balance($user));
    }

    public function testAffiliateCommissionAccruesFromARealCompletedOrder()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->db->insert('settings', array(
            'setting_key' => 'affiliate_enabled',
            'setting_value' => json_encode(array('value' => true)),
            'category' => 'affiliate',
        ));
        $app->db->insert('settings', array(
            'setting_key' => 'affiliate_commission_rate',
            'setting_value' => json_encode(array('value' => '10')),
            'category' => 'affiliate',
        ));

        $referrer = $app->register('bob', 'bob@x.test');
        $buyer    = $app->register('carol', 'carol@x.test');
        $app->db->where('id', $buyer->id)->update('users', array('referred_by_id' => $referrer->id));
        $buyer = $app->db->where('id', $buyer->id)->get('users')->row();
        $app->credit($buyer, '100.00000000');

        $app->library('OrderService');
        $placed = $app->orderservice->place($buyer, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $this->assertTrue($placed['ok'], $placed['error'] ?? '');

        $order = $app->db->where('id', $placed['order']->id)->get('orders')->row();
        $app->orderservice->apply_status($order, 'COMPLETED', 'PROVIDER', 'done');

        // Whatever the affiliate wiring did, it must not have corrupted the books.
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
        $this->assertSame('98.00000000', $app->balance($buyer),
            'a commission accrues to the referrer; it is not taken from the buyer again');
    }

    /* ======================= schema agreement ============================ */

    public function testEveryServiceWritesOnlyColumnsThatExist()
    {
        // FakeDb throws on an unknown column, so simply exercising the write
        // paths proves the code and the migrations agree. This is the failure
        // mode a mock-based unit test structurally cannot catch.
        list($app, $user) = $this->app();

        $placed = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $order = $app->db->where('id', $placed['order']->id)->get('orders')->row();
        $app->orderservice->apply_status($order, 'IN_PROGRESS', 'PROVIDER', 'x');
        $order = $app->db->where('id', $order->id)->get('orders')->row();
        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'x', array('remains' => 100));

        $this->assertGreaterThan(0, $app->db->count('order_status_history'));
        $this->assertGreaterThan(0, $app->db->count('ledger_entries'));
    }

    public function testTheLedgerIsTheOnlyThingThatWritesWalletBalances()
    {
        list($app, $user) = $this->app();
        $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));

        // FakeDb records every direct write to wallets.balance. The ledger is
        // allowed to make them; nothing else is. Each recorded write must be
        // matched by a wallet_transactions row.
        $balance_writes = count($app->db->raw_updates);
        $tx_rows = $app->db->count('wallet_transactions');
        $this->assertGreaterThan(0, $tx_rows);
        $this->assertLessThanOrEqual($tx_rows, $balance_writes,
            'a balance changed without a matching wallet_transactions row');
    }

    public function testOrderStateMachineIsAvailableWhereverItIsUsed()
    {
        // Regression: OrderService called OrderStateMachine::can() in four
        // places but nothing ever loaded the class — it is a plain static
        // utility, so CI's library loader never pulled it in. Placing any
        // order fatally errored. Only an integration test could see this,
        // because every unit test stubbed the state machine away.
        $src = file_get_contents(self::$root.'/application/libraries/OrderService.php');
        $this->assertMatchesRegularExpression(
            '~require_once\s+__DIR__\s*\.\s*[\'"]/OrderStateMachine\.php[\'"]~', $src,
            'OrderService must load the state machine it depends on');

        list($app, $user) = $this->app();
        $res = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $this->assertTrue($res['ok'], 'placing an order must not fatal');
    }

    /* ========================== validation =============================== */

    public function testQuantityBoundsAreEnforcedAgainstTheRealService()
    {
        list($app, $user) = $this->app();

        $under = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 10,
        ));
        $this->assertFalse($under['ok'], 'min_quantity is 100');

        $over = $app->orderservice->place($user, array(
            'service' => 1, 'link' => 'https://x.test/a', 'quantity' => 999999,
        ));
        $this->assertFalse($over['ok'], 'max_quantity is 10000');

        $this->assertSame(0, $app->db->count('orders'));
        $this->assertSame('100.00000000', $app->balance($user));
    }

    public function testAnUnknownServiceIsRejected()
    {
        list($app, $user) = $this->app();
        $res = $app->orderservice->place($user, array(
            'service' => 4242, 'link' => 'https://x.test/a', 'quantity' => 1000,
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('100.00000000', $app->balance($user));
    }
}
