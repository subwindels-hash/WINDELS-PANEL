<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Refunds and refills — the two paths where the panel gives money or goods
 * back, run against the real stack (real models, real ledger, real schema).
 *
 * Both were written as if the provider always says yes and the ledger always
 * pays. The failures that mattered:
 *
 *  - a refill the provider REFUSED was reported to the customer as requested,
 *    left in PENDING, and then ignored by the poller for ever, because the
 *    poller only looks at refills that already carry a provider refill id;
 *  - a refill lost to a TIMEOUT was treated exactly the same, so a top-up that
 *    never reached the provider was never re-sent;
 *  - the refill poller mapped provider words through the ORDER status map, so
 *    "Rejected" mapped to nothing, the row was skipped without even recording
 *    that it had been checked, and — as rows are polled oldest-checked first —
 *    a handful of such refills could starve the whole queue;
 *  - a partial delivery with `remains >= quantity` (nothing delivered)
 *    refunded ZERO;
 *  - a partial refund wrote `refunded_amount` whether or not the ledger paid,
 *    and overwrote it rather than accumulating, so a second report could claim
 *    a refund the customer never received;
 *  - cancelling refunded the customer even when the provider refused to stop,
 *    which pays for the delivery twice.
 *
 * Each test below is one of those, stated as the customer-visible outcome.
 */
class RefundsRefillsTest extends TestCase
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

    /** A customer with money, the real services, and every library involved. */
    private function app($balance = '50000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $customer = $app->register('buyer', 'buyer@x.test');
        $app->credit($customer, $balance);
        $app->library(array('LedgerService', 'OrderService', 'RefillService',
                            'NotificationService', 'CronWorkers'));
        $app->model(array('Order_model', 'Refill_model', 'Wallet_model', 'User_model',
                          'Service_model', 'Provider_model', 'Setting_model',
                          'Notification_model', 'Refill_status_history_model'));
        return array($app, $customer);
    }

    /** An order that has been delivered — the only kind that can be refilled. */
    private function completed_order($app, $customer, $quantity = 1000)
    {
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/1', 'quantity' => $quantity,
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $app->db->where('id', $res['order']->id)->update('orders', array(
            'status' => 'COMPLETED', 'provider_order_id' => 'PO-1',
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ));
        return $app->Order_model->find_by_id($res['order']->id);
    }

    private function refill_rows($app) { return $app->rows('refills'); }

    private function notifications($app, $type)
    {
        return array_values(array_filter($app->rows('notifications'),
            function ($n) use ($type) { return ($n['type'] ?? '') === $type; }));
    }

    /* ========================== refill: refusals ========================= */

    /**
     * The headline defect. "Refill not available for this order" was shown to
     * the customer as a successful request, and the row stayed open for ever.
     */
    public function testAProviderRefusalIsReportedToTheCustomerAndClosesTheRefill()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->provider_responses['requestRefill'] = array(
            'ok' => false, 'error' => 'Refill not available for this order', 'retryable' => false,
        );

        $res = $app->refillservice->request($order->public_id, $customer);

        $this->assertFalse($res['ok'], 'a refused refill must never be reported as requested');
        $this->assertSame('PROVIDER_REFUSED', $res['code']);
        $this->assertStringContainsString('Refill not available', $res['error']);

        $rows = $this->refill_rows($app);
        $this->assertCount(1, $rows, 'the attempt is still recorded for staff');
        $this->assertSame('FAILED', $rows[0]['status'], 'a refusal is terminal, not "pending"');
        $this->assertNotEmpty($rows[0]['completed_at'], 'a closed refill has an end date');
        $this->assertStringContainsString('Refill not available', (string)$rows[0]['error']);
    }

    public function testARefusedRefillTellsTheCustomerInTheirInbox()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->provider_responses['requestRefill'] = array('ok' => false, 'error' => 'Order too old');

        $app->refillservice->request($order->public_id, $customer);

        $notes = $this->notifications($app, 'refill.failed');
        $this->assertCount(1, $notes, 'the customer must hear that their remedy failed');
        $this->assertStringContainsString($order->public_id, $notes[0]['body']);
    }

    /** A refusal is final; re-sending it only annoys the provider. */
    public function testARefusedRefillIsNotRetriedByTheWorker()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->provider_responses['requestRefill'] = array('ok' => false, 'error' => 'Incorrect order ID');
        $app->refillservice->request($order->public_id, $customer);
        $calls = count($app->provider_calls);

        $app->cronworkers->refill_status();

        $this->assertSame($calls, count($app->provider_calls),
            'a closed refill must not be re-sent on every cron run');
    }

    /* ====================== refill: transport failures =================== */

    /**
     * A timeout is not an answer. The refill has to survive it and be sent
     * again, or the customer's only remedy is lost to one bad minute.
     */
    public function testATimeoutKeepsTheRefillOpenAndTheWorkerSendsItAgain()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->provider_responses['requestRefill'] = array(
            'ok' => false, 'error' => 'The provider could not be reached.', 'retryable' => true,
        );

        $res = $app->refillservice->request($order->public_id, $customer);
        $this->assertTrue($res['ok'], 'nothing was refused, so nothing is refused to the customer');
        $rows = $this->refill_rows($app);
        $this->assertSame('PENDING', $rows[0]['status']);
        $this->assertEmpty($rows[0]['completed_at']);

        // The provider comes back. One worker run both re-sends the refill and
        // polls it, so it lands on whatever the provider then reports.
        $app->provider_responses['requestRefill']   = array('ok' => true, 'provider_refill_id' => 'R-77');
        $app->provider_responses['getRefillStatus'] = array('ok' => true, 'data' => array('status' => 'In progress'));
        $summary = $app->cronworkers->refill_status();

        $rows = $this->refill_rows($app);
        $this->assertSame('IN_PROGRESS', $rows[0]['status'], 'the worker re-sent it and then polled it');
        $this->assertSame('R-77', $rows[0]['provider_refill_id']);
        $this->assertSame(1, $summary['sent']);
    }

    /** Retries are bounded, or one dead provider leaves immortal queue items. */
    public function testAnUnansweredRefillIsWrittenOffRatherThanRetriedForEver()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->provider_responses['requestRefill'] = array(
            'ok' => false, 'error' => 'timeout', 'retryable' => true,
        );
        $app->refillservice->request($order->public_id, $customer);

        for ($i = 0; $i < RefillService::MAX_SUBMIT_ATTEMPTS + 1; $i++) {
            $app->cronworkers->refill_status();
        }

        $rows = $this->refill_rows($app);
        $this->assertSame('FAILED', $rows[0]['status']);
        $this->assertStringContainsString('Given up', (string)$rows[0]['error']);
        $this->assertCount(1, $this->notifications($app, 'refill.failed'),
            'giving up is still an outcome the customer is owed');
    }

    /**
     * An order that never reached a provider cannot be refilled by a machine.
     * It stays open and flagged for staff — but it must not burn retries or be
     * written off as if a provider had refused it.
     */
    public function testARefillWithNoProviderReferenceIsHandedToStaffNotFailed()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->db->where('id', $order->id)->update('orders', array('provider_order_id' => null));

        $res = $app->refillservice->request($order->public_id, $customer);
        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('staff', $res['message']);

        for ($i = 0; $i < RefillService::MAX_SUBMIT_ATTEMPTS + 2; $i++) {
            $app->cronworkers->refill_status();
        }
        $rows = $this->refill_rows($app);
        $this->assertSame('PENDING', $rows[0]['status'],
            'a human still has to act; the worker must not close it behind their back');
        $meta = json_decode((string)$rows[0]['metadata'], true);
        $this->assertTrue(!empty($meta['manual']), 'the queue has to be able to show why it is stuck');
    }

    /* ========================= refill: the poller ======================== */

    public function testThePollerSettlesAnAcceptedRefillAndTellsTheCustomer()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->refillservice->request($order->public_id, $customer);

        $app->provider_responses['getRefillStatus'] = array('ok' => true, 'data' => array('status' => 'Completed'));
        $app->cronworkers->refill_status();

        $rows = $this->refill_rows($app);
        $this->assertSame('COMPLETED', $rows[0]['status']);
        $this->assertNotEmpty($rows[0]['completed_at']);
        $this->assertCount(1, $this->notifications($app, 'refill.completed'));
        $history = $app->rows('refill_status_history');
        $this->assertSame('COMPLETED', end($history)['new_status'], 'the trail records who moved it');
    }

    /**
     * "Rejected" is a word only a refill uses. Under the order status map it
     * mapped to nothing at all, and the row was skipped without being touched.
     */
    public function testAProviderRejectionDuringPollingClosesTheRefill()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->refillservice->request($order->public_id, $customer);

        $app->provider_responses['getRefillStatus'] = array('ok' => true, 'data' => array('status' => 'Rejected'));
        $app->cronworkers->refill_status();

        $rows = $this->refill_rows($app);
        $this->assertSame('FAILED', $rows[0]['status']);
        $this->assertCount(1, $this->notifications($app, 'refill.failed'));
    }

    /**
     * The starvation bug: rows are polled oldest-checked-first, so a status we
     * cannot map must still record that it was checked. Otherwise the same
     * rows are re-selected on every run and nothing else is ever polled.
     */
    public function testAnUnmappableStatusStillRecordsThatTheRefillWasChecked()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->refillservice->request($order->public_id, $customer);
        $app->db->where('order_id', $order->id)->update('refills',
            array('last_checked_at' => '2020-01-01 00:00:00'));

        $app->provider_responses['getRefillStatus'] = array('ok' => true, 'data' => array('status' => 'Zorbling'));
        $app->cronworkers->refill_status();

        $rows = $this->refill_rows($app);
        $this->assertNotSame('2020-01-01 00:00:00', $rows[0]['last_checked_at'],
            'an unknown status must not leave the row at the head of the queue for ever');
        $this->assertSame('PROCESSING', $rows[0]['status'], 'and must not invent a state either');
    }

    /**
     * A refill nobody ever settles is worse than a refused one: the customer
     * watches a top-up that is never coming.
     */
    public function testARefillTheProviderNeverSettlesIsClosedAndAnnounced()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->refillservice->request($order->public_id, $customer);
        $app->db->where('order_id', $order->id)->update('refills',
            array('requested_at' => gmdate('Y-m-d H:i:s', time() - (400 * 3600))));

        $app->provider_responses['getRefillStatus'] = array('ok' => true, 'data' => array('status' => 'Pending'));
        $summary = $app->cronworkers->refill_status();

        $rows = $this->refill_rows($app);
        $this->assertSame('FAILED', $rows[0]['status']);
        $this->assertStringContainsString('never settled', (string)$rows[0]['error']);
        $this->assertSame(1, $summary['closed']);
    }

    /** Outside the guarantee window the provider will refuse; do not pretend. */
    public function testARefillOutsideTheGuaranteeWindowIsRefusedBeforeCallingTheProvider()
    {
        list($app, $customer) = $this->app();
        $order = $this->completed_order($app, $customer);
        $app->db->where('id', $order->id)->update('orders',
            array('completed_at' => gmdate('Y-m-d H:i:s', time() - (90 * 86400))));
        $app->Setting_model->set('refill_window_days', '30', 'orders');
        $before = count($app->provider_calls);

        $res = $app->refillservice->request($order->public_id, $customer);

        $this->assertFalse($res['ok']);
        $this->assertSame('WINDOW_CLOSED', $res['code']);
        $this->assertSame($before, count($app->provider_calls), 'no point asking');
        $this->assertCount(0, $this->refill_rows($app));
    }

    /* ======================== partial refund maths ======================= */

    /**
     * `remains == quantity` means the provider delivered nothing. The old
     * arithmetic guarded `remains < quantity` and therefore refunded zero: the
     * customer paid in full for an empty delivery.
     */
    public function testAPartialThatDeliveredNothingRefundsTheWholeCharge()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/2', 'quantity' => 1000,
        ));
        $order  = $res['order'];
        $charge = (string)$order->charge;
        $before = $app->balance($customer);

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'nothing delivered',
            array('remains' => 1000));

        $order = $app->Order_model->find_by_id($order->id);
        $this->assertSame(number_format((float)$charge, 8, '.', ''), $order->refunded_amount);
        $this->assertSame(bcadd($before, $charge, 8), $app->balance($customer));
    }

    /** A remainder above the quantity is a provider quirk, not free money. */
    public function testARemainderLargerThanTheOrderIsClampedNotMultiplied()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/3', 'quantity' => 1000,
        ));
        $order  = $res['order'];
        $charge = (string)$order->charge;

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'odd report',
            array('remains' => 5000));

        $order = $app->Order_model->find_by_id($order->id);
        $this->assertSame(1000, (int)$order->remains);
        $this->assertSame(0, bccomp($order->refunded_amount, $charge, 8),
            'never refund more than was charged');
    }

    /**
     * A drop reported in stages. The refund must accumulate to the proportion
     * of the LATEST report, and the ledger must be paid exactly the difference.
     */
    public function testAWorseningPartialRefundsOnlyTheDifference()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/4', 'quantity' => 1000,
        ));
        $order  = $res['order'];
        $charge = (string)$order->charge;              // 1000 x 2.00 = 2000
        $after_charge = $app->balance($customer);

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'first report',
            array('remains' => 200));
        $order = $app->Order_model->find_by_id($order->id);
        $first = $order->refunded_amount;
        $this->assertSame(0, bccomp($first, bcmul($charge, '0.2', 8), 8));

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'more dropped',
            array('remains' => 500));
        $order = $app->Order_model->find_by_id($order->id);

        $this->assertSame(0, bccomp($order->refunded_amount, bcmul($charge, '0.5', 8), 8),
            'the recorded refund is the proportion of the latest report');
        $this->assertSame(bcadd($after_charge, bcmul($charge, '0.5', 8), 8), $app->balance($customer),
            'and the wallet received exactly that, not the two proportions added together');
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits, 'double entry still balances');
    }

    /** Re-reporting the same partial must move no money at all. */
    public function testRepeatingTheSamePartialReportRefundsNothingTwice()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/5', 'quantity' => 1000,
        ));
        $order = $res['order'];

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'report', array('remains' => 300));
        $balance = $app->balance($customer);
        $order = $app->Order_model->find_by_id($order->id);
        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'same report again',
            array('remains' => 300));

        $this->assertSame($balance, $app->balance($customer));
    }

    /**
     * Partial first, full refund after. Together they must return the charge
     * once — the bug being that a mis-recorded partial silently reduces what
     * the later refund pays out.
     */
    public function testAPartialFollowedByAFullRefundReturnsExactlyTheCharge()
    {
        list($app, $customer) = $this->app();
        $before = $app->balance($customer);
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/6', 'quantity' => 1000,
        ));
        $order = $res['order'];

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'partial', array('remains' => 400));
        $order = $app->Order_model->find_by_id($order->id);
        $app->orderservice->apply_status($order, 'REFUNDED', 'ADMIN', 'goodwill');

        $this->assertSame($before, $app->balance($customer),
            'the customer is made whole exactly once');
        $order = $app->Order_model->find_by_id($order->id);
        $this->assertSame(0, bccomp($order->refunded_amount, $order->charge, 8));
        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits, 'double entry still balances');
    }

    /**
     * If the ledger cannot pay, the order must not claim it was refunded. A
     * phantom `refunded_amount` is money the customer never gets and nobody
     * ever looks for again.
     */
    public function testAFailedRefundIsNeverRecordedAsPaid()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/7', 'quantity' => 1000,
        ));
        $order = $res['order'];
        // Stand in a ledger that refuses the credit — a locked wallet, a
        // database that rejected the write. The charge has already been taken
        // through the real ledger, so only the refund is affected.
        $app->ledgerservice = new RefusingLedger();

        $app->orderservice->apply_status($order, 'PARTIAL', 'PROVIDER', 'partial', array('remains' => 500));

        $order = $app->Order_model->find_by_id($order->id);
        $this->assertSame(0, bccomp((string)($order->refunded_amount ?: '0'), '0', 8),
            'no ledger movement, no recorded refund');
        $this->assertStringContainsString('staff', (string)$order->note);
    }

    /* ============================ cancellation =========================== */

    /**
     * The expensive one: a provider that refuses to stop is still delivering,
     * and refunding the customer anyway means paying for the order twice.
     */
    public function testACancellationTheProviderRefusesDoesNotRefundTheCustomer()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/8', 'quantity' => 1000,
        ));
        $order   = $res['order'];
        $balance = $app->balance($customer);
        $app->provider_responses['requestCancel'] = array(
            'ok' => false, 'error' => 'Order already in progress', 'retryable' => false,
        );

        $out = $app->orderservice->cancel($order, $customer);

        $this->assertFalse($out['ok']);
        $this->assertSame('PROVIDER_REFUSED', $out['code']);
        $this->assertStringContainsString('Order already in progress', $out['error']);
        $this->assertSame($balance, $app->balance($customer), 'no refund on an order still running');
        $this->assertNotSame('CANCELED', $app->Order_model->find_by_id($order->id)->status);
    }

    /** Staff can override, knowingly — that is what `force` is for. */
    public function testStaffCanCancelAnywayAndTheCustomerIsRefunded()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/9', 'quantity' => 1000,
        ));
        $order   = $res['order'];
        $balance = $app->balance($customer);
        $app->provider_responses['requestCancel'] = array('ok' => false, 'error' => 'Order already in progress');

        $out = $app->orderservice->cancel($order, null,
            array('source' => 'ADMIN', 'force' => true, 'reason' => 'goodwill'));

        $this->assertTrue($out['ok'], $out['error'] ?? '');
        $this->assertSame('CANCELED', $app->Order_model->find_by_id($order->id)->status);
        $this->assertSame(bcadd($balance, (string)$order->charge, 8), $app->balance($customer));
    }

    /** A cancellation is an event; the inbox and the reseller both hear it. */
    public function testACancellationTellsTheCustomer()
    {
        list($app, $customer) = $this->app();
        $res = $app->orderservice->place($customer, array(
            'service' => 1, 'link' => 'https://insta.test/p/10', 'quantity' => 1000,
        ));

        $out = $app->orderservice->cancel($res['order'], $customer);

        $this->assertTrue($out['ok'], $out['error'] ?? '');
        $this->assertCount(1, $this->notifications($app, 'order.canceled'));
    }

    /* ======================== the shape of the code ====================== */

    /**
     * The admin cancel screens must not go back to apply_status(): that path
     * never speaks to the provider, which is the whole defect.
     */
    public function testAdminCancelPathsGoThroughTheServiceThatAsksTheProvider()
    {
        foreach (array('admin/Orders.php', 'admin/Operations.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringNotContainsString("apply_status(\$order, 'CANCELED'", $src, $rel);
            $this->assertStringContainsString("'source' => 'ADMIN'", $src, $rel);
        }
    }
}

/** A ledger that cannot pay, so the "did we record a refund we never made?" rule can be proved. */
class RefusingLedger
{
    public function refund($wallet_id, $amount, $type, $ref, $idem = null)
    {
        return array('ok' => false, 'error' => 'ledger unavailable');
    }
    public function __call($m, $a) { return array('ok' => false, 'error' => 'ledger unavailable'); }
}
