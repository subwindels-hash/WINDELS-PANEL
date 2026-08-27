<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Admin refill, cancellation, drip-feed and subscription queues (Session 30).
 *
 * All four were routed in Session 15 with no controller behind them, so
 * `orders.refill` and `orders.cancel` gated nothing and support could see a
 * refill stuck in PROCESSING for three days without being able to act.
 *
 * These are queues over engines that already worked, so the tests are not
 * about the engines. They are about the one thing that goes wrong when you
 * bolt an admin surface onto a customer-scoped service: **whose account the
 * action runs against**. Every one of these services looks its row up by
 * `user_id`, so an admin path that passes the actor instead of the owner
 * either 404s on every row or — for drip-feed cancellation, which refunds an
 * unspent reserve — pays the wrong wallet.
 */
class AdminOperationsTest extends TestCase
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

    /** A customer with a completed order, plus an admin who is not them. */
    private function app($balance = '50000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $admin    = $app->register('ops', 'ops@x.test', 'Str0ng!pass1', 'ADMIN');
        $customer = $app->register('buyer', 'buyer@x.test');
        $app->credit($customer, $balance);
        $app->library(array('LedgerService', 'OrderService', 'RefillService',
                            'DripfeedService', 'SubscriptionService'));
        $app->model(array('Order_model', 'Refill_model', 'Dripfeed_order_model',
                          'Subscription_model', 'Wallet_model', 'User_model', 'Service_model'));
        return array($app, $admin, $customer);
    }

    private function completed_order($app, $customer)
    {
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/1', 'quantity' => 1000,
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $app->db->where('id', $res['order']->id)->update('orders',
            array('status' => 'COMPLETED', 'provider_order_id' => 'PO-1'));
        return $app->Order_model->find_by_id($res['order']->id);
    }

    /* ===================== the owner-vs-actor guarantee ================== */

    /**
     * The mistake this screen exists to avoid: RefillService scopes its
     * lookup by user, so an admin path that passed itself would report
     * "order not found" for every order on the panel.
     */
    public function testAnAdminRefillRunsAgainstTheOrderOwnerNotTheAdmin()
    {
        list($app, $admin, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);

        // What the controller does: resolve the owner, then act as them.
        $owner = $app->User_model->find_by_id($order->user_id);
        $res   = $app->refillservice->request($order->public_id, $owner);
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        // What passing the actor would do.
        $wrong = $app->refillservice->request($order->public_id, $admin);
        $this->assertFalse($wrong['ok']);
        $this->assertSame('NO_ORDER', $wrong['code'],
            'passing the admin must not silently succeed against someone else’s order');
    }

    public function testTheRefillIsRecordedAgainstTheOrder()
    {
        list($app, , $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $owner = $app->User_model->find_by_id($order->user_id);

        $app->refillservice->request($order->public_id, $owner);

        $rows = $app->rows('refills');
        $this->assertCount(1, $rows);
        $this->assertSame((int)$order->id, (int)$rows[0]['order_id']);
    }

    /** The engine's own guard must still hold on the admin path. */
    public function testASecondRefillIsRefusedWhileOneIsLive()
    {
        list($app, , $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $owner = $app->User_model->find_by_id($order->user_id);

        $app->refillservice->request($order->public_id, $owner);
        $again = $app->refillservice->request($order->public_id, $owner);

        $this->assertFalse($again['ok']);
        $this->assertSame('DUPLICATE', $again['code']);
        $this->assertCount(1, $app->rows('refills'));
    }

    /**
     * Cancelling a drip-feed refunds the unspent reserve. Running it against
     * the wrong user would credit the wrong wallet — the reason the admin
     * path reuses the service instead of reimplementing the arithmetic.
     */
    public function testCancellingADripFeedRefundsTheOwnersWalletNotTheAdmins()
    {
        list($app, $admin, $customer) = $this->app();
        $created = $app->dripfeedservice->create($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/2',
            'total_quantity' => 2000, 'quantity_per_run' => 1000, 'runs' => 2,
            'interval_minutes' => 60,
        ));
        $this->assertTrue($created['ok'], $created['error'] ?? '');

        $customer_before = $app->balance($customer);
        $admin_before    = $app->balance($admin);

        $drip  = $created['dripfeed'];
        $owner = $app->User_model->find_by_id($drip->user_id);
        $res   = $app->dripfeedservice->cancel($drip->public_id, $owner);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(bcadd($customer_before, $drip->charge, 8), $app->balance($customer),
            'the reserve must return to the customer who paid it');
        $this->assertSame($admin_before, $app->balance($admin),
            'the admin who clicked cancel must never be credited');
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testPauseAndResumeMoveADripFeedThroughItsStates()
    {
        list($app, , $customer) = $this->app();
        $created = $app->dripfeedservice->create($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/3',
            'total_quantity' => 2000, 'quantity_per_run' => 1000, 'runs' => 2,
            'interval_minutes' => 60,
        ));
        $drip  = $created['dripfeed'];
        $owner = $app->User_model->find_by_id($drip->user_id);

        $this->assertTrue($app->dripfeedservice->pause($drip->public_id, $owner)['ok']);
        $this->assertSame('PAUSED', $app->Dripfeed_order_model->find_by_id($drip->id)->status);

        $this->assertTrue($app->dripfeedservice->resume($drip->public_id, $owner)['ok']);
        $this->assertSame('ACTIVE', $app->Dripfeed_order_model->find_by_id($drip->id)->status);

        // Resuming an already-active schedule is a state error, not a no-op.
        $this->assertFalse($app->dripfeedservice->resume($drip->public_id, $owner)['ok']);
    }

    public function testASubscriptionCanBePausedResumedAndCancelled()
    {
        list($app, , $customer) = $this->app();
        $created = $app->subscriptionservice->create($customer, array(
            'service' => 1, 'target' => 'https://insta.test/u/x', 'quantity' => 100,
            'interval_type' => 'daily', 'runs' => 3,
        ));
        $this->assertTrue($created['ok'], $created['error'] ?? '');
        $sub   = $created['subscription'];
        $owner = $app->User_model->find_by_id($sub->user_id);

        $this->assertTrue($app->subscriptionservice->pause($sub->public_id, $owner)['ok']);
        $this->assertSame('PAUSED', $app->Subscription_model->find_by_id($sub->id)->status);

        $this->assertTrue($app->subscriptionservice->resume($sub->public_id, $owner)['ok']);
        $this->assertTrue($app->subscriptionservice->cancel($sub->public_id, $owner)['ok']);
        $this->assertSame('CANCELED', $app->Subscription_model->find_by_id($sub->id)->status);
    }

    /* ============================== queues ============================== */

    public function testTheRefillQueueReadsAcrossCustomers()
    {
        list($app, , $customer) = $this->app();
        $second = $app->register('buyer2', 'buyer2@x.test');
        $app->credit($second, '50000');

        foreach (array($customer, $second) as $u) {
            $order = $this->completed_order($app, $u);
            $app->refillservice->request($order->public_id,
                $app->User_model->find_by_id($order->user_id));
        }

        $this->assertSame(2, $app->Refill_model->admin_count(array()),
            'the staff queue must span customers, unlike for_user()');
        $rows = $app->Refill_model->admin_search(array(), 25, 0);
        $this->assertCount(2, $rows);
        // The join must carry enough to act on: which order, whose.
        $this->assertNotEmpty($rows[0]->order_public_id);
        $this->assertNotEmpty($rows[0]->username);
    }

    public function testTheRefillQueueFiltersByStatus()
    {
        list($app, , $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->refillservice->request($order->public_id, $app->User_model->find_by_id($order->user_id));

        $this->assertSame(1, $app->Refill_model->admin_count(array('status' => 'PENDING')));
        $this->assertSame(0, $app->Refill_model->admin_count(array('status' => 'COMPLETED')));

        $counts = $app->Refill_model->status_counts();
        $this->assertSame(1, $counts['PENDING']);
    }

    public function testTheSchedulerQueuesReadAcrossCustomers()
    {
        list($app, , $customer) = $this->app();
        $app->dripfeedservice->create($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/4',
            'total_quantity' => 2000, 'quantity_per_run' => 1000, 'runs' => 2,
            'interval_minutes' => 60));
        $app->subscriptionservice->create($customer, array(
            'service' => 1, 'target' => 'https://insta.test/u/y', 'quantity' => 100,
            'interval_type' => 'daily', 'runs' => 3));

        $this->assertSame(1, $app->Dripfeed_order_model->admin_count(array()));
        $this->assertSame(1, $app->Subscription_model->admin_count(array()));

        $drip = $app->Dripfeed_order_model->admin_search(array(), 25, 0)[0];
        $this->assertNotEmpty($drip->username, 'the queue must name the customer');
        $this->assertNotEmpty($drip->service_name);
    }

    public function testAQueueLooksUpARowByItsPublicId()
    {
        list($app, , $customer) = $this->app();
        $created = $app->dripfeedservice->create($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/5',
            'total_quantity' => 2000, 'quantity_per_run' => 1000, 'runs' => 2,
            'interval_minutes' => 60));

        $this->assertNotNull($app->Dripfeed_order_model->admin_find($created['dripfeed']->public_id));
        $this->assertNull($app->Dripfeed_order_model->admin_find('NOPE'));
    }

    /* ======================= controller guarantees ====================== */

    /**
     * The pattern, pinned in source: every action resolves the owner and
     * hands that to the service.
     */
    public function testTheControllerActsAsTheOwner()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Operations.php');
        $this->assertStringContainsString('$owner = $this->User_model->find_by_id(', $src);
        $this->assertStringNotContainsString('$this->current_user)', $src,
            'a service call must never be handed the acting admin as the owner');
    }

    public function testEveryQueueActionIsPostOnlyPermissionedAndAudited()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Operations.php');
        foreach (array('refill_request', 'cancel', 'dripfeed_action', 'subscription_action') as $action) {
            $this->assertStringContainsString('function '.$action.'(', $src);
        }
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertSame(4, substr_count($src, '$this->guard('),
            'every mutation must go through guard()');
        $this->assertStringContainsString("'orders.refill'", $src);
        $this->assertStringContainsString("'orders.cancel'", $src);
        $this->assertStringContainsString('$this->audit(', $src);
    }

    public function testTheControllerNeverMovesMoneyItself()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Operations.php');
        foreach (array('ledgerservice->', "update('wallets'", "insert('wallet_transactions'") as $write) {
            $this->assertStringNotContainsString($write, $src,
                'refunds belong to the services, found '.$write);
        }
    }

    /** Only pause/resume/cancel may be dispatched from a URL segment. */
    public function testTheActionDispatcherIsAllowListed()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Operations.php');
        $this->assertStringContainsString("in_array(\$action, array('pause', 'resume', 'cancel'), true)", $src,
            'a URL segment must never select an arbitrary service method');

        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString('(pause|resume|cancel)', $routes,
            'the route itself should constrain the action too');
    }
}
