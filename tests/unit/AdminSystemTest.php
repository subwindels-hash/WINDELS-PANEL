<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Service categories, the blacklist and the audit trail (Session 30).
 *
 * The last three routed-but-missing admin screens: `admin/categories`,
 * `admin/blacklist` and `admin/audit-logs` all 404'd, so
 * `categories.manage`, `blacklist.manage` and `audit.view` gated nothing.
 *
 * The tests concentrate on the two places where a naive implementation is
 * actively harmful rather than merely absent:
 *
 *   1. **The blacklist accepts regular expressions.**
 *      Blacklist_model::text_contains_blacklisted_link() runs any `/.../`
 *      entry against user-supplied text on every registration and order. Its
 *      own comment waves this off because "only staff may add entries" — an
 *      assumption that was safe only while no form existed to add them. A
 *      pattern like `/(a+)+$/` would hang every signup, so it must be refused
 *      at the point of entry.
 *   2. **The audit trail must be unwritable from the admin screen.** A log an
 *      administrator can edit is not evidence, and this is the screen most
 *      likely to be opened by someone covering their tracks.
 */
class AdminSystemTest extends TestCase
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

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library('SystemAdminService');
        $app->model(array('Service_category_model', 'Blacklist_model', 'Audit_log_model', 'User_model'));
        $admin = $app->register('sys', 'sys@x.test', 'Str0ng!pass1', 'ADMIN');
        return array($app, $admin);
    }

    /* ============ the blacklist's sharp edge: hostile regex ============= */

    /**
     * The pattern that would take the site down.
     *
     * `(a+)+$` against a non-matching string of a's backtracks exponentially.
     * Stored, it would run on every registration attempt — a self-inflicted
     * denial of service, added through a form with a "Block" button.
     */
    public function testACatastrophicallyBacktrackingPatternIsRefused()
    {
        list($app, ) = $this->app();

        $res = $app->systemadminservice->blacklist_add('links', '/(a+)+$/', 'sneaky');

        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_PATTERN', $res['code']);
        $this->assertStringContainsString('backtrack', strtolower($res['error']));
        $this->assertSame(0, $app->systemadminservice->count_entries('links'),
            'a refused pattern must never reach the table the matcher reads');
    }

    public function testAnUncompilablePatternIsRefused()
    {
        list($app, ) = $this->app();

        $res = $app->systemadminservice->blacklist_add('links', '/unclosed[/', null);

        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_PATTERN', $res['code']);
        $this->assertSame(0, $app->systemadminservice->count_entries('links'));
    }

    public function testAnOverlongPatternIsRefused()
    {
        list($app, ) = $this->app();
        $long = '/'.str_repeat('abc|', 100).'/';

        $res = $app->systemadminservice->blacklist_add('links', $long, null);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('limited to', strtolower($res['error']));
    }

    /** A sane regex and a plain substring must both still work. */
    public function testReasonablePatternsAreAccepted()
    {
        list($app, ) = $this->app();

        $this->assertTrue($app->systemadminservice->blacklist_add('links', 'spam-domain.test')['ok']);
        $this->assertTrue($app->systemadminservice->blacklist_add('links', '#https?://bad\.test/#i')['ok']);
        $this->assertSame(2, $app->systemadminservice->count_entries('links'));
    }

    /**
     * The whole point: a stored entry has to actually block something. The
     * matcher is the code the registration path calls.
     */
    public function testAStoredPatternBlocksAMatchingLink()
    {
        list($app, ) = $this->app();
        $app->systemadminservice->blacklist_add('links', 'spam-domain.test', 'spam');

        $this->assertTrue($app->Blacklist_model->text_contains_blacklisted_link(
            'check out https://spam-domain.test/x'));
        $this->assertFalse($app->Blacklist_model->text_contains_blacklisted_link(
            'check out https://legit.test/x'));
    }

    public function testRemovingAnEntryUnblocksIt()
    {
        list($app, ) = $this->app();
        $add = $app->systemadminservice->blacklist_add('links', 'spam-domain.test');
        $this->assertTrue($app->Blacklist_model->text_contains_blacklisted_link('https://spam-domain.test'));

        $res = $app->systemadminservice->blacklist_remove('links', $add['entry']->id);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertFalse($app->Blacklist_model->text_contains_blacklisted_link('https://spam-domain.test'));
    }

    /* ======================= blacklist validation ======================= */

    public function testEmailsAreValidatedAndNormalised()
    {
        list($app, ) = $this->app();

        $bad = $app->systemadminservice->blacklist_add('emails', 'not-an-email');
        $this->assertFalse($bad['ok']);

        $ok = $app->systemadminservice->blacklist_add('emails', '  Fraud@Example.TEST ');
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame('fraud@example.test', $ok['entry']->email,
            'the matcher lower-cases before comparing, so the stored value must match');
        $this->assertTrue($app->Blacklist_model->is_email_blacklisted('FRAUD@EXAMPLE.TEST'));
    }

    public function testIpsAreValidated()
    {
        list($app, ) = $this->app();

        $this->assertFalse($app->systemadminservice->blacklist_add('ips', '999.1.1.1')['ok']);
        $this->assertTrue($app->systemadminservice->blacklist_add('ips', '203.0.113.9')['ok']);
        $this->assertTrue($app->Blacklist_model->is_ip_blacklisted('203.0.113.9'));
    }

    public function testADuplicateEntryIsRefused()
    {
        list($app, ) = $this->app();
        $app->systemadminservice->blacklist_add('emails', 'dupe@example.test');

        $again = $app->systemadminservice->blacklist_add('emails', 'dupe@example.test');

        $this->assertFalse($again['ok']);
        $this->assertSame('DUPLICATE', $again['code']);
        $this->assertSame(1, $app->systemadminservice->count_entries('emails'));
    }

    public function testAnUnknownListIsRejected()
    {
        list($app, ) = $this->app();
        $this->expectException(InvalidArgumentException::class);
        $app->systemadminservice->count_entries('passwords');
    }

    /* ============================ categories ============================ */

    public function testACategoryCanBeCreatedAndSlugged()
    {
        list($app, ) = $this->app();

        $res = $app->systemadminservice->save_category(null, array(
            'name' => 'TikTok Views', 'platform' => 'tiktok', 'is_active' => '1',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('tiktok-views', $res['category']->slug);
        $this->assertTrue($res['created']);
    }

    public function testDuplicateSlugsAreRefused()
    {
        list($app, ) = $this->app();
        $app->systemadminservice->save_category(null, array('name' => 'Instagram', 'is_active' => '1'));

        // seed_minimal() already created an "Instagram" category.
        $res = $app->systemadminservice->save_category(null, array('name' => 'Instagram'));

        $this->assertFalse($res['ok']);
        $this->assertSame('DUPLICATE', $res['code']);
    }

    /**
     * The FK is ON DELETE SET NULL, so deleting a populated category would
     * succeed and quietly orphan every service in it — invisible on the
     * storefront, and hard to reverse.
     */
    public function testACategoryHoldingServicesCannotBeDeleted()
    {
        list($app, ) = $this->app();
        $category = $app->Service_category_model->find_by_id(1); // has the seeded service

        $res = $app->systemadminservice->delete_category($category);

        $this->assertFalse($res['ok']);
        $this->assertSame('IN_USE', $res['code']);
        $this->assertNotNull($app->Service_category_model->find_by_id(1));
        // ...and the service is still attached to it.
        $this->assertSame(1, (int)$app->db->where('id', 1)->get('services')->row()->category_id);
    }

    public function testAnEmptyCategoryCanBeDeleted()
    {
        list($app, ) = $this->app();
        $made = $app->systemadminservice->save_category(null, array('name' => 'Empty', 'is_active' => '1'));

        $res = $app->systemadminservice->delete_category($made['category']);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertNull($app->Service_category_model->find_by_id($made['category']->id));
    }

    /** Hiding a populated category is allowed, but says what it will do. */
    public function testHidingAPopulatedCategoryWarns()
    {
        list($app, ) = $this->app();
        $category = $app->Service_category_model->find_by_id(1);

        $res = $app->systemadminservice->save_category($category, array(
            'name' => $category->name, 'slug' => $category->slug, 'is_active' => '0',
        ));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertNotEmpty($res['warnings']);
        $this->assertStringContainsString('storefront', strtolower(implode(' ', $res['warnings'])));
    }

    public function testACategoryCannotBeItsOwnParent()
    {
        list($app, ) = $this->app();
        $category = $app->Service_category_model->find_by_id(1);

        $res = $app->systemadminservice->save_category($category, array(
            'name' => $category->name, 'slug' => $category->slug, 'parent_id' => $category->id,
        ));

        $this->assertFalse($res['ok']);
    }

    /* =========================== audit trail ============================ */

    public function testTheAuditTrailIsSearchableByResourceAndActor()
    {
        list($app, $admin) = $this->app();
        $app->Audit_log_model->record($admin->id, 'order.refunded', 'orders', '7',
            array('status' => 'COMPLETED'), array('status' => 'REFUNDED'), '10.0.0.1', 'UA', 'req1');
        $app->Audit_log_model->record($admin->id, 'user.suspended', 'users', '3',
            null, array('status' => 'SUSPENDED'), '10.0.0.1', 'UA', 'req2');

        $this->assertSame(2, $app->systemadminservice->audit_count(array()));
        $this->assertSame(1, $app->systemadminservice->audit_count(array('resource' => 'orders')));
        $this->assertSame(2, $app->systemadminservice->audit_count(array('actor_id' => $admin->id)));
        $this->assertSame(1, $app->systemadminservice->audit_count(array('search' => 'refunded')));
    }

    public function testTheAuditSearchNamesTheActor()
    {
        list($app, $admin) = $this->app();
        $app->Audit_log_model->record($admin->id, 'settings.updated', 'settings', null,
            null, array('site_name' => 'x'), '10.0.0.1', 'UA', 'req3');

        $rows = $app->systemadminservice->audit_search(array());

        $this->assertCount(1, $rows);
        $this->assertSame('sys', $rows[0]->actor_name,
            'an entry that cannot name who did it is not much of an audit trail');
    }

    public function testTheResourceListIsDerivedFromTheTrail()
    {
        list($app, $admin) = $this->app();
        $app->Audit_log_model->record($admin->id, 'a.b', 'orders', '1', null, null, null, null, null);
        $app->Audit_log_model->record($admin->id, 'c.d', 'users', '2', null, null, null, null, null);

        $resources = $app->systemadminservice->audit_resources();

        $this->assertContains('orders', $resources);
        $this->assertContains('users', $resources);
    }

    /**
     * The structural guarantee: neither the service nor the controller has
     * any way to modify an audit row.
     */
    public function testNothingOnThisScreenCanRewriteTheAuditTrail()
    {
        foreach (array('libraries/SystemAdminService.php', 'controllers/admin/System.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/'.$rel);
            foreach (array("delete('audit_logs'", "update('audit_logs'",
                           "->delete('audit_logs')", 'truncate') as $write) {
                $this->assertStringNotContainsString($write, $src,
                    basename($rel).' must not be able to alter the audit trail: '.$write);
            }
        }

        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringNotContainsString("admin/audit-logs/", $routes,
            'the audit trail must expose no sub-actions at all');
    }

    /* ======================= controller guarantees ====================== */

    public function testEachAreaKeepsItsOwnPermission()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/System.php');
        $this->assertStringContainsString("require_perm('categories.manage')", $src);
        $this->assertStringContainsString("require_perm('blacklist.manage')", $src);
        $this->assertStringContainsString("require_perm('audit.view')", $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringContainsString('$this->audit(', $src);
    }

    /** Blocking someone is itself an auditable act. */
    public function testBlacklistChangesAreAudited()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/System.php');
        $this->assertStringContainsString("'blacklist.added'", $src);
        $this->assertStringContainsString("'blacklist.removed'", $src);
    }
}
