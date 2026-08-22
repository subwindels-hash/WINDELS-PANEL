<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Cross-domain analytics and unified history (Session 28, rebuild-spec phase G).
 *
 * Phase G is the one phase with no new domain in it. Its whole job is to make
 * the six that already exist visible in one place, and the reason it is worth
 * a test file of its own is the bug it opens with:
 *
 *   Between sessions 21 and 27, `AdminStats::revenue()` read only the `orders`
 *   table. Every VTU purchase, virtual number, identity check and gift card
 *   sold in that window was reported as zero revenue on the admin landing
 *   page. Nothing failed, nothing logged, and the headline number on the first
 *   screen an operator sees was simply wrong.
 *
 * That is the failure mode this file exists to prevent recurring, so most of
 * what follows is not "does the arithmetic work" but "does the total actually
 * include every table money can land in". `testRevenueCountsEveryDomain` is
 * the one that would have caught it, and the source-level gate at the bottom
 * asserts the same thing structurally, so a future domain added without
 * touching AdminStats fails loudly rather than quietly under-reporting.
 */
class AnalyticsTest extends TestCase
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
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    /** A world with one customer and the analytics libraries loaded. */
    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $user = $app->register('an_user', 'an@x.test');
        $app->library(array('AdminStats', 'ActivityFeed'));
        $app->model(array('Service_transaction_model', 'Order_model'));
        return array($app, $user);
    }

    /**
     * One service_transactions row.
     *
     * Written directly rather than through a domain service: this file is
     * about the reporting layer, and driving six real services would make a
     * reporting bug look like a domain bug.
     */
    private function service($app, $user, array $o = array())
    {
        static $n = 0;
        $n++;
        $app->db->insert('service_transactions', array_merge(array(
            'public_id'       => 'STX'.str_pad((string)$n, 23, '0', STR_PAD_LEFT),
            'user_id'         => $user->id,
            'service_domain'  => 'VTU',
            'service_type'    => 'AIRTIME',
            'status'          => 'SUCCESSFUL',
            'amount'          => '1000.00000000',
            'provider_cost'   => '950.00000000',
            'refunded_amount' => '0.00000000',
            'currency'        => 'NGN',
            'source'          => 'WEB',
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ), $o));
        return $app->db->insert_id();
    }

    /** One SMM order. */
    private function order($app, $user, array $o = array())
    {
        static $n = 0;
        $n++;
        $app->db->insert('orders', array_merge(array(
            'public_id'       => 'ORD'.str_pad((string)$n, 23, '0', STR_PAD_LEFT),
            'user_id'         => $user->id,
            'service_id'      => 1,
            'status'          => 'COMPLETED',
            'quantity'        => 1000,
            'charge'          => '2000.00000000',
            'rate_at_order'   => '2.00000000',
            'provider_charge' => '1500.00000000',
            'refunded_amount' => '0.00000000',
            'currency'        => 'NGN',
            'link'            => 'https://x.test/a',
            'source'          => 'WEB',
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ), $o));
        return $app->db->insert_id();
    }

    /* ================== the bug this phase exists to fix ================= */

    /**
     * The regression test for the whole session. An SMM order and one sale in
     * each service domain: the headline revenue figure must include all six.
     */
    public function testRevenueCountsEveryDomain()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user, array('charge' => '2000.00000000'));
        $this->service($app, $user, array('service_domain' => 'VTU',      'amount' => '1000.00000000'));
        $this->service($app, $user, array('service_domain' => 'NUMBER',   'amount' => '300.00000000'));
        $this->service($app, $user, array('service_domain' => 'IDENTITY', 'amount' => '250.00000000'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'amount' => '42000.00000000'));

        $revenue = $app->adminstats->revenue(1);

        $this->assertSame(5, $revenue['orders'], 'every sale counts, whichever table it is in');
        $this->assertSame(1, $revenue['smm']);
        $this->assertSame(4, $revenue['services']);
        $this->assertSame('45550.00000000', $revenue['gross'],
            'a revenue figure that reads only `orders` reports 2000 here and is wrong by 43550');
        $this->assertSame('45550.00000000', $revenue['net']);
    }

    public function testRefundsAreSubtractedFromBothTables()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user,   array('charge' => '2000.00000000', 'refunded_amount' => '500.00000000'));
        $this->service($app, $user, array('amount' => '1000.00000000', 'refunded_amount' => '1000.00000000'));

        $revenue = $app->adminstats->revenue(1);

        $this->assertSame('3000.00000000', $revenue['gross']);
        $this->assertSame('1500.00000000', $revenue['refunded']);
        $this->assertSame('1500.00000000', $revenue['net']);
    }

    public function testRevenueIsWindowedByTheRequestedRange()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('amount' => '1000.00000000'));
        $this->service($app, $user, array('amount' => '9999.00000000',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-10 days'))));

        $this->assertSame('1000.00000000', $app->adminstats->revenue(1)['gross']);
        $this->assertSame('10999.00000000', $app->adminstats->revenue(30)['gross']);
    }

    public function testRevenueIsZeroRatherThanEmptyOnAQuietDay()
    {
        list($app,) = $this->app();

        $revenue = $app->adminstats->revenue(1);

        // A caller reading ?? on a missing row would hide a broken query; the
        // aggregate has to answer 0, as SQL does.
        $this->assertSame(0, $revenue['orders']);
        $this->assertSame('0.00000000', $revenue['gross']);
        $this->assertSame('0.00000000', $revenue['net']);
    }

    /* ======================== the domain breakdown ======================= */

    public function testTheBreakdownSeparatesDomainsAndComputesMargin()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user, array('charge' => '2000.00000000', 'provider_charge' => '1500.00000000'));
        $this->service($app, $user, array('service_domain' => 'VTU',
            'amount' => '1000.00000000', 'provider_cost' => '950.00000000'));
        $this->service($app, $user, array('service_domain' => 'VTU',
            'amount' => '1000.00000000', 'provider_cost' => '950.00000000'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD',
            'amount' => '42000.00000000', 'provider_cost' => '38000.00000000'));

        $by = $app->adminstats->revenue_by_domain(30);

        $this->assertSame(2, $by['VTU']['sales']);
        $this->assertSame('2000.00000000', $by['VTU']['net']);
        $this->assertSame('100.00000000', $by['VTU']['margin']);
        $this->assertSame('4000.00000000', $by['GIFTCARD']['margin']);
        $this->assertSame('500.00000000', $by['SMM']['margin']);
    }

    /**
     * Some vendors bill a prepaid wallet rather than per sale, and a foreign
     * cost that could not be converted is stored NULL rather than guessed. A
     * margin of zero and an unknown margin must not render identically — the
     * first says "we made nothing", the second says "we cannot tell".
     */
    public function testAnUncostedDomainReportsNullMarginNotZero()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'IDENTITY',
            'amount' => '250.00000000', 'provider_cost' => null));

        $by = $app->adminstats->revenue_by_domain(30);

        $this->assertSame(0, $by['IDENTITY']['costed']);
        $this->assertNull($by['IDENTITY']['margin']);
        $this->assertSame('250.00000000', $by['IDENTITY']['net']);
    }

    public function testTheCostedCountExposesAPartiallyCostedDomain()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'NUMBER',
            'amount' => '300.00000000', 'provider_cost' => '200.00000000'));
        $this->service($app, $user, array('service_domain' => 'NUMBER',
            'amount' => '300.00000000', 'provider_cost' => null));

        $by = $app->adminstats->revenue_by_domain(30);

        // The margin is real but derived from half the sales, and the view
        // shows that denominator so nobody reads it as the whole picture.
        $this->assertSame(2, $by['NUMBER']['sales']);
        $this->assertSame(1, $by['NUMBER']['costed']);
        $this->assertSame('400.00000000', $by['NUMBER']['margin']);
    }

    public function testTheBreakdownLeadsWithTheBiggestEarner()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'VTU',      'amount' => '1000.00000000'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'amount' => '42000.00000000'));
        $this->service($app, $user, array('service_domain' => 'IDENTITY', 'amount' => '250.00000000'));

        $order = array_keys($app->adminstats->revenue_by_domain(30));

        $this->assertSame(array('GIFTCARD', 'VTU', 'IDENTITY'), $order);
    }

    public function testADomainWithNoSalesInTheWindowIsAbsentRatherThanZero()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'VTU'));

        $by = $app->adminstats->revenue_by_domain(30);

        $this->assertArrayHasKey('VTU', $by);
        $this->assertArrayNotHasKey('GIFTCARD', $by);
        $this->assertArrayNotHasKey('SMM', $by, 'no SMM row when no SMM order was placed');
    }

    /* ========================= delivery health ========================== */

    public function testHealthCountsInFlightAndSettledSeparately()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'status' => 'SUCCESSFUL'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'status' => 'SUCCESSFUL'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'status' => 'FAILED'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'status' => 'PROCESSING'));

        $health = $app->adminstats->domain_health();

        $this->assertSame(4, $health['GIFTCARD']['total']);
        $this->assertSame(1, $health['GIFTCARD']['in_flight']);
        $this->assertSame(2, $health['GIFTCARD']['successful']);
        $this->assertSame(1, $health['GIFTCARD']['failed']);
        // 2 of 3 settled — the in-flight one is excluded, or every busy minute
        // would read as a partial outage.
        $this->assertSame(66.7, $health['GIFTCARD']['success_rate']);
    }

    public function testSuccessRateIsUnknownRatherThanZeroBeforeAnythingSettles()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'NUMBER', 'status' => 'PROCESSING'));

        $health = $app->adminstats->domain_health();

        $this->assertNull($health['NUMBER']['success_rate'],
            '0% and "nothing has settled yet" are different things');
    }

    public function testStuckCountsOnlyPurchasesPastTheGraceWindow()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'VTU', 'status' => 'PROCESSING'));
        $this->service($app, $user, array('service_domain' => 'VTU', 'status' => 'PROCESSING',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 hours'))));

        $health = $app->adminstats->domain_health(30);

        $this->assertSame(2, $health['VTU']['in_flight']);
        $this->assertSame(1, $health['VTU']['stuck'],
            'a purchase made seconds ago is in flight, not stuck');
    }

    public function testTheActionQueueSurfacesStuckServicePurchases()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('status' => 'PROCESSING',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 hours'))));

        $queue = $app->adminstats->action_queue();

        // The SMM window is 24h; these domains settle in seconds, so an hour
        // is already an emergency.
        $this->assertSame(1, $queue['stuck_services']);
        $this->assertSame(0, $queue['stuck_orders']);
    }

    /* ======================== vendor reliability ======================== */

    public function testProviderPerformanceReadsTheCallLog()
    {
        list($app, $user) = $this->app();
        $tx = $this->service($app, $user);
        foreach (array(array('SUCCESS', 100), array('SUCCESS', 300), array('FAILED', 200)) as $i => $call) {
            $app->db->insert('provider_transactions', array(
                'provider_id' => 1, 'service_transaction_id' => $tx,
                'action' => 'PURCHASE', 'status' => $call[0], 'latency_ms' => $call[1],
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        }

        $rows = $app->adminstats->provider_performance(7);

        $this->assertCount(1, $rows);
        $this->assertSame('Acme SMM', $rows[0]['provider']);
        $this->assertSame(3, $rows[0]['calls']);
        $this->assertSame(2, $rows[0]['ok']);
        $this->assertSame(1, $rows[0]['failed']);
        $this->assertSame(66.7, $rows[0]['success_rate']);
        $this->assertSame(200, $rows[0]['avg_latency']);
    }

    public function testProviderPerformanceLeadsWithTheWorstVendor()
    {
        list($app, $user) = $this->app();
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('providers', array(
            'public_id' => 'PROV0000000000000000000099', 'name' => 'Flaky Vendor',
            'api_url' => 'https://flaky.test', 'api_key_encrypted' => 'enc:x',
            'api_type' => 'MOCK', 'status' => 'ACTIVE', 'currency' => 'NGN',
            'created_at' => $now, 'updated_at' => $now,
        ));
        $flaky = $app->db->insert_id();

        foreach (array(1, 1) as $_) {
            $app->db->insert('provider_transactions', array('provider_id' => 1,
                'action' => 'PURCHASE', 'status' => 'SUCCESS', 'latency_ms' => 50,
                'created_at' => $now));
        }
        $app->db->insert('provider_transactions', array('provider_id' => $flaky,
            'action' => 'PURCHASE', 'status' => 'FAILED', 'latency_ms' => 9000,
            'created_at' => $now));

        $rows = $app->adminstats->provider_performance(7);

        $this->assertSame('Flaky Vendor', $rows[0]['provider'],
            'this table exists to surface the vendor that is failing');
        $this->assertSame(0.0, $rows[0]['success_rate']);
    }

    /* =========================== revenue series ========================== */

    public function testTheSeriesCoversEveryDayIncludingEmptyOnes()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('amount' => '1000.00000000'));

        $series = $app->adminstats->revenue_series(14);

        $this->assertCount(14, $series);
        $this->assertSame(gmdate('Y-m-d'), array_key_last($series),
            'today is the right-hand end of the chart');
        $today = $series[gmdate('Y-m-d')];
        $this->assertSame('1000.00000000', $today['net']);
        $this->assertSame(1, $today['sales']);
        // A gap in the middle must render as a zero bar, not vanish and shift
        // every later day one place to the left.
        $yesterday = $series[gmdate('Y-m-d', strtotime('-1 day'))];
        $this->assertSame('0.00000000', $yesterday['net']);
    }

    public function testTheSeriesNetsRefundsAndSpansBothTables()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user,   array('charge' => '2000.00000000', 'refunded_amount' => '500.00000000'));
        $this->service($app, $user, array('amount' => '1000.00000000'));

        $today = $app->adminstats->revenue_series(7)[gmdate('Y-m-d')];

        $this->assertSame('2500.00000000', $today['net']);
        $this->assertSame(2, $today['sales']);
    }

    /* ==================== unified history (§20) ========================= */

    public function testTheFeedMergesEveryDomainNewestFirst()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user,   array('created_at' => gmdate('Y-m-d H:i:s', strtotime('-3 hours'))));
        $this->service($app, $user, array('service_domain' => 'VTU',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 hours'))));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour'))));

        $feed = $app->activityfeed->for_user($user->id);

        $this->assertSame(3, $feed['total']);
        $this->assertSame(array('GIFTCARD', 'VTU', 'SMM'),
            array_map(function ($r) { return $r['domain']; }, $feed['rows']));
    }

    public function testEveryFeedRowHasTheSameShapeWhicheverTableItCameFrom()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user);
        $this->service($app, $user);

        foreach ($app->activityfeed->for_user($user->id)['rows'] as $row) {
            foreach (array('domain','public_id','label','status','amount',
                           'refunded','currency','created_at','url') as $key) {
                $this->assertArrayHasKey($key, $row,
                    'a caller must never need to know which table a row came from');
            }
        }
    }

    public function testTheFeedFiltersByDomain()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user);
        $this->service($app, $user, array('service_domain' => 'VTU'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD'));

        $vtu = $app->activityfeed->for_user($user->id, array('domain' => 'VTU'));
        $smm = $app->activityfeed->for_user($user->id, array('domain' => 'SMM'));

        $this->assertSame(1, $vtu['total']);
        $this->assertSame('VTU', $vtu['rows'][0]['domain']);
        $this->assertSame(1, $smm['total']);
        $this->assertSame('SMM', $smm['rows'][0]['domain']);
    }

    public function testTheFeedFiltersByStatusAcrossTables()
    {
        list($app, $user) = $this->app();
        $this->order($app, $user,   array('status' => 'COMPLETED'));
        $this->service($app, $user, array('status' => 'FAILED'));
        $this->service($app, $user, array('status' => 'SUCCESSFUL'));

        $failed = $app->activityfeed->for_user($user->id, array('status' => 'FAILED'));

        $this->assertSame(1, $failed['total']);
        $this->assertSame('FAILED', $failed['rows'][0]['status']);
    }

    public function testTheFeedNeverShowsAnotherCustomersPurchases()
    {
        list($app, $user) = $this->app();
        $other = $app->register('other', 'other@x.test');
        $this->service($app, $other, array('amount' => '9999.00000000'));
        $this->order($app, $other);
        $this->service($app, $user, array('amount' => '1000.00000000'));

        $feed = $app->activityfeed->for_user($user->id);

        $this->assertSame(1, $feed['total']);
        $this->assertSame('1000.00000000', $feed['rows'][0]['amount']);
    }

    public function testTheFeedPaginatesAcrossTheMergedList()
    {
        list($app, $user) = $this->app();
        for ($i = 0; $i < 5; $i++) {
            $this->service($app, $user, array(
                'created_at' => gmdate('Y-m-d H:i:s', strtotime('-'.($i + 10).' minutes'))));
        }
        for ($i = 0; $i < 3; $i++) {
            $this->order($app, $user, array(
                'created_at' => gmdate('Y-m-d H:i:s', strtotime('-'.($i + 1).' minutes'))));
        }

        $page1 = $app->activityfeed->for_user($user->id, array(), 5, 0);
        $page2 = $app->activityfeed->for_user($user->id, array(), 5, 5);

        $this->assertSame(8, $page1['total']);
        $this->assertCount(5, $page1['rows']);
        $this->assertCount(3, $page2['rows']);
        // No row may appear on both pages: a merged feed with an unstable sort
        // is how a customer sees the same purchase twice and one not at all.
        $ids = array_merge(
            array_map(function ($r) { return $r['public_id']; }, $page1['rows']),
            array_map(function ($r) { return $r['public_id']; }, $page2['rows']));
        $this->assertCount(8, array_unique($ids));
    }

    public function testTheFeedLabelsEachDomainInWordsTheCustomerRecognises()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'IDENTITY', 'service_type' => 'NIN'));
        $this->service($app, $user, array('service_domain' => 'GIFTCARD', 'service_type' => 'PURCHASE'));
        $this->service($app, $user, array('service_domain' => 'NUMBER',   'service_type' => 'RENTAL'));

        $labels = array();
        foreach ($app->activityfeed->for_user($user->id)['rows'] as $r) {
            $labels[$r['domain']] = $r['label'];
        }

        $this->assertSame('NIN check', $labels['IDENTITY']);
        $this->assertSame('Gift card', $labels['GIFTCARD']);
        $this->assertSame('Virtual number', $labels['NUMBER']);
    }

    public function testEachFeedRowLinksToItsOwnDomainPage()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'GIFTCARD'));

        $row = $app->activityfeed->for_user($user->id)['rows'][0];

        $this->assertSame('dashboard/giftcards/'.$row['public_id'], $row['url']);
    }

    /* ------------------------- the admin feed --------------------------- */

    public function testTheAdminFeedSpansCustomersAndNamesThem()
    {
        list($app, $user) = $this->app();
        $other = $app->register('second', 'second@x.test');
        $this->service($app, $user,  array('service_domain' => 'VTU'));
        $this->service($app, $other, array('service_domain' => 'GIFTCARD'));

        $rows = $app->activityfeed->recent(array('*'), 10);

        $this->assertCount(2, $rows);
        $names = array_map(function ($r) { return $r['username']; }, $rows);
        $this->assertContains('an_user', $names);
        $this->assertContains('second', $names);
    }

    /**
     * A staff member without `giftcards.view` still needs to see that gift
     * cards are selling — hiding the row would under-report the business to
     * whoever happens to be logged in. What they lose is the link, not the fact.
     */
    public function testTheAdminFeedShowsEveryRowButOnlyLinksPermittedOnes()
    {
        list($app, $user) = $this->app();
        $this->service($app, $user, array('service_domain' => 'GIFTCARD'));
        $this->service($app, $user, array('service_domain' => 'VTU'));

        $rows = $app->activityfeed->recent(array('vtu.view'), 10);

        $this->assertCount(2, $rows);
        $by = array();
        foreach ($rows as $r) $by[$r['domain']] = $r;
        $this->assertNotNull($by['VTU']['url']);
        $this->assertNull($by['GIFTCARD']['url'],
            'no dead link to a screen this operator would be refused');
    }

    public function testTheAdminFeedIsBoundedByItsLimit()
    {
        list($app, $user) = $this->app();
        for ($i = 0; $i < 12; $i++) $this->service($app, $user);

        $this->assertCount(4, $app->activityfeed->recent(array('*'), 4));
    }

    /* ========================= wiring and gates ========================== */

    public function testTheAnalyticsScreenExistsAndIsPermissionGated()
    {
        $ctrl = self::$root.'/application/controllers/admin/Analytics.php';
        $this->assertFileExists($ctrl);
        $this->assertFileExists(self::$root.'/application/views/admin/analytics/index.php');

        $src = file_get_contents($ctrl);
        $this->assertStringContainsString("require_perm('reports.view')", $src);
    }

    /**
     * Analytics is a reporting screen. It must have no way to change anything
     * — an operator should be able to hand it to someone who may read the
     * numbers and touch nothing.
     */
    public function testTheAnalyticsScreenCannotWriteAnything()
    {
        foreach (array('application/controllers/admin/Analytics.php',
                       'application/libraries/AdminStats.php',
                       'application/libraries/ActivityFeed.php') as $file) {
            $src = file_get_contents(self::$root.'/'.$file);
            foreach (array('->insert(', '->update(', '->delete(', 'transition(',
                           'ledgerservice->') as $write) {
                $this->assertStringNotContainsString($write, $src,
                    basename($file).' must be read-only, found '.$write);
            }
        }
    }

    /**
     * The structural half of the opening regression test.
     *
     * Any figure claiming to be revenue has to read both money tables. A new
     * domain added later without touching AdminStats would still be counted,
     * because every service domain lands in `service_transactions` — but a
     * *refactor* that dropped one of these two reads would silently halve the
     * panel's headline number, exactly as the original bug did.
     */
    public function testEveryRevenueFigureReadsBothMoneyTables()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AdminStats.php');

        foreach (array('revenue', 'revenue_by_domain', 'revenue_series') as $method) {
            $this->assertMatchesRegularExpression(
                '~function '.$method.'\(.*?\n    \}~s', $src, $method.'() must exist');
        }
        preg_match('~function revenue\(.*?\n    \}~s', $src, $m);
        $this->assertStringContainsString("'orders'", $m[0]);
        $this->assertStringContainsString("'service_transactions'", $m[0],
            'a revenue total that reads only `orders` reports every service domain as zero');

        preg_match('~function revenue_series\(.*?\n    \}~s', $src, $s);
        $this->assertStringContainsString("'orders'", $s[0]);
        $this->assertStringContainsString("'service_transactions'", $s[0]);
    }

    public function testTheUnifiedHistoryScreenExists()
    {
        $ctrl = self::$root.'/application/controllers/dashboard/History.php';
        $this->assertFileExists($ctrl);
        $this->assertFileExists(self::$root.'/application/views/dashboard/history/index.php');

        $src = file_get_contents($ctrl);
        $this->assertStringContainsString('ActivityFeed', $src);
        $this->assertStringContainsString('PER_PAGE', $src,
            'a merged feed across every domain must be paginated');
    }

    /**
     * The history page reads; every action stays on the domain page that owns
     * the rules for it. One place can cancel a number, and it is the one that
     * knows a number with a code cannot be cancelled.
     */
    public function testTheHistoryScreenHasNoActions()
    {
        $src = file_get_contents(self::$root.'/application/views/dashboard/history/index.php');
        $this->assertStringNotContainsString('<form method="post"', $src);
        $ctrl = file_get_contents(self::$root.'/application/controllers/dashboard/History.php');
        $this->assertStringNotContainsString("method() !== 'post'", $ctrl,
            'there is nothing here to POST to');
    }

    public function testTheRoutesAndNavigationAreRegistered()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("\$route['admin/analytics']", $routes);
        $this->assertStringContainsString("\$route['dashboard/history']", $routes);

        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString('admin/analytics', $layout);
        $this->assertStringContainsString('dashboard/history', $layout);

        // Icons must exist or the nav renders an empty box.
        $icons = file_get_contents(self::$root.'/application/views/partials/icon.php');
        $this->assertStringContainsString("'chart'", $icons);
        $this->assertStringContainsString("'list'", $icons);
    }

    public function testTheAdminOverviewShowsEveryDomainNotJustSmm()
    {
        $ctrl = file_get_contents(self::$root.'/application/controllers/admin/Dashboard.php');
        $this->assertStringContainsString('activityfeed->recent', $ctrl,
            'the overview fed itself only SMM orders until phase G');
        $this->assertStringContainsString('revenue_by_domain', $ctrl);

        $view = file_get_contents(self::$root.'/application/views/admin/dashboard.php');
        $this->assertStringContainsString('stuck_services', $view);
    }
}
