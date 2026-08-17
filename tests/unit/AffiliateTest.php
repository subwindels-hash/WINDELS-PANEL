<?php
use PHPUnit\Framework\TestCase;

/**
 * Affiliate tests (Session 14) — attribution rules, the commission engine,
 * payout idempotency and the route/controller/authorization guarantees.
 *
 * Runs without a database or network: an in-memory fake CI provides just the
 * query-builder surface AffiliateService and LedgerService actually use.
 */
class AffiliateTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        if (!function_exists('site_url')) eval('function site_url($u=""){ return "https://panel.test/".ltrim($u,"/"); }');
        if (!function_exists('windels_public_id')) require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/LedgerService.php';
        require_once self::$root.'/application/libraries/AffiliateService.php';
        // Only the constants are needed from the model class.
        if (!class_exists('Referral_commission_model')) {
            eval('class Referral_commission_model { const STATUS_PENDING="PENDING"; const STATUS_PAID="PAID"; const STATUS_REVERSED="REVERSED"; const STATUS_REJECTED="REJECTED"; }');
        }
    }

    /* --------------------------- attribution --------------------------- */

    public function testAttributeLinksReferrerAndReferred()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        $res = $svc->attribute($ci->referrer, $ci->referred);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(1, $ci->inserts['referrals']);
        $this->assertSame((int)$ci->referrer->id, (int)$res['referral']->referrer_id);
        $this->assertSame((int)$ci->referred->id, (int)$res['referral']->referred_id);
    }

    public function testSelfReferralIsRejected()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        $res = $svc->attribute($ci->referrer, $ci->referrer);
        $this->assertFalse($res['ok']);
        $this->assertSame('SELF_REFERRAL', $res['code']);
        $this->assertSame(0, $ci->inserts['referrals'] ?? 0);
    }

    public function testAttributionIsFirstTouchAndNeverOverwritten()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        $this->assertTrue($svc->attribute($ci->referrer, $ci->referred)['ok']);

        $other = (object)array('id'=>99,'status'=>'ACTIVE','referral_code'=>'OTHER123');
        $res = $svc->attribute($other, $ci->referred);
        $this->assertFalse($res['ok']);
        $this->assertSame('ALREADY_ATTRIBUTED', $res['code']);
        $this->assertSame(1, $ci->inserts['referrals']);
    }

    public function testCircularReferralIsRejected()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        // referred -> referrer already exists; the reverse edge must be refused.
        $this->assertTrue($svc->attribute($ci->referred, $ci->referrer)['ok']);
        $res = $svc->attribute($ci->referrer, $ci->referred);
        $this->assertFalse($res['ok']);
        $this->assertSame('CIRCULAR', $res['code']);
    }

    public function testInactiveReferrerIsRejected()
    {
        $ci = $this->fresh();
        $ci->referrer->status = 'SUSPENDED';
        $svc = new AffiliateService();
        $res = $svc->attribute($ci->referrer, $ci->referred);
        $this->assertFalse($res['ok']);
        $this->assertSame('REFERRER_INACTIVE', $res['code']);
    }

    public function testAccountIsCreatedLazilyWithStableCode()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        $account = $svc->account_for($ci->referrer);
        $this->assertNotNull($account);
        $this->assertSame('REF12345', $account->code, 'reuses users.referral_code');
        // A second call must not create a second account.
        $svc->account_for($ci->referrer);
        $this->assertSame(1, $ci->inserts['referral_accounts']);
    }

    public function testResolveCodeIgnoresUnknownAndInactiveUsers()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        $this->assertNull($svc->resolve_code('does-not-exist'));
        $this->assertNull($svc->resolve_code(''));
        $this->assertNotNull($svc->resolve_code('REF12345'));

        $ci->referrer->status = 'BANNED';
        $this->assertNull($svc->resolve_code('REF12345'));
    }

    /* ---------------------------- commission --------------------------- */

    public function testCommissionAmountUsesBcmathNotFloats()
    {
        $svc = new AffiliateService();
        $this->assertSame('0.50000000', $svc->commission_amount('10.00000000', '5.0000'));
        $this->assertSame('0.00350000', $svc->commission_amount('0.07000000', '5.0000'));
        $this->assertSame('1.23456789', $svc->commission_amount('12.34567890', '10.0000'));
        // Non-numeric / negative input can never mint money.
        $this->assertSame('0.00000000', $svc->commission_amount('abc', '5'));
        $this->assertSame('0.00000000', $svc->commission_amount('10', '-5'));
        $this->assertSame('0.00000000', $svc->commission_amount('10', '0'));
    }

    public function testRecordForOrderAccruesPendingCommission()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $svc = new AffiliateService();

        $res = $svc->record_for_order($ci->order);
        $this->assertTrue($res['ok'], $res['skipped'] ?? '');
        $this->assertSame('PENDING', $res['commission']->status);
        // 20.00 charge at 5% = 1.00
        $this->assertSame('1.00000000', $res['commission']->amount);
        $this->assertSame(1, $ci->inserts['referral_commissions']);
    }

    public function testCommissionIsNetOfRefunds()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $ci->order->refunded_amount = '4.00000000';   // 20 - 4 = 16 at 5% = 0.80
        $svc = new AffiliateService();

        $res = $svc->record_for_order($ci->order);
        $this->assertTrue($res['ok']);
        $this->assertSame('0.80000000', $res['commission']->amount);
    }

    public function testRecordForOrderIsIdempotent()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $svc = new AffiliateService();

        $svc->record_for_order($ci->order);
        $again = $svc->record_for_order($ci->order);
        $this->assertTrue($again['ok']);
        $this->assertTrue(!empty($again['duplicate']));
        $this->assertSame(1, $ci->inserts['referral_commissions'], 'a second accrual must not insert');
    }

    public function testUnreferredOrNonQualifyingOrdersAccrueNothing()
    {
        $ci = $this->fresh();          // no referral edge registered
        $svc = new AffiliateService();
        $res = $svc->record_for_order($ci->order);
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_REFERRED', $res['skipped']);

        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        foreach (array('PENDING','PROCESSING','IN_PROGRESS','CANCELED','FAILED','REFUNDED') as $status) {
            $ci->order->status = $status;
            $r = $svc->record_for_order($ci->order);
            $this->assertFalse($r['ok'], "status {$status} must not accrue");
            $this->assertSame('NOT_QUALIFYING', $r['skipped']);
        }
        $this->assertSame(0, $ci->inserts['referral_commissions'] ?? 0);
    }

    public function testDisabledProgramAccruesNothing()
    {
        $ci = $this->fresh();
        $ci->flags['affiliate_program'] = false;
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $svc = new AffiliateService();
        $res = $svc->record_for_order($ci->order);
        $this->assertFalse($res['ok']);
        $this->assertSame('DISABLED', $res['skipped']);
    }

    /* ------------------------------ payout ----------------------------- */

    public function testPayCreditsWalletThroughLedgerAndMarksPaid()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $svc = new AffiliateService();
        $commission = (object)array('id'=>77,'referral_id'=>31,'order_id'=>9,'amount'=>'1.00000000','currency'=>'NGN','status'=>'PENDING');
        $ci->commissions[77] = $commission;

        $before = $ci->wallet->balance;
        $res = $svc->pay($commission);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame(bcadd($before, '1.00000000', 8), $ci->wallet->balance);
        $this->assertSame('REFERRAL_BONUS', $ci->last_wallet_tx['type']);
        $this->assertSame('CREDIT', $ci->last_wallet_tx['direction']);
        $this->assertSame('referral:commission:77', $ci->last_wallet_tx['idempotency_key']);
        $this->assertSame('PAID', $ci->commissions[77]->status);
        $this->assertSame(1, $ci->notifications);
    }

    public function testPayRefusesNonPendingCommission()
    {
        $ci = $this->fresh();
        $svc = new AffiliateService();
        $paid = (object)array('id'=>78,'referral_id'=>31,'amount'=>'1.00000000','currency'=>'NGN','status'=>'PAID');
        $res = $svc->pay($paid);
        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_PENDING', $res['code']);
        $this->assertSame(0, $ci->ledger_credits);
    }

    public function testPayLosingTheClaimRaceDoesNotCreditTwice()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $ci->claim_fails = true;    // another worker won the compare-and-set
        $svc = new AffiliateService();
        $commission = (object)array('id'=>79,'referral_id'=>31,'order_id'=>9,'amount'=>'2.00000000','currency'=>'NGN','status'=>'PENDING');
        $ci->commissions[79] = $commission;

        $res = $svc->pay($commission);
        $this->assertFalse($res['ok']);
        $this->assertSame('RACE_LOST', $res['code']);
    }

    public function testLedgerIdempotencyKeyBlocksDoubleCredit()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $svc = new AffiliateService();
        $commission = (object)array('id'=>80,'referral_id'=>31,'order_id'=>9,'amount'=>'3.00000000','currency'=>'NGN','status'=>'PENDING');
        $ci->commissions[80] = $commission;

        $svc->pay($commission);
        $balance_after_first = $ci->wallet->balance;

        // Replay the same commission id: the ledger must treat it as a duplicate.
        $commission->status = 'PENDING';
        $svc->pay($commission);
        $this->assertSame($balance_after_first, $ci->wallet->balance, 'the wallet must be credited exactly once');
        $this->assertSame(1, $ci->ledger_credits);
    }

    public function testPayDueRespectsHoldWindowAndMinimum()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $ci->settings['referral_min_payout'] = '0.50000000';
        $ci->payable = array(
            (object)array('id'=>90,'referral_id'=>31,'order_id'=>9,'amount'=>'1.00000000','currency'=>'NGN','status'=>'PENDING','referrer_id'=>1),
            (object)array('id'=>91,'referral_id'=>31,'order_id'=>10,'amount'=>'0.10000000','currency'=>'NGN','status'=>'PENDING','referrer_id'=>1),
        );
        $svc = new AffiliateService();
        $res = $svc->pay_due();

        $this->assertSame(1, $res['paid'], 'only the commission above the minimum is paid');
        $this->assertSame(1, $res['skipped']);
        $this->assertSame('1.00000000', $res['amount']);
        // The cutoff passed to payable() must be in the past by hold_hours.
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s', time() - (24 * 3600) + 5), $ci->last_cutoff);
    }

    public function testReverseForOrderVoidsUnpaidCommission()
    {
        $ci = $this->fresh();
        $ci->referral = (object)array('id'=>31,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
        $ci->commissions[77] = (object)array('id'=>77,'referral_id'=>31,'order_id'=>9,'amount'=>'1.00000000','currency'=>'NGN','status'=>'PENDING');
        $ci->commission_for_order = $ci->commissions[77];
        $svc = new AffiliateService();

        $res = $svc->reverse_for_order($ci->order);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['reversed']);
        $this->assertSame('REVERSED', $ci->commissions[77]->status);
        $this->assertSame(0, $ci->ledger_credits, 'a reversal never touches the wallet');
    }

    /* --------------------- source-level guarantees --------------------- */

    public function testOnlyLedgerServiceMutatesWallets()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AffiliateService.php');
        $this->assertStringNotContainsString("update('wallets'", $src);
        $this->assertStringContainsString('ledgerservice->credit', $src);
        // The credit is keyed on the commission id, making replays free.
        $this->assertStringContainsString("'referral:commission:'", $src);
    }

    public function testCommissionMathNeverUsesFloatArithmetic()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AffiliateService.php');
        foreach (array('bcmul','bcdiv','bcadd','bcsub','bccomp') as $fn) {
            $this->assertStringContainsString($fn.'(', $src, "commission math must use {$fn}");
        }
        $this->assertDoesNotMatchRegularExpression('/\(float\)\s*\$(charge|amount|percent)/', $src);
    }

    public function testMarkPaidIsACompareAndSet()
    {
        $src = file_get_contents(self::$root.'/application/models/Referral_commission_model.php');
        $this->assertStringContainsString("where('status', self::STATUS_PENDING)", $src);
        $this->assertStringContainsString('affected_rows()', $src);
    }

    public function testReferralAccountCountersAreSqlIncrementsNotReadModifyWrite()
    {
        $src = file_get_contents(self::$root.'/application/models/Referral_account_model.php');
        $this->assertStringContainsString("'total_referred + '", $src);
        $this->assertStringContainsString("'total_earned + '", $src);
        // Values interpolated into SQL are clamped to a decimal literal first.
        $this->assertStringContainsString('preg_match', $src);
    }

    /* ---------------------- routes / controllers ----------------------- */

    public function testCustomerAndAdminRoutesExist()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("'dashboard/referrals'] = 'dashboard/referrals/index'", $routes);
        $this->assertStringContainsString("'dashboard/referrals/commissions'", $routes);
        $this->assertStringContainsString("'admin/affiliates'] = 'admin/affiliates/index'", $routes);
        $this->assertStringContainsString("'admin/affiliates/payout'", $routes);
        $this->assertStringContainsString("'admin/affiliates/(:num)/rate'", $routes);
        $this->assertStringContainsString("'api/v1/referrals'", $routes);

        // The specific routes must be declared before the generic ones.
        $this->assertLessThan(
            strpos($routes, "\$route['dashboard/referrals/commissions'"),
            strpos($routes, "\$route['dashboard/referrals']")
        );
        $this->assertLessThan(
            strpos($routes, "\$route['admin/affiliates/(:num)/rate'"),
            strpos($routes, "\$route['admin/affiliates/payout'")
        );
    }

    public function testControllersExistAndAreGuarded()
    {
        $customer = self::$root.'/application/controllers/dashboard/Referrals.php';
        $admin    = self::$root.'/application/controllers/admin/Affiliates.php';
        $this->assertFileExists($customer);
        $this->assertFileExists($admin);

        $src = file_get_contents($customer);
        $this->assertStringContainsString('extends Auth_Controller', $src);
        $this->assertStringContainsString('function index(', $src);
        $this->assertStringContainsString('function commissions(', $src);
        $this->assertStringContainsString("'nav_active'", $src);
        $this->assertStringContainsString("'unread'", $src);
        // Customers never mutate affiliate state.
        $this->assertStringNotContainsString('->pay(', $src);
        $this->assertStringNotContainsString('pay_due', $src);

        $asrc = file_get_contents($admin);
        $this->assertStringContainsString('extends Admin_Controller', $asrc);
        $this->assertStringContainsString("require_perm('affiliates.view')", $asrc);
        $this->assertStringContainsString("require_perm('affiliates.manage')", $asrc);
        // Both mutations are POST-only.
        $this->assertSame(2, substr_count($asrc, "method(true) !== 'POST') show_404()"));
        $this->assertStringContainsString('Audit_log_model', $asrc);
    }

    public function testAdminViewsSendCsrfTokensOnEveryForm()
    {
        $html = file_get_contents(self::$root.'/application/views/admin/affiliates/index.php');
        $this->assertSame(substr_count($html, '<form'), substr_count($html, 'get_csrf_token_name'),
            'every admin affiliate form must carry a CSRF token');
    }

    public function testCustomerViewsNeverExposeAnotherUsersData()
    {
        foreach (array('index','commissions') as $view) {
            $html = file_get_contents(self::$root.'/application/views/dashboard/referrals/'.$view.'.php');
            $this->assertStringNotContainsString('->email', $html, 'referred users\' emails must stay private');
            $this->assertStringContainsString('htmlspecialchars', $html);
        }
    }

    public function testModelsScopeQueriesToTheRequestingReferrer()
    {
        $src = file_get_contents(self::$root.'/application/models/Referral_commission_model.php');
        $this->assertStringContainsString("where('r.referrer_id', \$referrer_id)", $src);
        $rsrc = file_get_contents(self::$root.'/application/models/Referral_model.php');
        $this->assertStringContainsString("where('r.referrer_id', \$referrer_id)", $rsrc);
    }

    /* -------------------- wiring into other modules -------------------- */

    public function testRegistrationAttributesReferral()
    {
        $src = file_get_contents(self::$root.'/application/libraries/AuthService.php');
        $this->assertStringContainsString('AffiliateService', $src);
        $this->assertStringContainsString('attribute(', $src);
        // Attribution failure must never break registration.
        $this->assertStringContainsString('attribution failed', $src);
    }

    public function testOrderEngineAccruesAndReversesCommissions()
    {
        $src = file_get_contents(self::$root.'/application/libraries/OrderService.php');
        $this->assertStringContainsString('sync_affiliate', $src);
        $this->assertStringContainsString('record_for_order', $src);
        $this->assertStringContainsString('reverse_for_order', $src);
    }

    public function testCronWorkerIsCliOnlyAndRegistered()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $this->assertStringContainsString('function affiliate_payouts(', $src);
        $this->assertStringContainsString('extends Cron_Controller', $src);
        $crontab = file_get_contents(self::$root.'/cron/crontab.example');
        $this->assertStringContainsString('cron affiliate_payouts', $crontab);
        // Never reachable over HTTP (§66).
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringNotContainsString('affiliate_payouts', $routes);
    }

    public function testAffiliateSettingsAreSeeded()
    {
        if (!class_exists('Core_seeder')) {
            if (!class_exists('Seeder')) require_once self::$root.'/application/libraries/Seeder.php';
            require_once self::$root.'/application/seeds/Core_seeder.php';
        }
        $settings = array();
        foreach (Core_seeder::default_settings() as $s) { $settings[$s[0]] = $s; }

        foreach (array('referral_commission_percent','referral_commission_scope','referral_hold_hours','referral_min_payout') as $key) {
            $this->assertArrayHasKey($key, $settings, "{$key} must be seeded");
            $this->assertSame('affiliate', $settings[$key][2]);
        }
        $this->assertSame('LIFETIME', $settings['referral_commission_scope'][1]);
    }

    /* ------------------------------ fakes ------------------------------ */

    private function fresh()
    {
        $ci = new AffFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

/* ------------------------------- doubles ------------------------------- */

#[AllowDynamicProperties]
class AffFakeCI {
    public $referrer, $referred, $order, $wallet, $referral = null, $account = null;
    public $db, $load, $inserts = array(), $notifications = 0, $ledger_credits = 0;
    public $commissions = array(), $commission_for_order = null, $payable = array();
    public $settings = array(), $flags = array('affiliate_program' => true);
    public $claim_fails = false, $last_wallet_tx = array(), $last_cutoff = null;
    public $seen_idem = array();

    public function __construct() {
        // Register before constructing anything that calls get_instance()
        // inside its own constructor (the real libraries below do).
        $GLOBALS['__fake_ci'] = $this;
        // Register ourselves before building anything that calls get_instance()
        // in its constructor (LedgerService does).
        $GLOBALS['__fake_ci'] = $this;
        $this->referrer = (object)array('id'=>1,'status'=>'ACTIVE','referral_code'=>'REF12345');
        $this->referred = (object)array('id'=>2,'status'=>'ACTIVE','referral_code'=>'REF67890');
        $this->order    = (object)array('id'=>9,'public_id'=>'ORD1','user_id'=>2,'status'=>'COMPLETED',
                                        'charge'=>'20.00000000','refunded_amount'=>'0.00000000','currency'=>'NGN');
        $this->wallet   = (object)array('id'=>11,'user_id'=>1,'balance'=>'50.00000000','currency'=>'NGN');
        $this->db   = new AffFakeDb($this);
        $this->load = new AffFakeLoader($this);

        $this->Referral_account_model    = new AffFakeAccountModel($this);
        $this->Referral_model            = new AffFakeReferralModel($this);
        $this->Referral_commission_model = new AffFakeCommissionModel($this);
        $this->User_model                = new AffFakeUserModel($this);
        $this->Wallet_model              = new AffFakeWalletModel($this);
        $this->Setting_model             = new AffFakeSettingModel($this);
        $this->Feature_flag_model        = new AffFakeFlagModel($this);
        $this->ledgerservice             = new LedgerService();
    }
    public function bump($table) { $this->inserts[$table] = ($this->inserts[$table] ?? 0) + 1; }
}

class AffFakeLoader {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function model($n) { return $this; }
    public function library($n) { return $this; }
}

/** Only the query-builder surface LedgerService + AffiliateService use. */
class AffFakeDb {
    private $ci; private $where = array();
    public function __construct($ci) { $this->ci = $ci; }
    public function where($k, $v = null) { if (is_array($k)) $this->where += $k; else $this->where[$k] = $v; return $this; }
    public function where_in($k, $v) { return $this; }
    public function select($s, $b = false) { return $this; }
    public function from($t) { return $this; }
    public function join($t, $on, $type = '') { return $this; }
    public function order_by($k, $d = 'ASC') { return $this; }
    public function group_by($k) { return $this; }
    public function limit($l, $o = 0) { return $this; }
    public function set($k, $v, $esc = true) { return $this; }
    public function trans_start() {} public function trans_complete() {} public function trans_rollback() {}
    public function trans_status() { return true; }
    public function affected_rows() { return 1; }
    public function insert_id() { return 5; }
    public function count_all_results($t = null) { return 0; }

    public function query($sql, $binds = false) { return new AffFakeResult($this->ci->wallet); }

    public function insert($t, $d = array()) {
        $this->ci->bump($t);
        if ($t === 'wallet_transactions') {
            $this->ci->last_wallet_tx = $d;
            $this->ci->seen_idem[$d['idempotency_key']] = $d;
            if (($d['type'] ?? '') === 'REFERRAL_BONUS') $this->ci->ledger_credits++;
        }
        if ($t === 'notifications') $this->ci->notifications++;
        $this->where = array();
        return true;
    }
    public function update($t, $d) {
        if ($t === 'wallets' && isset($d['balance'])) $this->ci->wallet->balance = $d['balance'];
        $this->where = array();
        return true;
    }

    public function get($t = null) {
        $w = $this->where; $this->where = array();
        if ($t === 'wallet_transactions') {
            $idem = $w['idempotency_key'] ?? null;
            if ($idem && isset($this->ci->seen_idem[$idem])) {
                return new AffFakeResult((object)array_merge(array('id' => 501), $this->ci->seen_idem[$idem]));
            }
            return new AffFakeResult(null);
        }
        if ($t === 'referral_commissions') {
            return new AffFakeResult($this->ci->commission_for_order);
        }
        if ($t === 'wallets') return new AffFakeResult($this->ci->wallet);
        return new AffFakeResult(null);
    }
}

class AffFakeResult {
    private $row; public $rows;
    public function __construct($r) { $this->row = $r; $this->rows = $r ? array($r) : array(); }
    public function row() { return $this->row; }
    public function result() { return $this->rows; }
}

class AffFakeAccountModel {
    private $ci; private $next_id = 21;
    public function __construct($ci) { $this->ci = $ci; }
    public function for_user($uid) {
        return ($this->ci->account && (int)$this->ci->account->user_id === (int)$uid) ? $this->ci->account : null;
    }
    public function find_by_code($code) {
        return ($this->ci->account && $this->ci->account->code === $code) ? $this->ci->account : null;
    }
    public function find_by_id($id) {
        return ($this->ci->account && (int)$this->ci->account->id === (int)$id)
            ? $this->ci->account
            : (object)array('id'=>$id,'user_id'=>1,'commission_percent'=>'5.0000');
    }
    public function create(array $d) {
        $this->ci->bump('referral_accounts');
        $this->ci->account = (object)array_merge(array('id'=>$this->next_id++), $d);
        return $this->ci->account;
    }
    public function add_totals($id, $referred = 0, $earned = '0', $paid = '0') { return true; }
    public function set_percent($id, $p) { return true; }
}

class AffFakeReferralModel {
    private $ci; private $next_id = 31;
    public function __construct($ci) { $this->ci = $ci; }
    public function for_referred($id) {
        return ($this->ci->referral && (int)$this->ci->referral->referred_id === (int)$id) ? $this->ci->referral : null;
    }
    public function find_by_id($id) {
        return $this->ci->referral ?: (object)array('id'=>$id,'referrer_id'=>1,'referred_id'=>2,'referral_account_id'=>21);
    }
    public function create(array $d) {
        $this->ci->bump('referrals');
        $this->ci->referral = (object)array_merge(array('id'=>$this->next_id++), $d);
        return $this->ci->referral;
    }
    public function count_for_referrer($id) { return $this->ci->referral ? 1 : 0; }
    public function for_referrer($id, $l = 25, $o = 0) { return array(); }
}

class AffFakeCommissionModel {
    private $ci; private $next_id = 77;
    public function __construct($ci) { $this->ci = $ci; }
    public function find_by_id($id) { return $this->ci->commissions[$id] ?? null; }
    public function find_for_order($referral_id, $order_id) {
        foreach ($this->ci->commissions as $c) {
            if ((int)$c->referral_id === (int)$referral_id && (int)$c->order_id === (int)$order_id) return $c;
        }
        return null;
    }
    public function create(array $d) {
        $this->ci->bump('referral_commissions');
        $id = $this->next_id++;
        $row = (object)array_merge(array('id'=>$id), $d);
        $this->ci->commissions[$id] = $row;
        $this->ci->commission_for_order = $row;
        return $row;
    }
    public function count_for_referrer($id, $status = null) { return count($this->ci->commissions); }
    public function sum_for_referrer($id, $status = null) { return '0.00000000'; }
    public function for_referrer($id, $l = 25, $o = 0, $s = null) { return array(); }
    public function payable($not_after, $limit = 200) {
        $this->ci->last_cutoff = $not_after;
        foreach ($this->ci->payable as $row) { $this->ci->commissions[$row->id] = $row; }
        return $this->ci->payable;
    }
    public function mark_paid($id, $wt_id) {
        if ($this->ci->claim_fails) return false;
        if (!isset($this->ci->commissions[$id])) return false;
        if ($this->ci->commissions[$id]->status !== 'PENDING') return false;
        $this->ci->commissions[$id]->status = 'PAID';
        $this->ci->commissions[$id]->wallet_transaction_id = $wt_id;
        return true;
    }
    public function reverse($id) {
        if (!isset($this->ci->commissions[$id]) || $this->ci->commissions[$id]->status !== 'PENDING') return false;
        $this->ci->commissions[$id]->status = 'REVERSED';
        return true;
    }
}

class AffFakeUserModel {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function find_by_id($id) {
        foreach (array($this->ci->referrer, $this->ci->referred) as $u) {
            if ((int)$u->id === (int)$id) return $u;
        }
        return null;
    }
    public function find_by_referral_code($code) {
        foreach (array($this->ci->referrer, $this->ci->referred) as $u) {
            if ($u->referral_code === $code) return $u;
        }
        return null;
    }
}

class AffFakeWalletModel {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function for_user($id) { return $this->ci->wallet; }
}

class AffFakeSettingModel {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function get($k, $default = null) { return $this->ci->settings[$k] ?? $default; }
}

class AffFakeFlagModel {
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function enabled($k) { return (bool)($this->ci->flags[$k] ?? true); }
}
