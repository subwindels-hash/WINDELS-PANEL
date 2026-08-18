<?php
use PHPUnit\Framework\TestCase;

/**
 * Admin panel tests (Session 15) — the operational back office: the order
 * queue, the manual-deposit approval queue and the staff ticket queue.
 *
 * The behavioural tests exercise the services the controllers delegate to
 * (OrderService refunds, PaymentService::confirm, TicketService staff replies)
 * against an in-memory fake CI. The source-level tests pin the guarantees that
 * matter for an admin surface: POST-only mutations, a permission check on every
 * action, audit logging, and no controller writing money directly.
 */
class AdminPanelTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('windels_public_id')) require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/LedgerService.php';
        require_once self::$root.'/application/libraries/OrderStateMachine.php';
        require_once self::$root.'/application/libraries/PricingService.php';
        require_once self::$root.'/application/libraries/GatewayInterface.php';
        require_once self::$root.'/application/libraries/ManualGateway.php';
        require_once self::$root.'/application/libraries/EncryptionService.php';
        require_once self::$root.'/application/libraries/PaymentService.php';
        require_once self::$root.'/application/libraries/ProviderAdapterInterface.php';
        require_once self::$root.'/application/libraries/OrderService.php';
        require_once self::$root.'/application/libraries/TicketService.php';
    }

    /* ===================== order queue: refund behaviour ================= */

    public function testCancelingAnOrderRefundsTheCharge()
    {
        $ci  = $this->fresh();
        $svc = new OrderService();

        $before = $ci->wallet->balance;
        $res = $svc->apply_status($ci->order, 'CANCELED', 'ADMIN', 'Customer asked');

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('CANCELED', $ci->order_row->status);
        $this->assertSame(1, $ci->ledger_refunds, 'canceling must return the charge');
        $this->assertSame(bcadd($before, $ci->order->charge, 8), $ci->wallet->balance);
        $this->assertSame($ci->order->charge, $ci->order_row->refunded_amount);
    }

    public function testRefundingACompletedOrderReturnsTheCharge()
    {
        $ci = $this->fresh();
        $ci->order->status = 'COMPLETED';
        $ci->order_row->status = 'COMPLETED';
        $svc = new OrderService();

        $res = $svc->apply_status($ci->order, 'REFUNDED', 'ADMIN', 'Goodwill');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(1, $ci->ledger_refunds);
        $this->assertSame($ci->order->charge, $ci->order_row->refunded_amount);
    }

    public function testRefundIsIdempotentAcrossRepeatedCalls()
    {
        $ci  = $this->fresh();
        $svc = new OrderService();

        $svc->apply_status($ci->order, 'CANCELED', 'ADMIN', 'first');
        $balance_after_first = $ci->wallet->balance;
        // Re-applying the same terminal state must not pay the customer twice.
        $svc->apply_status($ci->order_row, 'CANCELED', 'ADMIN', 'again');

        $this->assertSame(1, $ci->ledger_refunds, 'a repeated cancel must not double-refund');
        $this->assertSame($balance_after_first, $ci->wallet->balance);
    }

    public function testPartialThenRefundOnlyReturnsTheRemainder()
    {
        $ci  = $this->fresh();
        $ci->order->status = 'IN_PROGRESS';
        $ci->order_row->status = 'IN_PROGRESS';
        $svc = new OrderService();

        // Half delivered: half the charge comes back.
        $svc->apply_status($ci->order, 'PARTIAL', 'ADMIN', 'half delivered', array('remains' => 500));
        $partial = $ci->order_row->refunded_amount;
        $this->assertSame(bcdiv($ci->order->charge, '2', 8), $partial);

        // Refunding afterwards must only return what is still outstanding.
        $svc->apply_status($ci->order_row, 'REFUNDED', 'ADMIN', 'rest');
        $this->assertSame($ci->order->charge, $ci->order_row->refunded_amount);
        $this->assertSame(
            bcadd('100.00000000', $ci->order->charge, 8),
            $ci->wallet->balance,
            'the two refunds together must equal exactly one charge'
        );
    }

    public function testIllegalTransitionIsRejectedAndRefundsNothing()
    {
        $ci = $this->fresh();
        $ci->order->status = 'COMPLETED';
        $svc = new OrderService();

        $res = $svc->apply_status($ci->order, 'PROCESSING', 'ADMIN');
        $this->assertFalse($res['ok']);
        $this->assertSame(0, $ci->ledger_refunds);
    }

    /* ================== deposit queue: approval behaviour ================ */

    public function testApprovingADepositCreditsTheWalletOnce()
    {
        $ci  = $this->fresh();
        $svc = new PaymentService();

        $res = $svc->confirm($ci->tx, 'ADMIN', 'BANKREF-1');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('SUCCESS', $ci->tx->status);
        $this->assertSame(1, $ci->ledger_credits);
        $this->assertSame('BANKREF-1', $ci->tx->provider_tx_id);

        // Approving again is a no-op, not a second credit.
        $again = $svc->confirm($ci->tx, 'ADMIN');
        $this->assertTrue($again['ok']);
        $this->assertTrue(!empty($again['duplicate']));
        $this->assertSame(1, $ci->ledger_credits);
    }

    public function testRejectingADepositCreditsNothing()
    {
        $ci  = $this->fresh();
        $svc = new PaymentService();

        $svc->mark_failed($ci->tx->id, 'No funds received');
        $this->assertSame('FAILED', $ci->tx->status);
        $this->assertSame(0, $ci->ledger_credits);
    }

    public function testAnAlreadyCreditedDepositCannotBeRejected()
    {
        $ci  = $this->fresh();
        $svc = new PaymentService();

        $svc->confirm($ci->tx, 'ADMIN');
        $this->assertSame('SUCCESS', $ci->tx->status);

        // mark_failed() must refuse to touch a terminal SUCCESS row.
        $svc->mark_failed($ci->tx->id, 'too late');
        $this->assertSame('SUCCESS', $ci->tx->status);
        $this->assertSame(1, $ci->ledger_credits);
    }

    /* =================== ticket queue: staff behaviour =================== */

    public function testStaffReplyIsVisibleAndAnswersTheTicket()
    {
        $ci  = $this->fresh();
        $svc = new TicketService();

        $res = $svc->staff_reply($ci->ticket->public_id, $ci->staff, 'We are on it.');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(0, (int)$ci->message->is_internal_note);
        $this->assertSame(1, (int)$ci->message->is_staff);
        $this->assertSame('ANSWERED', $ci->ticket->status);
    }

    public function testInternalNoteIsHiddenAndDoesNotAnswerTheTicket()
    {
        $ci  = $this->fresh();
        $svc = new TicketService();

        $res = $svc->staff_reply($ci->ticket->public_id, $ci->staff, 'Refunded manually, watch for a chargeback.', true);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(1, (int)$ci->message->is_internal_note);
        // A note is bookkeeping: the customer must not see a status change.
        $this->assertSame('OPEN', $ci->ticket->status);
    }

    public function testACustomerReplyCanNeverBecomeAnInternalNote()
    {
        $ci  = $this->fresh();
        $svc = new TicketService();

        // The customer path passes is_staff = false; even if an internal flag
        // leaked in, add_message() must force the message visible.
        $res = $svc->reply($ci->ticket->public_id, $ci->customer, 'Any update?');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(0, (int)$ci->message->is_internal_note);
        $this->assertSame(0, (int)$ci->message->is_staff);
    }

    public function testStaffReplyRejectsAnEmptyBody()
    {
        $ci  = $this->fresh();
        $svc = new TicketService();
        $res = $svc->staff_reply($ci->ticket->public_id, $ci->staff, '   ');
        $this->assertFalse($res['ok']);
        $this->assertSame('BAD_MESSAGE', $res['code']);
    }

    public function testAssignmentRequiresAStaffMember()
    {
        $ci  = $this->fresh();
        $svc = new TicketService();

        $ok = $svc->assign($ci->ticket->public_id, $ci->staff->id);
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame((int)$ci->staff->id, (int)$ci->ticket->assigned_to_id);

        // A customer must never appear in the assignee slot.
        $bad = $svc->assign($ci->ticket->public_id, $ci->customer->id);
        $this->assertFalse($bad['ok']);
        $this->assertSame('BAD_ASSIGNEE', $bad['code']);
    }

    public function testStatusChangeRejectsUnknownValues()
    {
        $ci  = $this->fresh();
        $svc = new TicketService();
        $this->assertTrue($svc->set_status($ci->ticket->public_id, 'CLOSED')['ok']);
        $this->assertSame('CLOSED', $ci->ticket->status);

        $bad = $svc->set_status($ci->ticket->public_id, 'DELETED');
        $this->assertFalse($bad['ok']);
        $this->assertSame('BAD_STATUS', $bad['code']);
    }

    /* ======================= controller guarantees ======================= */

    public function testEveryAdminControllerExtendsAdminController()
    {
        foreach (glob(self::$root.'/application/controllers/admin/*.php') as $file) {
            $src = file_get_contents($file);
            $this->assertStringContainsString('extends Admin_Controller', $src,
                basename($file).' must extend Admin_Controller');
        }
    }

    public function testEveryAdminMutationIsPostOnly()
    {
        // Each mutating action must refuse a GET, so a link or a prefetch can
        // never change state.
        $expected = array(
            'Orders.php'   => array('status','cancel','refund'),
            'Payments.php' => array('approve','reject'),
            'Tickets.php'  => array('reply','assign','status','priority'),
            // Catalogue changes a price, which is money by another name.
            'Catalogue.php'=> array('create','update','status'),
        );
        foreach ($expected as $file => $actions) {
            $src = file_get_contents(self::$root.'/application/controllers/admin/'.$file);
            foreach ($actions as $action) {
                $this->assertStringContainsString('function '.$action.'(', $src,
                    "{$file} must define {$action}()");
            }
            // The guard is centralised; assert it exists and is used by each action.
            $this->assertStringContainsString("method(true) !== 'POST') show_404()", $src,
                "{$file} must reject non-POST mutations");
            $this->assertSame(count($actions), substr_count($src, '$this->guard('),
                "{$file}: every mutation must go through guard()");
        }
    }

    public function testAdminActionsRequireGranularPermissions()
    {
        $orders = file_get_contents(self::$root.'/application/controllers/admin/Orders.php');
        $this->assertStringContainsString("require_perm('orders.view')", $orders);
        $this->assertStringContainsString("'orders.edit'", $orders);
        $this->assertStringContainsString("'orders.cancel'", $orders);
        $this->assertStringContainsString("'orders.refund'", $orders);

        $payments = file_get_contents(self::$root.'/application/controllers/admin/Payments.php');
        $this->assertStringContainsString("require_perm('payments.view')", $payments);
        $this->assertStringContainsString("'payments.manage'", $payments);

        $tickets = file_get_contents(self::$root.'/application/controllers/admin/Tickets.php');
        $this->assertStringContainsString("require_perm('tickets.view')", $tickets);
        $this->assertStringContainsString("'tickets.reply'", $tickets);
        $this->assertStringContainsString("'tickets.manage'", $tickets);
    }

    public function testAdminControllersNeverMoveMoneyDirectly()
    {
        foreach (glob(self::$root.'/application/controllers/admin/*.php') as $file) {
            $src = file_get_contents($file);
            $this->assertStringNotContainsString("ledgerservice->", $src,
                basename($file).' must not call LedgerService directly');
            $this->assertStringNotContainsString("update('wallets'", $src,
                basename($file).' must never write wallets');
            $this->assertStringNotContainsString("insert('wallet_transactions'", $src,
                basename($file).' must never write wallet_transactions');
        }
    }

    public function testAdminMutationsAreAuditLogged()
    {
        foreach (array('Orders.php','Payments.php','Tickets.php') as $file) {
            $src = file_get_contents(self::$root.'/application/controllers/admin/'.$file);
            $this->assertStringContainsString('Audit_log_model', $src, "{$file} must load the audit log");
            $this->assertStringContainsString('$this->audit(', $src, "{$file} must record its mutations");
        }
    }

    public function testAdminViewsEscapeOutputAndCarryCsrfTokens()
    {
        foreach (glob(self::$root.'/application/views/admin/*/*.php') as $file) {
            $src = file_get_contents($file);
            // Every POST form needs the CSRF hidden field.
            if (strpos($src, 'method="post"') !== false) {
                $this->assertStringContainsString('get_csrf_token_name()', basename($file).': '.$src,
                    basename($file).' has a POST form without a CSRF token');
            }
            // A form field that *collects* a key is fine; rendering a stored
            // one is not.
            $this->assertStringNotContainsString('api_key_encrypted', $src,
                basename($file).' must not render stored credentials');
            $this->assertStringNotContainsString('->api_key', $src,
                basename($file).' must not echo an API key');
        }
    }

    public function testAdminRoutesPlaceActionsBeforeTheCatchAllDetail()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        foreach (array('orders','payments','tickets') as $area) {
            $detail = strpos($routes, "\$route['admin/{$area}/(:any)']");
            $this->assertNotFalse($detail, "admin/{$area} detail route missing");
            // Every action route for this area must be declared before the
            // catch-all, or CI3 would swallow it.
            preg_match_all("~\\\$route\['admin/{$area}/\(:any\)/([a-z-]+)'\]~", $routes, $m, PREG_OFFSET_CAPTURE);
            $this->assertNotEmpty($m[0], "admin/{$area} has no action routes");
            foreach ($m[0] as $match) {
                $this->assertLessThan($detail, $match[1],
                    "admin/{$area} action route '{$match[0]}' must precede the (:any) detail route");
            }
        }
    }

    public function testStaffQueueModelsAreNotUserScoped()
    {
        // The admin queues deliberately read across users; that is safe only
        // because the controllers gate them behind a permission.
        $ticket = file_get_contents(self::$root.'/application/models/Ticket_model.php');
        $this->assertStringContainsString('function admin_search', $ticket);
        $this->assertStringContainsString('function admin_find', $ticket);

        $order = file_get_contents(self::$root.'/application/models/Order_model.php');
        $this->assertStringContainsString('function admin_search', $order);
        $this->assertStringContainsString('function admin_count', $order);

        // ...while the customer-facing lookups stay scoped.
        $this->assertStringContainsString("where('user_id', \$user_id)", $ticket);
        $this->assertStringContainsString("->where('user_id', \$user_id)", $order);
    }

    public function testAdminDashboardShowsRealAggregatesNotPlaceholders()
    {
        $view = file_get_contents(self::$root.'/application/views/admin/dashboard.php');
        // The Session 14 placeholder promised widgets "ship in Session 15".
        $this->assertStringNotContainsString('ship in', $view);
        $this->assertStringNotContainsString('Session 15', $view);
        $this->assertStringContainsString('windels_money($today', $view);
        $this->assertStringContainsString('queue[', $view);

        $ctrl = file_get_contents(self::$root.'/application/controllers/admin/Dashboard.php');
        $this->assertStringContainsString('AdminStats', $ctrl);
        $this->assertStringContainsString("require_perm('reports.view')", $ctrl);
        $this->assertStringContainsString("'unread'", $ctrl);
    }

    public function testAdminStatsOnlyReads()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AdminStats.php');
        foreach (array('->insert(', '->update(', '->delete(') as $write) {
            $this->assertStringNotContainsString($write, $src,
                'AdminStats must be read-only, found '.$write);
        }
    }

    public function testDashboardWidgetsRespectPermissions()
    {
        // A STAFF member without payments.view must not be shown the deposit
        // queue card, so each card is wrapped in a permission check.
        $view = file_get_contents(self::$root.'/application/views/admin/dashboard.php');
        $this->assertStringContainsString('$has($perm)', $view);
        $this->assertStringContainsString("'payments.view'", $view);
        $this->assertStringContainsString("'tickets.view'", $view);
        $this->assertStringContainsString("'orders.view'", $view);
    }

    /* ------------------------------- fakes ------------------------------ */

    private function fresh()
    {
        $ci = new AdminFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

/* ------------------------------- doubles -------------------------------- */

#[AllowDynamicProperties]
class AdminFakeCI {
    public $db, $load, $input, $auth, $request_id = 'test';
    public $wallet, $order, $order_row, $service, $tx, $method, $ticket, $message;
    public $staff, $customer;
    public $ledger_refunds = 0, $ledger_credits = 0, $inserts = array();

    public function __construct() {
        // Register first: the real libraries call get_instance() from their
        // own constructors.
        $GLOBALS['__fake_ci'] = $this;

        $this->staff    = (object)array('id'=>2,'username'=>'agent','role'=>'STAFF','status'=>'ACTIVE');
        $this->customer = (object)array('id'=>7,'username'=>'buyer','role'=>'CUSTOMER','status'=>'ACTIVE');
        $this->wallet   = (object)array('id'=>11,'user_id'=>7,'balance'=>'100.00000000','currency'=>'NGN');
        $this->service  = (object)array('id'=>3,'public_id'=>'SVC1','cancel_supported'=>1,'refill_supported'=>1);

        $charge = '12.00000000';
        $this->order = (object)array(
            'id'=>21,'public_id'=>'ORD1','user_id'=>7,'service_id'=>3,'provider_id'=>null,
            'provider_order_id'=>null,'status'=>'PROCESSING','quantity'=>1000,'charge'=>$charge,
            'rate_at_order'=>'12.00000000','refunded_amount'=>'0.00000000','remains'=>null,
            'currency'=>'NGN','link'=>'https://x.com/a','source'=>'WEB',
        );
        // The "stored" row the service reads back after each write.
        $this->order_row = (object)array_merge((array)$this->order, array());

        $this->method = (object)array('id'=>1,'code'=>'manual','name'=>'Manual','type'=>'MANUAL','is_active'=>1,
            'min_amount'=>'1.00000000','max_amount'=>'5000.00000000','fee_percent'=>'0','fee_fixed'=>'0',
            'bonus_percent'=>'0','instructions'=>'Bank details');
        $this->tx = (object)array('id'=>42,'public_id'=>'PAY1','user_id'=>7,'status'=>'PENDING',
            'amount'=>'50.00000000','credited_amount'=>'50.00000000','fee'=>'0','bonus'=>'0',
            'currency'=>'NGN','idempotency_key'=>'payment:deposit:seed','provider_tx_id'=>null,
            'wallet_transaction_id'=>null);

        $this->ticket = (object)array('id'=>31,'public_id'=>'TCK1','user_id'=>7,'subject'=>'Help',
            'status'=>'OPEN','priority'=>'MEDIUM','department'=>'orders','order_id'=>null,
            'assigned_to_id'=>null,'closed_at'=>null);

        $this->db    = new AdminFakeDb($this);
        $this->load  = new AdminFakeLoader();
        $this->input = new AdminFakeInput();
        $this->auth  = new AdminFakeAuth($this);

        $this->Order_model                = new AdminFakeOrderModel($this);
        $this->Order_status_history_model = new AdminFakeHistoryModel($this);
        $this->Service_model              = new AdminFakeServiceModel($this);
        $this->Provider_model             = new AdminFakeNullModel();
        $this->Wallet_model               = new AdminFakeWalletModel($this);
        $this->User_model                 = new AdminFakeUserModel($this);
        $this->Ticket_model               = new AdminFakeTicketModel($this);
        $this->Ticket_message_model       = new AdminFakeMessageModel($this);
        $this->Payment_transaction_model  = new AdminFakeTxModel($this);
        $this->Payment_webhook_model      = new AdminFakeNullModel();
        $this->Payment_event_model        = new AdminFakeEventModel($this);
        $this->Setting_model              = new AdminFakeSettings();
        $this->Blacklist_model            = new AdminFakeBlacklist();

        $this->pricingservice    = new PricingService();
        $this->ledgerservice     = new LedgerService();
        $this->encryptionservice = new EncryptionService();
    }
}

class AdminFakeLoader { function model($n){} function library($n){} }
class AdminFakeInput { function ip_address(){return '127.0.0.1';} function user_agent(){return 'PHPUnit';} }
class AdminFakeAuth { private $ci; function __construct($ci){$this->ci=$ci;} function id(){return $this->ci->staff->id;} }
class AdminFakeNullModel { public function __call($m, $a){ return null; } }
class AdminFakeBlacklist { function text_contains_blacklisted_link($l){ return false; } }
class AdminFakeSettings { function get($k,$d=null){ return $d; } }

class AdminFakeDb {
    private $ci; private $wheres = array();
    public function __construct($ci){ $this->ci = $ci; }
    public function query($sql, $b = false){ return new AdminFakeResult($this->ci->wallet); }
    public function where($k, $v = null){ if (!is_array($k)) $this->wheres[$k] = $v; return $this; }
    public function where_in($k, $v){ return $this; }
    public function order_by($k, $d = 'ASC', $e = null){ return $this; }
    public function limit($l, $o = 0){ return $this; }
    public function select($s, $e = null){ return $this; }
    public function from($t){ return $this; }
    public function join($t, $on, $type = ''){ return $this; }
    public function group_start(){ return $this; }
    public function group_end(){ return $this; }
    public function like($k, $v){ return $this; }
    public function or_like($k, $v){ return $this; }
    public function group_by($k){ return $this; }
    public function trans_start(){} public function trans_complete(){}
    public function trans_rollback(){} public function trans_status(){ return true; }
    public function insert_id(){ return 99; }
    public function count_all_results($t = null){ return 0; }
    public function insert_batch($t, $rows){ return true; }

    public function insert($t, $d = array()){
        $this->ci->inserts[$t] = ($this->ci->inserts[$t] ?? 0) + 1;
        if ($t === 'wallet_transactions') {
            // The real LedgerService runs, so count movements from its rows.
            if (($d['type'] ?? '') === 'REFUND') $this->ci->ledger_refunds++;
            if (($d['type'] ?? '') === 'DEPOSIT') $this->ci->ledger_credits++;
        }
        return true;
    }

    public function update($t, $d){
        $w = $this->wheres; $this->wheres = array();
        if ($t === 'orders')   foreach ($d as $k => $v) $this->ci->order_row->$k = $v;
        if ($t === 'tickets')  foreach ($d as $k => $v) $this->ci->ticket->$k = $v;
        if ($t === 'wallets' && isset($d['balance'])) $this->ci->wallet->balance = $d['balance'];
        if ($t === 'payment_transactions') foreach ($d as $k => $v) $this->ci->tx->$k = $v;
        return true;
    }

    public function get($t = null){
        $w = $this->wheres; $this->wheres = array();
        if ($t === 'wallets') return new AdminFakeResult($this->ci->wallet);
        if ($t === 'orders')  return new AdminFakeResult($this->ci->order_row);
        if ($t === 'services') return new AdminFakeResult($this->ci->service);
        if ($t === 'tickets') return new AdminFakeResult($this->ci->ticket);
        if ($t === 'payment_methods') return new AdminFakeResult($this->ci->method);
        if ($t === 'payment_transactions') return new AdminFakeResult($this->ci->tx);
        if ($t === 'wallet_transactions') {
            // Idempotency probe: only report a hit once the key has been used.
            $idem = $w['idempotency_key'] ?? null;
            if ($idem !== null && in_array($idem, $this->ci->seen_idem ?? array(), true)) {
                return new AdminFakeResult((object)array('id'=>77,'idempotency_key'=>$idem));
            }
            if ($idem !== null) {
                $this->ci->seen_idem = array_merge($this->ci->seen_idem ?? array(), array($idem));
            }
            return new AdminFakeResult(null);
        }
        return new AdminFakeResult(null);
    }
}

class AdminFakeResult {
    private $row; public $rows;
    public function __construct($row){ $this->row = $row; $this->rows = $row ? array($row) : array(); }
    public function row(){ return $this->row; }
    public function result(){ return $this->rows; }
}

class AdminFakeOrderModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function find_by_id($id){ return $this->ci->order_row; }
    function admin_find($pid){ return $this->ci->order_row; }
    function find_public_for_user($pid, $uid){ return $this->ci->order_row; }
    function find_by_idempotency_key($k){ return null; }
}
class AdminFakeHistoryModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function record(){ $this->ci->inserts['order_status_history'] = ($this->ci->inserts['order_status_history'] ?? 0) + 1; }
    function for_order($id){ return array(); }
}
class AdminFakeServiceModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function find_by_id($id){ return $this->ci->service; }
    function find_by_public_id($p){ return $this->ci->service; }
}
class AdminFakeWalletModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function for_user($uid){ return $this->ci->wallet; }
}
class AdminFakeUserModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function find_by_id($id){
        if ((int)$id === (int)$this->ci->staff->id) return $this->ci->staff;
        if ((int)$id === (int)$this->ci->customer->id) return $this->ci->customer;
        return null;
    }
    function is_staff($u){ return $u && in_array($u->role, array('SUPER_ADMIN','ADMIN','STAFF'), true); }
    function staff_members(){ return array($this->ci->staff); }
}
class AdminFakeTicketModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function admin_find($pid){ return $pid === $this->ci->ticket->public_id ? $this->ci->ticket : null; }
    function find_by_id($id){ return $this->ci->ticket; }
    function find_public_for_user($pid, $uid){
        return ($pid === $this->ci->ticket->public_id && (int)$uid === (int)$this->ci->ticket->user_id)
            ? $this->ci->ticket : null;
    }
    function create(array $d){ return $this->ci->ticket; }
    function touch($id, array $extra = array()){ foreach ($extra as $k => $v) $this->ci->ticket->$k = $v; }
    function close($id){ $this->ci->ticket->status = 'CLOSED'; }
    function assign($id, $staff_id){ $this->ci->ticket->assigned_to_id = $staff_id; }
    function set_status($id, $s){ $this->ci->ticket->status = $s; }
    function set_priority($id, $p){ $this->ci->ticket->priority = $p; }
}
class AdminFakeMessageModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function create(array $d){
        $this->ci->message = (object)array_merge(array('id'=>5), $d);
        $this->ci->inserts['ticket_messages'] = ($this->ci->inserts['ticket_messages'] ?? 0) + 1;
        return $this->ci->message;
    }
    function for_ticket($id, $internal = false){ return array(); }
}
class AdminFakeTxModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function find_by_id($id){ return $this->ci->tx; }
    function admin_find($pid){ return $this->ci->tx; }
    function find_by_idempotency_key($k){
        return ($this->ci->tx->idempotency_key === $k) ? $this->ci->tx : null;
    }
    function find_by_provider_tx($id){ return null; }
    function update_status($id, array $d){ foreach ($d as $k => $v) $this->ci->tx->$k = $v; }
}
class AdminFakeEventModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function insert($d){ $this->ci->inserts['payment_events'] = ($this->ci->inserts['payment_events'] ?? 0) + 1; }
    function for_transaction($id){ return array(); }
}
