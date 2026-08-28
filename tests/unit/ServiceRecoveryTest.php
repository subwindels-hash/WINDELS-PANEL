<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Service purchases under provider failure — VTU, numbers, identity, gift cards.
 *
 * These four domains all run through TransactionEngine, and all four are sold
 * the same way: charge the customer, call a vendor, settle later. The failure
 * they were not built for is the one that costs money — the vendor accepting a
 * purchase and then never being answerable about it:
 *
 *   - a dispatch that returns PROCESSING with **no provider reference** is
 *     invisible to every settlement worker, because
 *     `pending_provider_sync()` filters those rows out by definition. VTU had
 *     no give-up rule at all, so the row stayed PROCESSING for ever with the
 *     customer's money in it;
 *   - a PENDING row (the charge never completed) sat in the queues for ever
 *     too, inflating the "stuck purchases" figure staff are asked to act on;
 *   - an adapter that threw a PHP **Error** rather than an Exception escaped
 *     the engine's handler entirely: charged customer, no refund, 500;
 *   - and when money did come back, nobody told the customer.
 *
 * Everything below is driven through the real engine, the real ledger and the
 * real schema; only the vendor call is a double.
 */
class ServiceRecoveryTest extends TestCase
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

    private function app($balance = '50000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $user = $app->register('buyer', 'buyer@x.test');
        $app->credit($user, $balance);
        $app->library(array('LedgerService', 'TransactionEngine', 'CronWorkers', 'NotificationService'));
        $app->model(array('Service_transaction_model', 'Wallet_model', 'Notification_model',
                          'Service_transaction_status_history_model', 'Setting_model'));
        return array($app, $user);
    }

    /** One purchase through the real engine, with a scripted vendor answer. */
    private function purchase($app, $user, array $dispatch, $domain = 'VTU', $amount = '1000.00000000')
    {
        return $app->transactionengine->execute($user, array(
            'service_domain' => $domain,
            'service_type'   => 'AIRTIME',
            'amount'         => $amount,
            'dispatch'       => function ($tx) use ($dispatch) { return $dispatch; },
        ));
    }

    private function age($app, $tx_id, $expression)
    {
        $app->db->where('id', $tx_id)->update('service_transactions',
            array('created_at' => gmdate('Y-m-d H:i:s', strtotime($expression))));
    }

    private function tx($app, $id)
    {
        return $app->db->where('id', $id)->get('service_transactions')->row();
    }

    private function notifications($app)
    {
        return array_values(array_filter($app->rows('notifications'),
            function ($n) { return ($n['type'] ?? '') === 'purchase.refunded'; }));
    }

    /* ===================== the unpollable purchase ======================= */

    /**
     * The headline case. The vendor said "accepted, in progress" and gave us
     * nothing to check on. No worker can ever settle it, so the sweep must.
     */
    public function testAPurchaseWithNothingToPollIsRefundedOnceItIsHopeless()
    {
        list($app, $user) = $this->app();
        $before = $app->balance($user);

        $res = $this->purchase($app, $user, array('ok' => true, 'status' => 'PROCESSING'));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $id = (int)$res['transaction']->id;
        $this->assertSame('PROCESSING', $this->tx($app, $id)->status);
        $this->assertNotSame($before, $app->balance($user), 'the customer has paid');

        $this->age($app, $id, '-3 hours');
        $summary = $app->cronworkers->service_recovery();

        $tx = $this->tx($app, $id);
        $this->assertSame('FAILED', $tx->status);
        $this->assertSame(0, bccomp($tx->refunded_amount, '1000.00000000', 8));
        $this->assertSame($before, $app->balance($user), 'the customer is made whole');
        $this->assertSame(1, $summary['refunded']);
        $this->assertStringContainsString('nothing we can check', (string)$tx->failure_reason);
    }

    public function testTheRefundedCustomerIsToldWhyTheirMoneyCameBack()
    {
        list($app, $user) = $this->app();
        $res = $this->purchase($app, $user, array('ok' => true, 'status' => 'PROCESSING'));
        $this->age($app, (int)$res['transaction']->id, '-3 hours');

        $app->cronworkers->service_recovery();

        $notes = $this->notifications($app);
        $this->assertCount(1, $notes, 'a balance that changes with no explanation is a support ticket');
        $this->assertStringContainsString($res['transaction']->public_id, $notes[0]['body']);
        $this->assertStringContainsString('returned to your wallet', $notes[0]['body']);
    }

    /** A purchase still inside its window is somebody else's job. */
    public function testAFreshPurchaseIsLeftAlone()
    {
        list($app, $user) = $this->app();
        $res = $this->purchase($app, $user, array('ok' => true, 'status' => 'PROCESSING'));

        $summary = $app->cronworkers->service_recovery();

        $this->assertSame('PROCESSING', $this->tx($app, (int)$res['transaction']->id)->status);
        $this->assertSame(0, $summary['processed']);
    }

    /**
     * A purchase the vendor DID give a reference for is the settlement
     * worker's job — until the vendor stops answering entirely, which is what
     * the hard backstop is for.
     */
    public function testAPollablePurchaseIsOnlyClosedByTheHardBackstop()
    {
        list($app, $user) = $this->app();
        $res = $this->purchase($app, $user,
            array('ok' => true, 'status' => 'PROCESSING', 'reference' => 'VEND-1'));
        $id = (int)$res['transaction']->id;

        $this->age($app, $id, '-3 hours');
        $app->cronworkers->service_recovery();
        $this->assertSame('PROCESSING', $this->tx($app, $id)->status,
            'three hours is the poller’s problem, not a write-off');

        $this->age($app, $id, '-30 hours');
        $app->cronworkers->service_recovery();
        $tx = $this->tx($app, $id);
        $this->assertSame('FAILED', $tx->status);
        $this->assertStringContainsString('never settled', (string)$tx->failure_reason);
    }

    /* ========================= the unpaid record ========================= */

    /**
     * A PENDING row means the charge never completed. It must be closed —
     * it counts against the "stuck purchases" figure staff act on — but no
     * money may move, because none was taken.
     */
    public function testAnUnpaidPendingRecordIsClosedWithoutMovingMoney()
    {
        list($app, $user) = $this->app();
        $before = $app->balance($user);
        $app->db->insert('service_transactions', array(
            'public_id' => 'STXPENDING0000000000000001', 'user_id' => $user->id,
            'service_domain' => 'GIFTCARD', 'service_type' => 'GIFTCARD',
            'status' => 'PENDING', 'amount' => '2500.00000000', 'currency' => 'NGN',
            'source' => 'WEB', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-3 hours')),
        ));
        $id = $app->db->insert_id();

        $summary = $app->cronworkers->service_recovery();

        $tx = $this->tx($app, $id);
        $this->assertSame('FAILED', $tx->status);
        $this->assertStringContainsString('before payment', (string)$tx->failure_reason);
        $this->assertSame($before, $app->balance($user), 'nothing was charged, so nothing is refunded');
        $this->assertSame(0, $summary['refunded']);
    }

    /* ====================== money moves exactly once ===================== */

    public function testTheSweepNeverRefundsTheSamePurchaseTwice()
    {
        list($app, $user) = $this->app();
        $before = $app->balance($user);
        $res = $this->purchase($app, $user, array('ok' => true, 'status' => 'PROCESSING'));
        $this->age($app, (int)$res['transaction']->id, '-3 hours');

        $app->cronworkers->service_recovery();
        $app->cronworkers->service_recovery();
        $app->cronworkers->service_recovery();

        $this->assertSame($before, $app->balance($user));
        $this->assertCount(1, $this->notifications($app), 'and tells them once');
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    public function testAnAlreadySuccessfulPurchaseIsNeverSweptAway()
    {
        list($app, $user) = $this->app();
        $res = $this->purchase($app, $user, array('ok' => true, 'reference' => 'VEND-9'));
        $id = (int)$res['transaction']->id;
        $this->assertSame('SUCCESSFUL', $this->tx($app, $id)->status);

        $this->age($app, $id, '-40 hours');
        $app->cronworkers->service_recovery();

        $this->assertSame('SUCCESSFUL', $this->tx($app, $id)->status,
            'delivered is not stuck, however old');
    }

    /* ==================== an adapter that blows up ======================= */

    /**
     * A PHP Error is not an Exception. An adapter reaching a method on null —
     * the shape of every "the vendor changed a field" bug — used to escape the
     * engine, leaving a charged customer, no refund and a 500.
     */
    public function testAnAdapterThatThrowsAPhpErrorStillRefunds()
    {
        list($app, $user) = $this->app();
        $before = $app->balance($user);

        $res = $app->transactionengine->execute($user, array(
            'service_domain' => 'VTU', 'service_type' => 'DATA', 'amount' => '750.00000000',
            'dispatch' => function ($tx) { $nope = null; return $nope->status(); },
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('PROVIDER_ERROR', $res['code']);
        $this->assertSame($before, $app->balance($user), 'a crash must not keep the money');
        $this->assertCount(1, $this->notifications($app));
    }

    /* ========================= the sweep is wired ======================== */

    public function testTheJobIsRegisteredEverywhereItHasToBe()
    {
        $root = self::$root;
        $this->assertStringContainsString("'service_recovery'",
            file_get_contents($root.'/application/controllers/Cron.php'),
            'the job must be listed and callable');
        $this->assertStringContainsString("'service_recovery'",
            file_get_contents($root.'/application/config/marvy.php'),
            'and have a schedule');
        $this->assertStringContainsString('service_recovery',
            file_get_contents($root.'/cron/crontab.example'),
            'and be in the crontab operators actually install');
    }
}
