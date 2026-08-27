<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Admin VTU queue (Session 23).
 *
 * Session 21 shipped the VTU domain and seeded `vtu.view`/`vtu.manage`/
 * `vtu.refund`, but nothing in the admin panel rendered them: a purchase stuck
 * in PROCESSING could only be settled by waiting for the cron, and a refund the
 * engine fully supported had no button. These tests cover the screens that
 * close that gap.
 *
 * The behavioural half runs the real models, TransactionEngine and LedgerService
 * against the migration-derived schema, because the money assertions are the
 * point: an admin refund that silently fails to move money looks identical to a
 * successful one from the UI. The source-level half pins the same admin-surface
 * guarantees AdminPanelTest pins for orders, payments and tickets — POST-only
 * mutations, a permission per action, audit logging and CSRF.
 */
class AdminVtuTest extends TestCase
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
    }

    /** A world with a customer who has made VTU purchases. */
    private function app($balance = '100000')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->seed_vtu();
        $user = $app->register('vtu_user', 'vtu@x.test');
        $app->credit($user, $balance);
        $app->library('VtuService');
        $app->model('Service_transaction_model');
        return array($app, $user);
    }

    /* ========================= the admin queue ========================== */

    public function testTheQueueListsPurchasesAcrossAllCustomers()
    {
        list($app, $alice) = $this->app();
        $bob = $app->register('bob', 'bob@x.test');
        $app->credit($bob, '5000');

        $app->vtuservice->airtime($alice, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000'));
        $app->vtuservice->airtime($bob, array(
            'network' => 'MTN', 'msisdn' => '08037654321', 'amount' => '500'));

        $rows = $app->Service_transaction_model->admin_search(array('domain' => 'VTU'), 25, 0);

        // The customer-facing history is user-scoped; this deliberately is not.
        $this->assertCount(2, $rows);
        $owners = array_map(function ($r) { return $r->username; }, $rows);
        sort($owners);
        $this->assertSame(array('bob', 'vtu_user'), $owners);
    }

    public function testTheQueueJoinsTheContextTheListNeeds()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000'));

        $row = $app->Service_transaction_model->admin_search(array('domain' => 'VTU'), 25, 0)[0];

        // Every column the index view reads must come back from the one query,
        // or the list becomes an N+1 (or worse, renders blank).
        $columns = array_keys(get_object_vars($row));
        foreach (array('public_id','username','email','service_type','amount',
                       'currency','status','source','created_at','refunded_amount',
                       'recipient') as $column) {
            $this->assertContains($column, $columns, "admin_search must select {$column}");
        }
        $this->assertSame('08031234567', $row->recipient);
        $this->assertSame('vtu_user', $row->username);
    }

    public function testTheQueueIsScopedToItsDomain()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000'));

        // A future domain's transactions must not leak into the VTU screen.
        $app->db->insert('service_transactions', array(
            'public_id' => 'STXGIFTCARD00000000000001', 'user_id' => $user->id,
            'service_domain' => 'GIFTCARD', 'service_type' => 'STEAM',
            'status' => 'SUCCESSFUL', 'amount' => '5000.00000000',
            'currency' => 'NGN', 'source' => 'WEB', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));

        $this->assertCount(1, $app->Service_transaction_model->admin_search(array('domain' => 'VTU'), 25, 0));
        $this->assertSame(1, $app->Service_transaction_model->admin_count(array('domain' => 'VTU')));
        $this->assertSame(2, $app->Service_transaction_model->admin_count(array()));
    }

    public function testTheListAndItsCountApplyTheSameFilters()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000'));
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567'));

        $filters = array('domain' => 'VTU', 'type' => 'DATA');
        // A count that disagrees with the list paginates into empty pages.
        $this->assertCount(1, $app->Service_transaction_model->admin_search($filters, 25, 0));
        $this->assertSame(1, $app->Service_transaction_model->admin_count($filters));
    }

    public function testTheQueueIsPaginated()
    {
        list($app, $user) = $this->app();
        for ($i = 0; $i < 5; $i++) {
            $app->vtuservice->airtime($user, array(
                'network' => 'MTN', 'msisdn' => '0803123456'.$i, 'amount' => '100'));
        }

        $page1 = $app->Service_transaction_model->admin_search(array('domain' => 'VTU'), 2, 0);
        $page2 = $app->Service_transaction_model->admin_search(array('domain' => 'VTU'), 2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame(5, $app->Service_transaction_model->admin_count(array('domain' => 'VTU')));
        $this->assertNotSame($page1[0]->public_id, $page2[0]->public_id, 'the offset must move the window');
    }

    public function testSearchingMatchesThePublicIdAndProviderReference()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08031234567', 'amount' => '1000'));
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08037654321', 'amount' => '500'));

        $target = $app->rows('service_transactions')[0];
        $found = $app->Service_transaction_model->admin_search(
            array('domain' => 'VTU', 'search' => $target['public_id']), 25, 0);

        $this->assertCount(1, $found, 'support searches by the id the customer quotes');
        $this->assertSame($target['public_id'], $found[0]->public_id);
    }

    public function testStatusCountsDriveTheQueueHeader()
    {
        list($app, $user) = $this->app();
        // Ends 9999: the mock adapter answers asynchronously, so this one stays
        // PROCESSING — exactly the row an admin needs to find.
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08039999999', 'amount' => '1000'));
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567'));

        $counts = $app->Service_transaction_model->status_counts('VTU');

        $this->assertSame(1, $counts['PROCESSING'] ?? 0);
        $this->assertSame(1, $counts['SUCCESSFUL'] ?? 0);
    }

    public function testStatusCountsIgnoreOtherDomains()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567'));
        $app->db->insert('service_transactions', array(
            'public_id' => 'STXNUMBER000000000000001', 'user_id' => $user->id,
            'service_domain' => 'NUMBER', 'service_type' => 'OTP',
            'status' => 'SUCCESSFUL', 'amount' => '100.00000000',
            'currency' => 'NGN', 'source' => 'WEB', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));

        // A VTU header card counting other domains would misreport the queue.
        $this->assertSame(1, $app->Service_transaction_model->status_counts('VTU')['SUCCESSFUL']);
        $this->assertSame(2, $app->Service_transaction_model->status_counts()['SUCCESSFUL']);
    }

    /* =========================== the detail ============================= */

    public function testTheDetailLookupJoinsCustomerAndProvider()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->electricity($user, array(
            'network' => 'IKEDC', 'meter' => '12345678901', 'amount' => '5000',
            'meter_type' => 'PREPAID',
        ));
        $stx = $app->rows('service_transactions')[0];

        $tx = $app->Service_transaction_model->admin_find($stx['public_id'], 'VTU');

        $this->assertNotNull($tx);
        $this->assertSame('vtu_user', $tx->username);
        $this->assertSame('Acme VTU', $tx->provider_name);
        $this->assertSame('ELECTRICITY', $tx->service_type);
    }

    public function testTheDetailLookupRefusesAnotherDomainsTransaction()
    {
        list($app, $user) = $this->app();
        $app->db->insert('service_transactions', array(
            'public_id' => 'STXGIFTCARD00000000000002', 'user_id' => $user->id,
            'service_domain' => 'GIFTCARD', 'service_type' => 'STEAM',
            'status' => 'SUCCESSFUL', 'amount' => '5000.00000000',
            'currency' => 'NGN', 'source' => 'WEB', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));

        // The controller passes its own domain, so /admin/vtu/<giftcard id>
        // must 404 rather than render a gift card in the VTU screen.
        $this->assertNull(
            $app->Service_transaction_model->admin_find('STXGIFTCARD00000000000002', 'VTU'));
        $this->assertNotNull(
            $app->Service_transaction_model->admin_find('STXGIFTCARD00000000000002'));
    }

    public function testTheDetailShowsTheDeliveredTokenAndProviderCallLog()
    {
        list($app, $user) = $this->app();
        $app->model(array('Vtu_transaction_model', 'Provider_transaction_model'));
        $app->vtuservice->electricity($user, array(
            'network' => 'IKEDC', 'meter' => '12345678901', 'amount' => '5000',
            'meter_type' => 'PREPAID',
        ));
        $stx = $app->rows('service_transactions')[0];

        $detail = $app->Vtu_transaction_model->for_transaction($stx['id']);
        $calls  = $app->Provider_transaction_model->for_transaction($stx['id']);

        // The token is the whole product for prepaid electricity: if support
        // cannot read it back, the customer cannot be helped.
        $this->assertNotEmpty($detail->token);
        $this->assertSame('12345678901', $detail->recipient);
        $this->assertCount(1, $calls);
        $this->assertSame('PURCHASE', $calls[0]->action);
    }

    /* ============================= refunds ============================== */

    public function testAnAdminRefundReturnsTheChargeToTheWallet()
    {
        list($app, $user) = $this->app();
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567'));
        $this->assertSame('99700.00000000', $app->balance($user));

        $app->library('TransactionEngine');
        $stx = $app->rows('service_transactions')[0];
        $res = $app->transactionengine->transition($stx['id'], 'REFUNDED', 'ADMIN', 'Never delivered');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('100000.00000000', $app->balance($user));
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c, 'an admin refund must leave the ledger balanced');
    }

    public function testAnAdminRefundIsRecordedInTheStatusHistory()
    {
        list($app, $user) = $this->app();
        $app->model('Service_transaction_status_history_model');
        $app->library('TransactionEngine');
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567'));

        $stx = $app->rows('service_transactions')[0];
        $app->transactionengine->transition($stx['id'], 'REFUNDED', 'ADMIN', 'Goodwill');

        $history = $app->Service_transaction_status_history_model->for_transaction($stx['id']);
        $last = end($history);
        // The detail view renders from_status/to_status, not the order-shaped
        // previous_status/new_status.
        $this->assertSame('REFUNDED', $last->to_status);
        $this->assertSame('ADMIN', $last->source);
        $this->assertSame('Goodwill', $last->reason);
    }

    public function testRefundingTwiceThroughTheAdminPathPaysOnce()
    {
        list($app, $user) = $this->app();
        $app->library('TransactionEngine');
        $app->vtuservice->data($user, array(
            'network' => 'MTND', 'product' => 'MTN-1GB', 'msisdn' => '08031234567'));
        $stx = $app->rows('service_transactions')[0];

        $app->transactionengine->transition($stx['id'], 'REFUNDED', 'ADMIN', 'first');
        // A double-clicked refund button must not pay the customer twice.
        $again = $app->transactionengine->transition($stx['id'], 'REFUNDED', 'ADMIN', 'again');

        $this->assertFalse($again['ok']);
        $this->assertSame('TERMINAL', $again['code']);
        $this->assertSame('100000.00000000', $app->balance($user));
    }

    /* ======================== manual re-check =========================== */

    public function testRecheckingSettlesAPurchaseTheProviderHasCompleted()
    {
        list($app, $user) = $this->app();
        $app->library('TransactionEngine');
        // Ends 9999 → the mock provider accepted it but answers asynchronously.
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08039999999', 'amount' => '1000'));
        $stx = $app->rows('service_transactions')[0];
        $this->assertSame('PROCESSING', $stx['status']);

        // What the controller does with the adapter's answer.
        $res = $app->transactionengine->transition($stx['id'], 'SUCCESSFUL', 'ADMIN');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('SUCCESSFUL', $app->rows('service_transactions')[0]['status']);
        $this->assertSame('99020.00000000', $app->balance($user),
            'settling a delivered purchase must not move money again');
    }

    public function testRecheckingAFailedPurchaseRefundsTheCustomer()
    {
        list($app, $user) = $this->app();
        $app->library('TransactionEngine');
        $app->vtuservice->airtime($user, array(
            'network' => 'MTN', 'msisdn' => '08039999999', 'amount' => '1000'));
        $stx = $app->rows('service_transactions')[0];

        $res = $app->transactionengine->transition(
            $stx['id'], 'FAILED', 'ADMIN', 'Provider reported failure on manual re-check');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('100000.00000000', $app->balance($user),
            'a confirmed failure must return the charge');
        list($d, $c) = $app->ledger_is_balanced();
        $this->assertSame($d, $c);
    }

    /* ====================== admin-surface contract ====================== */

    public function testTheControllerExists()
    {
        $this->assertFileExists(self::$root.'/application/controllers/admin/Vtu.php',
            'VTU permissions are seeded, so the screens that use them must exist');
        $this->assertFileExists(self::$root.'/application/views/admin/vtu/index.php');
        $this->assertFileExists(self::$root.'/application/views/admin/vtu/detail.php');
    }

    public function testEveryMutationIsPostOnlyAndGuarded()
    {
        $src = $this->controller();
        foreach (array('index', 'detail', 'recheck', 'refund') as $action) {
            $this->assertStringContainsString('function '.$action.'(', $src,
                "Vtu.php must define {$action}()");
        }
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src,
            'Vtu.php must reject non-POST mutations');
        // recheck() and refund() — the two mutations, and nothing else.
        $this->assertSame(2, substr_count($src, '$this->guard('),
            'every mutation must go through guard()');
    }

    public function testActionsRequireGranularPermissions()
    {
        $src = $this->controller();
        $this->assertStringContainsString("require_perm('vtu.view')", $src);
        $this->assertStringContainsString("'vtu.manage'", $src);
        $this->assertStringContainsString("'vtu.refund'", $src);

        // Those keys must actually be in the catalog, or the screen is dead.
        $seeder = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        foreach (array('vtu.view', 'vtu.manage', 'vtu.refund') as $perm) {
            $this->assertStringContainsString("'".$perm."'", $seeder,
                "{$perm} must be a seeded permission");
        }
    }

    public function testMutationsAreAuditLogged()
    {
        $src = $this->controller();
        $this->assertStringContainsString('Audit_log_model', $src);
        // Both mutations record, including the "nothing changed" re-check.
        $this->assertSame(3, substr_count($src, '$this->audit('),
            'every mutation outcome that changes or confirms state must be audited');
    }

    public function testTheControllerNeverMovesMoneyItself()
    {
        $src = $this->controller();
        $this->assertStringNotContainsString('ledgerservice->', $src,
            'refunds must go through TransactionEngine, not the ledger directly');
        $this->assertStringNotContainsString("update('wallets'", $src);
        $this->assertStringNotContainsString("update('service_transactions'", $src,
            'the status column belongs to TransactionEngine::transition()');
        $this->assertStringContainsString('transactionengine->transition(', $src);
    }

    public function testTheListEndpointIsBounded()
    {
        $src = $this->controller();
        // Without a limit the queue loads every VTU purchase ever made.
        $this->assertStringContainsString('const PER_PAGE', $src);
        $this->assertMatchesRegularExpression('~admin_search\(\$filters, \$limit~', $src);
    }

    public function testTheViewsCarryCsrfTokensAndEscapeOutput()
    {
        foreach (array('index', 'detail') as $view) {
            $src = file_get_contents(self::$root.'/application/views/admin/vtu/'.$view.'.php');
            if (strpos($src, 'method="post"') !== false) {
                $this->assertStringContainsString('get_csrf_token_name()', $src,
                    "{$view}.php has a POST form without a CSRF token");
            }
            $this->assertStringNotContainsString('api_key_encrypted', $src);
            $this->assertStringNotContainsString('->api_key', $src);
        }
    }

    public function testActionRoutesPrecedeTheCatchAllDetailRoute()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $detail = strpos($routes, "\$route['admin/vtu/(:any)']");
        $this->assertNotFalse($detail, 'admin/vtu detail route missing');

        preg_match_all("~\\\$route\['admin/vtu/\(:any\)/([a-z-]+)'\]~", $routes, $m, PREG_OFFSET_CAPTURE);
        $this->assertNotEmpty($m[0], 'admin/vtu has no action routes');
        foreach ($m[0] as $match) {
            // CI3 matches in declaration order: a catch-all first swallows these.
            $this->assertLessThan($detail, $match[1],
                "admin/vtu action route '{$match[0]}' must precede the (:any) detail route");
        }
        $this->assertStringContainsString("\$route['admin/vtu'] = 'admin/vtu/index';", $routes);
    }

    public function testTheAdminNavLinksTheQueueBehindItsPermission()
    {
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString("array('admin/vtu',", $layout,
            'the admin nav must link the VTU queue');
        $this->assertStringContainsString("'vtu.view'", $layout,
            'the nav entry must be gated on vtu.view');
    }

    /**
     * The nav renders icons by name and an unknown name renders nothing, so a
     * typo is an invisible menu item rather than an error.
     */
    public function testEveryNavIconExists()
    {
        $icons = file_get_contents(self::$root.'/application/views/partials/icon.php');
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');

        // The nav is defined as grouped sections ($nav_groups = $is_admin ?
        // array(...) : array(...)) and flattened afterwards. Read the whole
        // definition, both admin and customer branches.
        preg_match("~\\\$nav_groups = \\\$is_admin \? array\((.*?)\n\);~s", $layout, $m);
        $this->assertNotEmpty($m, 'could not read the nav definition');
        preg_match_all("~,\s*'([a-z-]+)'\)~", $m[1], $used);

        foreach (array_unique($used[1]) as $icon) {
            $this->assertStringContainsString("'".$icon."'", $icons,
                "the nav uses an icon '{$icon}' that partials/icon.php does not define");
        }
    }

    public function testTheSuccessfulStatusHasABadge()
    {
        require_once self::$root.'/application/libraries/DashboardStats.php';
        // Service transactions end SUCCESSFUL, not COMPLETED; without a mapping
        // every delivered purchase renders in the neutral "unknown" style.
        $this->assertStringContainsString('success', DashboardStats::status_badge('SUCCESSFUL'));
        $this->assertStringContainsString('badge', DashboardStats::status_badge('CANCELLED'));
    }

    private function controller()
    {
        return file_get_contents(self::$root.'/application/controllers/admin/Vtu.php');
    }
}
