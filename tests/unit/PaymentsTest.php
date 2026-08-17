<?php
use PHPUnit\Framework\TestCase;

/**
 * Payments tests (Session 11) — deposit initialization, fee/bonus math,
 * idempotent confirmation and webhook reconciliation. No DB or network.
 */
class PaymentsTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) {
            eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        }
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('windels_public_id')) require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/GatewayInterface.php';
        require_once self::$root.'/application/libraries/ManualGateway.php';
        require_once self::$root.'/application/libraries/PricingService.php';
        require_once self::$root.'/application/libraries/LedgerService.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/PaymentService.php';
    }

    /* ----------------------------- fees ----------------------------- */

    public function testFeeAndBonusMath()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $method = (object)array('fee_percent'=>'2.5','fee_fixed'=>'0.30','bonus_percent'=>'5.0');
        // 2.5% of 100 = 2.50 + 0.30 = 2.80
        $this->assertSame('2.80000000', $svc->calculate_fee($method, '100.00'));
        // 5% bonus of 100 = 5.00
        $this->assertSame('5.00000000', $svc->calculate_bonus($method, '100.00'));
    }

    public function testZeroFeeWhenNoSurcharge()
    {
        $this->fresh();
        $svc = new PaymentService();
        $m = (object)array('fee_percent'=>'0','fee_fixed'=>'0','bonus_percent'=>'0');
        $this->assertSame('0.00000000', $svc->calculate_fee($m, '10'));
        $this->assertSame('0.00000000', $svc->calculate_bonus($m, '10'));
    }

    /* --------------------------- deposit ---------------------------- */

    public function testDepositRejectsUnknownOrInactiveMethod()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $res = $svc->deposit($ci->user, array('payment_method'=>'nope','amount'=>10));
        $this->assertFalse($res['ok']); $this->assertSame('NO_METHOD', $res['code']);

        $ci->method->is_active = 0;
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>10));
        $this->assertFalse($res['ok']); $this->assertSame('METHOD_INACTIVE', $res['code']);
    }

    public function testDepositValidatesAmountAndBounds()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>-5));
        $this->assertFalse($res['ok']); $this->assertSame('BAD_AMOUNT', $res['code']);
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>0.5));
        $this->assertFalse($res['ok']); $this->assertSame('AMOUNT_TOO_LOW', $res['code']);
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>999999));
        $this->assertFalse($res['ok']); $this->assertSame('AMOUNT_TOO_HIGH', $res['code']);
    }

    public function testDepositCreatesPendingTransactionForManualGateway()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $res = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>100,'currency'=>'USD'));
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $tx = $res['transaction'];
        $this->assertSame('PENDING', $tx->status);
        $this->assertSame('100.00000000', $tx->amount);
        $this->assertSame('97.20000000', $tx->credited_amount); // 100 - 2.80 + 0
        $this->assertSame('2.80000000', $tx->fee);
        $this->assertSame(1, $ci->inserts['payment_transactions']);
        $this->assertSame(2, $ci->inserts['payment_events']); // CREATED->PENDING
        $this->assertNull($res['redirect_url']);
        $this->assertIsArray($res['checkout']);
    }

    public function testDepositIsIdempotent()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $payload = array('payment_method'=>'manual','amount'=>50,'idempotency_key'=>'dep-1');
        $first = $svc->deposit($ci->user, $payload);
        $this->assertTrue($first['ok']);
        $second = $svc->deposit($ci->user, $payload);
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['duplicate']));
        $this->assertSame(1, $ci->inserts['payment_transactions']);
    }

    /* --------------------------- confirm ---------------------------- */

    public function testConfirmCreditsWalletOnce()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $dep = $svc->deposit($ci->user, array('payment_method'=>'manual','amount'=>100));
        $tx = $dep['transaction'];

        $res = $svc->confirm($tx, 'ADMIN');
        $this->assertTrue($res['ok']);
        $this->assertSame('SUCCESS', $res['transaction']->status);
        $this->assertSame(1, $ci->ledger_credits);
        $this->assertNotNull($res['transaction']->wallet_transaction_id);
        $this->assertSame('97.20000000', $res['transaction']->credited_amount);

        // Re-confirm is a no-op (idempotent).
        $again = $svc->confirm($res['transaction'], 'ADMIN');
        $this->assertTrue($again['ok']);
        $this->assertSame(1, $ci->ledger_credits, 'must not double-credit');
    }

    public function testConfirmRejectsNonPendingStates()
    {
        $ci = $this->fresh();
        $svc = new PaymentService();
        $tx = (object)array('id'=>5,'public_id'=>'X','user_id'=>7,'status'=>'FAILED','credited_amount'=>'10','amount'=>'10','idempotency_key'=>null);
        $res = $svc->confirm($tx, 'ADMIN');
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_STATE', $res['code']);
    }

    /* --------------------------- webhooks --------------------------- */

    public function testWebhookIsIdempotentByEventId()
    {
        $ci = $this->fresh();
        $ci->webhook_sig = true;
        $svc = new PaymentService();
        $body = json_encode(array('id'=>'evt_1','status'=>'success','reference'=>null));
        $sig  = hash_hmac('sha256', $body, 'test-webhook-secret');
        $first = $svc->record_webhook('stripe', $body, array('x-stripe-signature'=>$sig));
        $this->assertTrue($first['ok']);
        $second = $svc->record_webhook('stripe', $body, array('x-stripe-signature'=>$sig));
        $this->assertTrue($second['ok']);
        $this->assertTrue(!empty($second['already_seen']));
        $this->assertSame(1, $ci->inserts['payment_webhooks']);
    }

    public function testWebhookWithInvalidSignatureIsRejected()
    {
        $ci = $this->fresh();
        $ci->webhook_sig = false;
        $svc = new PaymentService();
        $res = $svc->record_webhook('stripe', '{}', array());
        $this->assertFalse($res['ok']);
        $this->assertSame('Invalid signature', $res['error']);
    }

    /* ---------------------------- source ---------------------------- */

    public function testWalletControllerPostsToPaymentService()
    {
        $src = file_get_contents(self::$root.'/application/controllers/dashboard/Wallet.php');
        $this->assertStringContainsString('PaymentService', $src);
        $this->assertStringContainsString('function deposit', $src);
        $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src);
        $this->assertStringNotContainsString("ledgerservice->credit", $src);
        $this->assertStringNotContainsString("insert('wallet_transactions'", $src);
    }

    public function testWebhookRouteAndControllerExist()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'webhook/(:any)'", $routes);
        $this->assertFileExists(self::$root.'/application/controllers/Webhooks.php');
        $wh = file_get_contents(self::$root.'/application/controllers/Webhooks.php');
        $this->assertStringContainsString('record_webhook', $wh);
        $this->assertStringContainsString('extends MY_Controller', $wh);
    }

    /* ----------------------------- fakes ---------------------------- */

    private function fresh() {
        $ci = new PayFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

#[AllowDynamicProperties]
class PayFakeCI {
    public $user, $method, $wallet, $tx, $db, $load, $input, $auth, $request_id='test';
    public $inserts=array(), $ledger_credits=0, $webhook_sig=null;
    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        $this->user = (object)array('id'=>7,'role'=>'CUSTOMER','status'=>'ACTIVE');
        $this->method = (object)array(
            'id'=>1,'code'=>'manual','name'=>'Manual','type'=>'MANUAL','is_active'=>1,
            'min_amount'=>'5.00000000','max_amount'=>'5000.00000000',
            'fee_percent'=>'2.5','fee_fixed'=>'0.30','bonus_percent'=>'0','instructions'=>'Bank details here.',
        );
        $this->wallet = (object)array('id'=>11,'balance'=>'100.00000000','currency'=>'USD');
        $this->tx = (object)array('id'=>42,'public_id'=>'PAY1','status'=>'PENDING','amount'=>'100','credited_amount'=>'97.2','fee'=>'2.8','bonus'=>'0','user_id'=>7,'idempotency_key'=>null,'currency'=>'USD');
        $this->db = new PayFakeDb($this);
        $this->input = new PayFakeInput();
        $this->auth = new PayFakeAuth($this);
        $this->load = new PayFakeLoader();
        $this->Payment_transaction_model = new PayFakeTxModel($this);
        $this->Payment_webhook_model = new PayFakeWhModel($this);
        $this->Payment_event_model = new PayFakeEventModel($this);
        $this->Wallet_model = new PayFakeWalletModel($this);
        $this->Setting_model = new PayFakeSettings();
        $this->pricingservice = new PricingService();
        $this->ledgerservice = new PayFakeLedger($this);
        $this->encryptionservice = new EncryptionService();
    }
}
class PayFakeLoader { function model($n){} function library($n){} }
class PayFakeInput { function ip_address(){return '127.0.0.1';} function user_agent(){return 'PHPUnit';} }
class PayFakeAuth { private $ci; function __construct($ci){$this->ci=$ci;} function id(){return $this->ci->user->id;} }

class PayFakeDb {
    private $ci; private $wheres=array();
    public function __construct($ci){$this->ci=$ci;}
    public function query($sql,$b=false){ return new PayFakeResult($this->ci->wallet); }
    public function where($k,$v=null){ if(!is_array($k)) $this->wheres[$k]=$v; return $this; }
    public function where_in($k,$v){ return $this; }
    public function order_by($k,$d='ASC'){ return $this; }
    public function limit($l,$o=0){ return $this; }
    public function select($s,$b=false){ return $this; }
    public function from($t){ return $this; }
    public function join($t,$on,$type=''){ return $this; }
    public function trans_start(){} public function trans_complete(){} public function trans_rollback(){} public function trans_status(){return true;}
    public function insert_id(){ return 42; }
    public function count_all_results($t){ return 0; }
    public function insert($t,$d=array()){
        $this->ci->inserts[$t]=($this->ci->inserts[$t]??0)+1;
        if ($t==='payment_transactions') { $this->ci->tx = (object)array_merge((array)$this->ci->tx,$d); }
        return true;
    }
    public function update($t,$d){ return true; }
    public function get($t=null){
        $w = $this->wheres; $this->wheres = array();
        if ($t==='payment_methods') {
            // A code that isn't the seeded method must return no row.
            $code = $w['code'] ?? null;
            return new PayFakeResult(
                ($code === null || $code === $this->ci->method->code) ? $this->ci->method : null
            );
        }
        if ($t==='wallets') return new PayFakeResult($this->ci->wallet);
        if ($t==='payment_transactions') return new PayFakeResult($this->ci->tx);
        if ($t==='wallet_transactions') return new PayFakeResult((object)array('id'=>99));
        return new PayFakeResult(null);
    }
}
class PayFakeResult {
    private $row; public $rows;
    public function __construct($row){$this->row=$row;$this->rows=$row?array($row):array();}
    public function row(){return $this->row;} public function result(){return $this->rows;}
}
class PayFakeTxModel {
    private $ci; private $idem=array(); function __construct($ci){$this->ci=$ci;}
    function find_by_idempotency_key($k){
        // Rows persisted through insert() are keyed here too, which is what
        // makes a repeated deposit with the same key resolve to a duplicate.
        if (isset($this->idem[$k])) return $this->idem[$k];
        $tx = $this->ci->tx;
        return ($tx && isset($tx->idempotency_key) && $tx->idempotency_key === $k) ? $tx : null;
    }
    function seed_idem($k,$tx){$this->idem[$k]=$tx;}
    function find_by_id($id){return $this->ci->tx;}
    function find_by_provider_tx($id){return null;}
    function find_public_for_user($p,$u){return $this->ci->tx;}
    function update_status($id,$d){foreach($d as $k=>$v)$this->ci->tx->$k=$v;}
    function for_user($id,$l=25,$o=0){return array();}
}
class PayFakeWhModel {
    private $ci; private $seen=array(); function __construct($ci){$this->ci=$ci;}
    function record_once($gw,$eid,$payload,$sig,$type){
        if ($eid && isset($this->seen[$gw.':'.$eid])) return false;
        if ($eid) $this->seen[$gw.':'.$eid]=1;
        $this->ci->inserts['payment_webhooks']=($this->ci->inserts['payment_webhooks']??0)+1;
        return 7;
    }
}
class PayFakeEventModel {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function insert($d){$this->ci->inserts['payment_events']=($this->ci->inserts['payment_events']??0)+1;}
}
class PayFakeWalletModel { private $ci; function __construct($ci){$this->ci=$ci;} function for_user($id){return $this->ci->wallet;} }
class PayFakeSettings {
    function get($k,$d=null){
        if (substr($k, -15) === '.webhook_secret') return 'test-webhook-secret';
        return $d;
    }
}
class PayFakeLedger {
    private $ci; function __construct($ci){$this->ci=$ci;}
    function credit($wid,$amt,$type,$rt,$rid,$idem,$meta=null){
        $this->ci->ledger_credits++;
        return array('ok'=>true,'public_id'=>'WT','balance_after'=>bcadd($this->ci->wallet->balance,$amt,8));
    }
    function charge($wid,$amt,$rt,$rid,$idem,$meta=null){return array('ok'=>true);}
    function refund($wid,$amt,$rt,$rid,$idem=null){return array('ok'=>true);}
}
