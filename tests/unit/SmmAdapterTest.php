<?php
use PHPUnit\Framework\TestCase;

/**
 * StandardSmmAdapter — the adapter every SMM order in the panel goes through.
 *
 * SMM panels answer **HTTP 200 with `{"error": "..."}`** for a wrong API key,
 * an unknown order id, a service that no longer exists — everything. The
 * adapter used to hand those straight back as `ok = true`, so:
 *
 *   - the health probe recorded a provider with bad credentials as ONLINE;
 *   - a catalogue sync against a rejecting provider "succeeded" with 0 rows;
 *   - a refill or cancellation the provider refused was reported to the
 *     customer as accepted;
 *   - a maintenance HTML page decoded to null and was treated as valid data.
 *
 * These tests pin the distinction between transport failure, provider refusal
 * and a real payload, plus the batching the status poller depends on. No
 * network: the HTTP client is a scripted double.
 */
class SmmAdapterTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/StandardSmmAdapter.php';
        require_once self::$root.'/application/libraries/MockProviderAdapter.php';
    }

    /* ------------------------------ helpers ------------------------------ */

    private function adapter(array $responses)
    {
        $GLOBALS['__fake_ci'] = new SmmFakeCI();
        $provider = (object)array(
            'id' => 3, 'name' => 'Acme SMM', 'api_url' => 'https://panel.example/api/v2',
            'api_key_encrypted' => 'encrypted', 'timeout_ms' => 15000, 'currency' => 'USD',
        );
        $http = new SmmFakeHttp($responses);
        return array(new StandardSmmAdapter($provider, $http), $http);
    }

    private function json($payload, $code = 200)
    {
        return array('http_code' => $code, 'body' => json_encode($payload));
    }

    /* --------------------------- refusal handling ------------------------ */

    public function testAnErrorEnvelopeWithHttp200IsAFailureNotData()
    {
        list($adapter) = $this->adapter(array($this->json(array('error' => 'Incorrect API key'))));
        $res = $adapter->getBalance();

        $this->assertFalse($res['ok'], 'a wrong API key must not read as a healthy provider');
        $this->assertSame('Incorrect API key', $res['error']);
    }

    public function testTheAlternativeStatusErrorEnvelopeIsAlsoARefusal()
    {
        list($adapter) = $this->adapter(array($this->json(array('status' => 'error', 'message' => 'Service disabled'))));
        $res = $adapter->getServices();
        $this->assertFalse($res['ok']);
        $this->assertSame('Service disabled', $res['error']);
    }

    public function testAnHtmlMaintenancePageIsNotAnEmptyCatalogue()
    {
        list($adapter) = $this->adapter(array(array('http_code' => 200, 'body' => '<html>maintenance</html>')));
        $res = $adapter->getServices();

        $this->assertFalse($res['ok'], 'unparseable output must never look like "no services"');
        $this->assertStringContainsString('not JSON', $res['error']);
    }

    public function testTransportFailureAndHttpErrorsAreReported()
    {
        list($adapter) = $this->adapter(array(array('http_code' => 0, 'body' => null, 'error' => 'timeout')));
        $this->assertFalse($adapter->getBalance()['ok']);

        list($adapter2) = $this->adapter(array(array('http_code' => 502, 'body' => 'bad gateway')));
        $res = $adapter2->getBalance();
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('502', $res['error']);
    }

    /* ------------------------------- balance ----------------------------- */

    public function testBalanceIsNormalisedAndCarriesTheCurrency()
    {
        list($adapter, $http) = $this->adapter(array($this->json(array('balance' => '123.45', 'currency' => 'USD'))));
        $res = $adapter->getBalance();

        $this->assertTrue($res['ok']);
        $this->assertSame('123.45', $res['data']['balance']);
        $this->assertSame('USD', $res['data']['currency']);
        $this->assertSame('balance', $http->calls[0]['payload']['action']);
        $this->assertSame('decrypted-key', $http->calls[0]['payload']['key'], 'the stored key is decrypted per call');
    }

    public function testABalanceResponseWithoutABalanceIsAFailure()
    {
        list($adapter) = $this->adapter(array($this->json(array('currency' => 'USD'))));
        $this->assertFalse($adapter->getBalance()['ok']);
    }

    /* -------------------------------- orders ----------------------------- */

    public function testCreateOrderReturnsTheProviderOrderId()
    {
        list($adapter, $http) = $this->adapter(array($this->json(array('order' => 23501, 'charge' => '0.27'))));
        $res = $adapter->createOrder(array('service' => 12, 'link' => 'https://x/y', 'quantity' => 1000));

        $this->assertTrue($res['ok']);
        $this->assertSame('23501', $res['provider_order_id']);
        $this->assertSame('0.27', $res['charge']);
        $this->assertSame('add', $http->calls[0]['payload']['action']);
        $this->assertSame(1000, $http->calls[0]['payload']['quantity']);
    }

    public function testAnOrderAcceptedWithoutAnIdIsTreatedAsFailed()
    {
        // Without an id we could never poll, refill or cancel it — charging the
        // customer for an order we cannot track is worse than refusing.
        list($adapter) = $this->adapter(array($this->json(array('ok' => true))));
        $res = $adapter->createOrder(array('service' => 1, 'link' => 'x', 'quantity' => 10));
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('no order id', $res['error']);
    }

    public function testARejectedOrderCarriesTheProvidersReason()
    {
        list($adapter) = $this->adapter(array($this->json(array('error' => 'Not enough funds'))));
        $res = $adapter->createOrder(array('service' => 1, 'link' => 'x', 'quantity' => 10));
        $this->assertFalse($res['ok']);
        $this->assertSame('Not enough funds', $res['error']);
    }

    /* ------------------------------- status ------------------------------ */

    public function testSingleOrderStatusIsKeyedLikeTheBatchShape()
    {
        list($adapter) = $this->adapter(array($this->json(array(
            'status' => 'In progress', 'charge' => '0.27', 'start_count' => '3572', 'remains' => '157',
        ))));
        $res = $adapter->getOrderStatus('23501');

        $this->assertTrue($res['ok']);
        $this->assertArrayHasKey('23501', $res['data'], 'callers must not need two shapes');
        $this->assertSame('In progress', $res['data']['23501']['status']);
        $this->assertSame('157', $res['data']['23501']['remains']);
    }

    public function testBatchStatusIsChunkedToTheProviderLimit()
    {
        $ids = array();
        for ($i = 1; $i <= 250; $i++) $ids[] = (string)$i;

        $responses = array();
        foreach (array_chunk($ids, 100) as $chunk) {
            $payload = array();
            foreach ($chunk as $id) $payload[$id] = array('status' => 'Completed');
            $responses[] = $this->json($payload);
        }
        list($adapter, $http) = $this->adapter($responses);
        $res = $adapter->getMultipleOrderStatus($ids);

        $this->assertTrue($res['ok']);
        $this->assertCount(3, $http->calls, '250 ids must be split across three requests');
        $this->assertCount(250, $res['data'], 'every id must come back');
        // A panel that rejects an oversized batch rejects the WHOLE batch, so
        // an unchunked poller silently stops updating orders.
        $this->assertLessThanOrEqual(100,
            count(explode(',', $http->calls[0]['payload']['orders'])));
    }

    public function testAListShapedBatchResponseIsKeyedByItsOwnOrderIds()
    {
        list($adapter) = $this->adapter(array($this->json(array(
            array('order' => 11, 'status' => 'Completed'),
            array('order' => 12, 'status' => 'Partial', 'remains' => '40'),
        ))));
        $res = $adapter->getMultipleOrderStatus(array('11', '12'));

        $this->assertSame('Completed', $res['data']['11']['status']);
        $this->assertSame('40', $res['data']['12']['remains']);
    }

    public function testOneBadChunkDoesNotLoseTheGoodOnes()
    {
        $ids = array();
        for ($i = 1; $i <= 150; $i++) $ids[] = (string)$i;

        $first = array();
        foreach (array_slice($ids, 0, 100) as $id) $first[$id] = array('status' => 'Completed');

        list($adapter) = $this->adapter(array(
            $this->json($first),
            $this->json(array('error' => 'Incorrect order IDs')),
        ));
        $res = $adapter->getMultipleOrderStatus($ids);

        $this->assertTrue($res['ok'], 'one bad id must not stall the whole queue');
        $this->assertCount(100, $res['data']);
    }

    public function testAnEmptyIdListNeverCallsTheProvider()
    {
        list($adapter, $http) = $this->adapter(array());
        $res = $adapter->getMultipleOrderStatus(array());
        $this->assertTrue($res['ok']);
        $this->assertSame(array(), $res['data']);
        $this->assertCount(0, $http->calls);
    }

    /* ------------------------------- refills ----------------------------- */

    public function testRefillAcceptsBothDocumentedShapes()
    {
        list($flat) = $this->adapter(array($this->json(array('refill' => 42))));
        $this->assertSame('42', $flat->requestRefill('23501')['provider_refill_id']);

        list($list) = $this->adapter(array($this->json(array(array('order' => 23501, 'refill' => 43)))));
        $this->assertSame('43', $list->requestRefill('23501')['provider_refill_id']);
    }

    public function testARefusedRefillIsNotReportedAsAccepted()
    {
        list($adapter) = $this->adapter(array($this->json(array('error' => 'Incorrect order ID'))));
        $res = $adapter->requestRefill('nope');
        $this->assertFalse($res['ok']);
        $this->assertSame('Incorrect order ID', $res['error']);
    }

    public function testRefillStatusIsFlattenedToAStatusString()
    {
        list($flat) = $this->adapter(array($this->json(array('status' => 'Completed'))));
        $this->assertSame('Completed', $flat->getRefillStatus('42')['data']['status']);

        list($nested) = $this->adapter(array($this->json(array(
            array('refill' => 42, 'status' => array('status' => 'In progress')),
        ))));
        $this->assertSame('In progress', $nested->getRefillStatus('42')['data']['status']);
    }

    /* ---------------------------- cancellation --------------------------- */

    public function testARefusedCancellationIsAFailure()
    {
        list($adapter) = $this->adapter(array($this->json(array(
            array('order' => 9, 'cancel' => array('error' => 'Incorrect order ID')),
        ))));
        $res = $adapter->requestCancel('9');

        $this->assertFalse($res['ok'], 'the customer must not be told an order was cancelled when it was not');
        $this->assertSame('Incorrect order ID', $res['error']);
    }

    public function testAnAcceptedCancellationSucceeds()
    {
        list($adapter, $http) = $this->adapter(array($this->json(array(
            array('order' => 9, 'cancel' => 1),
        ))));
        $res = $adapter->requestCancel(array('9', '10'));

        $this->assertTrue($res['ok']);
        $this->assertSame('9,10', $http->calls[0]['payload']['orders']);
    }

    /* ------------------------------ contracts ---------------------------- */

    public function testTheMockAdapterAnswersInTheSameShapesAsTheRealOne()
    {
        // Development and the test suite run on the mock; if its shapes drift
        // from the real adapter, every dry run proves nothing.
        $mock = new MockProviderAdapter();

        $status = $mock->getOrderStatus('abc');
        $this->assertTrue($status['ok']);
        $this->assertArrayHasKey('abc', $status['data'],
            'the mock must key single status by order id, like the real adapter');

        $batch = $mock->getMultipleOrderStatus(array('a', 'b'));
        $this->assertSame(array('a', 'b'), array_keys($batch['data']));

        $services = $mock->getServices();
        $this->assertTrue($services['ok']);
        $this->assertSame(0, array_keys($services['data'])[0], 'the catalogue is a list');

        $balance = $mock->getBalance();
        $this->assertArrayHasKey('balance', $balance['data']);
        $this->assertArrayHasKey('currency', $balance['data']);
    }

    public function testEveryInterfaceMethodIsImplemented()
    {
        $interface = new ReflectionClass('ProviderAdapterInterface');
        foreach (array('StandardSmmAdapter', 'MockProviderAdapter') as $class) {
            foreach ($interface->getMethods() as $method) {
                $this->assertTrue(method_exists($class, $method->getName()),
                    $class.' must implement '.$method->getName().'()');
            }
        }
    }
}

/* -------------------------------- doubles -------------------------------- */

#[AllowDynamicProperties]
class SmmFakeCI
{
    public $load, $encryptionservice;
    public function __construct()
    {
        $this->load = new SmmFakeLoader();
        $this->encryptionservice = new SmmFakeEncryption();
    }
}
class SmmFakeLoader { public function library($n = null) {} public function model($n = null) {} }
class SmmFakeEncryption { public function decrypt($v) { return 'decrypted-key'; } }

/** Scripted HTTP: records each POST and returns the next queued response. */
class SmmFakeHttp
{
    public $calls = array();
    private $responses;

    public function __construct(array $responses) { $this->responses = $responses; }

    public function post($url, $payload = null, $headers = array(), $options = array())
    {
        $this->calls[] = array('url' => $url, 'payload' => $payload);
        $next = array_shift($this->responses);
        if ($next === null) return array('http_code' => 0, 'body' => null, 'error' => 'no scripted response');
        return $next;
    }

    public function get($url, $headers = array(), $options = array())
    {
        return $this->post($url, null, $headers, $options);
    }
}
