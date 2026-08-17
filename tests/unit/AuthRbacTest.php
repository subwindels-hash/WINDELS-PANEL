<?php
use PHPUnit\Framework\TestCase;

/**
 * AuthService + RBAC tests.
 *
 * These exercise the pure, database-free parts of the AuthService (password
 * hashing, role/permission decisions) and verify source-level guarantees that
 * the Session 03 stub has been replaced by a real RBAC check.
 */
class AuthRbacTest extends TestCase
{
    private static $root;
    /** @var AuthRbacFakeCI */
    private $fake_ci;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        // Minimal CI shims so the libraries can be loaded standalone.
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!class_exists('User_model')) {
            eval('class User_model { public function find_by_id($id){ $u = get_instance()->current_user; return ($u && (int)$u->id === (int)$id) ? $u : null; } public function touch_login($id,$ip){} }');
        }
        if (!class_exists('Role_model')) eval('class Role_model {}');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('windels_public_id')) {
            require_once self::$root.'/application/helpers/windels_helper.php';
        }
        require_once self::$root.'/application/libraries/SignedToken.php';
        require_once self::$root.'/application/libraries/Totp.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/AuthService.php';
    }

    protected function setUp(): void
    {
        // Install (or reuse) the get_instance() shim and point it at a fresh
        // fake CI for this test. The shared global slot is the same one
        // SeedRunTest uses, so we cooperate rather than redefine it.
        if (!function_exists('get_instance')) {
            eval('function get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        $this->fake_ci = new AuthRbacFakeCI();
        $GLOBALS['__fake_ci'] = $this->fake_ci;
    }

    public function testPasswordHashUsesArgon2OrBcryptAndVerifies()
    {
        $auth = new AuthService();
        $hash = $auth->hash_password('Horse-Battery-Staple-9!');
        $this->assertMatchesRegularExpression('/^\$(argon2id|2y)\$/', $hash);
        $this->assertTrue(password_verify('Horse-Battery-Staple-9!', $hash));
        $this->assertFalse(password_verify('wrong-password', $hash));
    }

    public function testSuperAdminBypassesPermissionChecks()
    {
        $ci = $this->fake_ci;
        $ci->perm_model->set_keys('ADMIN', array('orders.view'));

        $auth = new AuthService();
        $ci->current_user = (object)array('id' => 1, 'role' => 'SUPER_ADMIN', 'status' => 'ACTIVE');

        $this->assertTrue($auth->can('anything.at.all'));
        $this->assertSame(array('*'), $auth->permissions());
        $this->assertTrue($auth->has_role(array('SUPER_ADMIN','ADMIN')));
    }

    public function testStaffPermissionIsEnforcedFromRoleMatrix()
    {
        $ci = $this->fake_ci;
        // STAFF can orders.view but not orders.refund or settings.manage.
        $ci->perm_model->set_keys('STAFF', array('orders.view','orders.edit','tickets.reply'));

        $auth = new AuthService();
        $ci->current_user = (object)array('id' => 2, 'role' => 'STAFF', 'status' => 'ACTIVE');

        $this->assertTrue($auth->can('orders.view'));
        $this->assertFalse($auth->can('orders.refund'));
        $this->assertFalse($auth->can('settings.manage'));
        $this->assertSame(array('orders.view','orders.edit','tickets.reply'), $auth->permissions());
    }

    public function testUnauthenticatedUserHasNoPermissions()
    {
        $ci = $this->fake_ci;
        $auth = new AuthService();
        $ci->current_user = null;
        $this->assertFalse($auth->check());
        $this->assertFalse($auth->can('orders.view'));
        $this->assertSame(array(), $auth->permissions());
    }

    public function testCanAcceptsExplicitRoleArgument()
    {
        $ci = $this->fake_ci;
        $ci->perm_model->set_keys('CUSTOMER', array());
        $auth = new AuthService();
        // CUSTOMER role holds no admin perms regardless of current session.
        $this->assertFalse($auth->can('orders.view', 'CUSTOMER'));
        $this->assertTrue($auth->can('orders.view', 'SUPER_ADMIN'));
    }

    /* ----- source-level guarantees (the thing that replaces the TODO) ----- */

    public function testRealRbacReplacedSession03Stub()
    {
        $core = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        $this->assertStringNotContainsString('TODO Session 03', $core, 'Session 03 RBAC stub must be removed');
        $this->assertStringContainsString('AuthService', $core);
        $this->assertStringContainsString('$this->auth->can(', $core);
        // Admin gate must still restrict to staff roles.
        $this->assertStringContainsString("'SUPER_ADMIN','ADMIN','STAFF'", $core);
    }

    public function testAuthControllerExistsAndHandlesAllAuthRoutes()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Auth.php');
        foreach (array('function login', 'function register', 'function logout',
                       'function forgot_password', 'function reset_password',
                       'function verify_email', 'function mfa_verify') as $needle) {
            $this->assertStringContainsString($needle, $src, "Auth controller missing {$needle}");
        }
        // No license artifacts (§81).
        $this->assertStringNotContainsString('PURCHASE_CODE', $src);
        $this->assertStringNotContainsString('Envato', $src);
        // Passwords must be hashed, never stored plaintext: the controller
        // delegates to AuthService and performs no password_hash/password column write.
        $this->assertStringNotContainsString("'password' =>", $src); // no raw password column write
        $this->assertStringNotContainsString('password_hash(', $src);
        $this->assertStringContainsString('auth->', $src); // delegates to the service
    }

    public function testAuthLibraryNeverLogsSecretsOrRawPasswords()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        // The raw password must never be written into a column or an audit row.
        $this->assertDoesNotMatchRegularExpression('/["\']password["\']\s*=>\s*\$/', $src);
        // Every persisted password hash comes from the hashing helper.
        $this->assertStringContainsString("'password_hash' => \$this->hash_password", $src);
        // A failed login must run password_verify against a dummy hash (no user enumeration via timing).
        $this->assertStringContainsString('DUMMY_BCRYPT', $src);
        // MFA secret is stored via the encryption service, not plaintext.
        $this->assertStringContainsString('encryptionservice->encrypt', $src);
        $this->assertStringContainsString('sess_regenerate', $src, 'login must regenerate session id');
    }

    public function testRateLimiterCountsFailuresWithoutRedis()
    {
        require_once self::$root.'/application/libraries/RateLimiter.php';
        $ci = $this->fake_ci;
        $ci->rate_db = new AuthRbacFakeRateDb();
        $rl = new RateLimiter();
        // Reflection to redirect $ci->db to the fake for this library's instance.
        $ref = new ReflectionProperty($rl, 'ci'); $ref->setAccessible(true);
        $stub = new stdClass(); $stub->db = $ci->rate_db;
        $ref->setValue($rl, $stub);

        $this->assertFalse($rl->too_many_failures('1.2.3.4', 'a@b.com', 3, 900));
        $rl->record('a@b.com', '1.2.3.4', false);
        $rl->record('a@b.com', '1.2.3.4', false);
        $this->assertFalse($rl->too_many_failures('1.2.3.4', 'a@b.com', 3, 900));
        $rl->record('a@b.com', '1.2.3.4', false);
        $this->assertTrue($rl->too_many_failures('1.2.3.4', 'a@b.com', 3, 900));
    }
}

/* ----------------------------- test doubles ----------------------------- */

#[AllowDynamicProperties]
class AuthRbacFakeCI {
    public $perm_model;
    public $session;
    public $current_user = null;
    public $load;
    public $input;
    public $db;
    public $rate_db;
    public $request_id = 'test';

    public function __construct()
    {
        $this->perm_model = new AuthRbacFakePermModel();
        $this->session = new AuthRbacFakeSession();
        $this->load = new AuthRbacFakeLoader($this);
        $this->input = new AuthRbacFakeInput();
        $this->db = new AuthRbacFakeDb();
    }
}

class AuthRbacFakeLoader {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }

    /** Mimic CI3's loader: assign loaded models/libraries as properties. */
    public function library($names, $params = null, $object_name = null) {
        foreach ((array)$names as $name) {
            $prop = strtolower($object_name ?: $name);
            if (isset($this->ci->$prop)) continue;
            // AuthService loads the real primitive libraries.
            if ($name === 'SignedToken') $this->ci->$prop = new SignedToken();
            elseif ($name === 'Totp') $this->ci->$prop = new Totp();
            elseif ($name === 'EncryptionService') $this->ci->$prop = new EncryptionService();
            else $this->ci->$prop = new stdClass();
        }
        return $this;
    }

    public function model($names) {
        foreach ((array)$names as $name) {
            $prop = strtolower($name);
            if (isset($this->ci->$prop)) continue;
            if ($name === 'Permission_model') $this->ci->$prop = $this->ci->perm_model;
            elseif (class_exists($name)) $this->ci->$prop = new $name();
            else $this->ci->$prop = new stdClass();
        }
        return $this;
    }
}

class AuthRbacFakeSession {
    private $data = array();
    public function userdata($k) {
        if ($k === 'user_id') {
            $u = get_instance()->current_user;
            return $u ? (int)$u->id : null;
        }
        return $this->data[$k] ?? null;
    }
    public function set_userdata($k, $v = null) { if (is_array($k)) $this->data = array_merge($this->data, $k); else $this->data[$k] = $v; }
    public function unset_userdata($k) { unset($this->data[$k]); }
    public function sess_regenerate($destroy=false) {}
    public function sess_destroy() { $this->data = array(); }
    public function set_flashdata($k,$v) {}
}

class AuthRbacFakeInput {
    public function ip_address() { return '127.0.0.1'; }
    public function user_agent() { return 'PHPUnit'; }
}

class AuthRbacFakeDb {
    public function insert($t,$d){ return true; }
    public function where($k,$v=null){ return $this; }
}

class AuthRbacFakePermModel {
    private $keys = array();
    public function set_keys($role, array $keys) { $this->keys[$role] = $keys; }
    public function keys_for_role($role) { return $this->keys[$role] ?? array(); }
    public function role_has($role, $key) {
        if ($role === 'SUPER_ADMIN') return true;
        return in_array($key, $this->keys[$role] ?? array(), true);
    }
}

class AuthRbacFakeRateDb {
    public $attempts = array();
    public function insert($t, $d) { $this->attempts[] = $d; return $this; }
    public function where($k, $v = null) { return $this; }
    public function group_start() { return $this; }
    public function group_end() { return $this; }
    public function or_where($k, $v) { return $this; }
    public function count_all_results($t) {
        $n = 0;
        foreach ($this->attempts as $a) {
            if (empty($a['success']) && ($a['ip']==='1.2.3.4' || $a['email']==='a@b.com')) $n++;
        }
        return $n;
    }
}
