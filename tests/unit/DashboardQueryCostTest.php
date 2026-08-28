<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The admin dashboard's query cost (module 20).
 *
 * The first screen every staff member opens cost **31 queries**, and about a
 * third of them were the same questions asked twice:
 *
 *  - `order_status_counts()` ran once for the controller and again inside
 *    `platform_overview()` — the same GROUP BY over the largest table, twice
 *    per page load;
 *  - `revenue(1)` and `revenue(30)` each ran a sum and an "unearned" count
 *    over both money tables: eight queries for two nested windows;
 *  - `platform_overview()` and `customers()` each scanned `users` in full for
 *    figures that differ only by a WHERE clause;
 *  - open tickets and unassigned tickets were two counts over one table;
 *  - "orders today" and "orders stuck" were two more scans of `orders`.
 *
 * Nobody notices on a seeded dev database. On a panel with a year of trading
 * it is the difference between a dashboard that opens and one that staff stop
 * opening — and it is paid on every page load by every member of staff.
 *
 * These tests pin **both halves**: the widgets still report the same numbers,
 * and they no longer cost a query each. A performance change that quietly
 * alters a figure is worse than the slow version.
 */
class DashboardQueryCostTest extends TestCase
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
        $user = $app->register('dash', 'dash@x.test');
        $app->library(array('AdminStats'));
        return array($app, $user);
    }

    private function order($app, $user, array $o = array())
    {
        static $n = 0;
        $n++;
        $app->db->insert('orders', array_merge(array(
            'public_id'       => 'ORDQ'.str_pad((string)$n, 22, '0', STR_PAD_LEFT),
            'user_id'         => $user->id,
            'service_id'      => 1,
            'status'          => 'COMPLETED',
            'quantity'        => 100,
            'charge'          => '1000.00000000',
            'rate_at_order'   => '10.00000000',
            'provider_charge' => '800.00000000',
            'refunded_amount' => '0.00000000',
            'currency'        => 'NGN',
            'link'            => 'https://x.test/a',
            'source'          => 'WEB',
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ), $o));
    }

    private function service($app, $user, array $o = array())
    {
        static $n = 0;
        $n++;
        $app->db->insert('service_transactions', array_merge(array(
            'public_id'       => 'STXQ'.str_pad((string)$n, 22, '0', STR_PAD_LEFT),
            'user_id'         => $user->id,
            'service_domain'  => 'VTU',
            'service_type'    => 'AIRTIME',
            'status'          => 'SUCCESSFUL',
            'amount'          => '500.00000000',
            'provider_cost'   => '480.00000000',
            'refunded_amount' => '0.00000000',
            'currency'        => 'NGN',
            'source'          => 'WEB',
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ), $o));
    }

    /** Queries issued while running $fn. */
    private function cost($app, callable $fn)
    {
        $before = count($app->db->queries);
        $fn();
        return count($app->db->queries) - $before;
    }

    /* ======================= the numbers still hold ====================== */

    /**
     * Two nested windows, one pass per table — and the answers are identical
     * to asking for each window on its own.
     */
    public function testBatchedWindowsAgreeWithSingleWindowAnswers()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user, array('charge' => '2000.00000000'));
        $this->order($app, $user, array('charge' => '3000.00000000',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-10 days'))));
        $this->service($app, $user, array('amount' => '500.00000000'));
        $this->service($app, $user, array('status' => 'FAILED', 'amount' => '700.00000000'));

        $batched = $app->adminstats->revenue_windows(array(1, 30));
        $app->adminstats->flush();
        $today = $app->adminstats->revenue(1);
        $app->adminstats->flush();
        $month = $app->adminstats->revenue(30);

        $this->assertSame($today, $batched[1], 'today must not change shape when batched');
        $this->assertSame($month, $batched[30], 'nor the month');

        // And the figures themselves are right: 2000 + 500 today; the 10-day-old
        // 3000 joins in the month; the FAILED 700 earns nothing either way.
        $this->assertSame('2500.00000000', $batched[1]['gross']);
        $this->assertSame('5500.00000000', $batched[30]['gross']);
        $this->assertSame(1, $batched[1]['unearned'], 'the failed sale is reported, not hidden');
    }

    /** The consolidated user pass answers both widgets, exactly as before. */
    public function testTheOverviewAndCustomerWidgetsStillDisagreeCorrectly()
    {
        list($app, $user) = $this->app();
        $app->register('staffer', 'staffer@x.test', 'Str0ng!pass1', 'STAFF');
        $suspended = $app->register('gone', 'gone@x.test');
        $app->db->where('id', $suspended->id)->update('users', array('status' => 'SUSPENDED'));

        $overview  = $app->adminstats->platform_overview();
        $customers = $app->adminstats->customers();

        // seed_minimal() may create its own accounts, so assert the relationship
        // rather than absolute counts: staff are users but never customers.
        $this->assertGreaterThan($customers['total'], $overview['users_total'],
            'a staff account is a user and not a customer');
        $this->assertSame(1, $overview['users_suspended']);
        $this->assertGreaterThanOrEqual(1, $customers['active']);
        $this->assertLessThanOrEqual($customers['total'], $customers['active']);
    }

    /** Orders today and stuck orders ride along on the status GROUP BY. */
    public function testOrderCountsTodayAndStuckAreConsistentWithTheRows()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user);                                   // today, completed
        $this->order($app, $user, array('status' => 'PENDING'));     // today, pending
        $this->order($app, $user, array('status' => 'PROCESSING',    // three days stuck
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-3 days'))));

        $overview = $app->adminstats->platform_overview();
        $queue    = $app->adminstats->action_queue();
        $counts   = $app->adminstats->order_status_counts();

        $this->assertSame(3, array_sum($counts));
        $this->assertSame(2, $overview['orders_today'], 'the three-day-old order is not from today');
        $this->assertSame(1, $overview['orders_pending']);
        $this->assertSame(1, $queue['stuck_orders'],
            'PROCESSING for three days is a customer who paid and is waiting');
    }

    /** Open and unassigned tickets come from one scan and still differ. */
    public function testTicketQueueSeparatesUnassignedFromOpen()
    {
        list($app, $user) = $this->app();
        $now = gmdate('Y-m-d H:i:s');
        foreach (array(
            array('OPEN', null), array('OPEN', 1), array('PENDING', null), array('CLOSED', null),
        ) as $i => $t) {
            $app->db->insert('tickets', array(
                'public_id' => 'TKTQ'.str_pad((string)$i, 22, '0', STR_PAD_LEFT),
                'user_id' => $user->id, 'subject' => 'q'.$i,
                'status' => $t[0], 'priority' => 'MEDIUM', 'assigned_to_id' => $t[1],
                'created_at' => $now, 'updated_at' => $now,
            ));
        }

        $queue = $app->adminstats->action_queue();
        $this->assertSame(3, $queue['tickets'], 'CLOSED is not in the queue');
        $this->assertSame(2, $queue['unassigned_tickets'], 'one of the three has an owner');
    }

    /* ============================ the cost =============================== */

    /**
     * The whole dashboard widget set, measured.
     *
     * This is the regression guard: the number is asserted, so reintroducing
     * a per-widget query fails here rather than on a busy panel months later.
     * Counted against FakeDb, which records every query the code issues.
     */
    public function testTheWholeWidgetSetIsBounded()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user);
        $this->service($app, $user);

        $stats = $app->adminstats;
        $cost = $this->cost($app, function () use ($stats) {
            $revenue = $stats->revenue_windows(array(1, 30));
            $stats->platform_overview();
            $stats->revenue_series(14);
            $stats->action_queue();
            $stats->customers();
            $stats->provider_health();
            $stats->order_status_counts();
            $stats->revenue_by_domain(30);
        });

        // orders+services windows 2, orders group-by 1, wallets 1, payouts 1,
        // users 1, series 2, deposits 1, stuck services 1, tickets 1,
        // providers 1, by-domain 2 = 14. The bound leaves a little room and
        // still fails loudly if a widget starts querying per figure again.
        $this->assertLessThanOrEqual(16, $cost,
            'the dashboard widget set cost '.$cost.' queries; it used to be 25 and must not go back');
    }

    /** Asking the same question twice in one request must cost once. */
    public function testRepeatedWidgetsAreMemoisedWithinTheRequest()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user);

        $stats = $app->adminstats;
        $first  = $this->cost($app, function () use ($stats) { $stats->order_status_counts(); });
        $second = $this->cost($app, function () use ($stats) { $stats->order_status_counts(); });

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second,
            'the controller and platform_overview() both ask for this; it must only be read once');

        $again = $this->cost($app, function () use ($stats) { $stats->platform_overview(); });
        $this->assertLessThan($first + 4, $again,
            'platform_overview() must reuse the status counts rather than re-running them');
    }

    /**
     * A long-running CLI process can outlive its own answers, so the memo has
     * to be droppable. Without this a cron worker would report the figures it
     * saw when it booted, for ever.
     */
    public function testTheMemoCanBeDropped()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user);
        $stats = $app->adminstats;

        $stats->order_status_counts();
        $this->order($app, $user, array('status' => 'PENDING'));
        $this->assertSame(1, array_sum($stats->order_status_counts()), 'still the memoised answer');

        $stats->flush();
        $this->assertSame(2, array_sum($stats->order_status_counts()), 'and fresh after a flush');
    }

    /** The perf budget in the e2e checker has to move with the code. */
    public function testThePerfCheckerBudgetsTheDashboard()
    {
        $src = file_get_contents(self::$root.'/tools/devserver/perf_check.mjs');
        $this->assertMatchesRegularExpression(
            "~'/admin',\s*\n?\s*22\]~", $src,
            'perf_check must hold the dashboard to its measured cost');
    }
}
