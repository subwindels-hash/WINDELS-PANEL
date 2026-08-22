<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * VTpass live-vendor integration (§14, §23).
 *
 * Every response here is a captured VTpass shape in tests/fixtures/vtpass —
 * no network, no credentials. The HTTP client is a hand-rolled fake so the
 * assertions can cover the two things that decide whether real money is safe:
 * what we send (auth headers, request_id format, serviceID mapping) and how we
 * read what comes back (000 vs 099 vs a timeout).
 *
 * The expensive failures this pins down:
 *   - a timeout on /pay reported as a failure refunds a customer whose airtime
 *     was delivered, and VTpass keeps our money;
 *   - storing the transactionId as the provider reference makes /requery
 *     impossible, so a PROCESSING purchase never settles;
 *   - a Bearer token authenticates as nobody, so nothing works at all.
 */
class VtpassTest extends TestCase
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
        require_once self::$root.'/application/libraries/VtpassAdapter.php';
    }

    /* ------------------------------ helpers ------------------------------ */

    private static function fixture($name)
    {
        $path = self::$root.'/tests/fixtures/vtpass/'.$name;
        if (!file_exists($path)) throw new RuntimeException('missing fixture '.$name);
        return file_get_contents($path);
    }

    /** A provider row shaped like the one an admin would create. */
    private function provider(array $overrides = array())
    {
        return (object)array_merge(array(
            'id'                => 7,
            'public_id'         => 'PROV0000000000000000000009',
            'name'              => 'VTpass (sandbox)',
            'api_url'           => VtpassAdapter::SANDBOX_URL,
            'api_key_encrypted' => json_encode(array(
                'api_key'    => 'test-api-key',
                'public_key' => 'PK_testpublic',
                'secret_key' => 'SK_testsecret',
            )),
            'api_type'    => 'VTPASS',
            'status'      => 'ACTIVE',
            'currency'    => 'NGN',
            'timeout_ms'  => 20000,
            'retry_policy'=> null,
        ), $overrides);
    }

    /**
     * Adapter wired to a scripted HTTP client.
     * $script is a list of responses, consumed in order.
     */
    private function adapter(array $script, array $provider_overrides = array())
    {
        // VtpassAdapter decrypts through get_instance()->encryptionservice.
        $GLOBALS['__fake_ci'] = new VtpassFakeCI();
        $http = new VtpassFakeHttp($script);
        return array(new VtpassAdapter($this->provider($provider_overrides), $http), $http);
    }

    private static function ok($body)
    {
        return array('http_code' => 200, 'body' => $body, 'request_id' => 'rid');
    }

    /* ============================== auth ================================= */

    /**
     * VTpass does not read Authorization. A Bearer header means every call is
     * unauthenticated, which is how the pre-existing StandardVtuAdapter was
     * written.
     */
    public function testPurchasesAuthenticateWithApiKeyAndSecretKey()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('airtime_success.json'))));
        $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                'amount' => '1000', 'reference' => '01J5REF0001'));

        $headers = $http->calls[0]['headers'];
        $this->assertContains('api-key: test-api-key', $headers);
        $this->assertContains('secret-key: SK_testsecret', $headers);
        $this->assertNotContains('public-key: PK_testpublic', $headers,
            'the public key must not be sent on a purchase');
        foreach ($headers as $h) {
            $this->assertStringNotContainsStringIgnoringCase('authorization:', $h,
                'VTpass ignores Authorization; a Bearer header authenticates as nobody');
        }
    }

    /** Reads use the public key; sending the secret key on a GET is rejected. */
    public function testLookupsAuthenticateWithThePublicKey()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('balance.json'))));
        $adapter->balance();

        $headers = $http->calls[0]['headers'];
        $this->assertSame('GET', $http->calls[0]['method']);
        $this->assertContains('api-key: test-api-key', $headers);
        $this->assertContains('public-key: PK_testpublic', $headers);
        $this->assertNotContains('secret-key: SK_testsecret', $headers);
    }

    /** A single-string key still works; it is simply the api-key. */
    public function testABareApiKeyStringIsAcceptedAsTheApiKey()
    {
        list($adapter, $http) = $this->adapter(
            array(self::ok(self::fixture('balance.json'))),
            array('api_key_encrypted' => 'plain-single-key'));
        $adapter->balance();

        $this->assertContains('api-key: plain-single-key', $http->calls[0]['headers']);
    }

    /* =========================== request_id ============================== */

    /**
     * VTpass validates the first 12 characters as YYYYMMDDHHII in Africa/Lagos
     * and answers 085 otherwise. A UTC clock is wrong for the hour before
     * midnight WAT — every purchase in that window would be rejected.
     */
    public function testRequestIdIsTimestampedInLagosTimeNotUtc()
    {
        list($adapter,) = $this->adapter(array());

        // 23:30 UTC on the 17th is 00:30 on the 18th in Lagos.
        $utc = new DateTime('2026-08-17 23:30:00', new DateTimeZone('UTC'));
        $id = $adapter->request_id('01J5REF0001', $utc);

        $this->assertStringStartsWith('202608180030', $id,
            'the date prefix must be West Africa Time');
        $this->assertGreaterThanOrEqual(12, strlen($id));
        $this->assertMatchesRegularExpression('/^\d{12}[A-Za-z0-9]+$/', $id);
    }

    public function testRequestIdIsAlwaysLongEnoughEvenWithoutAReference()
    {
        list($adapter,) = $this->adapter(array());
        $id = $adapter->request_id(null);
        $this->assertGreaterThan(12, strlen($id));
        $this->assertMatchesRegularExpression('/^\d{12}[A-Za-z0-9]+$/', $id);
    }

    /** Our public_id carries dashes in some formats; VTpass wants alphanumerics. */
    public function testRequestIdStripsNonAlphanumericsFromTheReference()
    {
        list($adapter,) = $this->adapter(array());
        $id = $adapter->request_id('01J5-REF/0001');
        $this->assertMatchesRegularExpression('/^\d{12}[A-Za-z0-9]+$/', $id);
    }

    /* ========================= serviceID mapping ========================= */

    /**
     * Our network codes are ours (IKEDC, 9MOBILE); VTpass has its own
     * (ikeja-electric, etisalat). Passing ours through raw is code 012,
     * "product does not exist", on every live call.
     */
    public function testOurNetworkCodesAreMappedToVtpassServiceIds()
    {
        list($adapter,) = $this->adapter(array());
        $expected = array(
            'MTN' => 'mtn', '9MOBILE' => 'etisalat', 'MTN-DATA' => 'mtn-data',
            'IKEDC' => 'ikeja-electric', 'EKEDC' => 'eko-electric',
            'PHED' => 'portharcourt-electric', 'DSTV' => 'dstv', 'WAEC' => 'waec',
        );
        foreach ($expected as $ours => $theirs) {
            $this->assertSame($theirs, $adapter->map_service_id($ours), $ours);
        }
    }

    /** A vendor account with bespoke ids overrides the table, not the code. */
    public function testServiceIdsCanBeOverriddenPerProvider()
    {
        list($adapter,) = $this->adapter(array(), array(
            'retry_policy' => json_encode(array('vtpass' => array(
                'service_ids' => array('IKEDC' => 'ikeja-electric-v2')))),
        ));
        $this->assertSame('ikeja-electric-v2', $adapter->map_service_id('IKEDC'));
        $this->assertSame('mtn', $adapter->map_service_id('MTN'), 'other codes keep the default');
    }

    public function testAirtimeSendsTheMappedServiceIdAndPlainAmount()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('airtime_success.json'))));
        $adapter->airtime(array('network_code' => '9MOBILE', 'msisdn' => '08011111111',
                                'amount' => '1000.00000000', 'reference' => '01J5REF0001'));

        $body = $http->calls[0]['data'];
        $this->assertSame('etisalat', $body['serviceID']);
        $this->assertSame('08011111111', $body['phone']);
        // VTpass rejects "1000.00000000"; it wants a plain number.
        $this->assertSame('1000', $body['amount']);
        $this->assertArrayHasKey('request_id', $body);
    }

    /* ============================ purchases ============================== */

    public function testASuccessfulAirtimePurchaseIsReportedSuccessful()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('airtime_success.json'))));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                       'amount' => '1000', 'reference' => '01J5REF0001'));

        $this->assertTrue($res['ok']);
        $this->assertSame('SUCCESSFUL', $res['status']);
        // total_amount is what VTpass actually charged us, and is the number
        // margin reporting has to use.
        $this->assertSame('970.00000000', $res['cost']);
    }

    /**
     * The reference we hand back is the requery key. VTpass requeries by the
     * request_id *we sent*, so returning its transactionId would strand every
     * async purchase in PROCESSING forever.
     */
    public function testTheReturnedReferenceIsTheRequestIdNotTheProviderTransactionId()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('airtime_success.json'))));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                       'amount' => '1000', 'reference' => '01J5REF0001'));

        $this->assertSame($http->calls[0]['data']['request_id'], $res['reference']);
        $this->assertNotSame('17452788191234567890', $res['reference']);
        // It is still recorded, because support quotes it to VTpass.
        $this->assertSame('17452788191234567890', $res['detail']['provider_transaction_id']);
    }

    public function testCode099IsAcceptedAsInFlightRatherThanFailed()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('airtime_pending.json'))));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '201000000000',
                                       'amount' => '1000', 'reference' => '01J5REF0002'));

        $this->assertTrue($res['ok'], '099 means accepted, not rejected');
        $this->assertSame('PROCESSING', $res['status']);
    }

    public function testATerminalProviderCodeRejectsWithAReadableReason()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('airtime_low_balance.json'))));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                       'amount' => '1000', 'reference' => '01J5REF0003'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('balance', $res['error']);
        $this->assertStringNotContainsString('SK_', $res['error'], 'never leak a key into an error');
    }

    /**
     * The single most expensive case. A timeout is not evidence the purchase
     * failed — VTpass says to requery. Reporting ok:false here makes
     * TransactionEngine refund a customer who did receive their airtime.
     */
    public function testATimeoutIsTreatedAsInFlightNotAsAFailure()
    {
        // SecureHttpClient reports an exhausted retry budget as http_code 0.
        list($adapter,) = $this->adapter(array(
            array('http_code' => 0, 'body' => null, 'error' => 'Operation timed out'),
        ));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '300000000000',
                                       'amount' => '1000', 'reference' => '01J5REF0004'));

        $this->assertTrue($res['ok'], 'a timeout must not refund a possibly-delivered purchase');
        $this->assertSame('PROCESSING', $res['status']);
        $this->assertNotEmpty($res['reference'], 'it must still be requeryable');
    }

    /** 5xx after the retry budget is the same class of unknown. */
    public function testAServerErrorIsAlsoTreatedAsInFlight()
    {
        list($adapter,) = $this->adapter(array(array('http_code' => 502, 'body' => 'Bad Gateway')));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                       'amount' => '1000', 'reference' => '01J5REF0005'));

        $this->assertTrue($res['ok']);
        $this->assertSame('PROCESSING', $res['status']);
    }

    /** 200 with an HTML maintenance page is ambiguous, not a clean failure. */
    public function testAnUnparseableBodyIsTreatedAsInFlight()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('maintenance.html'))));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                       'amount' => '1000', 'reference' => '01J5REF0006'));

        $this->assertTrue($res['ok']);
        $this->assertSame('PROCESSING', $res['status']);
    }

    /** Bad credentials are terminal: retrying cannot help, and money is safe. */
    public function testRejectedCredentialsFailFastWithoutRefundAmbiguity()
    {
        list($adapter,) = $this->adapter(array(array('http_code' => 401, 'body' => 'Unauthorized')));
        $res = $adapter->airtime(array('network_code' => 'MTN', 'msisdn' => '08011111111',
                                       'amount' => '1000', 'reference' => '01J5REF0007'));

        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('credentials', $res['error']);
    }

    /* ============================ electricity ============================ */

    public function testElectricityExtractsTheTokenAndUnits()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('electricity_success.json'))));
        $res = $adapter->electricity(array(
            'disco_code' => 'IKEDC', 'meter' => '1111111111111', 'meter_type' => 'PREPAID',
            'amount' => '5000', 'phone' => '08011111111', 'reference' => '01J5REF0010',
        ));

        $this->assertTrue($res['ok']);
        $this->assertSame('SUCCESSFUL', $res['status']);
        // "Token : 2636 …" — the label is VTpass's, not part of the token.
        $this->assertSame('2636 3242 1231 6721 6721', $res['detail']['token']);
        $this->assertSame('79.9 kWh', $res['detail']['units']);

        $body = $http->calls[0]['data'];
        $this->assertSame('ikeja-electric', $body['serviceID']);
        $this->assertSame('1111111111111', $body['billersCode']);
        // VTpass carries the meter type as a lowercase variation_code.
        $this->assertSame('prepaid', $body['variation_code']);
    }

    /* ============================== verify =============================== */

    public function testMeterVerificationReturnsTheCustomerName()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('verify_meter_prepaid.json'))));
        $res = $adapter->verify(array('disco_code' => 'IKEDC', 'meter' => '1111111111111',
                                      'meter_type' => 'PREPAID'));

        $this->assertTrue($res['ok']);
        $this->assertSame('ABDULLAHI MUSA', $res['name']);
        $this->assertSame('12 KANO ROAD MINNA', $res['address']);
        $this->assertSame('/merchant-verify', $http->calls[0]['path']);
        $this->assertSame('prepaid', $http->calls[0]['data']['type']);
    }

    /**
     * VTpass flags a bad meter inside a 000 response rather than with an error
     * code. Reading only `code` would show the customer a blank name and let
     * them pay into a meter that does not exist.
     */
    public function testAWrongBillersCodeIsAFailedVerificationDespiteCode000()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('verify_meter_wrong.json'))));
        $res = $adapter->verify(array('disco_code' => 'IKEDC', 'meter' => '9999999999999',
                                      'meter_type' => 'PREPAID'));

        $this->assertFalse($res['ok']);
        $this->assertNotEmpty($res['error']);
    }

    public function testSmartcardVerificationDoesNotSendAMeterType()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('verify_smartcard.json'))));
        $res = $adapter->verify(array('provider_code' => 'DSTV', 'smartcard' => '1212121212'));

        $this->assertTrue($res['ok']);
        $this->assertSame('NGOZI OKAFOR', $res['name']);
        $this->assertArrayNotHasKey('type', $http->calls[0]['data'],
            'a smartcard lookup has no prepaid/postpaid dimension');
    }

    /* ============================= education ============================= */

    /** WAEC returns cards[]; collapsing that to one token loses the PINs. */
    public function testExamPinsCaptureEveryCardNotJustTheFirst()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('waec_success.json'))));
        $res = $adapter->education(array('exam_code' => 'WAEC', 'variation_code' => 'waecdirect',
                                         'quantity' => 2, 'phone' => '08011111111',
                                         'reference' => '01J5REF0020'));

        $this->assertTrue($res['ok']);
        $this->assertCount(2, $res['detail']['cards']);
        $this->assertStringContainsString('260516349117', $res['detail']['cards'][0]);
        $this->assertStringContainsString('260516349118', $res['detail']['cards'][1]);
    }

    /* ============================== requery ============================== */

    public function testRequerySendsTheRequestIdWeStored()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('requery_delivered.json'))));
        $res = $adapter->status('202608171831SANDBOXB2');

        $this->assertSame('/requery', $http->calls[0]['path']);
        $this->assertSame('202608171831SANDBOXB2', $http->calls[0]['data']['request_id']);
        $this->assertSame('SUCCESSFUL', $res['status']);
    }

    public function testAReversedTransactionSettlesAsFailedSoTheCustomerIsRefunded()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('requery_reversed.json'))));
        $res = $adapter->status('202608171832SANDBOXC3');

        $this->assertTrue($res['ok']);
        $this->assertSame('FAILED', $res['status']);
    }

    /**
     * 015 means VTpass never saw the request — so the /pay that timed out
     * never executed, and the customer must get their money back.
     */
    public function testAnUnknownRequestIdSettlesAsFailed()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('requery_unknown_request.json'))));
        $res = $adapter->status('202608179999NOTAREALID');

        $this->assertTrue($res['ok']);
        $this->assertSame('FAILED', $res['status']);
    }

    /** An unreachable provider must not be read as "the purchase failed". */
    public function testRequeryFailureLeavesTheTransactionAloneRatherThanFailingIt()
    {
        list($adapter,) = $this->adapter(array(
            array('http_code' => 0, 'body' => null, 'error' => 'timed out'),
        ));
        $res = $adapter->status('202608171831SANDBOXB2');

        $this->assertFalse($res['ok'], 'ok:false is what makes CronWorkers skip it');
        $this->assertArrayNotHasKey('status', $res,
            'no status at all is safer than guessing one');
    }

    /* ============================== balance ============================== */

    /** VTpass answers `contents` (plural) and a numeric code 1, not "000". */
    public function testBalanceReadsTheVtpassShape()
    {
        list($adapter,) = $this->adapter(array(self::ok(self::fixture('balance.json'))));
        $res = $adapter->balance();

        $this->assertTrue($res['ok']);
        $this->assertSame('1081.82000000', $res['balance']);
        $this->assertSame('NGN', $res['currency']);
    }

    /* ============================ catalogue ============================== */

    public function testVariationsAreNormalisedForTheCatalogueSync()
    {
        list($adapter, $http) = $this->adapter(array(self::ok(self::fixture('variations_dstv.json'))));
        $res = $adapter->variations('DSTV');

        $this->assertTrue($res['ok']);
        $this->assertSame('dstv', $res['service_id']);
        $this->assertCount(3, $res['variations']);
        $this->assertSame('dstv79', $res['variations'][1]['variation_code']);
        $this->assertSame('7900.00000000', $res['variations'][1]['amount']);
        $this->assertStringContainsString('serviceID=dstv', $http->calls[0]['path']);
        $this->assertSame('GET', $http->calls[0]['method']);
    }

    /* ======================== registry + wiring ========================== */

    public function testTheRegistryBuildsAVtpassAdapterForTheVtuFamily()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('Provider_manager');

        $this->assertContains('VTPASS',
            Provider_manager::supported_types(Provider_manager::FAMILY_VTU));
        $this->assertNotContains('VTPASS',
            Provider_manager::supported_types(Provider_manager::FAMILY_SMM),
            'a VTU vendor cannot service an SMM order');

        $adapter = $app->provider_manager->adapter($this->provider(), Provider_manager::FAMILY_VTU);
        $this->assertInstanceOf('VtpassAdapter', $adapter);
        $this->assertInstanceOf('VtuProviderInterface', $adapter);
    }

    public function testVtpassImplementsEveryVtuServiceType()
    {
        foreach (array('airtime','data','cable','electricity','education','verify','status','balance') as $m) {
            $this->assertTrue(method_exists('VtpassAdapter', $m),
                'VtpassAdapter must implement '.$m.'()');
        }
    }

    /**
     * The admin create form and the validator must agree with the registry.
     * They used to be two hardcoded lists, so adding an adapter silently left
     * it unselectable — or worse, selectable and unbuildable.
     */
    public function testTheAdminApiTypeWhitelistComesFromTheRegistry()
    {
        require_once self::$root.'/application/libraries/Provider_manager.php';
        $src = file_get_contents(self::$root.'/application/libraries/ProviderSyncService.php');
        $this->assertStringNotContainsString("array('STANDARD_SMM','MOCK')", $src,
            'the api_type whitelist must not be a second hardcoded list');
        $this->assertStringContainsString('self::supported_types()', $src);
        $this->assertContains('VTPASS', ProviderSyncService::supported_types());
    }

    public function testTheProviderFormOffersEveryRegisteredAdapter()
    {
        $view = file_get_contents(self::$root.'/application/views/admin/providers/index.php');
        $this->assertStringContainsString('$api_types', $view,
            'the type select must be driven by the registry, not a literal list');
        $controller = file_get_contents(self::$root.'/application/controllers/admin/Providers.php');
        $this->assertStringContainsString('api_types', $controller);
        $this->assertStringContainsString('Provider_manager::supported_types', $controller);
    }

    /** VTpass is unusable with only one of its three keys. */
    public function testCreatingAVtpassProviderRequiresAllThreeKeys()
    {
        $ci = new VtpassProviderCI();
        $GLOBALS['__fake_ci'] = $ci;
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
        $svc = new ProviderSyncService();

        $bad = $svc->create_provider(array(
            'name' => 'VTpass', 'api_url' => VtpassAdapter::SANDBOX_URL,
            'api_key' => 'k', 'api_type' => 'VTPASS',
        ));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('public key', implode(' ', $bad['errors']));

        $good = $svc->create_provider(array(
            'name' => 'VTpass', 'api_url' => VtpassAdapter::SANDBOX_URL,
            'api_key' => 'k', 'public_key' => 'PK_x', 'secret_key' => 'SK_y',
            'api_type' => 'VTPASS',
        ));
        $this->assertTrue($good['ok'], implode(' ', $good['errors'] ?? array()));

        // All three land in the one encrypted column, and none in plaintext.
        $stored = $good['provider']->api_key_encrypted;
        $this->assertStringNotContainsString('SK_y', $stored);
        $decoded = json_decode($ci->encryptionservice->decrypt($stored), true);
        $this->assertSame(array('api_key' => 'k', 'public_key' => 'PK_x', 'secret_key' => 'SK_y'), $decoded);
    }

    /* ====================== end-to-end through the engine ================= */

    /**
     * The whole point of the integration: a real VTpass response, through the
     * real VtuService/TransactionEngine/LedgerService, moving real money.
     */
    public function testAVtpassAirtimePurchaseChargesTheWalletOnce()
    {
        list($app, $user) = $this->vtu_app(array(self::ok(self::fixture('airtime_success.json'))));

        $before = $app->balance($user);
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08011111111', 'amount' => '1000',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('SUCCESSFUL', $res['transaction']->status);
        // ₦1,000 face at the 2% seeded discount = ₦980 charged.
        $this->assertSame('980.00000000', bcsub($before, $app->balance($user), 8));

        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    /**
     * A timeout must leave the purchase in flight and the money spent — the
     * settlement cron decides. Refunding here would double-pay whenever the
     * airtime was in fact delivered.
     */
    public function testATimeoutLeavesThePurchaseProcessingRatherThanRefunding()
    {
        list($app, $user) = $this->vtu_app(array(
            array('http_code' => 0, 'body' => null, 'error' => 'timed out'),
        ));

        $before = $app->balance($user);
        $res = $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08011111111', 'amount' => '1000',
        ));

        $this->assertTrue($res['ok']);
        $this->assertSame('PROCESSING', $res['transaction']->status);
        $this->assertSame('980.00000000', bcsub($before, $app->balance($user), 8));
        $this->assertNotEmpty($res['transaction']->provider_reference,
            'without a reference the settlement cron can never resolve this');
    }

    /**
     * ...and the cron then settles it. 015 (VTpass never saw the request)
     * means the purchase never happened, so the customer is made whole.
     */
    public function testTheSettlementCronRefundsAPurchaseVtpassNeverReceived()
    {
        list($app, $user) = $this->vtu_app(array(
            array('http_code' => 0, 'body' => null, 'error' => 'timed out'),
            self::ok(self::fixture('requery_unknown_request.json')),
        ));

        $before = $app->balance($user);
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08011111111', 'amount' => '1000',
        ));
        $this->assertSame('980.00000000', bcsub($before, $app->balance($user), 8));

        $app->library('CronWorkers');
        $res = $app->cronworkers->vtu_status();

        $this->assertSame(1, $res['processed']);
        $tx = $app->rows('service_transactions')[0];
        // The engine records the provider's verdict and refunds on the way in;
        // REFUNDED is reserved for a deliberate admin reversal.
        $this->assertSame('FAILED', $tx['status']);
        $this->assertSame('980.00000000', $tx['refunded_amount']);
        $this->assertSame($before, $app->balance($user), 'the customer must be whole again');

        list($debits, $credits) = $app->ledger_is_balanced();
        $this->assertSame($debits, $credits);
    }

    /** And a delivered requery closes it out without touching the wallet. */
    public function testTheSettlementCronCompletesADeliveredPurchase()
    {
        list($app, $user) = $this->vtu_app(array(
            self::ok(self::fixture('airtime_pending.json')),
            self::ok(self::fixture('requery_delivered.json')),
        ));

        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08011111111', 'amount' => '1000',
        ));
        $after_purchase = $app->balance($user);

        $app->library('CronWorkers');
        $app->cronworkers->vtu_status();

        $tx = $app->rows('service_transactions')[0];
        $this->assertSame('SUCCESSFUL', $tx['status']);
        $this->assertSame($after_purchase, $app->balance($user));
    }

    /**
     * Meter verification against a real VTpass payload — wired but, until now,
     * never exercised against anything but the mock.
     */
    public function testMeterVerificationRunsThroughTheServiceLayer()
    {
        list($app,) = $this->vtu_app(array(self::ok(self::fixture('verify_meter_prepaid.json'))));

        $res = $app->vtuservice->verify(array(
            'service_type' => 'ELECTRICITY', 'network' => 'IKEDC',
            'meter' => '1111111111111', 'meter_type' => 'PREPAID',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('ABDULLAHI MUSA', $res['name']);
    }

    /**
     * A harness whose VTU provider is VTpass, with the HTTP layer scripted.
     * Provider_manager builds the adapter itself, so the fake client is
     * injected by swapping SecureHttpClient on the container.
     */
    private function vtu_app(array $script, $balance = '100000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_vtu();

        // Turn the seeded MOCK VTU provider into a VTpass one.
        $app->db->where('public_id', 'PROV0000000000000000000002')->update('providers', array(
            'api_type' => 'VTPASS',
            'api_url'  => VtpassAdapter::SANDBOX_URL,
            'api_key_encrypted' => json_encode(array(
                'api_key' => 'test-api-key', 'public_key' => 'PK_x', 'secret_key' => 'SK_y')),
        ));

        $app->securehttpclient = new VtpassFakeHttp($script);
        $app->encryptionservice = new VtpassPassthroughEncryption();

        $user = $app->register('vtpass_user', 'vtpass@x.test');
        $app->credit($user, $balance);
        $app->library('VtuService');
        return array($app, $user);
    }
}

/* -------------------------------- doubles -------------------------------- */

/**
 * Scripted stand-in for SecureHttpClient.
 *
 * Deliberately not a mock framework: it records exactly what an adapter puts
 * on the wire, which is half of what these tests assert. Running out of
 * scripted responses throws rather than returning a default, so a test that
 * makes an unexpected call fails loudly instead of passing on a guess.
 */
class VtpassFakeHttp
{
    public $calls = array();
    private $script;

    public function __construct(array $script) { $this->script = $script; }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->record('GET', $url, array(), $headers);
    }

    public function post($url, $data = null, $headers = array(), $options = array())
    {
        return $this->record('POST', $url, is_array($data) ? $data : array(), $headers);
    }

    private function record($method, $url, array $data, array $headers)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        // The adapter builds /api/pay etc; strip the base so assertions read well.
        $path = preg_replace('~^/api~', '', (string)$path);
        $this->calls[] = array(
            'method' => $method, 'url' => $url,
            'path' => $path.($query ? '?'.$query : ''),
            'data' => $data, 'headers' => $headers,
        );
        if (!$this->script) {
            throw new RuntimeException('VtpassFakeHttp: unscripted '.$method.' '.$path);
        }
        return array_shift($this->script);
    }
}

/** Minimal container for the adapter's own get_instance() credential read. */
#[AllowDynamicProperties]
class VtpassFakeCI
{
    public $load;
    public function __construct()
    {
        $this->load = new VtpassFakeLoader($this);
        $this->encryptionservice = new VtpassPassthroughEncryption();
    }
}

class VtpassFakeLoader
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function library($n, $p = null, $o = null) { return $this; }
    public function model($n, $a = null, $d = false) { return $this; }
    public function helper($n = '') { return $this; }
}

/** The credential blob is stored encrypted; the tests care about the shape. */
class VtpassPassthroughEncryption
{
    public function encrypt($plain) { return 'enc:'.base64_encode((string)$plain); }
    public function decrypt($blob)
    {
        return strpos((string)$blob, 'enc:') === 0
            ? base64_decode(substr((string)$blob, 4)) : (string)$blob;
    }
}

/** Container for ProviderSyncService::create_provider() validation tests. */
#[AllowDynamicProperties]
class VtpassProviderCI
{
    public $db;
    public $load;
    public $input;
    public $auth;
    public $Provider_model;
    public $Audit_log_model;
    public function __construct()
    {
        $GLOBALS['__fake_ci'] = $this;
        $this->db = new VtpassNullDb();
        $this->encryptionservice = new VtpassPassthroughEncryption();
        $this->input = new VtpassNullInput();
        $this->auth = null;
        $this->Provider_model = new VtpassNullProviderModel();
        $this->Audit_log_model = new VtpassNullAudit();
        $this->load = new VtpassFakeLoader($this);
    }
}
class VtpassNullProviderModel
{
    public function create($data) { return (object)array_merge(array('id' => 1), $data); }
    public function record_health() {}
    public function record_sync() {}
}
class VtpassNullAudit { public function record() {} }
class VtpassNullInput
{
    public function ip_address() { return '127.0.0.1'; }
    public function user_agent() { return 'PHPUnit'; }
}
class VtpassNullDb
{
    public function where($k, $v = null) { return $this; }
    public function insert($t, $d) { return true; }
    public function insert_id() { return 1; }
    public function update($t, $d) { return true; }
    public function trans_start() {}
    public function trans_complete() {}
}
