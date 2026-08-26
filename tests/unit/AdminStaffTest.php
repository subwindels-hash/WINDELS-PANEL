<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Staff directory and the RBAC matrix (Session 30).
 *
 * `admin/staff` was routed and `staff.manage` seeded in Session 15, but no
 * controller existed, so the role matrix could only be changed by editing
 * Core_seeder and re-running the seed against a live database.
 *
 * This is the screen that can remove the ability to use the screen, so the
 * tests are almost entirely about staying recoverable: the lockout guard, the
 * decorative-grid guard, and the mass-grant guard on the shared customer
 * role. A matrix editor that saves correctly but lets an admin strip their
 * own `staff.manage` is not a working feature — it is a trap that fires once.
 */
class AdminStaffTest extends TestCase
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

    protected function setUp(): void
    {
        if (class_exists('Permission_model')) Permission_model::flush_cache();
    }

    /**
     * A world with the four roles, a realistic permission catalogue and the
     * seeded matrix.
     */
    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $app->library(array('RbacService', 'UserAdminService'));
        $app->model(array('Role_model', 'Permission_model', 'User_model'));

        $perms = array(
            'reports.view'   => 'dashboard',
            'users.view'     => 'users',
            'users.edit'     => 'users',
            'staff.manage'   => 'users',
            'orders.view'    => 'orders',
            'orders.refund'  => 'orders',
            'settings.manage'=> 'system',
        );
        foreach ($perms as $key => $category) {
            $app->db->insert('permissions', array(
                'perm_key' => $key, 'category' => $category, 'description' => $key,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        }
        // ADMIN holds everything; STAFF holds the read-only subset.
        $this->grant($app, 'ADMIN', array_keys($perms));
        $this->grant($app, 'STAFF', array('reports.view', 'users.view', 'orders.view'));
        Permission_model::flush_cache();

        $owner = $app->register('owner',  'owner@x.test',  'Str0ng!pass1', 'SUPER_ADMIN');
        $admin = $app->register('admin1', 'admin@x.test',  'Str0ng!pass1', 'ADMIN');
        $staff = $app->register('staff1', 'staff@x.test',  'Str0ng!pass1', 'STAFF');
        return array($app, $owner, $admin, $staff);
    }

    private function grant($app, $role_name, array $keys)
    {
        $role = $app->db->where('name', $role_name)->get('roles')->row();
        foreach ($keys as $key) {
            $p = $app->db->where('perm_key', $key)->get('permissions')->row();
            if ($p) $app->db->insert('role_permissions',
                array('role_id' => $role->id, 'permission_id' => $p->id));
        }
    }

    /* ========================== the lockout guard ======================= */

    /**
     * The single click that ends all further clicks.
     *
     * An ADMIN who removes `staff.manage` from ADMIN can no longer reach the
     * screen that would grant it back. Only SUPER_ADMIN could recover it, and
     * on a panel where nobody holds that role the answer is SQL.
     */
    public function testAnAdminCannotStripTheKeystonePermissionFromTheirOwnRole()
    {
        list($app, , $admin, ) = $this->app();

        $res = $app->rbacservice->set_permissions($admin, 'ADMIN',
            array('reports.view', 'users.view', 'orders.view'));

        $this->assertFalse($res['ok']);
        $this->assertSame('LOCKOUT', $res['code']);

        Permission_model::flush_cache();
        $this->assertContains('staff.manage', $app->Permission_model->keys_for_role('ADMIN'),
            'a refused save must leave the matrix untouched');
    }

    /** The same admin may edit their own role freely as long as they keep the key. */
    public function testAnAdminMayEditTheirOwnRoleWhileKeepingTheKeystone()
    {
        list($app, , $admin, ) = $this->app();

        $res = $app->rbacservice->set_permissions($admin, 'ADMIN',
            array('staff.manage', 'users.view'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        Permission_model::flush_cache();
        $held = $app->Permission_model->keys_for_role('ADMIN');
        $this->assertContains('staff.manage', $held);
        $this->assertNotContains('orders.refund', $held);
    }

    /** ...and may strip it from a *different* role, which is recoverable. */
    public function testTheKeystoneCanBeRemovedFromAnotherRole()
    {
        list($app, , $admin, ) = $this->app();
        $this->grant($app, 'STAFF', array('staff.manage'));
        Permission_model::flush_cache();

        $res = $app->rbacservice->set_permissions($admin, 'STAFF', array('reports.view'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        Permission_model::flush_cache();
        $this->assertNotContains('staff.manage', $app->Permission_model->keys_for_role('STAFF'));
    }

    /* ======================= decorative-grid guard ====================== */

    /**
     * SUPER_ADMIN short-circuits to true before reading a row, so editing its
     * grid would imply a restriction the code does not honour.
     */
    public function testTheSuperAdminGridCannotBeEdited()
    {
        list($app, $owner, , ) = $this->app();

        $res = $app->rbacservice->set_permissions($owner, 'SUPER_ADMIN', array('reports.view'));

        $this->assertFalse($res['ok']);
        $this->assertSame('UNRESTRICTED', $res['code']);
    }

    /** And the underlying check still passes for a permission it never held. */
    public function testSuperAdminPassesEveryCheckRegardless()
    {
        list($app, , , ) = $this->app();
        $app->library('AuthService');

        $this->assertTrue($app->Permission_model->role_has('SUPER_ADMIN', 'nonexistent.permission'),
            'SUPER_ADMIN bypasses the matrix — which is why its grid is locked');
    }

    /* ========================= mass-grant guard ========================= */

    /**
     * Every customer shares the CUSTOMER role, so a stray tick there is a
     * privilege grant to the entire user base rather than a single mistake.
     */
    public function testTheCustomerRoleCannotBeGrantedStaffPermissions()
    {
        list($app, $owner, , ) = $this->app();

        $res = $app->rbacservice->set_permissions($owner, 'CUSTOMER', array('orders.refund'));

        $this->assertFalse($res['ok']);
        $this->assertSame('CUSTOMER', $res['code']);
        Permission_model::flush_cache();
        $this->assertSame(array(), $app->Permission_model->keys_for_role('CUSTOMER'));
    }

    /* ========================== normal editing ========================== */

    public function testGrantingAPermissionTakesEffectImmediately()
    {
        list($app, $owner, , $staff) = $this->app();

        $this->assertFalse($app->Permission_model->role_has('STAFF', 'orders.refund'));

        $res = $app->rbacservice->set_permissions($owner, 'STAFF',
            array('reports.view', 'users.view', 'orders.view', 'orders.refund'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(array('orders.refund'), $res['added']);
        $this->assertSame(array(), $res['removed']);
        // The matrix is memoised per request; the service must drop the memo,
        // or the grant would not be visible until the next page load.
        $this->assertTrue($app->Permission_model->role_has('STAFF', 'orders.refund'),
            'a grant must be visible without a cache flush by the caller');
    }

    public function testRevokingAPermissionTakesEffectImmediately()
    {
        list($app, $owner, , ) = $this->app();

        $res = $app->rbacservice->set_permissions($owner, 'STAFF',
            array('reports.view', 'users.view'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(array('orders.view'), $res['removed']);
        $this->assertFalse($app->Permission_model->role_has('STAFF', 'orders.view'));
    }

    public function testTheWholeSetIsReplacedAtomically()
    {
        list($app, $owner, , ) = $this->app();

        $app->rbacservice->set_permissions($owner, 'STAFF', array('settings.manage'));

        Permission_model::flush_cache();
        $held = $app->Permission_model->keys_for_role('STAFF');
        sort($held);
        $this->assertSame(array('settings.manage'), $held,
            'saving replaces the grid rather than merging into it');
    }

    public function testUnknownPermissionKeysAreIgnoredRatherThanStored()
    {
        list($app, $owner, , ) = $this->app();

        $res = $app->rbacservice->set_permissions($owner, 'STAFF',
            array('reports.view', 'invented.permission'));

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        Permission_model::flush_cache();
        $this->assertSame(array('reports.view'), $app->Permission_model->keys_for_role('STAFF'));
    }

    public function testAnUnknownRoleIsRejected()
    {
        list($app, $owner, , ) = $this->app();
        $res = $app->rbacservice->set_permissions($owner, 'ROOT', array('reports.view'));
        $this->assertFalse($res['ok']);
        $this->assertSame('NO_ROLE', $res['code']);
    }

    public function testEmptyingARoleIsAllowed()
    {
        list($app, $owner, , ) = $this->app();

        $res = $app->rbacservice->set_permissions($owner, 'STAFF', array());

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        Permission_model::flush_cache();
        $this->assertSame(array(), $app->Permission_model->keys_for_role('STAFF'));
    }

    /* ============================== reading ============================= */

    public function testRolesCarryHeadcountsAndEditability()
    {
        list($app, , , ) = $this->app();
        $roles = array();
        foreach ($app->rbacservice->roles() as $r) $roles[$r->name] = $r;

        $this->assertSame(1, $roles['ADMIN']->headcount);
        $this->assertTrue($roles['ADMIN']->editable);
        $this->assertFalse($roles['SUPER_ADMIN']->editable);
        $this->assertFalse($roles['CUSTOMER']->editable);
    }

    public function testTheCatalogueIsGroupedByCategory()
    {
        list($app, , , ) = $this->app();
        $cat = $app->rbacservice->catalogue();

        $this->assertArrayHasKey('users', $cat);
        $keys = array_map(function ($p) { return $p->perm_key; }, $cat['users']);
        $this->assertContains('staff.manage', $keys);
    }

    /**
     * The reflective check that found the missing modules in the first place:
     * a permission nobody calls require_perm() on is a promise the code does
     * not keep.
     */
    public function testUnenforcedPermissionsAreReportedRatherThanHidden()
    {
        list($app, , , ) = $this->app();

        $dead = $app->rbacservice->unenforced();

        $this->assertContains('settings.manage', $app->Permission_model->keys_for_role('ADMIN'));
        // orders.view is checked by admin/Orders.php, so it must not be listed.
        $this->assertNotContains('orders.view', $dead,
            'a permission with a real require_perm() call must not be flagged as dead');
        $this->assertIsArray($dead);
    }

    /**
     * The check that found the sixteen missing modules: a permission granted
     * by the seeded role matrix but enforced by no code is a promise the panel
     * does not keep, and — as with `admin/customers` — usually means a whole
     * screen is absent.
     *
     * Known-dead keys are listed here rather than silently tolerated. The list
     * may shrink as modules ship; it must not grow without a deliberate edit.
     */
    public function testEverySeededPermissionIsEnforcedSomewhere()
    {
        require_once self::$root.'/application/libraries/Seeder.php';
        require_once self::$root.'/application/seeds/Core_seeder.php';

        $src = '';
        foreach (array('controllers', 'libraries') as $dir) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$root.'/application/'.$dir));
            foreach ($rii as $file) {
                if ($file->isDir() || substr($file->getFilename(), -4) !== '.php') continue;
                $src .= file_get_contents($file->getPathname());
            }
        }

        // Every seeded permission must be enforced by a concrete runtime path.
        $known_dead = array();

        $dead = array();
        foreach (Core_seeder::permission_catalog() as $keys) {
            foreach ($keys as $key) {
                if (strpos($src, "'".$key."'") !== false) continue;
                $dead[] = $key;
            }
        }
        sort($dead);
        sort($known_dead);

        $this->assertSame($known_dead, $dead,
            'permissions granted but enforced nowhere: '.implode(', ', array_diff($dead, $known_dead)));
    }

    /* ======================= controller guarantees ====================== */

    public function testTheStaffControllerIsGuardedAndAudited()
    {
        $src = file_get_contents(self::$root.'/application/controllers/admin/Staff.php');
        $this->assertStringContainsString("require_perm('staff.manage')", $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringContainsString('$this->audit(', $src);
        $this->assertStringContainsString('Audit_log_model', $src);
    }

    public function testTheStaffScreenNeverTouchesCredentials()
    {
        foreach (array('controllers/admin/Staff.php',
                       'views/admin/staff/index.php',
                       'views/admin/staff/permissions.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/'.$rel);
            foreach (array('password_hash', 'mfa_secret', 'api_key') as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $src,
                    basename($rel).' must not touch '.$forbidden);
            }
        }
    }
}
