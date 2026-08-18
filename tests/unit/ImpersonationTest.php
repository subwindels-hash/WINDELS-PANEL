<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/** Security and lifecycle coverage for read-only customer impersonation. */
class ImpersonationTest extends TestCase
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
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
    }

    private function app($actor_role = 'SUPER_ADMIN')
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $actor = $app->register('operator', 'operator@example.test', 'Str0ng!pass1', $actor_role);
        $target = $app->register('customer', 'customer@example.test');
        $app->session->set_userdata(array(
            'user_id' => (int)$actor->id,
            'role' => $actor->role,
            'login_at' => 1700000000,
        ));
        $app->library('ImpersonationService');
        return array($app, $actor, $target);
    }

    private function grantImpersonation($app, $role = 'ADMIN')
    {
        $role_row = $app->db->where('name', $role)->get('roles')->row();
        $app->db->insert('permissions', array(
            'perm_key' => 'users.impersonate',
            'description' => 'Read-only customer support session',
            'category' => 'users',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        $permission_id = $app->db->insert_id();
        $app->db->insert('role_permissions', array(
            'role_id' => $role_row->id,
            'permission_id' => $permission_id,
        ));
        Permission_model::flush_cache();
        return array($role_row->id, $permission_id);
    }

    public function testStartViewAndManualEndPreserveBothIdentitiesInTheAuditTrail()
    {
        list($app, $actor, $target) = $this->app();
        $last_login_before = $target->last_login_at ?? null;
        $app->session->set_userdata('staff_only_filter', 'open-tickets');

        $started = $app->impersonationservice->start(
            $actor, $target, 'Investigating support ticket T-1042', true,
            '203.0.113.9', 'Support browser', 'req-start'
        );

        $this->assertTrue($started['ok'], $started['error'] ?? '');
        $this->assertSame((int)$target->id, $app->session->userdata('user_id'));
        $this->assertSame('CUSTOMER', $app->session->userdata('role'));
        $this->assertNull($app->session->userdata('staff_only_filter'),
            'staff-scoped session data must not cross into the customer identity');
        $this->assertSame(ImpersonationService::TTL,
            $started['context']['expires_at'] - $started['context']['started_at']);
        $this->assertSame(1700000000, $started['context']['original_login_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $started['context']['id']);
        $this->assertSame($last_login_before,
            $app->User_model->find_by_id($target->id)->last_login_at ?? null,
            'impersonation is not a customer login and must not touch last_login_at');

        $state = $app->impersonationservice->enforce();
        $this->assertTrue($state['active']);
        $this->assertTrue($app->impersonationservice->record_access(
            $state, 'GET', 'dashboard/orders', '203.0.113.9', 'Support browser', 'req-view'
        ));
        $app->session->set_userdata('customer_form_token', 'customer-only');

        $ended = $app->impersonationservice->end(
            'MANUAL', '203.0.113.9', 'Support browser', 'req-end'
        );
        $this->assertTrue($ended['actor_restored']);
        $this->assertSame((int)$actor->id, $app->session->userdata('user_id'));
        $this->assertSame($actor->role, $app->session->userdata('role'));
        $this->assertSame(1700000000, $app->session->userdata('login_at'));
        $this->assertNull($app->session->userdata(ImpersonationService::SESSION_KEY));
        $this->assertNull($app->session->userdata('customer_form_token'),
            'customer-scoped session data must not cross back into the staff identity');

        $logs = $app->db->order_by('id', 'ASC')->get('audit_logs')->result();
        $this->assertCount(3, $logs);
        $this->assertSame(array(
            'user.impersonation.started',
            'user.impersonation.viewed',
            'user.impersonation.ended',
        ), array_map(function ($row) { return $row->action; }, $logs));
        foreach ($logs as $log) {
            $this->assertSame((int)$actor->id, (int)$log->actor_id,
                'the operator, never the effective customer, owns every audit row');
            $this->assertSame($target->public_id, $log->resource_id);
        }
        $view = json_decode($logs[1]->after_json, true);
        $this->assertSame('/dashboard/orders', $view['path']);
        $this->assertSame('GET', $view['method']);
        $this->assertSame($started['context']['id'], $view['impersonation_id']);
        $end = json_decode($logs[2]->after_json, true);
        $this->assertSame('MANUAL', $end['end_reason']);
    }

    public function testStartRejectsMissingConsentInvalidReasonsIdentityMismatchAndUnsafeTargets()
    {
        list($app, $actor, $target) = $this->app();

        $this->assertSame('CONFIRMATION_REQUIRED',
            $app->impersonationservice->start($actor, $target, 'valid reason', false)['code']);
        $this->assertSame('BAD_REASON',
            $app->impersonationservice->start($actor, $target, 'no', true)['code']);

        $app->session->set_userdata('user_id', $target->id);
        $this->assertSame('SESSION_MISMATCH',
            $app->impersonationservice->start($actor, $target, 'valid reason', true)['code']);
        $app->session->set_userdata('user_id', $actor->id);
        $app->session->unset_userdata('login_at');
        $this->assertSame('SESSION_MISMATCH',
            $app->impersonationservice->start($actor, $target, 'valid reason', true)['code']);
        $app->session->set_userdata('login_at', time() + 300);
        $this->assertSame('SESSION_MISMATCH',
            $app->impersonationservice->start($actor, $target, 'valid reason', true)['code']);
        $app->session->set_userdata('login_at', 1700000000);

        $this->assertSame('BAD_TARGET',
            $app->impersonationservice->start($actor, $actor, 'valid reason', true)['code']);
        $staff = $app->register('staff2', 'staff2@example.test', 'Str0ng!pass1', 'STAFF');
        $this->assertSame('BAD_TARGET',
            $app->impersonationservice->start($actor, $staff, 'valid reason', true)['code']);

        $app->db->where('id', $target->id)->update('users', array('status' => 'SUSPENDED'));
        $this->assertSame('TARGET_INACTIVE',
            $app->impersonationservice->start($actor, $target, 'valid reason', true)['code']);
        $this->assertSame((int)$actor->id, $app->session->userdata('user_id'));
        $this->assertSame(0, $app->db->count('audit_logs'));
    }

    public function testPermissionIsDefendedInsideTheServiceAndNestedSessionsAreRejected()
    {
        list($denied_app, $admin, $customer) = $this->app('ADMIN');
        $denied = $denied_app->impersonationservice->start($admin, $customer, 'valid support reason', true);
        $this->assertSame('PERMISSION_DENIED', $denied['code']);
        $this->assertSame((int)$admin->id, $denied_app->session->userdata('user_id'));

        list($app, $owner, $target) = $this->app();
        $first = $app->impersonationservice->start($owner, $target, 'valid support reason', true);
        $this->assertTrue($first['ok']);
        $nested = $app->impersonationservice->start($owner, $target, 'another support reason', true);
        $this->assertSame('NESTED', $nested['code']);
        $this->assertSame((int)$target->id, $app->session->userdata('user_id'));
    }

    public function testPermissionRevocationTerminatesAnActiveSession()
    {
        list($app, $admin, $target) = $this->app('ADMIN');
        list($role_id, $permission_id) = $this->grantImpersonation($app);
        $started = $app->impersonationservice->start($admin, $target, 'valid support reason', true);
        $this->assertTrue($started['ok'], $started['error'] ?? '');

        $app->db->where('role_id', $role_id)->where('permission_id', $permission_id)
            ->delete('role_permissions');
        Permission_model::flush_cache(); // next HTTP request has a fresh per-request cache
        $ended = $app->impersonationservice->enforce();

        $this->assertSame('PERMISSION_REVOKED', $ended['reason']);
        $this->assertTrue($ended['actor_restored']);
        $this->assertSame((int)$admin->id, $app->session->userdata('user_id'));
        $this->assertNull($app->session->userdata(ImpersonationService::SESSION_KEY));
    }

    public function testAuditFailurePreventsTheIdentitySwitch()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $actor = $app->register('operator', 'operator@example.test', 'Str0ng!pass1', 'SUPER_ADMIN');
        $target = $app->register('customer', 'customer@example.test');
        $app->session->set_userdata(array('user_id' => $actor->id, 'role' => $actor->role, 'login_at' => 123));
        $app->Audit_log_model = new class {
            public function record() { return false; }
        };
        $app->library('ImpersonationService');

        $result = $app->impersonationservice->start($actor, $target, 'valid support reason', true);

        $this->assertSame('AUDIT_UNAVAILABLE', $result['code']);
        $this->assertSame((int)$actor->id, $app->session->userdata('user_id'));
        $this->assertNull($app->session->userdata(ImpersonationService::SESSION_KEY));
    }

    public function testExpiryAndTargetSuspensionTerminateAndRestoreTheActiveActor()
    {
        list($app, $actor, $target) = $this->app();
        $app->impersonationservice->start($actor, $target, 'valid support reason', true);
        $context = $app->session->userdata(ImpersonationService::SESSION_KEY);
        $context['started_at'] = time() - ImpersonationService::TTL - 1;
        $context['expires_at'] = $context['started_at'] + ImpersonationService::TTL;
        $app->session->set_userdata(ImpersonationService::SESSION_KEY, $context);

        $expired = $app->impersonationservice->enforce();
        $this->assertSame('EXPIRED', $expired['reason']);
        $this->assertTrue($expired['actor_restored']);
        $this->assertSame((int)$actor->id, $app->session->userdata('user_id'));

        // A separately started session also ends as soon as the target becomes
        // inactive, even if the browser has not reached its hard expiry.
        $app->impersonationservice->start($actor, $target, 'second support reason', true);
        $app->db->where('id', $target->id)->update('users', array('status' => 'SUSPENDED'));
        $inactive = $app->impersonationservice->enforce();
        $this->assertSame('TARGET_INACTIVE', $inactive['reason']);
        $this->assertTrue($inactive['actor_restored']);
        $this->assertSame((int)$actor->id, $app->session->userdata('user_id'));

        list($staff_app, $staff_actor, $staff_target) = $this->app();
        $staff_app->impersonationservice->start($staff_actor, $staff_target, 'third support reason', true);
        $staff_app->db->where('id', $staff_actor->id)->update('users', array('status' => 'SUSPENDED'));
        $actor_inactive = $staff_app->impersonationservice->enforce();
        $this->assertSame('ACTOR_INACTIVE', $actor_inactive['reason']);
        $this->assertFalse($actor_inactive['actor_restored']);
        $this->assertNull($staff_app->session->userdata('user_id'));
    }

    public function testIdentityMismatchAndMalformedContextDestroyRatherThanRestore()
    {
        list($app, $actor, $target) = $this->app();
        $app->impersonationservice->start($actor, $target, 'valid support reason', true);
        $app->session->set_userdata('user_id', 999999);
        $mismatch = $app->impersonationservice->enforce();
        $this->assertSame('SESSION_MISMATCH', $mismatch['reason']);
        $this->assertFalse($mismatch['actor_restored']);
        $this->assertNull($app->session->userdata('user_id'));

        list($bad_app, , ) = $this->app();
        $bad_app->session->set_userdata(ImpersonationService::SESSION_KEY, array(
            'version' => 1, 'id' => str_repeat('a', 32),
            'actor_id' => array(1), 'target_id' => 2,
        ));
        $invalid = $bad_app->impersonationservice->enforce();
        $this->assertSame('INVALID_CONTEXT', $invalid['reason']);
        $this->assertFalse($invalid['actor_restored']);
        $this->assertNull($bad_app->session->userdata('user_id'));

        list($scalar_app, , ) = $this->app();
        $scalar_app->session->set_userdata(ImpersonationService::SESSION_KEY, 'corrupted');
        $scalar = $scalar_app->impersonationservice->enforce();
        $this->assertSame('INVALID_CONTEXT', $scalar['reason']);
        $this->assertFalse($scalar['actor_restored']);
        $this->assertNull($scalar_app->session->userdata('user_id'));

        list($public_app, $public_actor, $public_target) = $this->app();
        $public_app->impersonationservice->start($public_actor, $public_target, 'valid support reason', true);
        $public_context = $public_app->session->userdata(ImpersonationService::SESSION_KEY);
        $public_context['target_public_id'] = 'USR99999999999999999999999';
        $public_app->session->set_userdata(ImpersonationService::SESSION_KEY, $public_context);
        $public_mismatch = $public_app->impersonationservice->enforce();
        $this->assertSame('INVALID_CONTEXT', $public_mismatch['reason']);
        $this->assertFalse($public_mismatch['actor_restored']);
        $this->assertNull($public_app->session->userdata('user_id'));
    }

    public function testRoutesControllersAndShellPinTheReadOnlyBoundary()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $core = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        $users = file_get_contents(self::$root.'/application/controllers/admin/Users.php');
        $stop = file_get_contents(self::$root.'/application/controllers/Impersonation.php');
        $layout = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $detail = file_get_contents(self::$root.'/application/views/admin/users/detail.php');

        $action = strpos($routes, "admin/customers/(:any)/impersonate");
        $catch_all = strpos($routes, "admin/customers/(:any)']");
        $this->assertNotFalse($action);
        $this->assertNotFalse($catch_all);
        $this->assertLessThan($catch_all, $action, 'action route must precede the customer detail catch-all');
        $this->assertStringContainsString("impersonation/stop'] = 'impersonation/stop", $routes);

        $this->assertStringContainsString("method === 'GET' || \$method === 'HEAD'", $core);
        $this->assertStringContainsString("\$path === 'dashboard' || strpos(\$path, 'dashboard/') === 0", $core);
        $this->assertStringContainsString("\$path === 'impersonation/stop' && \$method === 'POST'", $core);
        $this->assertStringContainsString("\$path === 'logout'", $core);
        $this->assertStringContainsString('record_access(', $core);
        $this->assertStringContainsString("show_error('Customer impersonation is read-only", $core);

        $this->assertStringContainsString("method(true) !== 'POST'", $users);
        $this->assertStringContainsString("guard(\$public_id, 'users.impersonate')", $users);
        $this->assertStringContainsString("\$this->input->post('confirm', true) === '1'", $users);
        $this->assertStringContainsString("method(true) !== 'POST'", $stop);
        $this->assertStringContainsString('csrf', strtolower($layout));
        $this->assertStringContainsString('IMPERSONATING CUSTOMER', $layout);
        $this->assertStringContainsString('impersonation-read-only main form[method="post" i]', $layout);
        $this->assertStringContainsString("site_url('impersonation/stop')", $layout);
        $this->assertStringContainsString("\$has('users.impersonate')", $detail);
        $this->assertStringContainsString('name="confirm"', $detail);
    }
}
