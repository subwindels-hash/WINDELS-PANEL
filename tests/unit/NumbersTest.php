<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Virtual numbers + OTP (Session 25, rebuild-spec phase D).
 *
 * The audit singled this domain out as the first one the order engine does not
 * already model, because a reservation is not a delivery: the customer pays
 * up front for a number that is only worth anything if a code arrives before
 * a deadline the *vendor* sets. Almost every test here is about that gap.
 *
 * The behavioural half runs the real models, NumberService, TransactionEngine
 * and LedgerService against the migration-derived schema, because the money
 * assertions are the point — a reservation that expires without refunding
 * looks identical from the UI to one that refunds correctly. The 5sim half is
 * fixture-driven: no network, no credentials, but the exact bytes the vendor
 * sends, including its plain-text errors. The source-level half pins the same
 * admin-surface guarantees AdminVtuTest pins for VTU.
 */
class NumbersTest extends TestCase
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
        require_once self::$root.'/application/libraries/FiveSimAdapter.php';
        require_once self::$root.'/application/libraries/MockNumberAdapter.php';
    }

    protected function setUp(): void
    {
        // The mock adapter keeps reservation state statically so a poll can
        // evolve across calls; each test starts from an empty vendor.
        MockNumberAdapter::reset();
    }

    /** A world with a customer who can afford to rent numbers. */
    private function app($balance = '10000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_numbers();
        $user = $app->register('num_user', 'num@x.test');
        $app->credit($user, $balance);
        $app->library('NumberService');
        $app->model(array('Virtual_number_model', 'Otp_message_model', 'Service_transaction_model'));
        return array($app, $user);
    }

    /** Rent a number and poll it until the mock delivers its code. */
    private function rent_and_receive($app, $user, $service = 'WHATSAPP')
    {
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => $service));
        $number = $res['number'];
        // The mock answers on the second poll on purpose — a vendor that
        // returns the code instantly would let code that never polls pass.
        $app->numberservice->poll($number, 'CRON');
        $number = $app->Virtual_number_model->find_by_id($number->id);
        $app->numberservice->poll($number, 'CRON');
        return array($res['transaction'], $app->Virtual_number_model->find_by_id($number->id));
    }

    /* ========================= the reservation ========================== */

    /**
     * A reservation is charged immediately but is NOT a completed sale. If it
     * settled as SUCCESSFUL on reserve, an expiry would have to claw money
     * back out of a closed transaction instead of refunding an open one.
     */
    public function testReservingChargesTheWalletAndLeavesThePurchaseInFlight()
    {
        list($app, $user) = $this->app();

        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('PROCESSING', $res['transaction']->status,
            'a rented number is not a delivered service until a code arrives');
        $this->assertSame('NUMBER', $res['transaction']->service_domain);
        $this->assertSame('9550.00000000', $app->balance($user));
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testTheReservationRecordsTheNumberAndItsDeadline()
    {
        list($app, $user) = $this->app();

        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));
        $number = $res['number'];

        $this->assertNotEmpty($number->msisdn);
        $this->assertSame('RESERVED', $number->status);
        $this->assertNotEmpty($number->provider_order_id,
            'without a vendor reference nothing can ever poll this reservation');
        $this->assertNotEmpty($number->expires_at,
            'a reservation with no deadline can never be expired or refunded');
        $this->assertGreaterThan(time(), strtotime($number->expires_at.' UTC'));
    }

    /** The domain row is written before the vendor call, like VTU's. */
    public function testAVendorRejectionRefundsInFullAndStillRecordsTheAttempt()
    {
        list($app, $user) = $this->app();
        // NOSTOCK is refused by the mock adapter; give it stock so the request
        // gets past our own pre-check and reaches the vendor.
        $app->db->where('code', 'NG-NOSTOCK')->update('number_products', array('stock' => 50));

        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'NOSTOCK'));

        $this->assertFalse($res['ok']);
        $this->assertSame('10000.00000000', $app->balance($user),
            'a rejected reservation must leave the customer whole');
        $tx = $app->rows('service_transactions')[0];
        $this->assertSame('FAILED', $tx['status']);
        $this->assertSame('400.00000000', $tx['refunded_amount']);
        $this->assertCount(1, $app->rows('virtual_numbers'),
            'the attempt is still recorded, so support can see what was tried');
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /** Known-zero stock is refused before anyone is charged. */
    public function testAnOutOfStockNumberIsRefusedBeforeCharging()
    {
        list($app, $user) = $this->app();

        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'NOSTOCK'));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_STOCK', $res['code']);
        $this->assertSame('10000.00000000', $app->balance($user));
        $this->assertSame(array(), $app->rows('service_transactions'),
            'nothing should be recorded for a request that never reached the vendor');
    }

    public function testAnUnpricedProductCannotBeRented()
    {
        list($app, $user) = $this->app();
        $app->db->where('code', 'NG-WHATSAPP')->update('number_products', array('price' => null));

        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRICE', $res['code'],
            'a synced-but-unpriced product must never be buyable for nothing');
    }

    public function testADoubleClickedRentButtonReservesOnce()
    {
        list($app, $user) = $this->app();
        $input = array('country' => 'NG', 'service' => 'WHATSAPP', 'idempotency_key' => 'num:1:abc');

        $first  = $app->numberservice->reserve($user, $input);
        $second = $app->numberservice->reserve($user, $input);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['duplicate']));
        $this->assertSame($first['transaction']->id, $second['transaction']->id);
        $this->assertSame('9550.00000000', $app->balance($user), 'charged once, not twice');
    }

    /* ============================ receiving ============================= */

    public function testACodeSettlesThePurchaseAndIsStoredAgainstTheNumber()
    {
        list($app, $user) = $this->app();

        list($tx, $number) = $this->rent_and_receive($app, $user);

        $this->assertSame('RECEIVED', $number->status);
        $this->assertSame(1, (int)$number->sms_count);
        $this->assertSame('471925', $number->last_code);
        $this->assertSame('SUCCESSFUL', $app->rows('service_transactions')[0]['status'],
            'the money is earned the moment the customer has a usable code');
        $this->assertSame('9550.00000000', $app->balance($user),
            'settling a delivered reservation must not move money again');
    }

    /**
     * A vendor returns its whole inbox on every poll. Without the uniqueness
     * guard the customer's one code would appear once per poll, and they
     * could not tell which was current.
     */
    public function testPollingRepeatedlyDoesNotDuplicateTheCode()
    {
        list($app, $user) = $this->app();
        list($tx, $number) = $this->rent_and_receive($app, $user);
        $this->assertCount(1, $app->rows('otp_messages'));

        $again = $app->numberservice->poll($number, 'CRON');

        $this->assertTrue($again['ok']);
        $this->assertSame(0, $again['new_messages']);
        $this->assertCount(1, $app->rows('otp_messages'));
    }

    public function testTheCodeIsReadableFromTheOtpLog()
    {
        list($app, $user) = $this->app();
        list($tx, $number) = $this->rent_and_receive($app, $user);

        $messages = $app->Otp_message_model->for_number($number->id);

        $this->assertCount(1, $messages);
        $this->assertSame('471925', $messages[0]->code);
        $this->assertStringContainsString('471925', $messages[0]->body);
        $this->assertNotEmpty($messages[0]->received_at);
    }

    /* ============================== expiry ============================== */

    /**
     * The core of phase D. The customer paid, the deadline passed, no code
     * arrived: they get their money back without anyone asking.
     */
    public function testADeadlineThatPassesWithoutACodeRefundsTheCustomer()
    {
        list($app, $user) = $this->app();
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'SLOW'));
        $number = $res['number'];
        $this->assertSame('9650.00000000', $app->balance($user));

        $app->db->where('id', $number->id)->update('virtual_numbers',
            array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)));
        $number = $app->Virtual_number_model->find_by_id($number->id);

        $out = $app->numberservice->expire($number, 'CRON');

        $this->assertTrue($out['ok']);
        $this->assertSame('350.00000000', $out['refunded']);
        $this->assertSame('10000.00000000', $app->balance($user));
        $this->assertSame('EXPIRED', $app->rows('virtual_numbers')[0]['status']);
        $this->assertSame('FAILED', $app->rows('service_transactions')[0]['status']);
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /**
     * A number that received a code is not expirable — the service was
     * rendered. Otherwise a customer could take the code, wait out the clock
     * and be refunded for it.
     */
    public function testANumberThatReceivedACodeIsNeverExpired()
    {
        list($app, $user) = $this->app();
        list($tx, $number) = $this->rent_and_receive($app, $user);
        $app->db->where('id', $number->id)->update('virtual_numbers',
            array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)));
        $number = $app->Virtual_number_model->find_by_id($number->id);

        $out = $app->numberservice->expire($number, 'CRON');

        $this->assertFalse($out['ok']);
        $this->assertSame('HAS_CODE', $out['code']);
        $this->assertSame('9550.00000000', $app->balance($user));
    }

    /**
     * A code that lands in the last seconds still counts: the worker polls
     * before it expires anything, so the customer keeps a number that worked.
     */
    public function testACodeArrivingJustBeforeTheDeadlineStillSettlesTheSale()
    {
        list($app, $user) = $this->app();
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));
        $number = $res['number'];
        // First poll: nothing yet. Then the deadline lapses, and the code is
        // waiting on the next check.
        $app->numberservice->poll($number, 'CRON');
        $app->db->where('id', $number->id)->update('virtual_numbers',
            array('expires_at' => gmdate('Y-m-d H:i:s', time() - 5)));

        $app->library('CronWorkers');
        $app->cronworkers->numbers_status();

        $this->assertSame('SUCCESSFUL', $app->rows('service_transactions')[0]['status'],
            'polling must happen before expiry, or a late code is thrown away');
        $this->assertSame('9550.00000000', $app->balance($user));
    }

    /* ============================ cancelling ============================ */

    public function testCancellingBeforeACodeRefundsInFull()
    {
        list($app, $user) = $this->app();
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));

        $out = $app->numberservice->cancel($res['number'], 'CUSTOMER');

        $this->assertTrue($out['ok']);
        $this->assertSame('450.00000000', $out['refunded']);
        $this->assertSame('10000.00000000', $app->balance($user));
        $this->assertSame('CANCELLED', $app->rows('service_transactions')[0]['status']);
        $this->assertSame('CANCELLED', $app->rows('virtual_numbers')[0]['status']);
    }

    /**
     * The expensive one. A customer who has their code must not be able to
     * cancel and keep both.
     */
    public function testCancellingAfterACodeIsRefused()
    {
        list($app, $user) = $this->app();
        list($tx, $number) = $this->rent_and_receive($app, $user);

        $out = $app->numberservice->cancel($number, 'CUSTOMER');

        $this->assertFalse($out['ok']);
        $this->assertSame('HAS_CODE', $out['code']);
        $this->assertSame('9550.00000000', $app->balance($user));
        $this->assertSame('SUCCESSFUL', $app->rows('service_transactions')[0]['status']);
    }

    public function testCancellingTwicePaysOnce()
    {
        list($app, $user) = $this->app();
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));

        $app->numberservice->cancel($res['number'], 'CUSTOMER');
        $number = $app->Virtual_number_model->find_by_id($res['number']->id);
        $again = $app->numberservice->cancel($number, 'CUSTOMER');

        $this->assertFalse($again['ok']);
        $this->assertSame('NOT_LIVE', $again['code']);
        $this->assertSame('10000.00000000', $app->balance($user));
    }

    /* ======================= releasing and banning ====================== */

    public function testReleasingAWorkingNumberMovesNoMoney()
    {
        list($app, $user) = $this->app();
        list($tx, $number) = $this->rent_and_receive($app, $user);

        $out = $app->numberservice->release($number, 'CUSTOMER');

        $this->assertTrue($out['ok']);
        $this->assertSame('COMPLETED', $out['state']);
        $this->assertSame('9550.00000000', $app->balance($user),
            'the code was delivered, so the charge stands');
        $this->assertSame('SUCCESSFUL', $app->rows('service_transactions')[0]['status']);
        $this->assertNotEmpty($app->rows('virtual_numbers')[0]['released_at']);
    }

    /**
     * Releasing a number that never received a code still refunds. The
     * customer paid for an OTP; handing the number back early does not make
     * an undelivered service delivered.
     */
    public function testReleasingANumberThatNeverReceivedACodeStillRefunds()
    {
        list($app, $user) = $this->app();
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'SLOW'));

        $out = $app->numberservice->release($res['number'], 'CUSTOMER');

        $this->assertTrue($out['ok']);
        $this->assertSame('10000.00000000', $app->balance($user));
        $this->assertSame('FAILED', $app->rows('service_transactions')[0]['status']);
    }

    public function testReportingANumberAsAlreadyRegisteredRefunds()
    {
        list($app, $user) = $this->app();
        $res = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));

        $out = $app->numberservice->ban($res['number'], 'CUSTOMER');

        $this->assertTrue($out['ok']);
        $this->assertSame('BANNED', $app->rows('virtual_numbers')[0]['status']);
        $this->assertSame('10000.00000000', $app->balance($user));
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /* ============================= the worker =========================== */

    public function testTheWorkerCollectsCodesAndExpiresTheRest()
    {
        list($app, $user) = $this->app('50000');
        $app->library('CronWorkers');

        $lucky   = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'WHATSAPP'));
        $unlucky = $app->numberservice->reserve($user, array('country' => 'NG', 'service' => 'SLOW'));

        // The mock delivers on the second poll, so one tick warms it up.
        $app->cronworkers->numbers_status();
        $app->db->where('id', $unlucky['number']->id)->update('virtual_numbers',
            array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)));
        $out = $app->cronworkers->numbers_status();

        $this->assertSame(0, $out['failed']);
        $rows = array();
        foreach ($app->rows('virtual_numbers') as $r) $rows[$r['id']] = $r['status'];
        $this->assertSame('RECEIVED', $rows[$lucky['number']->id]);
        $this->assertSame('EXPIRED',  $rows[$unlucky['number']->id]);
        // 450 charged and kept, 350 charged and refunded.
        $this->assertSame('49550.00000000', $app->balance($user));
    }

    public function testTheWorkerIsAQuietNoOpWithNothingLive()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');

        $out = $app->cronworkers->numbers_status();

        $this->assertSame(0, $out['processed']);
        $this->assertSame(0, $out['failed']);
    }

    public function testTheWorkerIgnoresAlreadySettledReservations()
    {
        list($app, $user) = $this->app();
        $app->library('CronWorkers');
        list($tx, $number) = $this->rent_and_receive($app, $user);
        $app->numberservice->release($number, 'CUSTOMER');

        $out = $app->cronworkers->numbers_status();

        $this->assertSame(0, $out['processed'],
            'a completed reservation must not be polled forever');
        $this->assertSame('9550.00000000', $app->balance($user));
    }

    /* ======================== the 5sim integration ====================== */

    private static function fixture($name)
    {
        $path = self::$root.'/tests/fixtures/fivesim/'.$name;
        if (!file_exists($path)) throw new RuntimeException('missing fixture '.$name);
        return file_get_contents($path);
    }

    private function provider(array $overrides = array())
    {
        return (object)array_merge(array(
            'id' => 9, 'public_id' => 'PROV0000000000000000000011', 'name' => '5sim',
            'api_url' => FiveSimAdapter::BASE_URL,
            'api_key_encrypted' => 'test-bearer-token',
            'api_type' => 'FIVESIM', 'status' => 'ACTIVE', 'currency' => 'RUB',
            'timeout_ms' => 20000, 'retry_policy' => null,
        ), $overrides);
    }

    private function adapter(array $script, array $overrides = array())
    {
        $GLOBALS['__fake_ci'] = new NumbersFakeCI();
        $http = new NumbersFakeHttp($script);
        return array(new FiveSimAdapter($this->provider($overrides), $http), $http);
    }

    private static function ok($body, $code = 200)
    {
        return array('http_code' => $code, 'body' => $body, 'request_id' => 'rid');
    }

    public function testReservingSendsABearerTokenAndTheMappedSlugs()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('buy_activation.json'))));
        $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP',
                                'operator' => 'any', 'reference' => '01J5REF0001'));

        $call = $http->calls[0];
        $this->assertSame('GET', $call['method'], 'every 5sim endpoint is a GET');
        $this->assertContains('Authorization: Bearer test-bearer-token', $call['headers']);
        // Our stable codes must be translated, or the vendor answers "no product".
        $this->assertStringContainsString('/user/buy/activation/nigeria/any/whatsapp', $call['path']);
    }

    public function testTheDeadlineComesFromTheVendorNotOurClock()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('buy_activation.json'))));
        $res = $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertTrue($res['ok']);
        $this->assertSame('+2348157551412', $res['msisdn']);
        $this->assertSame('1101889666', $res['reference']);
        $this->assertSame('RESERVED', $res['state']);
        // The fixture's `expires` is 18:46:12Z; a locally computed deadline
        // would drift against the vendor and refund a live reservation.
        $this->assertSame('2026-08-17 18:46:12', $res['expires_at']);
    }

    /**
     * The expensive 5sim quirk: rejections are plain text, often with HTTP
     * 200. Code that json_decode()s and trusts the result reads every one of
     * these as a successful reservation.
     */
    public function testAPlainTextRejectionIsNotMistakenForASuccess()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('error_no_free_phones.txt'))));
        $res = $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertFalse($res['ok'], 'a 200 with a plain-text body is still a rejection');
        $this->assertStringContainsString('out of stock', $res['error']);
    }

    public function testAVendorBalanceFailureIsReportedAsSuch()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('error_no_balance.txt'))));
        $res = $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('out of funds', $res['error'],
            'an operator must be able to tell "no stock" from "we are broke"');
    }

    public function testPollingExtractsTheCodeAndMapsTheVendorState()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('check_received.json'))));
        $res = $adapter->status('1101889666');

        $this->assertTrue($res['ok']);
        $this->assertSame('RECEIVED', $res['state']);
        $this->assertCount(1, $res['messages']);
        $this->assertSame('471925', $res['messages'][0]['code']);
        $this->assertSame('987654321', $res['messages'][0]['id'],
            'the vendor message id is what makes a repeated poll idempotent');
    }

    public function testAWaitingReservationReportsNoMessages()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('check_waiting.json'))));
        $res = $adapter->status('1101889666');

        $this->assertTrue($res['ok']);
        $this->assertSame('RESERVED', $res['state']);
        $this->assertSame(array(), $res['messages']);
    }

    /** TIMEOUT is 5sim's word for "the deadline passed"; ours is EXPIRED. */
    public function testVendorTimeoutMapsToExpired()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('check_timeout.json'))));
        $res = $adapter->status('1101889666');

        $this->assertSame('EXPIRED', $res['state']);
    }

    public function testFinishAndCancelMapToTheirOwnStates()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('finish.json'))));
        $this->assertSame('COMPLETED', $adapter->finish('1101889666')['state']);

        list($adapter,) = $this->adapter(array(self::ok(self::fixture('cancel.json'))));
        $this->assertSame('CANCELLED', $adapter->cancel('1101889666')['state']);
    }

    public function testCancellingAReservationWithSmsIsRefusedByTheVendor()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('error_order_has_sms.txt'))));
        $res = $adapter->cancel('1101889666');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('already received a code', $res['error']);
    }

    /**
     * 5sim quotes roubles. Reporting that figure in a naira column would make
     * every margin wrong by a factor of twenty, so an unconfigured rate must
     * produce no cost at all rather than a plausible-looking one.
     */
    public function testAVendorPriceIsNotReportedAsBaseCurrencyWithoutARate()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('buy_activation.json'))));
        $res = $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertArrayNotHasKey('cost', $res,
            'a rouble price must never be passed off as naira');
        $this->assertSame('21', $res['cost_vendor']);
    }

    public function testAConfiguredRateConvertsTheVendorPrice()
    {
        list($adapter,) = $this->adapter(
            array(self::ok(self::fixture('buy_activation.json'))),
            array('retry_policy' => json_encode(array('fivesim' => array('rate_to_base' => '20'))))
        );
        $res = $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertSame('420.00000000', $res['cost'], '21 RUB at 20 naira each');
    }

    /**
     * A hosting row is a long-term rental with a different lifecycle; syncing
     * it into the activation catalogue would sell something this domain
     * cannot service.
     */
    public function testTheCatalogueSyncSkipsRentalsAndKeepsActivations()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('products_nigeria.json'))));
        $res = $adapter->products('NG');

        $this->assertTrue($res['ok']);
        $services = array_column($res['products'], 'service');
        $this->assertContains('WHATSAPP', $services);
        $this->assertContains('TELEGRAM', $services);
        $this->assertNotContains('1DAY', $services, 'hosting rows are rentals, not activations');
        $whatsapp = $res['products'][array_search('WHATSAPP', $services, true)];
        $this->assertSame(812, $whatsapp['stock']);
    }

    public function testRejectedCredentialsAreReportedClearly()
    {
        list($adapter,) = $this->adapter(array(array('http_code' => 401, 'body' => '', 'request_id' => 'r')));
        $res = $adapter->balance();

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('credentials', $res['error']);
    }

    /**
     * A transport failure must produce ok:false with no state, so the worker
     * skips the reservation and tries again rather than expiring a live one.
     */
    public function testATimeoutLeavesTheReservationUndecided()
    {
        list($adapter,) = $this->adapter(array(array('http_code' => 0, 'body' => null, 'error' => 'timed out')));
        $res = $adapter->status('1101889666');

        $this->assertFalse($res['ok']);
        $this->assertArrayNotHasKey('state', $res, 'no answer is safer than a guessed one');
    }

    public function testTheBalanceEndpointReadsTheProfileShape()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('profile.json'))));
        $res = $adapter->balance();

        $this->assertTrue($res['ok']);
        $this->assertSame('4210.55', $res['raw_balance']);
        $this->assertSame('RUB', $res['currency'],
            'without a rate the float stays in the vendor currency, labelled as such');
    }

    /* ================== the current 5sim protocol (key #1) ============== */

    /**
     * 5sim issue two dashboard keys: "API key for 5sim protocol" (the current
     * API this adapter speaks) and "API key for 5sim protocol (Deprecated
     * API)" — the legacy API1/handler_api protocol. The environment is the
     * canonical place the current-protocol key lives, and it must win over a
     * stale key in the provider row: production rotates the credential by
     * editing the environment, without a database write.
     */
    public function testCredentialsComeFromTheEnvironmentFirst()
    {
        putenv('FIVESIM_API_KEY=env-protocol-key-1');
        try {
            list($adapter, $http) = $this->adapter(
                array(self::ok(self::fixture('buy_activation.json'))),
                array('api_key_encrypted' => 'stale-deprecated-key-2'));
            $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

            $this->assertContains('Authorization: Bearer env-protocol-key-1', $http->calls[0]['headers'],
                'the environment key is the one that goes on the wire');
            $this->assertNotContains('Authorization: Bearer stale-deprecated-key-2', $http->calls[0]['headers']);
        } finally {
            putenv('FIVESIM_API_KEY');
        }
    }

    /** The portable VP_ spelling resolves to the same credential. */
    public function testThePortableEnvSpellingIsHonouredToo()
    {
        putenv('VP_FIVESIM_API_KEY=portable-key');
        try {
            list($adapter, $http) = $this->adapter(
                array(self::ok(self::fixture('buy_activation.json'))),
                array('api_key_encrypted' => ''));
            $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

            $this->assertContains('Authorization: Bearer portable-key', $http->calls[0]['headers']);
        } finally {
            putenv('VP_FIVESIM_API_KEY');
        }
    }

    /** No key anywhere: refuse before spending a request, with a say-why error. */
    public function testAMissingKeyFailsBeforeCallingTheVendor()
    {
        list($adapter, $http) = $this->adapter(array(), array('api_key_encrypted' => ''));
        $res = $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('not configured', $res['error']);
        $this->assertCount(0, $http->calls, 'no credential, no request');
    }

    /**
     * The current API's buy `ref` parameter is a referral code, not a client
     * order reference. Our transaction id belongs in provider_transactions,
     * not in a misused vendor field.
     */
    public function testTheBuyCallDoesNotMisuseTheReferralField()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('buy_activation.json'))));
        $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP',
                                'reference' => 'TXN0000000000000001'));

        $path = $http->calls[0]['path'];
        $this->assertStringNotContainsString('ref=', $path,
            'ref is a referral parameter in the current protocol');
        $this->assertStringContainsString('/user/buy/activation/nigeria/any/whatsapp', $path);
    }

    /** Countries and operators come from the current guest endpoint. */
    public function testCountriesAndOperatorsComeFromTheCurrentEndpoint()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('countries.json'))));
        $res = $adapter->countries();

        $this->assertSame('GET', $http->calls[0]['method']);
        $this->assertStringContainsString('/guest/countries', $http->calls[0]['path']);
        $this->assertTrue($res['ok']);
        $this->assertSame(array('any', 'mtn', 'glo', 'airtel', '9mobile'),
            $res['countries']['nigeria']);
        $this->assertSame('nigeria', $res['country_codes']['NG'],
            'our ISO codes are mapped to the vendor slugs');
    }

    public function testOperatorsAreReadFromTheCountryList()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('countries.json'))));
        $res = $adapter->operators('NG');

        $this->assertTrue($res['ok']);
        $this->assertContains('mtn', $res['operators']);
        $this->assertStringContainsString('/guest/countries', $http->calls[0]['path'],
            '5sim has no separate operators endpoint; the country list is the source');
    }

    /** Availability and prices ride the current /guest/prices endpoint. */
    public function testPricesUseTheCurrentPricesEndpointAndFlattenTheRows()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('prices_nigeria.json'))));
        $res = $adapter->prices('NG');

        $this->assertSame('GET', $http->calls[0]['method']);
        $this->assertStringContainsString('/guest/prices?', $http->calls[0]['path']);
        $this->assertStringContainsString('country=nigeria', $http->calls[0]['path']);
        $this->assertTrue($res['ok']);

        $by_key = array();
        foreach ($res['prices'] as $row) $by_key[$row['provider_product'].'/'.$row['operator']] = $row;
        $this->assertSame('21', $by_key['whatsapp/any']['cost_vendor']);
        $this->assertSame(812, $by_key['whatsapp/any']['stock']);
        $this->assertSame('WHATSAPP', $by_key['whatsapp/any']['service']);
        $this->assertSame(0, $by_key['whatsapp/glo']['stock'],
            'a sold-out operator is visible as zero, not missing');
    }

    public function testPricesFiltersNarrowTheRequest()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('prices_nigeria.json'))));
        $adapter->prices('NG', 'WHATSAPP', 'mtn');

        $path = $http->calls[0]['path'];
        $this->assertStringContainsString('country=nigeria', $path);
        $this->assertStringContainsString('product=whatsapp', $path,
            'our service code must be translated to the vendor slug');
        $this->assertStringContainsString('operator=mtn', $path);
    }

    /**
     * The deprecation guard. A provider row pointed at the API1 compatibility
     * protocol (handler_api.php under /stubs) — or at any host other than
     * 5sim.net — is refused in the constructor, before a single customer
     * request can be routed to the deprecated API.
     */
    public function testTheAdapterRefusesTheDeprecatedApi1Protocol()
    {
        foreach (array(
            'https://5sim.net/stubs/handler_api.php',
            'https://5sim.net/stubs/handler_api.php?api_key=x&action=getBalance',
            'https://api1.other-vendor.example/v1',
        ) as $bad_url) {
            try {
                new FiveSimAdapter($this->provider(array('api_url' => $bad_url)));
                $this->fail('constructing with '.$bad_url.' must throw');
            } catch (RuntimeException $e) {
                $this->assertStringContainsStringIgnoringCase(
                    in_array($bad_url, array('https://api1.other-vendor.example/v1'), true) ? 'host' : 'deprecated',
                    $e->getMessage());
            }
        }
    }

    /** A bare 5sim.net URL is normalised onto the current /v1 base. */
    public function testABareVendorUrlIsNormalisedOntoTheCurrentApi()
    {
        list($adapter, $http) = $this->adapter(
            array(self::ok(self::fixture('products_nigeria.json'))),
            array('api_url' => 'https://5sim.net'));
        $res = $adapter->products('NG');

        $this->assertTrue($res['ok']);
        $this->assertStringContainsString('/guest/products/nigeria/any', $http->calls[0]['path']);
    }

    /** A 429 is a rate limit, not an unusable response. */
    public function testARateLimitedVendorIsReportedAsRateLimiting()
    {
        list($adapter,) = $this->adapter(array(array('http_code' => 429, 'body' => '', 'request_id' => 'r')));
        $res = $adapter->status('1101889666');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('rate-limiting', $res['error']);
    }

    /**
     * The bearer token must never appear in a log line. The log carries the
     * action, the endpoint path and the HTTP status — nothing that a log
     * reader, a crash dump or a shared log sink could weaponise.
     */
    public function testLogsNeverContainTheApiToken()
    {
        $lines = array();
        FiveSimAdapter::$log_sink = function ($level, $message) use (&$lines) {
            $lines[] = $level.' '.$message;
        };
        try {
            list($adapter,) = $this->adapter(array(
                array('http_code' => 401, 'body' => '', 'request_id' => 'r'),
                self::ok(self::fixture('buy_activation.json')),
            ));
            $adapter->balance();
            $adapter->reserve(array('country' => 'NG', 'service' => 'WHATSAPP'));
        } finally {
            FiveSimAdapter::$log_sink = null;
        }

        $this->assertNotEmpty($lines, 'the adapter must log its vendor calls');
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('test-bearer-token', $line);
            $this->assertStringNotContainsString('Bearer', $line);
        }
    }

    /* ========================= registry + wiring ======================== */

    public function testTheRegistryBuildsNumberAdaptersForTheirOwnFamily()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('Provider_manager');

        $types = Provider_manager::supported_types(Provider_manager::FAMILY_NUMBER);
        $this->assertContains('FIVESIM', $types);
        $this->assertContains('MOCK_NUMBER', $types);
        $this->assertNotContains('FIVESIM',
            Provider_manager::supported_types(Provider_manager::FAMILY_VTU),
            'a number vendor cannot service an airtime purchase');

        $adapter = $app->provider_manager->adapter($this->provider(), Provider_manager::FAMILY_NUMBER);
        $this->assertInstanceOf('FiveSimAdapter', $adapter);
        $this->assertInstanceOf('NumberProviderInterface', $adapter);
    }

    public function testBothNumberAdaptersImplementTheWholeInterface()
    {
        foreach (array('FiveSimAdapter', 'MockNumberAdapter') as $class) {
            foreach (array('reserve','status','finish','cancel','ban','products','balance') as $m) {
                $this->assertTrue(method_exists($class, $m), $class.' must implement '.$m.'()');
            }
        }
    }

    public function testTheAdminApiTypeWhitelistIncludesTheNumberFamily()
    {
        require_once self::$root.'/application/libraries/Provider_manager.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
        $this->assertContains('FIVESIM', ProviderSyncService::supported_types(),
            'a registered adapter that the create form refuses is unusable');
        $this->assertSame(Provider_manager::FAMILY_NUMBER,
            ProviderSyncService::family((object)array('api_type' => 'FIVESIM')));
        $this->assertSame(Provider_manager::FAMILY_SMM,
            ProviderSyncService::family((object)array('api_type' => 'MOCK')),
            'MOCK stays the SMM demo adapter');
    }

    /* ====================== admin-surface contract ====================== */

    private function controller()
    {
        return file_get_contents(self::$root.'/application/controllers/admin/Numbers.php');
    }

    public function testTheAdminScreensExist()
    {
        $this->assertFileExists(self::$root.'/application/controllers/admin/Numbers.php',
            'numbers permissions are seeded, so the screens that use them must exist');
        $this->assertFileExists(self::$root.'/application/views/admin/numbers/index.php');
        $this->assertFileExists(self::$root.'/application/views/admin/numbers/detail.php');
    }

    public function testEveryAdminMutationIsPostOnlyAndGuarded()
    {
        $src = $this->controller();
        foreach (array('index', 'detail', 'recheck', 'release', 'refund') as $action) {
            $this->assertStringContainsString('function '.$action.'(', $src,
                "admin/Numbers.php must define {$action}()");
        }
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src,
            'admin/Numbers.php must reject non-POST mutations');
        $this->assertSame(3, substr_count($src, '$this->guard('),
            'every mutation must go through guard()');
    }

    public function testAdminActionsRequireGranularPermissions()
    {
        $src = $this->controller();
        $this->assertStringContainsString("require_perm('numbers.view')", $src);
        $this->assertStringContainsString("'numbers.manage'", $src);
        $this->assertStringContainsString("'numbers.refund'", $src);

        $seeder = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        foreach (array('numbers.view', 'numbers.manage', 'numbers.refund') as $perm) {
            $this->assertStringContainsString("'".$perm."'", $seeder,
                "{$perm} must be a seeded permission");
        }
    }

    public function testAdminMutationsAreAuditLogged()
    {
        $src = $this->controller();
        $this->assertStringContainsString('Audit_log_model', $src);
        $this->assertSame(3, substr_count($src, '$this->audit('),
            'every mutation must record what it did');
    }

    public function testNeitherControllerMovesMoneyItself()
    {
        foreach (array('admin/Numbers.php', 'dashboard/Numbers.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringNotContainsString('ledgerservice->', $src,
                $rel.': refunds must go through the service layer, not the ledger');
            $this->assertStringNotContainsString("update('wallets'", $src);
            $this->assertStringNotContainsString("update('service_transactions'", $src,
                $rel.': the status column belongs to TransactionEngine');
            $this->assertStringNotContainsString("update('virtual_numbers'", $src,
                $rel.': the reservation state belongs to NumberService');
        }
    }

    public function testTheCustomerScreensNeverSeeAnotherCustomersNumber()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Numbers.php');
        $this->assertStringContainsString('find_public_for_user', $src,
            'a reservation must be looked up scoped to the signed-in user');
        $this->assertStringNotContainsString('admin_find', $src);
    }

    public function testTheListEndpointsAreBounded()
    {
        foreach (array('admin/Numbers.php', 'dashboard/Numbers.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringContainsString('const PER_PAGE', $src,
                $rel.' must paginate its queue');
        }
    }

    public function testTheAdminViewsCarryCsrfTokensAndEscapeOutput()
    {
        foreach (array('index', 'detail') as $view) {
            $src = file_get_contents(self::$root.'/application/views/admin/numbers/'.$view.'.php');
            if (strpos($src, 'method="post"') !== false) {
                $this->assertStringContainsString('get_csrf_token_name()', $src,
                    "admin/numbers/{$view}.php has a POST form without a CSRF token");
            }
            $this->assertStringNotContainsString('api_key_encrypted', $src);
        }
    }

    /* ============================ scheduling ============================ */

    /**
     * A reservation lives about fifteen minutes. A worker that is registered
     * but never scheduled is the same as no worker at all — every customer
     * would have to press "check" themselves and nothing would ever refund.
     */
    public function testTheWorkerIsScheduledAndWired()
    {
        $config = file_get_contents(self::$root.'/application/config/marvy.php');
        $this->assertStringContainsString("'numbers_status'", $config,
            'the numbers worker must have a schedule');

        $controller = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $this->assertStringContainsString('function numbers_status(', $controller);
        $this->assertStringContainsString("\$this->execute('numbers_status'", $controller,
            'the job must run under the JobRunner lock');

        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('cron numbers_status', $crontab);
    }

    /* ============================== schema ============================== */

    public function testTheReservationCarriesItsOwnExpiryAndState()
    {
        $sql = '';
        foreach (IntegrationHarness::ddl() as $stmt) {
            if (strpos($stmt, 'CREATE TABLE IF NOT EXISTS virtual_numbers') !== false) $sql = $stmt;
        }
        $this->assertNotEmpty($sql, 'virtual_numbers must exist in the migrations');
        // The two columns the whole phase is about.
        $this->assertStringContainsString('expires_at', $sql);
        $this->assertStringContainsString('idx_vnum_status_expires', $sql,
            'the expiry sweep scans (status, expires_at) and needs the index');
        $this->assertStringContainsString('service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE', $sql,
            'one reservation per transaction, like every other domain table');
        $this->assertDoesNotMatchRegularExpression('/\bamount\s+DECIMAL/i', $sql,
            'money lives on service_transactions; a domain table must never duplicate it');
    }

    public function testOtpMessagesCannotBeStoredTwice()
    {
        $sql = '';
        foreach (IntegrationHarness::ddl() as $stmt) {
            if (strpos($stmt, 'CREATE TABLE IF NOT EXISTS otp_messages') !== false) $sql = $stmt;
        }
        $this->assertNotEmpty($sql);
        $this->assertStringContainsString('uq_otp_msg (virtual_number_id, provider_message_id)', $sql,
            'a repeated poll must not duplicate the customer\'s code');
    }
}

/* -------------------------------- doubles -------------------------------- */

/**
 * Scripted stand-in for SecureHttpClient. Records what went on the wire, which
 * is half of what these tests assert, and throws on an unscripted call so a
 * test that makes an unexpected request fails loudly rather than on a default.
 */
class NumbersFakeHttp
{
    public $calls = array();
    private $script;

    public function __construct(array $script) { $this->script = $script; }

    public function get($url, $headers = array(), $options = array())
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        $path = preg_replace('~^/v1~', '', (string)$path);
        $this->calls[] = array(
            'method' => 'GET', 'url' => $url,
            'path' => $path.($query ? '?'.$query : ''), 'headers' => $headers,
        );
        if (!$this->script) {
            throw new RuntimeException('NumbersFakeHttp: unscripted GET '.$path);
        }
        return array_shift($this->script);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        throw new RuntimeException('NumbersFakeHttp: 5sim has no POST endpoints');
    }
}

/** Minimal container for the adapter's own get_instance() credential read. */
#[AllowDynamicProperties]
class NumbersFakeCI
{
    public $load;
    public function __construct()
    {
        $this->load = new NumbersFakeLoader();
        $this->encryptionservice = new NumbersPassthroughEncryption();
    }
}

class NumbersFakeLoader
{
    public function library($n, $p = null, $o = null) { return $this; }
    public function model($n, $a = null, $d = false) { return $this; }
    public function helper($n = '') { return $this; }
}

class NumbersPassthroughEncryption
{
    public function encrypt($plain) { return 'enc:'.base64_encode((string)$plain); }
    public function decrypt($blob)
    {
        return strpos((string)$blob, 'enc:') === 0
            ? base64_decode(substr((string)$blob, 4)) : (string)$blob;
    }
}
