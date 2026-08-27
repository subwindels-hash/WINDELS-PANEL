<?php
use PHPUnit\Framework\TestCase;

/**
 * Customer dashboard tests (Session 06).
 *
 * Validates that every customer route maps to a real controller/method, the
 * dashboard overview assembles the expected aggregates, and the UI uses the
 * design system without exposing secrets. No database required.
 */
class DashboardTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) {
            eval('function &get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('marvy_money')) {
            require_once self::$root.'/application/helpers/marvy_helper.php';
        }
        require_once self::$root.'/application/libraries/DashboardStats.php';
    }

    public function testAllCustomerRoutesMapToExistingControllerMethods()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        // Map of route target => file:method that must exist.
        $map = array(
            'dashboard/dashboard/index'         => 'controllers/dashboard/Dashboard.php::index',
            'dashboard/orders/index'            => 'controllers/dashboard/Orders.php::index',
            'dashboard/orders/detail/$1'        => 'controllers/dashboard/Orders.php::detail',
            'dashboard/orders/new_order'        => 'controllers/dashboard/Orders.php::new_order',
            'dashboard/orders/mass_order'       => 'controllers/dashboard/Orders.php::mass_order',
            'dashboard/orders/mass_create'      => 'controllers/dashboard/Orders.php::mass_create',
            'dashboard/dripfeed/index'          => 'controllers/dashboard/Dripfeed.php::index',
            'dashboard/subscriptions/index'     => 'controllers/dashboard/Subscriptions.php::index',
            'dashboard/services/index'          => 'controllers/dashboard/Services.php::index',
            'dashboard/services/favorites'      => 'controllers/dashboard/Services.php::favorites',
            'dashboard/wallet/add_funds'        => 'controllers/dashboard/Wallet.php::add_funds',
            'dashboard/wallet/transactions'     => 'controllers/dashboard/Wallet.php::transactions',
            'dashboard/tickets/index'           => 'controllers/dashboard/Tickets.php::index',
            'dashboard/tickets/detail/$1'       => 'controllers/dashboard/Tickets.php::detail',
            'dashboard/account/api_keys'        => 'controllers/dashboard/Account.php::api_keys',
            'dashboard/referrals/index'         => 'controllers/dashboard/Referrals.php::index',
            'dashboard/notifications/index'     => 'controllers/dashboard/Notifications.php::index',
            'dashboard/account/profile'         => 'controllers/dashboard/Account.php::profile',
            'dashboard/account/security'        => 'controllers/dashboard/Account.php::security',
        );
        foreach ($map as $target => $spec) {
            list($rel, $method) = explode('::', $spec);
            $file = self::$root.'/application/'.$rel;
            $this->assertFileExists($file, "route target {$target} -> missing file {$rel}");
            $src = file_get_contents($file);
            $this->assertStringContainsString('function '.$method.'(', $src, "{$rel} missing method {$method}");
            $this->assertMatchesRegularExpression(
                "/\\\$route\['[^']+'\]\s*=\s*'".preg_quote($target, '/')."'/",
                $routes,
                "route for {$target} is not declared"
            );
        }
    }

    public function testAllDashboardControllersExtendAuthController()
    {
        foreach (glob(self::$root.'/application/controllers/dashboard/*.php') as $file) {
            $src = file_get_contents($file);
            $this->assertStringContainsString('extends Auth_Controller', $src, basename($file).' must extend Auth_Controller');
        }
    }

    public function testDashboardControllerPassesNavActiveAndUnread()
    {
        // The app shell relies on $nav_active and $unread — every dashboard
        // controller must provide them so the sidebar/notification bell work.
        foreach (glob(self::$root.'/application/controllers/dashboard/*.php') as $file) {
            $src = file_get_contents($file);
            // Controllers that only POST-and-redirect never render the shell,
            // so they have no view variables to pass.
            if (strpos($src, "load->view('layouts/app'") === false) {
                $this->assertStringContainsString('redirect(', $src,
                    basename($file).' renders no shell, so it must redirect');
                continue;
            }
            // The render() helper covers Account; the others pass inline.
            if (strpos(basename($file), 'Account.php') !== false) {
                $this->assertStringContainsString("'nav_active'", $src);
                $this->assertStringContainsString("'unread'", $src);
                continue;
            }
            $this->assertStringContainsString("'nav_active'", $src, basename($file).' must set nav_active');
            $this->assertStringContainsString("'unread'", $src, basename($file).' must pass unread count');
        }
    }

    public function testStatusBadgeCoversAllOrderStates()
    {
        foreach (array('PENDING','PROCESSING','IN_PROGRESS','COMPLETED','PARTIAL','CANCELED','CANCELLED','FAILED','REFUNDED') as $s) {
            $cls = DashboardStats::status_badge($s);
            $this->assertStringContainsString('badge', $cls, "no badge for status {$s}");
        }
        $this->assertSame('badge badge-default', DashboardStats::status_badge('UNKNOWN'));
    }

    public function testTransactionLabelsAreHumanReadable()
    {
        // Marketplace rows on historical installs may still carry
        // MARKETPLACE_PAYOUT types; they humanize via the generic fallback.
        foreach (array('DEPOSIT','ORDER_CHARGE','REFUND','REFERRAL_BONUS','ADJUSTMENT','MARKETPLACE_REFUND') as $t) {
            $obj = (object)array('type' => $t);
            $label = DashboardStats::transaction_label($obj);
            $this->assertNotEmpty($label);
            $this->assertDoesNotMatchRegularExpression('/^[A-Z_]+$/', $label, "{$t} should be humanized");
        }
    }

    public function testOverviewLoadsWalletAndAggregates()
    {
        // Drive DashboardStats against a fake CI super-object to prove it reads
        // the wallet and sums orders/transactions without touching a database.
        $ci = new DashboardFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $stats = new DashboardStats();

        $out = $stats->overview(42);

        $this->assertIsObject($out['wallet']);
        $this->assertSame(3, $out['totals']['orders']);
        $this->assertSame(2, $out['totals']['completed']);
        $this->assertSame(1, $out['totals']['active']);
        $this->assertSame('13.20000000', $out['totals']['spent']);
        $this->assertSame('100.00000000', $out['totals']['deposited']);
        $this->assertCount(2, $out['recent_orders']);
        $this->assertCount(2, $out['recent_transactions']);
        $this->assertSame(5, $out['unread_notifications']);
        $this->assertCount(1, $out['unread']);
    }

    public function testDashboardViewsUseDesignSystemAndNoSecrets()
    {
        foreach (glob(self::$root.'/application/views/dashboard/**/*.php') as $file) {
            $src = file_get_contents($file);
            // Component classes (not just raw utilities) should be used.
            $this->assertTrue(
                strpos($src, 'card') !== false || strpos($src, 'btn') !== false,
                basename($file).' should use design-system components'
            );
            // No password/secret leakage.
            $this->assertStringNotContainsString('password_hash', $src);
            $this->assertStringNotContainsString('api_key_encrypted', $src);
            // Every form that submits state is CSRF-protected. Disabled
            // placeholder forms (onsubmit="return false", no action) are exempt.
            if (preg_match_all('/<form\b[^>]*>/i', $src, $m)) {
                foreach ($m[0] as $tag) {
                    if (stripos($tag, 'method="get"') !== false) continue;
                    if (stripos($tag, 'onsubmit') !== false) continue;
                    $this->assertStringContainsString('csrf_token_name', $src, basename($file).' submitting POST form missing CSRF');
                }
            }
        }
    }

    public function testAppShellRendersNotificationBadgeAndMobileNav()
    {
        $shell = file_get_contents(self::$root.'/application/views/layouts/app.php');
        $this->assertStringContainsString('dashboard/notifications', $shell);
        $this->assertStringContainsString('Mobile bottom nav', $shell);
        $this->assertStringContainsString('partials/icon', $shell);
        // Brand component classes used.
        $this->assertStringContainsString('bg-brand-50', $shell);
        $this->assertStringContainsString('text-brand-700', $shell);
    }
}

/* -------------------------------- doubles -------------------------------- */

#[AllowDynamicProperties]
class DashboardFakeCI {
    public $load;
    public $db;
    public $wallet;
    public function __construct() {
        $GLOBALS['__fake_ci'] = $this;
        $this->wallet = (object)array('id'=>11,'user_id'=>7,'balance'=>'42.00000000','currency'=>'NGN');
        $this->load = new DashboardFakeLoader($this);
        $this->db   = new DashboardFakeDb();
    }
}
/** Mirrors CI3: loaded models are assigned under their class name. */
class DashboardFakeLoader {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function library($n) {}
    public function model($names) {
        foreach ((array)$names as $name) {
            if (isset($this->ci->$name)) continue;
            if ($name === 'Wallet_model')       $this->ci->$name = new DashboardFakeWalletModel($this->ci);
            elseif ($name === 'Order_model')    $this->ci->$name = new DashboardFakeOrderModel($this->ci);
            elseif ($name === 'Notification_model') $this->ci->$name = new DashboardFakeNotificationModel($this->ci);
            else $this->ci->$name = new stdClass();
        }
    }
}
/* The model doubles read through DashboardFakeDb, exactly as the real ones do,
   so the fixtures live in one place. */
class DashboardFakeWalletModel {
    private $ci; public function __construct($ci) { $this->ci = $ci; }
    public function for_user($id) { return $this->ci->db->where('user_id', $id)->get('wallets')->row(); }
}
class DashboardFakeOrderModel {
    private $ci; public function __construct($ci) { $this->ci = $ci; }
    public function for_user($id, $l = 25, $o = 0, $s = null) {
        return $this->ci->db->where('user_id', $id)->limit($l, $o)->get('orders')->result();
    }
}
class DashboardFakeNotificationModel {
    private $ci; public function __construct($ci) { $this->ci = $ci; }
    public function unread_for_user($id, $l = 20) {
        return $this->ci->db->where('user_id', $id)->limit($l)->get('notifications')->result();
    }
}
class DashboardFakeDb {
    private $selects = array();
    private $last_table;
    public function select($s, $b=false){ $this->selects[]=$s; return $this; }
    public function where($k,$v=null){ return $this; }
    public function where_in($k,$v){ return $this; }
    public function order_by($k){ return $this; }
    public function limit($l,$o=0){ return $this; }
    public function join($t,$on,$type=''){ return $this; }
    private $from_table;
    public function from($t){ $this->from_table=$t; $this->last_table=$t; return $this; }
    public function group_start(){ return $this; }
    public function group_end(){ return $this; }
    public function insert($t,$d=array()){ return true; }
    public function insert_id(){ return 1; }
    public function count_all_results($t){
        if ($t==='notifications') return 5;
        return 3;
    }
    public function get($t=null){
        $this->last_table = $t;
        // CI3 resets the builder state after each get(); so must the fake, or
        // a previous ->from() leaks into the next query.
        $from = $this->from_table;
        $this->from_table = null;
        $selects = $this->selects;
        $this->selects = array();
        // Deposit-sum join query (from/join form).
        if ($from === 'wallet_transactions wt') {
            return new DashboardFakeResult(array(
                (object)array('total'=>'100.00000000'),
            ));
        }
        if ($t==='wallets') {
            return new DashboardFakeResult(array(
                (object)array('id'=>7,'balance'=>'42.00000000','currency'=>'NGN'),
            ));
        }
        if ($t==='wallet_transactions') return new DashboardFakeResult(array(
            (object)array('public_id'=>'wt1','direction'=>'CREDIT','amount'=>'100.00000000','currency'=>'NGN','type'=>'DEPOSIT','created_at'=>date('Y-m-d H:i:s')),
            (object)array('public_id'=>'wt2','direction'=>'DEBIT','amount'=>'1.20000000','currency'=>'NGN','type'=>'ORDER_CHARGE','created_at'=>date('Y-m-d H:i:s')),
        ));
        if ($t==='notifications') return new DashboardFakeResult(array(
            (object)array('public_id'=>'n1','title'=>'Order complete','body'=>'done','channel'=>'IN_APP','is_read'=>0,'created_at'=>date('Y-m-d H:i:s')),
        ));
        if ($t==='orders') {
            // If we selected aggregate columns, return the aggregate row.
            $sel = implode(' ', $selects);
            if (strpos($sel, 'COUNT(*)') !== false) {
                return new DashboardFakeResult(array(
                    (object)array('orders'=>3,'completed'=>2,'active'=>1,'pending'=>0,'spent'=>'13.20000000'),
                ));
            }
            return new DashboardFakeResult(array(
                (object)array('public_id'=>'o1','service_id'=>1,'service_name'=>'IG Followers','quantity'=>100,'charge'=>'1.20000000','currency'=>'NGN','status'=>'COMPLETED','created_at'=>date('Y-m-d H:i:s')),
                (object)array('public_id'=>'o2','service_id'=>2,'service_name'=>'TT Likes','quantity'=>50,'charge'=>'12.00000000','currency'=>'NGN','status'=>'IN_PROGRESS','created_at'=>date('Y-m-d H:i:s')),
            ));
        }
        return new DashboardFakeResult(array());
    }
    public function query($sql){ return new DashboardFakeResult(array()); }
}
class DashboardFakeResult {
    private $rows;
    public function __construct($rows){ $this->rows=$rows; }
    public function result(){ return $this->rows; }
    public function row(){ return reset($this->rows) ?: null; }
}
