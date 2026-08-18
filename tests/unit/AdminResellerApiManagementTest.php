<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/** Admin reseller-key policy, safe reads, revocation, and runtime contracts. */
class AdminResellerApiManagementTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/libraries/ApiKeyPolicy.php';
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $user = $app->register('reseller', 'reseller@example.test');
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('api_keys', array(
            'public_id'=>'KEY00000000000000000000001',
            'user_id'=>$user->id,
            'name'=>'Production integration',
            'key_hash'=>hash('sha256', 'wind_secret-that-must-never-leave-auth'),
            'prefix'=>'wind_abcd',
            'ip_whitelist'=>null,
            'scopes'=>null,
            'rate_limit_per_minute'=>60,
            'expires_at'=>null,
            'revoked_at'=>null,
            'created_at'=>$now,
        ));
        $app->model(array('Api_key_model','Audit_log_model'));
        return array($app, $user);
    }

    public function testPolicyAcceptsOnlyBoundedStrictValues()
    {
        $policy = new ApiKeyPolicy();
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $valid = $policy->validate_update(array(
            'name'=>'Restricted key',
            'rate_limit_per_minute'=>'125',
            'ip_whitelist'=>"203.0.113.10\n2001:0DB8:0:0:0:0:0:10\n203.0.113.10",
            'access_mode'=>'scoped',
            'scopes'=>array('services.read','orders.read'),
            'expires_at'=>$future,
        ));
        $this->assertTrue($valid['ok'], $valid['error'] ?? '');
        $this->assertSame(array('203.0.113.10','2001:db8::10'), json_decode($valid['data']['ip_whitelist'], true));
        $this->assertSame(array('services.read','orders.read'), json_decode($valid['data']['scopes'], true));
        $this->assertSame(125, $valid['data']['rate_limit_per_minute']);

        foreach (array(
            array('rate_limit_per_minute'=>'0'),
            array('rate_limit_per_minute'=>'10001'),
            array('access_mode'=>''),
            array('ip_whitelist'=>'203.0.113.0/24'),
            array('scopes'=>array('wallet.write')),
            array('expires_at'=>gmdate('Y-m-d H:i:s', time() - 1)),
        ) as $change) {
            $input = array_merge(array(
                'name'=>'Key', 'rate_limit_per_minute'=>'60', 'ip_whitelist'=>'',
                'access_mode'=>'scoped', 'scopes'=>array('services.read'), 'expires_at'=>'',
            ), $change);
            $result = $policy->validate_update($input);
            $this->assertFalse($result['ok'], 'Unsafe policy unexpectedly passed: '.json_encode($change));
        }
    }

    public function testNullScopesRemainFullAccessAndExplicitScopesAreExact()
    {
        list($app) = $this->app();
        $app->model('User_model');
        $app->library('ApiAuthenticator');
        $key = (object)array('scopes'=>null);
        $this->assertTrue($app->apiauthenticator->allows_scope($key, 'orders.write'));
        $key->scopes = json_encode(array('services.read'));
        $this->assertTrue($app->apiauthenticator->allows_scope($key, 'services.read'));
        $this->assertFalse($app->apiauthenticator->allows_scope($key, 'orders.write'));
        $key->scopes = 'not-json';
        $this->assertFalse($app->apiauthenticator->allows_scope($key, 'services.read'));
    }

    public function testAdminAndCustomerReadsNeverProjectCredentialHash()
    {
        list($app, $user) = $this->app();
        $customer = $app->Api_key_model->for_user_safe($user->id);
        $admin = $app->Api_key_model->admin_list(array('status'=>'ACTIVE','search'=>'Production'), 25, 0);
        $detail = $app->Api_key_model->safe_admin_detail('KEY00000000000000000000001');

        $this->assertCount(1, $customer);
        $this->assertCount(1, $admin);
        $this->assertNotNull($detail);
        $this->assertFalse(property_exists($customer[0], 'key_hash'));
        $this->assertFalse(property_exists($admin[0], 'key_hash'));
        $this->assertFalse(property_exists($detail, 'key_hash'));
        $this->assertSame('reseller@example.test', $detail->email);
    }

    public function testAdminPolicyUpdateIsNormalizedAndAuditedWithoutSecrets()
    {
        list($app) = $this->app();
        $app->library('ApiKeyAdminService');
        $key = $app->Api_key_model->safe_admin_detail('KEY00000000000000000000001');
        $result = $app->apikeyadminservice->update_policy($key, array(
            'name'=>'Read-only reporting',
            'rate_limit_per_minute'=>'30',
            'ip_whitelist'=>"203.0.113.10\n2001:db8::10",
            'access_mode'=>'scoped',
            'scopes'=>array('services.read','orders.read','account.read'),
            'expires_at'=>gmdate('Y-m-d H:i:s', time() + 7200),
        ), 99, '198.51.100.4', 'Test agent');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $stored = $app->rows('api_keys')[0];
        $this->assertSame(hash('sha256', 'wind_secret-that-must-never-leave-auth'), $stored['key_hash']);
        $this->assertSame(30, $stored['rate_limit_per_minute']);
        $this->assertSame(array('services.read','orders.read','account.read'), json_decode($stored['scopes'], true));
        $audit = $app->rows('audit_logs');
        $this->assertCount(1, $audit);
        $this->assertSame('api_key.policy_updated', $audit[0]['action']);
        $this->assertStringNotContainsString('secret-that-must-never-leave-auth', (string)$audit[0]['before_json'].(string)$audit[0]['after_json']);
        $this->assertStringNotContainsString('key_hash', (string)$audit[0]['before_json'].(string)$audit[0]['after_json']);
    }

    public function testRevocationIsPermanentIdempotentAndAuditedOnce()
    {
        list($app) = $this->app();
        $app->library('ApiKeyAdminService');
        $key = $app->Api_key_model->safe_admin_detail('KEY00000000000000000000001');
        $first = $app->apikeyadminservice->revoke($key, 99, '198.51.100.4', 'Test agent');
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['already_revoked']);
        $this->assertNotEmpty($app->rows('api_keys')[0]['revoked_at']);

        $again = $app->apikeyadminservice->revoke($first['key'], 99, '198.51.100.4', 'Test agent');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['already_revoked']);
        $this->assertCount(1, $app->rows('audit_logs'));

        $update = $app->apikeyadminservice->update_policy($first['key'], array(
            'name'=>'Resurrected', 'rate_limit_per_minute'=>'60', 'ip_whitelist'=>'',
            'access_mode'=>'full', 'expires_at'=>'',
        ), 99, '198.51.100.4', 'Test agent');
        $this->assertFalse($update['ok']);
        $this->assertSame('IMMUTABLE', $update['code']);
    }

    public function testUsageEvidenceReadsAreBoundedAndAggregated()
    {
        list($app) = $this->app();
        $now = gmdate('Y-m-d H:i:s');
        foreach (array(
            array('/api/v1/services','GET',200,12),
            array('/api/v1/services','GET',200,8),
            array('/api/v1/orders','POST',403,3),
        ) as $i=>$call) {
            $app->db->insert('api_usage_logs', array(
                'api_key_id'=>1, 'endpoint'=>$call[0], 'method'=>$call[1],
                'ip'=>'203.0.113.10', 'status'=>$call[2], 'duration_ms'=>$call[3],
                'created_at'=>$now,
            ));
        }
        $recent = $app->Api_key_model->usage_for_key(1, 2);
        $summary = $app->Api_key_model->usage_summary(1);
        $routes = $app->Api_key_model->endpoint_usage(1, 20);
        $this->assertCount(2, $recent);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['successful']);
        $this->assertSame(1, $summary['failed']);
        $this->assertCount(2, $routes);
        $this->assertSame(2, (int)$routes[0]->requests);
    }

    public function testAdminSurfaceRuntimeScopesAndUsageLoggingAreWired()
    {
        $controller = file_get_contents(self::$root.'/application/controllers/admin/Api_keys.php');
        $runtime = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $account = file_get_contents(self::$root.'/application/controllers/dashboard/Account.php');
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $nav = file_get_contents(self::$root.'/application/views/layouts/app.php');

        $this->assertStringContainsString("require_perm('api.manage')", $controller);
        $this->assertStringContainsString("'DashboardStats'", $controller);
        $this->assertStringContainsString('private function render(', $controller);
        $this->assertStringContainsString('$this->current_user->id', $controller);
        $this->assertStringContainsString("admin/api-keys/(:any)/policy", $routes);
        $this->assertStringContainsString("admin/api-keys/(:any)/revoke", $routes);
        $this->assertStringContainsString("'api.manage'", $nav);
        $this->assertStringContainsString("require_scope('orders.write'", $runtime);
        $this->assertStringContainsString("require_scope('orders.read'", $runtime);
        $this->assertStringContainsString("insert('api_usage_logs'", $runtime);
        $this->assertStringContainsString('$this->log_usage($code)', $runtime);
        $this->assertStringContainsString('$this->log_usage($http)', $runtime);
        $this->assertStringContainsString("method()) !== 'POST'", $account);
    }
}
