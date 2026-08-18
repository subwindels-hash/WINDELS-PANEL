<?php
use PHPUnit\Framework\TestCase;

// Test doubles at the bottom of this file implement ProviderAdapterInterface,
// which PHP must resolve while compiling the file — before setUpBeforeClass runs.
if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__, 2).'/system/');
require_once dirname(__DIR__, 2).'/application/libraries/ProviderAdapterInterface.php';

/**
 * Order engine tests (Session 09) — validates the OrderService create flow,
 * state transitions, idempotency, partial refunds and route/controller rules
 * without a database or network.
 */
class OrderServiceTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) {
            eval('function get_instance() { return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('windels_public_id')) {
            require_once self::$root.'/application/helpers/windels_helper.php';
        }
        require_once self::$root.'/application/libraries/OrderStateMachine.php';
        require_once self::$root.'/application/libraries/PricingService.php';
        require_once self::$root.'/application/libraries/LedgerService.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/MockProviderAdapter.php';
        require_once self::$root.'/application/libraries/StandardSmmAdapter.php';
        require_once self::$root.'/application/libraries/ProviderSyncService.php';
        require_once self::$root.'/application/libraries/OrderService.php';
    }

    /* -------------------------- validations -------------------------- */

    public function testRejectsUnknownService()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>'does-not-exist','link'=>'https://x.com/a','quantity'=>100));
        $this->assertFalse($res['ok']);
        $this->assertSame('NO_SERVICE', $res['code']);
    }

    public function testRejectsInactiveService()
    {
        $ci = $this->fresh_ci();
        $ci->service->status = 'INACTIVE';
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->public_id,'link'=>'https://x.com/a','quantity'=>100));
        $this->assertFalse($res['ok']);
        $this->assertSame('SERVICE_INACTIVE', $res['code']);
    }

    public function testRejectsQuantityBelowMinimum()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>10));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_QUANTITY', $res['code']);
    }

    public function testRejectsQuantityAboveMaximum()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>999999999));
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_QUANTITY', $res['code']);
    }

    public function testRejectsInvalidLink()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        foreach (array('not-a-url','ftp://x.com','http://localhost/x','http://127.0.0.1/x','javascript:alert(1)') as $bad) {
            $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>$bad,'quantity'=>100));
            $this->assertFalse($res['ok'], "link should be rejected: {$bad}");
            $this->assertSame('BAD_LINK', $res['code']);
        }
    }

    public function testRejectsBlacklistedLink()
    {
        $ci = $this->fresh_ci();
        $ci->blacklist_block = true;
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://spammer.example/p','quantity'=>100));
        $this->assertFalse($res['ok']);
        $this->assertSame('BLACKLISTED', $res['code']);
    }

    public function testRejectsInsufficientBalance()
    {
        $ci = $this->fresh_ci();
        $ci->wallet->balance = '0.00000000';
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>1000));
        $this->assertFalse($res['ok']);
        $this->assertSame('INSUFFICIENT_BALANCE', $res['code']);
    }

    /* ----------------------------- create ---------------------------- */

    public function testPlacesOrderAndChargesWallet()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $res = $svc->place($ci->user, array(
            'service'=>$ci->service->id, 'link'=>'https://instagram.com/p/abc', 'quantity'=>1000,
        ));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $order = $res['order'];
        $this->assertSame('PROCESSING', $order->status);
        $this->assertSame(1000, (int)$order->quantity);
        // 1.20/1000 * 1000 = 1.20
        $this->assertSame('1.20000000', $order->charge);
        $this->assertSame('WEB', $order->source);
        $this->assertSame(1, $ci->ledger_charges);
        // One history row for PENDING, one for PROCESSING.
        $this->assertSame(2, $ci->history_count);
        $this->assertSame(1, $ci->notifications);
    }

    public function testOrderUsesResolvedUserPrice()
    {
        $ci = $this->fresh_ci();
        $ci->price_override = '0.99000000'; // user-specific price
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>1000));
        $this->assertTrue($res['ok']);
        $this->assertSame('0.99000000', $res['order']->charge);
        $this->assertSame('0.99000000', $res['order']->rate_at_order);
    }

    public function testIdempotentSubmissionDoesNotRecharge()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $payload = array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>100,'idempotency_key'=>'idem-123');
        $first = $svc->place($ci->user, $payload);
        $this->assertTrue($first['ok']);
        // Seed the order model so a repeat call sees the prior idempotency key.
        $ci->Order_model->seedIdempotency('idem-123', $first['order']);
        $second = $svc->place($ci->user, $payload);
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['duplicate']));
        $this->assertSame(1, $ci->ledger_charges, 'duplicate must not charge again');
        $this->assertSame($first['order']->id, $second['order']->id);
    }

    public function testIdempotencyCollisionNeverRevealsAnotherUsersOrder()
    {
        $ci = $this->fresh_ci();
        $foreign = clone $ci->order;
        $foreign->user_id = 999;
        $ci->Order_model->seedIdempotency('shared-key', $foreign);

        $svc = new OrderService();
        $res = $svc->place($ci->user, array(
            'service'=>$ci->service->id,
            'link'=>'https://x.com/a',
            'quantity'=>100,
            'idempotency_key'=>'shared-key',
        ));

        $this->assertFalse($res['ok']);
        $this->assertSame('IDEMPOTENCY_CONFLICT', $res['code']);
        $this->assertArrayNotHasKey('order', $res);
        $this->assertSame(0, $ci->ledger_charges);
    }

    public function testFailedProviderSubmissionRefundsAndFailsOrder()
    {
        $ci = $this->fresh_ci();
        $ci->provider->status = 'ACTIVE';
        $ci->adapter_fail = true;
        $svc = new OrderService();
        $balance_before = $ci->wallet->balance;
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>100));
        $this->assertFalse($res['ok']);
        $this->assertSame('SUBMIT_FAILED', $res['code']);
        $this->assertSame('FAILED', $res['order']->status);
        // Charge then refund — net zero and one refund recorded.
        $this->assertSame(1, $ci->ledger_charges);
        $this->assertSame(1, $ci->ledger_refunds);
    }

    public function testNoProviderKeepsOrderPending()
    {
        $ci = $this->fresh_ci();
        $ci->service->provider_id = null;
        $svc = new OrderService();
        $res = $svc->place($ci->user, array('service'=>$ci->service->id,'link'=>'https://x.com/a','quantity'=>100));
        $this->assertTrue($res['ok']);
        $this->assertSame('PENDING', $res['order']->status);
        $this->assertNull($res['order']->provider_order_id);
    }

    /* ----------------------------- cancel ---------------------------- */

    public function testCancelMovesToCanceled()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $order = $this->make_order($ci, 'PROCESSING');
        $res = $svc->cancel($order->public_id, $ci->user);
        $this->assertTrue($res['ok']);
        $this->assertSame('CANCELED', $res['order']->status);
    }

    public function testCancelRejectsCompletedOrders()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $order = $this->make_order($ci, 'COMPLETED');
        $res = $svc->cancel($order->public_id, $ci->user);
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_CANCELLABLE', $res['code']);
    }

    /* --------------------------- transitions ------------------------- */

    public function testIllegalTransitionIsRejected()
    {
        $this->assertFalse(OrderStateMachine::can('COMPLETED', 'PROCESSING'));
        $this->assertTrue(OrderStateMachine::can('PENDING', 'PROCESSING'));
        $this->assertTrue(OrderStateMachine::can('IN_PROGRESS', 'PARTIAL'));
    }

    public function testPartialDeliveryRefundsProportionally()
    {
        $ci = $this->fresh_ci();
        $svc = new OrderService();
        $order = $this->make_order($ci, 'IN_PROGRESS', '2.00000000', 1000);
        $res = $svc->apply_status($order, 'COMPLETED', 'PROVIDER', 'partial', array('remains'=>200));
        $this->assertTrue($res['ok']);
        $this->assertSame('PARTIAL', $res['order']->status);
        $this->assertSame(200, (int)$res['order']->remains);
        // 200/1000 * 2.00 = 0.40 refund
        $this->assertSame('0.40000000', $res['order']->refunded_amount);
        $this->assertSame(1, $ci->ledger_refunds);
    }

    /* -------------------------- source rules ------------------------- */

    public function testControllerRoutesArePostOnlyAndGated()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Orders.php');
        $this->assertStringContainsString('extends Auth_Controller', $src);
        $this->assertStringContainsString('function create', $src);
        $this->assertStringContainsString('function cancel', $src);
        $this->assertStringContainsString('OrderService', $src);
        $this->assertStringContainsString('function refill', $src);
        // create / mass create / cancel / refill are all POST-only.
        $this->assertSame(4, substr_count($src, "if (\$this->input->method(true) !== 'POST') show_404();"));
    }

    public function testNoDirectOrderOrWalletMutationOutsideService()
    {
        foreach (array('controllers/dashboard/Orders.php','controllers/Services.php') as $rel) {
            $src = file_get_contents(self::$root.'/application/'.$rel);
            $this->assertStringNotContainsString("insert('orders'", $src);
            $this->assertStringNotContainsString("update('wallets'", $src);
            $this->assertStringNotContainsString("insert('wallet_transactions'", $src);
        }
    }

    /* ----------------------------- helpers --------------------------- */

    private function make_order($ci, $status, $charge = '1.20000000', $qty = 100) {
        $ci->order->status = $status;
        $ci->order->charge = $charge;
        $ci->order->quantity = $qty;
        $ci->order->provider_id = null;
        $ci->order->provider_order_id = null;
        return $ci->order;
    }

    private function fresh_ci() {
        $ci = new OrderFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

/* ------------------------------- doubles ------------------------------- */

#[AllowDynamicProperties]
class OrderFakeCI {
    public $user, $service, $provider, $wallet, $order;
    public $db, $load, $input, $auth, $request_id = 'test';
    public $ledger_charges = 0, $ledger_refunds = 0, $history_count = 0, $notifications = 0;
    public $adapter_fail = false, $blacklist_block = false, $price_override = null;
    // Models & libraries used by OrderService:
    public $Service_model, $Order_model, $Order_status_history_model, $Provider_model;
    public $Wallet_model, $Blacklist_model, $Notification_model;
    public $pricingservice, $ledgerservice, $providersyncservice, $encryptionservice;

    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        $this->user = (object)array('id'=>7,'role'=>'CUSTOMER','price_group_id'=>null,'status'=>'ACTIVE');
        $this->service = (object)array(
            'id'=>3,'public_id'=>'01SVC','name'=>'IG Followers','status'=>'ACTIVE',
            'rate'=>'1.20000000','provider_rate'=>'0.80000000','min_quantity'=>100,'max_quantity'=>100000,
            'increment_step'=>1,'provider_id'=>5,'provider_service_id'=>'1001',
            'cancel_supported'=>1,'refill_supported'=>0,'average_time'=>'0-1h',
        );
        $this->provider = (object)array('id'=>5,'status'=>'ACTIVE','api_type'=>'MOCK','name'=>'Mock');
        $this->wallet = (object)array('id'=>11,'balance'=>'100.00000000','currency'=>'NGN');
        $this->order = (object)array(
            'id'=>99,'public_id'=>'01ORDER99','status'=>'PENDING','charge'=>'1.20000000',
            'quantity'=>100,'user_id'=>7,'service_id'=>3,'provider_id'=>5,'provider_order_id'=>null,
        );
        $this->db = new OrderFakeDb($this);
        $this->input = new OrderFakeInput();
        $this->auth = new OrderFakeAuth($this);
        $this->load = new OrderFakeLoader();

        $this->Service_model = new OrderStubServiceModel($this);
        $this->Order_model = new OrderStubOrderModel($this);
        $this->Order_status_history_model = new OrderStubHistoryModel($this);
        $this->Provider_model = new OrderStubProviderModel($this);
        $this->Wallet_model = new OrderStubWalletModel($this);
        $this->Blacklist_model = new OrderStubBlacklistModel($this);
        $this->Notification_model = new OrderStubNotificationModel($this);
        $this->pricingservice = new PricingService();
        $this->ledgerservice = new LedgerService();
        $this->encryptionservice = new EncryptionService();
        $this->providersyncservice = new OrderStubProviderSync($this);
    }
}
class OrderFakeLoader {
    public function model($n){ return $this; }
    public function library($n){ return $this; }
}
class OrderFakeInput {
    public function ip_address(){ return '127.0.0.1'; }
    public function user_agent(){ return 'PHPUnit'; }
}
class OrderFakeAuth {
    private $ci;
    public function __construct($ci){ $this->ci=$ci; }
    public function id(){ return $this->ci->user->id; }
}
class OrderFakeDb {
    private $ci;
    public function __construct($ci){ $this->ci=$ci; }
    public function query($sql, $binds=false){ return new OrderFakeResult($this->ci->wallet); }
    public function where($k,$v=null){ return $this; }
    public function where_in($k,$v){ return $this; }
    public function order_by($k,$d='ASC'){ return $this; }
    public function limit($l,$o=0){ return $this; }
    public function select($s,$b=false){ return $this; }
    public function from($t){ return $this; }
    public function join($t,$on,$type=''){ return $this; }
    public function group_start(){ return $this; }
    public function group_end(){ return $this; }
    public function trans_start(){}
    public function trans_complete(){}
    public function trans_rollback(){}
    public function trans_status(){ return true; }
    public function insert_id(){ return 99; }
    public function count_all_results($t){
        if ($t==='blacklisted_links') return $this->ci->blacklist_block ? 1 : 0;
        return 0;
    }
    public function insert($t,$d=array()){
        if ($t==='wallet_transactions') {
            if (($d['type'] ?? '') === 'ORDER_CHARGE') { $this->ci->ledger_charges++; $this->ci->wallet->balance = bcsub($this->ci->wallet->balance, $d['amount'], 8); }
            if (($d['type'] ?? '') === 'REFUND') { $this->ci->ledger_refunds++; $this->ci->wallet->balance = bcadd($this->ci->wallet->balance, $d['amount'], 8); }
        }
        if ($t==='order_status_history') $this->ci->history_count++;
        if ($t==='orders') { $this->ci->order->id=99; $this->ci->order->public_id=$d['public_id']; $this->ci->order->status=$d['status']; $this->ci->order->charge=$d['charge']; $this->ci->order->quantity=$d['quantity']; $this->ci->order->rate_at_order=$d['rate_at_order']; $this->ci->order->source=$d['source']; }
        if ($t==='notifications') $this->ci->notifications++;
        return true;
    }
    public function update($t,$d){
        if ($t==='orders') foreach ($d as $k=>$v) $this->ci->order->$k=$v;
        if ($t==='wallets' && isset($d['balance'])) $this->ci->wallet->balance=$d['balance'];
        return true;
    }
    public function get($t=null){
        if ($t==='users') return new OrderFakeResult($this->ci->user);
        if ($t==='services') return new OrderFakeResult($this->ci->service);
        if ($t==='wallets') return new OrderFakeResult($this->ci->wallet);
        if ($t==='providers') return new OrderFakeResult($this->ci->provider);
        if ($t==='orders') return new OrderFakeResult($this->ci->order);
        if ($t==='blacklisted_links') return new OrderFakeResult(array());
        if ($t==='user_service_prices') {
            return $this->ci->price_override
                ? new OrderFakeResult((object)array('rate'=>$this->ci->price_override))
                : new OrderFakeResult(null);
        }
        if ($t==='wallet_transactions') return new OrderFakeResult(null);
        return new OrderFakeResult(null);
    }
}
class OrderFakeResult {
    private $row; public $rows;
    public function __construct($row){ $this->row=$row; $this->rows=$row?array($row):array(); }
    public function row(){ return $this->row; }
    public function result(){ return $this->rows; }
}

/* Stubs */
class OrderStubServiceModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    private function match($needle){
        $s = $this->ci->service;
        foreach (array($s->id, $s->public_id, $s->slug ?? 'ig-followers') as $known) {
            if ((string)$known === (string)$needle) return $s;
        }
        return null;   // unknown identifiers must miss, as they would in SQL
    }
    function find_by_public_id($id){return $this->match($id);}
    function find_by_id($id){return $this->match($id);}
    function find_by_slug($s){return $this->match($s);}
    function active(){return array($this->ci->service);}
}
class OrderStubOrderModel {
    private $ci; private $idem = array();
    function __construct($ci){$this->ci=$ci;}
    function seedIdempotency($k,$order){ $this->idem[$k]=$order; }
    function find_by_idempotency_key($k){ return $this->idem[$k] ?? null; }
    function find_by_id($id){return $this->ci->order;}
    function find_public_for_user($p,$u){return $this->ci->order;}
    function for_user($id,$l=25,$o=0,$s=null){return array();}
    function count_for_user($id,$s=null){return 0;}
    function for_user_with_service($id,$l=25,$o=0,$s=null){return array();}
}
class OrderStubHistoryModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function record(){$this->ci->history_count++;return true;}
    function for_order($id){return array();}
}
class OrderStubProviderModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function find_by_id($id){return $this->ci->provider;}
    function active(){return array();}
}
class OrderStubWalletModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function for_user($id){return $this->ci->wallet;}
}
class OrderStubBlacklistModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function text_contains_blacklisted_link($t){return $this->ci->blacklist_block;}
    function is_email_blacklisted($e){return false;}
    function is_ip_blacklisted($i){return false;}
}
class OrderStubNotificationModel {
    function unread_for_user($id,$l=20){return array();}
    function mark_read($id,$p=null){}
    function for_user($id,$l=25,$o=0){return array();}
    function count_for_user($id,$u=false){return 0;}
}
class OrderStubProviderSync {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function adapter($provider){
        if ($this->ci->adapter_fail) return new OrderStubFailingAdapter();
        return new MockProviderAdapter();
    }
}
class OrderStubFailingAdapter implements ProviderAdapterInterface {
    public function getServices(){return array('ok'=>false,'error'=>'provider down');}
    public function createOrder($p){return array('ok'=>false,'error'=>'provider rejected');}
    public function getOrderStatus($id){return array('ok'=>false);}
    public function getMultipleOrderStatus(array $ids){return array('ok'=>false);}
    public function getBalance(){return array('ok'=>false);}
    public function requestRefill($id){return array('ok'=>false);}
    public function getRefillStatus($id){return array('ok'=>false);}
    public function requestCancel($id){return array('ok'=>false);}
}
