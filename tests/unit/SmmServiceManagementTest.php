<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/** SMM service catalogue write-boundary and admin surface. */
class SmmServiceManagementTest extends TestCase
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
        require_once self::$root.'/application/helpers/windels_helper.php';
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->model(array('Service_model','Service_category_model','Provider_model',
            'Provider_service_model','Service_price_model','User_service_price_model','User_model'));
        $app->library('SmmServiceAdminService');
        return $app;
    }

    private function stage($app, $provider_service_id='202', $rate='1.25000000')
    {
        $now = gmdate('Y-m-d H:i:s');
        $app->db->insert('provider_services', array(
            'provider_id'=>1, 'provider_service_id'=>$provider_service_id,
            'name'=>'TikTok Views', 'category'=>'TikTok', 'rate'=>$rate,
            'min_quantity'=>50, 'max_quantity'=>50000, 'service_type'=>'DEFAULT',
            'cancel_supported'=>0, 'refill_supported'=>1, 'dripfeed_supported'=>0,
            'raw_payload'=>json_encode(array('service'=>$provider_service_id,'secret'=>'not-a-credential')),
            'last_synced_at'=>$now,
        ));
    }

    public function testProviderDraftIsResolvedServerSideAndDefaultsInactive()
    {
        $app = $this->app();
        $this->stage($app);

        $result = $app->smmserviceadminservice->draft_from_provider(
            'PROV0000000000000000000001', '202');

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $draft = $result['draft'];
        $this->assertSame('TikTok Views', $draft->name);
        $this->assertSame('1.25000000', $draft->rate);
        $this->assertSame('INACTIVE', $draft->status);
        $this->assertSame(50, $draft->min_quantity);
    }

    public function testCreateIgnoresBrowserProviderCostAndCapturesTrustedSnapshot()
    {
        $app = $this->app();
        $this->stage($app, '202', '1.25000000');

        $res = $app->smmserviceadminservice->save(null, array(
            'name'=>'TikTok Views', 'slug'=>'tiktok-views',
            'category'=>'CAT00000000000000000000001',
            'provider'=>'PROV0000000000000000000001',
            'provider_service_id'=>'202', 'provider_rate'=>'0.00000001',
            'rate'=>'2.50000000', 'min_quantity'=>'50', 'max_quantity'=>'50000',
            'status'=>'INACTIVE',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('1.25000000', $res['service']->provider_rate);
        $snapshot = json_decode($res['service']->provider_source_snapshot, true);
        $this->assertSame('202', $snapshot['provider_service_id']);
        $this->assertSame('1.25000000', $snapshot['rate']);
        $this->assertArrayNotHasKey('raw_payload', $snapshot);
    }

    public function testExistingSnapshotSurvivesAnOrdinaryAdminEdit()
    {
        $app = $this->app();
        $this->stage($app, '101', '0.75000000');
        $service = $app->Service_model->find_by_id(1);
        $original = '{"provider_service_id":"101","rate":"0.75000000"}';
        $app->db->where('id',1)->update('services', array(
            'provider_rate'=>'0.75000000', 'provider_source_snapshot'=>$original,
        ));
        $service = $app->Service_model->find_by_id(1);

        $res = $app->smmserviceadminservice->save($service, array(
            'name'=>$service->name, 'slug'=>$service->slug,
            'category'=>'CAT00000000000000000000001',
            'provider'=>'PROV0000000000000000000001',
            'provider_service_id'=>'101', 'rate'=>'3.00000000',
            'min_quantity'=>'100', 'max_quantity'=>'10000', 'status'=>'ACTIVE',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame($original, $res['service']->provider_source_snapshot);
        $this->assertSame('0.75000000', $res['service']->provider_rate);
    }

    public function testGroupAndUserOverridesUpsertThenRemove()
    {
        $app = $this->app();
        $user = $app->register('priced', 'priced@example.test');
        $service = $app->Service_model->find_by_id(1);

        $group = $app->smmserviceadminservice->set_group_rate($service, 1, '1.50000000');
        $customer = $app->smmserviceadminservice->set_user_rate($service, $user->public_id, '1.25000000');
        $this->assertTrue($group['ok']);
        $this->assertTrue($customer['ok']);
        $this->assertSame('1.50000000', $app->Service_price_model->for_group(1,1)->rate);
        $this->assertSame('1.25000000', $app->User_service_price_model->for_user($user->id,1)->rate);

        $this->assertTrue($app->smmserviceadminservice->set_group_rate($service, 1, '')['ok']);
        $this->assertTrue($app->smmserviceadminservice->set_user_rate($service, $user->public_id, '')['ok']);
        $this->assertNull($app->Service_price_model->for_group(1,1));
        $this->assertNull($app->User_service_price_model->for_user($user->id,1));
    }

    public function testArchiveIsAStateTransitionNotADelete()
    {
        $app = $this->app();
        $service = $app->Service_model->find_by_id(1);

        $res = $app->smmserviceadminservice->archive($service);

        $this->assertTrue($res['ok']);
        $this->assertSame('ARCHIVED', $app->Service_model->find_by_id(1)->status);
        $this->assertCount(1, $app->rows('services'));
    }

    public function testInvalidExactDecimalAndLimitsAreRefused()
    {
        $app = $this->app();
        $service = $app->Service_model->find_by_id(1);
        $base = array(
            'name'=>'X', 'slug'=>'x', 'category'=>'CAT00000000000000000000001',
            'rate'=>'1e4', 'min_quantity'=>100, 'max_quantity'=>500, 'status'=>'ACTIVE',
        );

        $res = $app->smmserviceadminservice->save($service, $base);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('decimal', strtolower($res['error']));

        $base['rate'] = '1.00000000';
        $base['min_quantity'] = 500;
        $base['max_quantity'] = 100;
        $res = $app->smmserviceadminservice->save($service, $base);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('maximum', strtolower($res['error']));

        $base['min_quantity'] = 100;
        $base['max_quantity'] = 500;
        $base['average_time'] = str_repeat('x', 65);
        $res = $app->smmserviceadminservice->save($service, $base);
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_TIME', $res['code']);
    }

    public function testAutoPriceSyncRequiresTrustedProviderSource()
    {
        $app = $this->app();
        $service = $app->Service_model->find_by_id(1);

        $res = $app->smmserviceadminservice->save($service, array(
            'name'=>$service->name, 'slug'=>$service->slug,
            'category'=>'CAT00000000000000000000001',
            'rate'=>'1.00000000', 'min_quantity'=>100, 'max_quantity'=>1000,
            'status'=>'ACTIVE', 'auto_price_sync'=>'1',
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_AUTO_PRICE', $res['code']);
    }

    public function testAutoPriceSyncRefusesAComputedRateThatCannotFitTheSchema()
    {
        $app = $this->app();
        $this->stage($app, '202', '999999999999.00000000');
        $app->db->where('id', 1)->update('providers', array(
            'rate_multiplier'=>'2.00000000', 'markup'=>'0.00000000',
        ));
        $service = $app->Service_model->find_by_id(1);

        $res = $app->smmserviceadminservice->save($service, array(
            'name'=>$service->name, 'slug'=>$service->slug,
            'category'=>'CAT00000000000000000000001',
            'provider'=>'PROV0000000000000000000001',
            'provider_service_id'=>'202', 'min_quantity'=>100, 'max_quantity'=>1000,
            'status'=>'ACTIVE', 'auto_price_sync'=>'1',
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_RATE', $res['code']);
    }

    public function testProviderSyncRefreshesTrustedEvidenceAndOnlyOptInSellingRate()
    {
        $app = $this->app();
        $app->db->where('id', 1)->update('services', array(
            'name'=>'Admin title', 'description'=>'Admin copy', 'rate'=>'9.00000000',
            'provider_id'=>1, 'provider_service_id'=>'101', 'auto_price_sync'=>0,
        ));
        $app->db->where('id', 1)->update('providers', array(
            'rate_multiplier'=>'1.50000000', 'markup'=>'0.25000000',
        ));
        $source = array(
            'provider_service_id'=>'101', 'name'=>'Vendor title', 'category'=>'Social',
            'rate'=>'2.00000000', 'min_quantity'=>50, 'max_quantity'=>5000,
            'service_type'=>'DEFAULT', 'cancel_supported'=>1, 'refill_supported'=>1,
            'dripfeed_supported'=>0, 'last_synced_at'=>gmdate('Y-m-d H:i:s'),
        );
        // FakeDb intentionally does not emulate MySQL's ON DUPLICATE KEY syntax;
        // invoke the propagation boundary with the exact normalized row shape.
        $propagate = new ReflectionMethod($app->Provider_service_model, 'propagate_service_source');
        if (PHP_VERSION_ID < 80100) $propagate->setAccessible(true);

        $propagate->invoke($app->Provider_service_model, 1, $source);
        $manual = $app->Service_model->find_by_id(1);
        $this->assertSame('2.00000000', $manual->provider_rate);
        $this->assertSame('9.00000000', $manual->rate);
        $this->assertSame('Admin title', $manual->name);
        $this->assertSame('Admin copy', $manual->description);
        $snapshot = json_decode($manual->provider_source_snapshot, true);
        $this->assertSame('2.00000000', $snapshot['rate']);
        $this->assertArrayNotHasKey('raw', $snapshot);

        $app->db->where('id', 1)->update('services', array('auto_price_sync'=>1));
        $source['rate'] = '4.00000000';
        $propagate->invoke($app->Provider_service_model, 1, $source);
        $auto = $app->Service_model->find_by_id(1);
        $this->assertSame('4.00000000', $auto->provider_rate);
        $this->assertSame('6.25000000', $auto->rate);
        $this->assertSame('Admin title', $auto->name);

        $source['rate'] = '999999999999.00000000';
        $propagate->invoke($app->Provider_service_model, 1, $source);
        $overflow = $app->Service_model->find_by_id(1);
        $this->assertSame('999999999999.00000000', $overflow->provider_rate);
        $this->assertSame('6.25000000', $overflow->rate);
    }

    public function testAdminSurfaceHasOrderedPostOnlyRoutesAndAuditing()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'admin/services/create'", $routes);
        $this->assertStringContainsString("'admin/services/(:any)/update'", $routes);
        $this->assertStringContainsString("'admin/services/(:any)/archive'", $routes);
        $this->assertLessThan(strpos($routes, "'admin/services/(:any)'"),
            strpos($routes, "'admin/services/create'"));

        $file = self::$root.'/application/controllers/admin/Services.php';
        $this->assertFileExists($file);
        $src = file_get_contents($file);
        $this->assertStringContainsString("require_perm('services.view')", $src);
        $this->assertStringContainsString("require_perm('services.manage')", $src);
        $this->assertStringContainsString("guard('pricing.manage')", $src);
        $this->assertStringContainsString("method(true) !== 'POST'", $src);
        $this->assertStringContainsString('$this->audit(', $src);
    }

    public function testProviderSyncRefreshIsNarrowAndOptInForSellingRate()
    {
        $src = file_get_contents(self::$root.'/application/models/Provider_service_model.php');
        $this->assertStringContainsString("'provider_rate'", $src);
        $this->assertStringContainsString("'provider_source_snapshot'", $src);
        $this->assertStringContainsString("\$scope['auto_price_sync'] = 1", $src);
        $this->assertStringContainsString("update('services', array(\n            'provider_rate'", $src);
        $this->assertStringContainsString("update('services', array(\n            'rate'", $src);
    }
}
