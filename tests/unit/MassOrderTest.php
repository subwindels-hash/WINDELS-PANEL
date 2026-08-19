<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/** Complete mass-order parser, orchestration, replay, and delivery contract. */
class MassOrderTest extends TestCase
{
    private static $root;
    private $app;
    private $user;
    private $service;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    protected function setUp(): void
    {
        $this->app = new IntegrationHarness();
        $this->app->seed_minimal();
        $this->user = $this->app->register('bulkuser', 'bulk@example.test');
        $this->app->credit($this->user, '10.00000000', 'mass:test:credit');
        $this->service = $this->app->db->where('slug', 'instagram-followers')->get('services')->row();
        $this->app->library('MassOrderService');
    }

    public function testValidRowsUseRealOrderEngineAndChargeExactTotal()
    {
        $result = $this->app->massorderservice->process_text(
            $this->user,
            "instagram-followers|https://example.test/a|100\n{$this->service->public_id}|https://example.test/b|200",
            $this->token('happy')
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['successful_count']);
        $this->assertSame(0, $result['failed_count']);
        $this->assertSame('9.40000000', $this->app->balance($this->user));
        $this->assertCount(2, $this->app->rows('orders'));
        $this->assertCount(2, $this->app->provider_calls);
        foreach ($this->app->rows('orders') as $order) {
            $this->assertSame('MASS', $order['source']);
            $this->assertStringStartsWith('mass-'.$this->user->id.'-', $order['idempotency_key']);
        }
        $this->assertTrue($this->app->ledger_is_balanced()[0] === $this->app->ledger_is_balanced()[1]);
    }

    public function testMalformedAndInvalidRowsDoNotBlockValidRows()
    {
        $text = "bad row\n\ninstagram-followers|https://example.test/good|100\n"
              ."instagram-followers|not-a-url|100";
        $result = $this->app->massorderservice->process_text($this->user, $text, $this->token('partial'));

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['successful_count']);
        $this->assertSame(2, $result['failed_count']);
        $this->assertSame(1, $result['failed'][0]['row']);
        $this->assertSame('BAD_FORMAT', $result['failed'][0]['code']);
        $this->assertSame(4, $result['failed'][1]['row']);
        $this->assertSame('BAD_LINK', $result['failed'][1]['code']);
        $this->assertSame('9.80000000', $this->app->balance($this->user));
        $this->assertCount(1, $this->app->provider_calls);
    }

    public function testStrictQuantityParsingRejectsCastableGarbageBeforeOrderEngine()
    {
        $result = $this->app->massorderservice->process_text(
            $this->user,
            'instagram-followers|https://example.test/a|100abc',
            $this->token('quantity')
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['successful_count']);
        $this->assertSame('BAD_QUANTITY', $result['failed'][0]['code']);
        $this->assertSame('10.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $this->app->rows('orders'));
        $this->assertCount(0, $this->app->provider_calls);
    }

    public function testExactRetryReturnsSavedResultWithoutChargeOrProviderReplay()
    {
        $token = $this->token('retry');
        $text = 'instagram-followers|https://example.test/retry|100';
        $first = $this->app->massorderservice->process_text($this->user, $text, $token);
        $balance = $this->app->balance($this->user);
        $second = $this->app->massorderservice->process_text($this->user, $text, $token);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['successful'], $second['successful']);
        $this->assertSame($balance, $this->app->balance($this->user));
        $this->assertCount(1, $this->app->rows('orders'));
        $this->assertCount(1, $this->app->provider_calls);
        $this->assertCount(1, $this->app->rows('mass_order_batches'));
    }

    public function testModifiedReplayWithConsumedTokenIsRejectedBeforeCharge()
    {
        $token = $this->token('conflict');
        $first = $this->app->massorderservice->process_text(
            $this->user, 'instagram-followers|https://example.test/one|100', $token
        );
        $balance = $this->app->balance($this->user);
        $second = $this->app->massorderservice->process_text(
            $this->user, 'instagram-followers|https://example.test/two|200', $token
        );

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('BATCH_TOKEN_CONFLICT', $second['code']);
        $this->assertSame($balance, $this->app->balance($this->user));
        $this->assertCount(1, $this->app->rows('orders'));
        $this->assertCount(1, $this->app->provider_calls);
    }

    public function testSameClientTokenIsScopedToAuthenticatedUser()
    {
        $other = $this->app->register('bulkother', 'bulkother@example.test');
        $this->app->credit($other, '1.00000000', 'mass:test:other');
        $token = $this->token('scoped');
        $text = 'instagram-followers|https://example.test/scoped|100';

        $one = $this->app->massorderservice->process_text($this->user, $text, $token);
        $two = $this->app->massorderservice->process_text($other, $text, $token);

        $this->assertTrue($one['ok']);
        $this->assertTrue($two['ok']);
        $this->assertSame('9.80000000', $this->app->balance($this->user));
        $this->assertSame('0.80000000', $this->app->balance($other));
        $this->assertCount(2, $this->app->rows('mass_order_batches'));
        $this->assertNotSame(
            $this->app->rows('orders')[0]['idempotency_key'],
            $this->app->rows('orders')[1]['idempotency_key']
        );
    }

    public function testRowLimitIsRejectedBeforeAnyChargeOrBatchClaim()
    {
        $rows = array_fill(0, MassOrderService::MAX_ROWS + 1,
            'instagram-followers|https://example.test/limit|100');
        $result = $this->app->massorderservice->process_text(
            $this->user, implode("\n", $rows), $this->token('row-limit')
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('TOO_MANY_ROWS', $result['code']);
        $this->assertSame('10.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $this->app->rows('orders'));
        $this->assertCount(0, $this->app->rows('mass_order_batches'));
        $this->assertCount(0, $this->app->provider_calls);
    }

    public function testPayloadLimitIsRejectedBeforeAnyCharge()
    {
        $result = $this->app->massorderservice->process_text(
            $this->user,
            str_repeat('x', MassOrderService::MAX_BYTES + 1),
            $this->token('byte-limit')
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('PAYLOAD_TOO_LARGE', $result['code']);
        $this->assertSame('10.00000000', $this->app->balance($this->user));
        $this->assertCount(0, $this->app->rows('mass_order_batches'));
    }

    public function testProviderFailureReturnsOrderIdAndRefundsTheRow()
    {
        $this->app->provider_responses['createOrder'] = array('ok'=>false, 'error'=>'Provider unavailable');
        $token = $this->token('provider-fail');
        $text = 'instagram-followers|https://example.test/provider-fail|100';
        $result = $this->app->massorderservice->process_text($this->user, $text, $token);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['successful_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertSame('SUBMIT_FAILED', $result['failed'][0]['code']);
        $this->assertNotEmpty($result['failed'][0]['order']);
        $this->assertSame('FAILED', $this->app->rows('orders')[0]['status']);
        $this->assertSame('10.00000000', $this->app->balance($this->user));
        $this->assertTrue($this->app->ledger_is_balanced()[0] === $this->app->ledger_is_balanced()[1]);

        $replay = $this->app->massorderservice->process_text($this->user, $text, $token);
        $this->assertTrue($replay['replayed']);
        $this->assertSame('SUBMIT_FAILED', $replay['failed'][0]['code']);
        $this->assertCount(1, $this->app->provider_calls);
        $this->assertSame('10.00000000', $this->app->balance($this->user));
    }

    public function testLaterInsufficientBalanceDoesNotRollbackEarlierSuccess()
    {
        $poor = $this->app->register('bulkpoor', 'bulkpoor@example.test');
        $this->app->credit($poor, '0.30000000', 'mass:test:poor');
        $text = "instagram-followers|https://example.test/first|100\n"
              ."instagram-followers|https://example.test/second|100";
        $result = $this->app->massorderservice->process_text($poor, $text, $this->token('balance'));

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['successful_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertSame('INSUFFICIENT_BALANCE', $result['failed'][0]['code']);
        $this->assertSame('0.10000000', $this->app->balance($poor));
        $this->assertCount(1, $this->app->provider_calls);
    }

    public function testApiInstructionArrayUsesSamePipeline()
    {
        $token = $this->token('api');
        $instructions = array(
            array('service'=>'instagram-followers', 'link'=>'https://example.test/api', 'quantity'=>100),
            'not-an-object',
        );
        $result = $this->app->massorderservice->process_instructions($this->user, $instructions, $token);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['successful_count']);
        $this->assertSame(1, $result['failed_count']);
        $this->assertSame('BAD_FORMAT', $result['failed'][0]['code']);
        $this->assertSame('MASS', $this->app->rows('orders')[0]['source']);

        // The request hash covers the complete API instruction, including
        // values that are not forwarded to OrderService.
        $instructions[0]['clientMetadata'] = 'modified replay';
        $conflict = $this->app->massorderservice->process_instructions($this->user, $instructions, $token);
        $this->assertFalse($conflict['ok']);
        $this->assertSame('BATCH_TOKEN_CONFLICT', $conflict['code']);
        $this->assertCount(1, $this->app->rows('orders'));
    }

    public function testMigrationRoutesControllersViewsAndFeatureGateAreWired()
    {
        $root = dirname(dirname(__DIR__));
        $routes = file_get_contents($root.'/application/config/routes.php');
        $dashboard = file_get_contents($root.'/application/controllers/dashboard/Orders.php');
        $api = file_get_contents($root.'/application/controllers/Api_v1.php');
        $layout = file_get_contents($root.'/application/views/layouts/app.php');
        $view = file_get_contents($root.'/application/views/dashboard/orders/mass_order.php');
        $sql = implode("\n", Migration_Mass_orders::statements());

        $this->assertStringContainsString("'dashboard/mass-order/create'", $routes);
        $this->assertLessThan(
            strpos($routes, "'dashboard/mass-order'"),
            strpos($routes, "'dashboard/mass-order/create'")
        );
        $this->assertStringContainsString("'api/v1/orders/mass'", $routes);
        $this->assertLessThan(
            strpos($routes, "'api/v1/orders/(:any)'"),
            strpos($routes, "'api/v1/orders/mass'")
        );
        $this->assertStringContainsString('function mass_create(', $dashboard);
        $this->assertStringContainsString("enabled('mass_order')", $dashboard);
        $this->assertStringContainsString('massorderservice->process_text', $dashboard);
        $this->assertStringContainsString('function create_mass_order(', $api);
        $this->assertStringContainsString('massorderservice->process_instructions', $api);
        $this->assertStringContainsString('dashboard/mass-order', $layout);
        $this->assertStringContainsString("form_open('dashboard/mass-order/create'", $view);
        $this->assertStringContainsString('mass_order_batches', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_mass_order_batch_token (user_id, token_hash)', $sql);
        $this->assertStringContainsString('result_json MEDIUMTEXT', $sql);
        $this->assertStringContainsString("\$config['migration_version'] = 19;", file_get_contents($root.'/application/config/migration.php'));
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS mass_order_batches', file_get_contents($root.'/docs/database.sql'));
    }

    private function token($suffix)
    {
        return 'mass-test-'.str_pad($suffix, 20, 'x');
    }
}
