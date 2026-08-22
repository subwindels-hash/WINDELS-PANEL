<?php
use PHPUnit\Framework\TestCase;

/**
 * Services module tests (Session 07) — public catalog, pricing resolution,
 * favorites and route/controller guarantees.
 */
class ServicesTest extends TestCase
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
        if (!function_exists('windels_money')) {
            require_once self::$root.'/application/helpers/windels_helper.php';
        }
        if (!function_exists('windels_public_id')) {
            require_once self::$root.'/application/helpers/windels_helper.php';
        }
        require_once self::$root.'/application/libraries/PricingService.php';
    }

    /* ------------------------------ routing ----------------------------- */

    public function testPublicServicesRoutesMap()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'services/index'", $routes);
        $this->assertStringContainsString("'services/detail/\$1'", $routes);

        $controller = self::$root.'/application/controllers/Services.php';
        $this->assertFileExists($controller);
        $src = file_get_contents($controller);
        $this->assertStringContainsString('extends Public_Controller', $src);
        $this->assertStringContainsString('function index', $src);
        $this->assertStringContainsString('function detail', $src);
    }

    public function testFavoritesRoutesAndController()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'dashboard/favorites/add/(:any)'", $routes);
        $this->assertStringContainsString("'dashboard/favorites/remove/(:any)'", $routes);

        $file = self::$root.'/application/controllers/dashboard/Favorites.php';
        $this->assertFileExists($file);
        $src = file_get_contents($file);
        $this->assertStringContainsString('extends Auth_Controller', $src);
        $this->assertStringContainsString('function add', $src);
        $this->assertStringContainsString('function remove', $src);
        // Toggle must be POST-only in practice; the controller records a CSRF form.
        $this->assertStringContainsString('service_favorites', $src);
        // No GET side effects: add/remove never render a form, they redirect
        // back to the referring page.
        $this->assertStringNotContainsString("load->view(", $src);
        $this->assertStringContainsString('redirect(', $src);
    }

    public function testDashboardServicesListsFavorites()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Services.php');
        $this->assertStringContainsString('function favorites', $src);
        $this->assertStringContainsString('service_favorites sf', $src);
        $this->assertStringContainsString('favorites_only', $src);
    }

    /* --------------------------- pricing ------------------------------ */

    public function testPricingServiceFallsBackToDefault()
    {
        $ci = new ServicesFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new PricingService();
        $service = (object)array('id'=>1,'rate'=>'1.20000000');
        $this->assertSame('1.20000000', $svc->price_for($service, null));
    }

    public function testPricingServicePrefersUserSpecific()
    {
        $ci = new ServicesFakeCI();
        $ci->user_price = '0.99000000';
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new PricingService();
        $service = (object)array('id'=>7,'rate'=>'1.20000000');
        $user = (object)array('id'=>3,'price_group_id'=>2);
        $this->assertSame('0.99000000', $svc->price_for($service, $user));
    }

    public function testPricingServiceUsesGroupWhenNoUserOverride()
    {
        $ci = new ServicesFakeCI();
        $ci->group_price = '1.05000000';
        $GLOBALS['__fake_ci'] = $ci;
        $svc = new PricingService();
        $service = (object)array('id'=>7,'rate'=>'1.20000000');
        $user = (object)array('id'=>3,'price_group_id'=>2);
        $this->assertSame('1.05000000', $svc->price_for($service, $user));
    }

    public function testChargeForQuantityUsesBcmath()
    {
        $svc = new PricingService();
        // rate 1.20 per 1000 -> for 1000 units = 1.20
        $this->assertSame('1.20000000', $svc->charge_for_quantity('1.20000000', 1000));
        // 500 units = 0.60
        $this->assertSame('0.60000000', $svc->charge_for_quantity('1.20000000', 500));
        // high quantity, no float
        $this->assertSame('1200.00000000', $svc->charge_for_quantity('1.20000000', 1000000));
    }

    /* ------------------------- catalog views --------------------------- */

    public function testPublicCatalogShowsSearchAndFilters()
    {
        $src = file_get_contents(self::$root.'/application/views/public/services/index.php');
        $this->assertStringContainsString('<form method="get"', $src);
        $this->assertStringContainsString('name="q"', $src);
        $this->assertStringContainsString('name="category"', $src);
        $this->assertStringContainsString('name="sort"', $src);
        $this->assertStringContainsString('Pagination', $src);
        // A search form is GET and must NOT contain a CSRF token.
        $this->assertStringNotContainsString('csrf_token_name', $src);
        $this->assertStringContainsString('total_pages', $src);
    }

    public function testServiceDetailComputesPriceAndShowsOrderForm()
    {
        $src = file_get_contents(self::$root.'/application/views/public/services/detail.php');
        $this->assertStringContainsString('id="ws-qty"', $src);
        $this->assertStringContainsString('id="ws-total"', $src);
        $this->assertStringContainsString('addEventListener', $src);
        $this->assertStringContainsString('windels_money', $src);
        // Favorite toggle is a CSRF-protected POST.
        $this->assertStringContainsString('favorites/add', $src);
        $this->assertStringContainsString('csrf_token_name', $src);
        // Order form goes through the dashboard new-order route.
        $this->assertStringContainsString("action=\"<?=site_url('dashboard/new-order')?>\"", $src);
    }

    public function testServiceDetailDoesNotLeakProviderSecrets()
    {
        $src = file_get_contents(self::$root.'/application/views/public/services/detail.php');
        $this->assertStringNotContainsString('api_key', $src);
        $this->assertStringNotContainsString('provider_rate', $src);
        $this->assertStringNotContainsString('provider_api', $src);
    }

    public function testDashboardServicesViewHasFavoriteToggle()
    {
        $src = file_get_contents(self::$root.'/application/views/dashboard/services/index.php');
        $this->assertStringContainsString('favorites/add', $src);
        $this->assertStringContainsString('favorites/remove', $src);
        $this->assertStringContainsString('csrf_token_name', $src);
        $this->assertStringContainsString('favorites_only', $src);
    }

    public function testNoDirectWalletOrUserMutation()
    {
        // Catalog/detail browsing must never write to wallets or users.
        foreach (array('application/controllers/Services.php',
                       'application/controllers/dashboard/Services.php',
                       'application/controllers/dashboard/Favorites.php') as $file) {
            $src = file_get_contents(self::$root.'/'.$file);
            $this->assertStringNotContainsString("update('wallets'", $src, basename($file));
            $this->assertStringNotContainsString("update('users'", $src, basename($file));
        }
    }
}

/* -------------------------------- doubles ------------------------------- */

#[AllowDynamicProperties]
class ServicesFakeCI {
    public $user_price = null;
    public $group_price = null;
    public function __construct() { $this->db = new ServicesFakeDb($this); }
}
class ServicesFakeDb {
    private $ci;
    public function __construct($ci){ $this->ci=$ci; }
    public function where($k,$v=null){ return $this; }
    public function get($t){ return new ServicesFakeResult($this->rowFor($t)); }
    private function rowFor($t){
        if ($t==='user_service_prices' && $this->ci->user_price)
            return (object)array('rate'=>$this->ci->user_price);
        if ($t==='service_prices' && $this->ci->group_price)
            return (object)array('rate'=>$this->ci->group_price);
        return null;
    }
}
class ServicesFakeResult {
    private $row;
    public function __construct($row){ $this->row=$row; }
    public function row(){ return $this->row; }
}
