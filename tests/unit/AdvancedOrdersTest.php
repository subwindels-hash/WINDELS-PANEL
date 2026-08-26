<?php
use PHPUnit\Framework\TestCase;

// Test doubles at the bottom of this file implement ProviderAdapterInterface,
// which PHP must resolve while compiling the file — before setUpBeforeClass runs.
if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__, 2).'/system/');
require_once dirname(__DIR__, 2).'/application/libraries/ProviderAdapterInterface.php';

/**
 * Advanced orders tests (Session 10) — refills, drip-feed and subscriptions
 * validation and lifecycle, plus routing/controller/source guarantees.
 */
class AdvancedOrdersTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('marvy_public_id')) require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/OrderStateMachine.php';
        require_once self::$root.'/application/libraries/PricingService.php';
        require_once self::$root.'/application/libraries/LedgerService.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/MockProviderAdapter.php';
        require_once self::$root.'/application/libraries/StandardSmmAdapter.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
        require_once self::$root.'/application/libraries/OrderService.php';
        require_once self::$root.'/application/libraries/RefillService.php';
        require_once self::$root.'/application/libraries/DripfeedService.php';
        require_once self::$root.'/application/libraries/SubscriptionService.php';
    }

    /* ----------------------------- refill ---------------------------- */

    public function testRefillRejectedForUnsupportedService()
    {
        $ci = $this->ci();
        $ci->service->refill_supported = 0;
        $svc = new RefillService();
        $res = $svc->request('ORDER1', $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('UNSUPPORTED', $res['code']);
    }

    public function testRefillRejectedForActiveOrder()
    {
        $ci = $this->ci();
        $ci->order->status = 'IN_PROGRESS';
        $svc = new RefillService();
        $res = $svc->request('ORDER1', $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_REFILLABLE', $res['code']);
    }

    public function testRefillRejectsDuplicate()
    {
        $ci = $this->ci();
        $ci->refill_active = true;
        $svc = new RefillService();
        $res = $svc->request('ORDER1', $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('DUPLICATE', $res['code']);
    }

    public function testRefillCreatesProcessingRowWithProvider()
    {
        $ci = $this->ci();
        $svc = new RefillService();
        $res = $svc->request('ORDER1', $ci->user);
        $this->assertTrue($res['ok']);
        $this->assertSame('PROCESSING', $res['refill']->status);
        $this->assertSame('r_42', $res['refill']->provider_refill_id);
        $this->assertSame(1, $ci->inserts['refills']);
        $this->assertSame(1, $ci->inserts['refill_status_history']);
    }

    public function testRefillWithoutProviderStaysPending()
    {
        $ci = $this->ci();
        $ci->order->provider_id = null;
        $svc = new RefillService();
        $res = $svc->request('ORDER1', $ci->user);
        $this->assertTrue($res['ok']);
        $this->assertSame('PENDING', $res['refill']->status);
    }

    /* ---------------------------- dripfeed --------------------------- */

    public function testDripfeedRequiresRunsAndInterval()
    {
        $ci = $this->ci();
        $svc = new DripfeedService();
        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'link'=>'https://x.com/a',
            'quantity_per_run'=>100,'runs'=>1,'total_quantity'=>100,'interval_minutes'=>60,
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_RUNS', $res['code']);

        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'link'=>'https://x.com/a',
            'quantity_per_run'=>100,'runs'=>4,'total_quantity'=>400,'interval_minutes'=>1,
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_INTERVAL', $res['code']);
    }

    public function testDripfeedTotalMustMatchRuns()
    {
        $ci = $this->ci();
        $svc = new DripfeedService();
        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'link'=>'https://x.com/a',
            'quantity_per_run'=>100,'runs'=>4,'total_quantity'=>500,'interval_minutes'=>60,
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_TOTAL', $res['code']);
    }

    public function testDripfeedCreatesScheduleAndReservesCharge()
    {
        $ci = $this->ci();
        $svc = new DripfeedService();
        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'link'=>'https://x.com/a',
            'quantity_per_run'=>100,'runs'=>4,'total_quantity'=>400,'interval_minutes'=>60,
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $drip = $res['dripfeed'];
        $this->assertSame('ACTIVE', $drip->status);
        $this->assertSame(4, (int)$drip->runs);
        $this->assertSame('0.48000000', $drip->charge); // 1.20/1000*400
        $this->assertSame(1, $ci->ledger_charges);
        $this->assertSame(1, $ci->inserts['dripfeed_orders']);
        $this->assertSame(4, $ci->inserts['dripfeed_runs']);
    }

    public function testDripfeedInsufficientBalance()
    {
        $ci = $this->ci();
        $ci->wallet->balance = '0.10000000';
        $svc = new DripfeedService();
        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'link'=>'https://x.com/a',
            'quantity_per_run'=>1000,'runs'=>4,'total_quantity'=>4000,'interval_minutes'=>60,
        ));
        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
    }

    public function testDripfeedPauseResumeCancel()
    {
        $ci = $this->ci();
        $svc = new DripfeedService();
        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'link'=>'https://x.com/a',
            'quantity_per_run'=>100,'runs'=>4,'total_quantity'=>400,'interval_minutes'=>60,
        ));
        $id = $res['dripfeed']->public_id;
        $this->assertTrue($svc->pause($id, $ci->user)['ok']);
        $this->assertSame('PAUSED', $ci->drip->status);
        $this->assertTrue($svc->resume($id, $ci->user)['ok']);
        $this->assertSame('ACTIVE', $ci->drip->status);
        $cancel = $svc->cancel($id, $ci->user);
        $this->assertTrue($cancel['ok']);
        $this->assertSame('CANCELED', $ci->drip->status);
        // No runs completed — full reserve refunded.
        $this->assertSame('0.48000000', $cancel['refund']);
        $this->assertSame(1, $ci->ledger_refunds);
    }

    /* -------------------------- subscriptions ------------------------- */

    public function testSubscriptionRequiresSupportedServiceAndQuantity()
    {
        $ci = $this->ci();
        $ci->service->subscription_supported = 0;
        $svc = new SubscriptionService();
        $res = $svc->create($ci->user, array('service'=>$ci->service->public_id,'target'=>'@handle','quantity'=>100,'interval_type'=>'daily'));
        $this->assertFalse($res['ok']);
        $this->assertSame('UNSUPPORTED', $res['code']);

        $ci->service->subscription_supported = 1;
        $res = $svc->create($ci->user, array('service'=>$ci->service->public_id,'target'=>'@handle','quantity'=>5,'interval_type'=>'daily'));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_QUANTITY', $res['code']);
    }

    public function testSubscriptionCreatesActiveRow()
    {
        $ci = $this->ci();
        $svc = new SubscriptionService();
        $res = $svc->create($ci->user, array(
            'service'=>$ci->service->public_id,'target'=>'https://instagram.com/u',
            'quantity'=>200,'interval_type'=>'weekly',
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('ACTIVE', $res['subscription']->status);
        $this->assertSame('weekly', $res['subscription']->interval_type);
        $this->assertSame(1, $ci->inserts['subscriptions']);
        $this->assertSame(1, $ci->inserts['subscription_events']);
    }

    public function testSubscriptionPauseResumeCancel()
    {
        $ci = $this->ci();
        $svc = new SubscriptionService();
        $res = $svc->create($ci->user, array('service'=>$ci->service->public_id,'target'=>'@u','quantity'=>200,'interval_type'=>'daily'));
        $id = $res['subscription']->public_id;
        $this->assertTrue($svc->pause($id, $ci->user)['ok']);
        $this->assertSame('PAUSED', $ci->subscription->status);
        $this->assertTrue($svc->resume($id, $ci->user)['ok']);
        $this->assertSame('ACTIVE', $ci->subscription->status);
        $this->assertTrue($svc->cancel($id, $ci->user)['ok']);
        $this->assertSame('CANCELED', $ci->subscription->status);
    }

    /* -------------------------- source rules ------------------------- */

    public function testAdvancedRoutesAreOrderedAndPostActionsExist()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array(
            "'dashboard/drip-feed/create'",
            "'dashboard/drip-feed/(:any)/pause'",
            "'dashboard/subscriptions/create'",
            "'dashboard/orders/(:any)/refill'",
        ) as $r) $this->assertStringContainsString($r, $routes);
        // catch-all drip detail must follow action routes
        $this->assertLessThan(
            strpos($routes, "'dashboard/drip-feed/(:any)'"),
            strpos($routes, "'dashboard/drip-feed/(:any)/pause'")
        );
    }

    public function testControllersExtendAuthAndPostGuard()
    {
        foreach (array('dashboard/Dripfeed.php','dashboard/Subscriptions.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringContainsString('extends Auth_Controller', $src);
            $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        }
        $orders = file_get_contents(self::$root.'/application/controllers/dashboard/Orders.php');
        $this->assertStringContainsString('function refill', $orders);
        $this->assertStringContainsString('RefillService', $orders);
    }

    public function testNoDirectWalletMutationOutsideServices()
    {
        foreach (array('dashboard/Dripfeed.php','dashboard/Subscriptions.php','dashboard/Orders.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/controllers/'.$rel);
            $this->assertStringNotContainsString("insert('wallet_transactions'", $src);
            $this->assertStringNotContainsString("update('wallets'", $src);
        }
    }

    /* ------------------------------ fake ----------------------------- */

    private function ci() {
        $ci = new AdvFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

#[AllowDynamicProperties]
class AdvFakeCI {
    public $user, $service, $provider, $wallet, $order, $drip, $subscription;
    public $db, $load, $input, $auth, $request_id='test';
    public $refill_active = false, $adapter_fail = false;
    public $ledger_charges=0, $ledger_refunds=0, $inserts=array();

    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        $this->user = (object)array('id'=>7,'role'=>'CUSTOMER','price_group_id'=>null,'status'=>'ACTIVE');
        $this->service = (object)array(
            'id'=>3,'public_id'=>'01SVC','status'=>'ACTIVE','rate'=>'1.20000000','provider_rate'=>'0.80000000',
            'min_quantity'=>100,'max_quantity'=>100000,'increment_step'=>1,'dripfeed_supported'=>1,
            'subscription_supported'=>1,'refill_supported'=>1,'cancel_supported'=>1,
            'provider_id'=>5,'provider_service_id'=>'1001',
        );
        $this->provider = (object)array('id'=>5,'status'=>'ACTIVE','api_type'=>'MOCK');
        $this->wallet = (object)array('id'=>11,'balance'=>'100.00000000','currency'=>'NGN');
        $this->order = (object)array(
            'id'=>99,'public_id'=>'ORDER1','status'=>'COMPLETED','charge'=>'1.20000000',
            'quantity'=>100,'user_id'=>7,'service_id'=>3,'provider_id'=>5,'provider_order_id'=>'mock_1',
        );
        $this->drip = (object)array('id'=>55,'public_id'=>'DRIP1','status'=>'ACTIVE','charge'=>'0.48000000','runs'=>4,'runs_completed'=>0);
        $this->subscription = (object)array('id'=>66,'public_id'=>'SUB1','status'=>'ACTIVE');
        $this->db = new AdvFakeDb($this);
        $this->input = new AdvFakeInput();
        $this->auth = new AdvFakeAuth($this);
        $this->load = new AdvFakeLoader();

        $this->Service_model = new AdvStubById($this,'service');
        $this->Order_model = new AdvStubOrder($this);
        $this->Refill_model = new AdvStubRefill($this);
        $this->Refill_status_history_model = new AdvStubHistory($this,'refill_status_history');
        $this->Provider_model = new AdvStubById($this,'provider');
        $this->Dripfeed_order_model = new AdvStubDrip($this);
        $this->Dripfeed_run_model = new AdvStubModel();
        $this->Subscription_model = new AdvStubSub($this);
        $this->Subscription_event_model = new AdvStubHistory($this,'subscription_events');
        $this->Wallet_model = new AdvStubWallet($this);
        $this->Blacklist_model = new AdvStubBlacklist();

        $this->pricingservice = new PricingService();
        $this->ledgerservice = new LedgerService();
        $this->encryptionservice = new EncryptionService();
        $this->providersyncservice = new AdvStubProviderSync();
        $this->orderservice = new OrderService();
        $this->dripfeedservice = new DripfeedService();
        $this->subscriptionservice = new SubscriptionService();
    }
}
class AdvFakeLoader { function model($n){} function library($n){} }
class AdvFakeInput { function ip_address(){return '127.0.0.1';} function user_agent(){return 'PHPUnit';} }
class AdvFakeAuth { private $ci; function __construct($ci){$this->ci=$ci;} function id(){return $this->ci->user->id;} }

class AdvFakeDb {
    private $ci;
    public function __construct($ci){$this->ci=$ci;}
    public function query($sql,$b=false){ return new AdvFakeResult($this->ci->wallet); }
    public function where($k,$v=null){ return $this; }
    public function where_in($k,$v){ return $this; }
    public function order_by($k,$d='ASC'){ return $this; }
    public function limit($l,$o=0){ return $this; }
    public function select($s,$b=false){ return $this; }
    public function from($t){ return $this; }
    public function join($t,$on,$type=''){ return $this; }
    public function group_start(){ return $this; }
    public function group_end(){ return $this; }
    public function trans_start(){} public function trans_complete(){} public function trans_rollback(){} public function trans_status(){return true;}
    public function insert_id(){ return 99; }
    public function count_all_results($t){ return 0; }
    public function insert($t,$d=array()){
        $this->ci->inserts[$t] = ($this->ci->inserts[$t] ?? 0) + 1;
        if ($t==='wallet_transactions') {
            // The real LedgerService runs here, so count the money movements
            // from the rows it writes rather than stubbing the service out.
            if (($d['direction'] ?? '') === 'DEBIT') $this->ci->ledger_charges++;
            if (($d['type'] ?? '') === 'REFUND') $this->ci->ledger_refunds++;
        }
        if ($t==='refills') { $this->ci->refill = (object)array_merge(array('id'=>77),$d); }
        if ($t==='dripfeed_orders') { $this->ci->drip = (object)array_merge((array)$this->ci->drip,$d); }
        if ($t==='subscriptions') { $this->ci->subscription = (object)array_merge((array)$this->ci->subscription,$d); }
        return true;
    }
    public function update($t,$d){
        if ($t==='dripfeed_orders') foreach ($d as $k=>$v) $this->ci->drip->$k=$v;
        if ($t==='subscriptions') foreach ($d as $k=>$v) $this->ci->subscription->$k=$v;
        if ($t==='wallets' && isset($d['balance'])) $this->ci->wallet->balance=$d['balance'];
        return true;
    }
    public function get($t=null){
        if ($t==='wallets') return new AdvFakeResult($this->ci->wallet);
        if ($t==='providers') return new AdvFakeResult($this->ci->provider);
        if ($t==='orders') return new AdvFakeResult($this->ci->order);
        if ($t==='services') return new AdvFakeResult($this->ci->service);
        if ($t==='refills') return new AdvFakeResult($this->ci->refill_active ? (object)array('id'=>1) : null);
        if ($t==='dripfeed_orders') return new AdvFakeResult($this->ci->drip);
        if ($t==='subscriptions') return new AdvFakeResult($this->ci->subscription);
        if ($t==='wallet_transactions') return new AdvFakeResult(null);
        if ($t==='blacklisted_links') return new AdvFakeResult(array());
        if ($t==='user_service_prices') return new AdvFakeResult(null);
        return new AdvFakeResult(null);
    }
}
class AdvFakeResult {
    private $row; public $rows;
    public function __construct($row){$this->row=$row;$this->rows=$row?array($row):array();}
    public function row(){return $this->row;} public function result(){return $this->rows;}
}

class AdvStubById {
    private $ci,$prop; function __construct($ci,$p){$this->ci=$ci;$this->prop=$p;}
    function find_by_id($id){return $this->ci->{$this->prop};}
    function find_by_public_id($id){return $this->ci->{$this->prop};}
    function find_by_slug($s){return $this->ci->{$this->prop};}
    function active(){return array($this->ci->service);}
}
class AdvStubOrder {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function find_public_for_user($p,$u){return $p===$this->ci->order->public_id && $u===$this->ci->user->id ? $this->ci->order : null;}
    function find_by_idempotency_key($k){return null;}
    function find_by_id($id){return $this->ci->order;}
}
class AdvStubRefill {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function active_for_order($id){return $this->ci->refill_active ? (object)array('id'=>1) : null;}
    function find_by_id($id){return $this->ci->refill ?? null;}
}
class AdvStubHistory {
    private $ci,$table; function __construct($ci,$t){$this->ci=$ci;$this->table=$t;}
    function record(){ $this->ci->inserts[$this->table]=($this->ci->inserts[$this->table]??0)+1; }
}
class AdvStubWallet {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function for_user($id){return $this->ci->wallet;}
}
class AdvStubBlacklist {
    function text_contains_blacklisted_link($t){return false;}
}
class AdvStubDrip {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function find_public_for_user($p,$u){return $p===$this->ci->drip->public_id ? $this->ci->drip : null;}
    function find_by_id($id){return $this->ci->drip;}
    function for_user($id,$l=25,$o=0){return array($this->ci->drip);}
    function count_for_user($id){return 1;}
}
class AdvStubSub {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function find_public_for_user($p,$u){return $p===$this->ci->subscription->public_id ? $this->ci->subscription : null;}
    function find_by_id($id){return $this->ci->subscription;}
    function for_user($id,$l=25,$o=0){return array($this->ci->subscription);}
    function count_for_user($id){return 1;}
}
class AdvStubModel {}
class AdvStubProviderSync {
    function adapter($p){ return new AdvMockRefillAdapter(); }
}
class AdvMockRefillAdapter implements ProviderAdapterInterface {
    public function getServices(){return array('ok'=>true,'data'=>array());}
    public function createOrder($p){return array('ok'=>true,'provider_order_id'=>'mock_1');}
    public function getOrderStatus($id){return array('ok'=>true,'data'=>array('status'=>'Completed'));}
    public function getMultipleOrderStatus(array $ids){return array('ok'=>true,'data'=>array());}
    public function getBalance(){return array('ok'=>true,'data'=>array('balance'=>'100','currency'=>'NGN'));}
    public function requestRefill($id){return array('ok'=>true,'provider_refill_id'=>'r_42');}
    public function getRefillStatus($id){return array('ok'=>true,'data'=>array('status'=>'Pending'));}
    public function requestCancel($id){return array('ok'=>true);}
}
