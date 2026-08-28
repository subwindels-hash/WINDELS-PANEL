<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The bridge from a synced provider catalogue to the sellable one.
 *
 * Adding a provider and syncing it only ever wrote provider_services — the
 * mirror. Until the "create from provider" sentinel and the bulk import, the
 * only crossing was one hand-built service at a time, which on a real
 * catalogue means the provider sits there and customers never see anything.
 * These tests pin the two crossings that now exist:
 *
 *   - one service, with its category created from the provider's own;
 *   - the whole catalogue at once, priced by the provider's rule and
 *     idempotent by construction (a provider service already linked is never
 *     duplicated).
 */
class ProviderImportTest extends TestCase
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
        require_once self::$root.'/application/helpers/marvy_helper.php';
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

    private function stage($app, array $services)
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($services as $s) {
            $app->db->insert('provider_services', array_merge(array(
                'provider_id'=>1,
                'min_quantity'=>100, 'max_quantity'=>100000,
                'service_type'=>'DEFAULT',
                'cancel_supported'=>0, 'refill_supported'=>0, 'dripfeed_supported'=>0,
                'raw_payload'=>null, 'last_synced_at'=>$now,
            ), $s));
        }
    }

    /* ================= single create: category from provider ============= */

    public function testSentinelCreatesTheProvidersCategoryAndLinksTheService()
    {
        $app = $this->app();
        $this->stage($app, array(array(
            'provider_service_id'=>'301', 'name'=>'Pinterest Followers',
            'category'=>'Pinterest Likes', 'rate'=>'1.20000000',
        )));

        $res = $app->smmserviceadminservice->save(null, array(
            'name'=>'Pinterest Followers', 'slug'=>'pinterest-followers',
            'category'=>SmmServiceAdminService::PROVIDER_CATEGORY_OPTION,
            'provider'=>'PROV0000000000000000000001', 'provider_service_id'=>'301',
            'rate'=>'1.50000000', 'min_quantity'=>100, 'max_quantity'=>100000,
            'status'=>'ACTIVE',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $category = $app->Service_category_model->find_by_slug('pinterest-likes');
        $this->assertNotNull($category, 'the provider category became a panel category');
        $this->assertSame(1, (int)$category->is_active, 'an imported category is on by default');
        $this->assertSame((int)$category->id, (int)$res['service']->category_id);
        $this->assertSame($category->name, $res['category_created']->name,
            'the caller is told which category it created, for the audit trail');
    }

    public function testSentinelNeedsAProviderLinkAndACategoryToCopy()
    {
        $app = $this->app();

        $no_link = $app->smmserviceadminservice->save(null, array(
            'name'=>'X', 'slug'=>'x-1',
            'category'=>SmmServiceAdminService::PROVIDER_CATEGORY_OPTION,
            'rate'=>'1.00000000', 'min_quantity'=>100, 'max_quantity'=>1000,
        ));
        $this->assertFalse($no_link['ok']);
        $this->assertSame('INVALID_CATEGORY', $no_link['code']);

        $this->stage($app, array(array(
            'provider_service_id'=>'302', 'name'=>'Uncategorised Thing',
            'category'=>null, 'rate'=>'1.00000000',
        )));
        $no_category = $app->smmserviceadminservice->save(null, array(
            'name'=>'Uncategorised Thing', 'slug'=>'uncategorised-thing',
            'category'=>SmmServiceAdminService::PROVIDER_CATEGORY_OPTION,
            'provider'=>'PROV0000000000000000000001', 'provider_service_id'=>'302',
            'rate'=>'1.00000000', 'min_quantity'=>100, 'max_quantity'=>1000,
        ));
        $this->assertFalse($no_category['ok']);
        $this->assertSame('INVALID_CATEGORY', $no_category['code']);
    }

    public function testALaterValidationFailureDoesNotOrphanTheCategory()
    {
        $app = $this->app();
        $this->stage($app, array(array(
            'provider_service_id'=>'303', 'name'=>'Broken Rate',
            'category'=>'Pinterest Likes', 'rate'=>'1.00000000',
        )));
        $before = count($app->rows('service_categories'));

        $res = $app->smmserviceadminservice->save(null, array(
            'name'=>'Broken Rate', 'slug'=>'broken-rate',
            'category'=>SmmServiceAdminService::PROVIDER_CATEGORY_OPTION,
            'provider'=>'PROV0000000000000000000001', 'provider_service_id'=>'303',
            'rate'=>'not-a-decimal', 'min_quantity'=>100, 'max_quantity'=>1000,
            'status'=>'ACTIVE',
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame($before, count($app->rows('service_categories')),
            'a refused save must not leave a half-created category behind');
    }

    public function testDraftFromProviderPreselectsAMatchingPanelCategory()
    {
        $app = $this->app();
        $this->stage($app, array(array(
            'provider_service_id'=>'304', 'name'=>'Instagram Likes Fast',
            'category'=>'Instagram', 'rate'=>'0.90000000',
        )));

        $res = $app->smmserviceadminservice->draft_from_provider(
            'PROV0000000000000000000001', '304');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        // A matching panel category is preselected, which is also what keeps
        // the form from offering the create-from-provider sentinel for it.
        $this->assertSame(1, (int)$res['draft']->category_id,
            'an existing category with the provider category slug is preselected');
    }

    /* ======================= the bulk import ============================= */

    public function testImportCreatesServicesCategoriesAndIsIdempotent()
    {
        $app = $this->app();
        $app->db->where('id', 1)->update('providers', array(
            'rate_multiplier'=>'1.20000000', 'markup'=>'0.10000000',
        ));
        $this->stage($app, array(
            array('provider_service_id'=>'401', 'name'=>'Instagram Likes',
                  'category'=>'Instagram', 'rate'=>'1.00000000',
                  'refill_supported'=>1),
            array('provider_service_id'=>'402', 'name'=>'Pinterest Followers',
                  'category'=>'Pinterest Likes', 'rate'=>'2.00000000'),
            array('provider_service_id'=>'403', 'name'=>'Mystery Boost',
                  'category'=>null, 'rate'=>'0.50000000'),
        ));

        $provider = $app->Provider_model->find_by_id(1);
        $res = $app->smmserviceadminservice->import_provider_services($provider, array(
            'status'=>'ACTIVE', 'create_categories'=>'1', 'auto_price_sync'=>'1',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(3, $res['created']);
        // Pinterest Likes is new, and so is the "Imported" bucket the
        // no-category row lands in; Instagram is reused, not duplicated.
        $this->assertSame(2, $res['categories_created']);

        // Instagram is reused, not duplicated.
        $this->assertCount(1, array_filter($app->rows('service_categories'),
            function ($c) { return $c['slug'] === 'instagram'; }));

        // A no-category row lands somewhere findable rather than vanishing.
        $mystery = $app->db->where('slug', 'mystery-boost-403')->get('services')->row();
        $this->assertNotNull($mystery, 'services with no provider category are still imported');
        $imported_cat = $app->Service_category_model->find_by_slug('imported');
        $this->assertNotNull($imported_cat);
        $this->assertSame((int)$imported_cat->id, (int)$mystery->category_id);

        // The pricing rule: 1.00 × 1.2 + 0.10 = 1.30, and the service follows it.
        $likes = $app->db->where('slug', 'instagram-likes-401')->get('services')->row();
        $this->assertSame('1.30000000', $likes->rate);
        $this->assertSame('1.00000000', $likes->provider_rate, 'vendor cost is trusted evidence');
        $this->assertSame(1, (int)$likes->auto_price_sync);
        $this->assertSame('ACTIVE', $likes->status, 'customers can order right away');
        $this->assertSame(1, (int)$likes->refill_supported, 'capability flags come across');

        // Re-running adds nothing: already-linked provider services are skipped.
        $again = $app->smmserviceadminservice->import_provider_services($provider, array(
            'status'=>'ACTIVE', 'create_categories'=>'1', 'auto_price_sync'=>'1',
        ));
        $this->assertTrue($again['ok']);
        $this->assertSame(0, $again['created']);
        $this->assertSame(3, $again['skipped_linked']);
        $this->assertSame(4, count($app->rows('services')),
            'the seed service plus three imports, still three after a re-run');
    }

    public function testImportSkipsWhatItCannotPriceAndRefusesWithoutASync()
    {
        $app = $this->app();
        $app->db->where('id', 1)->update('providers', array(
            'rate_multiplier'=>'2.00000000', 'markup'=>'0.00000000',
        ));
        $this->stage($app, array(
            array('provider_service_id'=>'501', 'name'=>'Normal', 'category'=>'Instagram',
                  'rate'=>'1.00000000'),
            array('provider_service_id'=>'502', 'name'=>'Overflow', 'category'=>'Instagram',
                  'rate'=>'999999999999.00000000'),
        ));

        $provider = $app->Provider_model->find_by_id(1);
        $res = $app->smmserviceadminservice->import_provider_services($provider, array(
            'status'=>'INACTIVE', 'create_categories'=>'1',
        ));

        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['created']);
        $this->assertSame(1, $res['skipped_rate'], 'a rate the schema cannot hold is skipped, not mangled');
        $this->assertNull($app->db->where('slug', 'overflow-502')->get('services')->row());

        $unsynced = $app->Provider_model->find_by_id(1);
        $app->db->where('provider_id', 1)->delete('provider_services');
        $empty = $app->smmserviceadminservice->import_provider_services($unsynced, array());
        $this->assertFalse($empty['ok']);
        $this->assertSame('NOTHING_SYNCED', $empty['code']);

        $bad_status = $app->smmserviceadminservice->import_provider_services(
            $unsynced, array('status'=>'ARCHIVED'));
        $this->assertFalse($bad_status['ok']);
        $this->assertSame('INVALID_STATUS', $bad_status['code']);
    }

    public function testImportWithoutCategoryCreationSkipsUnmatchedRows()
    {
        $app = $this->app();
        $this->stage($app, array(
            array('provider_service_id'=>'601', 'name'=>'Instagram Likes',
                  'category'=>'Instagram', 'rate'=>'1.00000000'),
            array('provider_service_id'=>'602', 'name'=>'Pinterest Followers',
                  'category'=>'Pinterest Likes', 'rate'=>'2.00000000'),
        ));

        $res = $app->smmserviceadminservice->import_provider_services(
            $app->Provider_model->find_by_id(1), array(
                'status'=>'ACTIVE', 'create_categories'=>'0',
            ));

        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['created']);
        $this->assertSame(1, $res['skipped_category']);
        $this->assertNull($app->Service_category_model->find_by_slug('pinterest-likes'),
            'no category is invented when the operator switched creation off');
    }

    /* ================= the shared job registry =========================== */

    public function testEveryScheduledJobHasARegistryWorker()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('CronRegistry');
        $schedules = $app->config->item('cron');
        $this->assertNotEmpty($schedules);

        foreach (array_keys($schedules) as $job) {
            $this->assertTrue($app->cronregistry->has($job), "scheduled job '{$job}' has no registry worker");
            $this->assertNotNull($app->cronregistry->worker($job), "job '{$job}' resolves to no callable");
            $this->assertContains($job, $app->cronregistry->jobs());
        }

        $this->assertFalse($app->cronregistry->has('not_a_job'));
        $this->assertNull($app->cronregistry->worker('not_a_job'));
        $this->assertNull($app->cronregistry->worker('__construct'),
            'helper methods must never be hand out as workers');
    }
}
