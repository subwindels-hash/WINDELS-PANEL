<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * VTU domain + the universal transaction engine (§9, §18, §19).
 *
 * Runs the real VtuService, TransactionEngine, LedgerService, models and
 * migration-derived schema — only the provider HTTP call is a double. The
 * money assertions matter most here: a VTU bug that silently keeps a
 * customer's money looks identical to a successful purchase from the outside.
 */
class VtuTest extends TestCase
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
        require_once self::$root.'/tests/_support/IntegrationHarness.php';
    }

    private function app($balance = '100000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_vtu();
        $user = $app->register('vtu_user', 'vtu@x.test');
        $app->credit($user, $balance);
        $app->library('VtuService');
        return array($app, $user);
    }

    private function stx($app)
    {
        $rows = $app->rows('service_transactions');
        return $rows ? $rows[0] : null;
    }

    /* ============================ airtime ============================== */

    public function testAirtimeChargesFaceValueLessDiscount()
    {
        list($app, $user) = $this->app();

        // ₦1,000 face at 2% discount = ₦980 charged.
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $this->stx($app);
        $this->assertSame('980.00000000', $tx['amount']);
        $this->assertSame('SUCCESSFUL', $tx['status']);
        $this->assertSame('VTU', $tx['service_domain']);
        $this->assertSame('AIRTIME', $tx['service_type']);
        $this->assertSame('99020.00000000', $app->balance($user));

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c, 'ledger must balance');
    }

    public function testAirtimeStoresTheDialledNumberInLocalFormat()
    {
        list($app, $user) = $this->app();
        // International form must normalise to the local 0-prefixed number.
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '+2348031234567', 'amount' => '500',
        ));
        $this->assertTrue($res['ok']);
        $vtu = $app->rows('vtu_transactions')[0];
        $this->assertSame('08031234567', $vtu['recipient']);
        $this->assertSame('500.00000000', $vtu['face_value']);
    }

    public function testAirtimeRejectsAMalformedNumberWithoutCharging()
    {
        list($app, $user) = $this->app();
        foreach (array('12345', '', '0803123456789', 'not-a-number') as $bad) {
            $res = $app->vtuservice->airtime($user, array(
                'network' => 'MTN', 'msisdn' => $bad, 'amount' => '500',
            ));
            $this->assertFalse($res['ok'], var_export($bad, true).' should be rejected');
            $this->assertSame('BAD_MSISDN', $res['code']);
        }
        $this->assertSame(0, $app->db->count('service_transactions'));
        $this->assertSame('100000.00000000', $app->balance($user));
    }

    public function testAirtimeEnforcesTheProductAmountBounds()
    {
        list($app, $user) = $this->app();
        $under = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '10',
        ));
        $this->assertFalse($under['ok'], 'minimum is 50');
        $over = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '99999',
        ));
        $this->assertFalse($over['ok'], 'maximum is 50000');
        $this->assertSame(0, $app->db->count('service_transactions'));
        $this->assertSame('100000.00000000', $app->balance($user));
    }

    /* ============================== data =============================== */

    public function testDataBundleChargesTheFixedProductPrice()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $this->stx($app);
        $this->assertSame('300.00000000', $tx['amount']);
        // Provider cost frozen for margin reporting (§15).
        $this->assertSame('285.00000000', $tx['provider_cost']);
        $this->assertSame('99700.00000000', $app->balance($user));
    }

    public function testAnUnknownProductIsRejected()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'NOPE', 'msisdn' => '08031234567',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('NO_PRODUCT', $res['code']);
        $this->assertSame('100000.00000000', $app->balance($user));
    }

    /** A product from another network must not be buyable under this one. */
    public function testAProductCannotBeBoughtUnderTheWrongNetwork()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'DSTV-COMPACT', 'msisdn' => '08031234567',
        ));
        $this->assertFalse($res['ok'], 'a cable package is not an MTN data bundle');
        $this->assertSame(0, $app->db->count('service_transactions'));
    }

    /* ====================== cable / electricity ======================== */

    public function testCablePaymentRequiresAValidSmartcard()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->cable($user, array(
            'network' => 'DSTV', 'product' => 'DSTV-COMPACT', 'smartcard' => 'abc',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_SMARTCARD', $res['code']);
    }

    public function testElectricityStoresTheTokenReturnedByTheProvider()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->electricity($user, array(
            'network' => 'IKEDC', 'meter' => '01234567890',
            'meter_type' => 'PREPAID', 'amount' => '5000',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        $vtu = $app->rows('vtu_transactions')[0];
        $this->assertNotEmpty($vtu['token'], 'the electricity token must be persisted');
        $this->assertNotEmpty($vtu['units']);
        // 1% discount on 5000 = 4950 charged.
        $this->assertSame('4950.00000000', $this->stx($app)['amount']);
    }

    public function testElectricityRejectsAnUnknownMeterType()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->electricity($user, array(
            'network' => 'IKEDC', 'meter' => '01234567890',
            'meter_type' => 'WHATEVER', 'amount' => '5000',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_METER_TYPE', $res['code']);
    }

    /* ============================ exam pins ============================ */

    public function testExamPinsMultiplyPriceAndCostByQuantity()
    {
        list($app, $user) = $this->app();
        $res = $app->vtuservice->education($user, array(
            'network' => 'WAEC', 'product' => 'WAEC-PIN', 'quantity' => 3,
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $this->stx($app);
        $this->assertSame('10500.00000000', $tx['amount'], '3 x 3500');
        $this->assertSame('10200.00000000', $tx['provider_cost'], '3 x 3400');
    }

    public function testExamPinQuantityIsBounded()
    {
        list($app, $user) = $this->app();
        foreach (array(0, -1, 11) as $qty) {
            $res = $app->vtuservice->education($user, array(
                'network' => 'WAEC', 'product' => 'WAEC-PIN', 'quantity' => $qty,
            ));
            $this->assertFalse($res['ok'], 'quantity '.$qty.' should be rejected');
        }
    }

    /* ======================== money integrity ========================== */

    /** The failure that must never silently keep the customer's money. */
    public function testAProviderRejectionRefundsInFull()
    {
        list($app, $user) = $this->app();
        // MockVtuAdapter declines any recipient ending 0000.
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08030000000', 'amount' => '1000',
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('PROVIDER_REJECTED', $res['code']);

        $tx = $this->stx($app);
        $this->assertSame('FAILED', $tx['status']);
        $this->assertSame('980.00000000', $tx['refunded_amount'], 'the full charge must come back');
        $this->assertSame('100000.00000000', $app->balance($user), 'balance restored exactly');

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testAnUnaffordablePurchaseChargesNothingAndCallsNoProvider()
    {
        list($app, $user) = $this->app('100');
        $res = $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
        $this->assertSame('100.00000000', $app->balance($user));
        $this->assertSame(0, $app->db->count('provider_transactions'),
            'no provider call may happen for an unaffordable purchase');
    }

    public function testADuplicateSubmissionResolvesToOneTransaction()
    {
        list($app, $user) = $this->app();
        $input = array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
            'idempotency_key' => 'vtu-retry-1',
        );
        $first  = $app->vtuservice->data($user, $input);
        $second = $app->vtuservice->data($user, $input);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['duplicate']), 'the retry must be recognised');
        $this->assertSame($first['transaction']->public_id, $second['transaction']->public_id);
        $this->assertSame(1, $app->db->count('service_transactions'));
        $this->assertSame('99700.00000000', $app->balance($user), 'charged exactly once');
    }

    /** A refund must never be issued twice for the same transaction. */
    public function testATransactionCannotBeRefundedTwice()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
        ));
        $tx = $this->stx($app);
        $app->library('TransactionEngine');

        $first  = $app->transactionengine->transition($tx['id'], 'REFUNDED', 'ADMIN', 'goodwill');
        $second = $app->transactionengine->transition($tx['id'], 'REFUNDED', 'ADMIN', 'again');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok'], 'a terminal transaction must not transition again');
        $this->assertSame('TERMINAL', $second['code']);
        $this->assertSame('100000.00000000', $app->balance($user), 'refunded once, not twice');

        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    public function testAsyncProviderResultsStayProcessingUntilConfirmed()
    {
        list($app, $user) = $this->app();
        // MockVtuAdapter returns PROCESSING for recipients ending 9999.
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08039999999', 'amount' => '1000',
        ));
        $this->assertTrue($res['ok']);
        $this->assertSame('PROCESSING', $this->stx($app)['status'],
            'an unsettled purchase must not be reported as successful');
        // Still charged: the money is with the provider, not the customer.
        $this->assertSame('99020.00000000', $app->balance($user));
    }

    /* ========================= engine contract ========================= */

    public function testEveryPurchaseRecordsAProviderCallAndStatusTrail()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
        ));
        $tx = $this->stx($app);

        $calls = $app->rows('provider_transactions');
        $this->assertCount(1, $calls);
        $this->assertSame('PURCHASE', $calls[0]['action']);
        $this->assertNotEmpty($calls[0]['provider_reference']);

        $history = array_map(function ($h) { return $h['to_status']; },
            $app->rows('service_transaction_status_history'));
        $this->assertSame(array('PENDING', 'PROCESSING', 'SUCCESSFUL'), $history);
        $this->assertNotEmpty($tx['wallet_transaction_id'], 'the debit must be linked');
    }

    /** The engine, not each domain, owns the money. */
    public function testOnlyTheLedgerWritesWalletBalances()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000',
        ));
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08030000000', 'amount' => '1000',
        ));

        // The ledger is allowed to write wallets.balance; nothing else is.
        // Every recorded write must be backed by a wallet_transactions row.
        $balance_writes = count($app->db->raw_updates);
        $tx_rows = $app->db->count('wallet_transactions');
        $this->assertGreaterThan(0, $tx_rows);
        $this->assertLessThanOrEqual($tx_rows, $balance_writes,
            'a wallet balance changed without a matching wallet_transactions row');
    }

    public function testUnifiedHistoryFiltersAcrossDomains()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000',
        ));
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
        ));
        $app->model('Service_transaction_model');

        $all = $app->Service_transaction_model->history_for_user($user->id);
        $this->assertCount(2, $all);

        $airtime = $app->Service_transaction_model->history_for_user($user->id,
            array('type' => 'AIRTIME'));
        $this->assertCount(1, $airtime);
        $this->assertSame('AIRTIME', $airtime[0]->service_type);

        $vtu = $app->Service_transaction_model->history_for_user($user->id,
            array('domain' => 'VTU'));
        $this->assertCount(2, $vtu);
    }

    /* ========================== cron settlement ======================== */

    /**
     * A purchase left PROCESSING means the customer has paid and received
     * nothing, so the worker that settles them is money-critical.
     */
    public function testTheCronWorkerSettlesProcessingPurchases()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08039999999', 'amount' => '1000',
        ));
        $this->assertSame('PROCESSING', $this->stx($app)['status']);

        // MockVtuAdapter::status() reports SUCCESSFUL.
        $app->library('CronWorkers');
        $res = $app->cronworkers->vtu_status();

        $this->assertSame(1, $res['processed']);
        $this->assertSame('SUCCESSFUL', $this->stx($app)['status']);
        $this->assertSame('99020.00000000', $app->balance($user),
            'settling a successful purchase must not move money again');
    }

    public function testTheWorkerIgnoresAlreadySettledPurchases()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567',
        ));
        $app->library('CronWorkers');
        $res = $app->cronworkers->vtu_status();

        $this->assertSame(0, $res['processed'],
            'a SUCCESSFUL purchase is not awaiting settlement');
    }

    /* ======================== provider registry ======================== */

    public function testTheRegistryRefusesAnUnknownAdapterTypeLoudly()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('Provider_manager');
        $provider = (object)array('api_type' => 'NOT_A_REAL_TYPE', 'api_url' => 'https://x.test');

        try {
            $app->provider_manager->adapter($provider, Provider_manager::FAMILY_VTU);
            $this->fail('an unknown api_type must not silently return an adapter');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NOT_A_REAL_TYPE', $e->getMessage());
            $this->assertStringContainsString('MOCK', $e->getMessage(), 'the error should list what is known');
        }
    }

    /** SMM and VTU families are separate contracts, not one merged interface. */
    public function testTheRegistryKeepsProviderFamiliesSeparate()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('Provider_manager');

        $smm = Provider_manager::supported_types(Provider_manager::FAMILY_SMM);
        $vtu = Provider_manager::supported_types(Provider_manager::FAMILY_VTU);
        $this->assertContains('STANDARD_SMM', $smm);
        $this->assertContains('STANDARD_VTU', $vtu);
        $this->assertNotContains('STANDARD_SMM', $vtu,
            'an SMM adapter cannot service a VTU purchase');

        $provider = (object)array('api_type' => 'MOCK', 'api_url' => 'https://x.test');
        $adapter = $app->provider_manager->adapter($provider, Provider_manager::FAMILY_VTU);
        $this->assertInstanceOf('VtuProviderInterface', $adapter);
    }

    public function testTheVtuAdapterImplementsEveryServiceType()
    {
        require_once dirname(dirname(__DIR__)).'/application/libraries/StandardVtuAdapter.php';
        foreach (array('airtime','data','cable','electricity','education','verify','status','balance') as $m) {
            $this->assertTrue(method_exists('StandardVtuAdapter', $m),
                'StandardVtuAdapter must implement '.$m.'()');
            $this->assertTrue(method_exists('MockVtuAdapter', $m));
        }
    }
}
