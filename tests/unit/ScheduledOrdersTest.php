<?php
use PHPUnit\Framework\TestCase;

/**
 * Scheduled order execution (Session 16) — the drip-feed and subscription
 * engines that place orders on a timer with no user present.
 *
 * These are the highest-risk paths in the panel: a bug here silently double-
 * charges a customer or double-submits to a provider, and nobody is watching.
 * The tests concentrate on exactly that: money is charged once, orders are
 * placed once, and a lost race is a no-op rather than a duplicate.
 */
class ScheduledOrdersTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('class CI_Model {}');
        if (!function_exists('get_instance')) eval('function &get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/helpers/windels_helper.php';
        require_once self::$root.'/application/libraries/DripfeedService.php';
        require_once self::$root.'/application/libraries/SubscriptionService.php';
    }

    /* ============================== drip-feed ============================= */

    public function testADueRunPlacesAPrepaidChildOrder()
    {
        $ci = $this->fresh();
        $svc = new DripfeedService();

        $res = $svc->execute_due_run($ci->drip);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['run_number']);

        // Prepaid: the schedule already took the full charge at creation time.
        $this->assertSame(1, count($ci->placed));
        $this->assertTrue($ci->placed[0]['prepaid'],
            'a drip-feed child order must not charge the wallet again');
    }

    public function testTheRunIsIdempotentPerScheduleAndRunNumber()
    {
        $ci = $this->fresh();
        $svc = new DripfeedService();
        $svc->execute_due_run($ci->drip);

        // A crash between placing the order and recording it must resolve to the
        // existing order on retry, not a second submission.
        $this->assertSame('dripfeed:DF1:run:1', $ci->placed[0]['input']['idempotency_key']);
    }

    public function testALostClaimPlacesNoOrder()
    {
        $ci = $this->fresh();
        $ci->claim_succeeds = false;   // another worker got there first
        $svc = new DripfeedService();

        $res = $svc->execute_due_run($ci->drip);
        $this->assertTrue($res['ok']);
        $this->assertTrue($res['skipped']);
        $this->assertSame(array(), $ci->placed, 'losing the race must be a no-op');
    }

    public function testASuccessfulRunSchedulesTheNext()
    {
        $ci = $this->fresh();
        $svc = new DripfeedService();
        $svc->execute_due_run($ci->drip);

        $this->assertSame(1, (int)$ci->drip->runs_completed);
        $this->assertSame('ACTIVE', $ci->drip->status);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $ci->drip->next_run_at);
    }

    public function testTheFinalRunCompletesTheSchedule()
    {
        $ci = $this->fresh();
        $ci->drip->runs = 3;
        $ci->drip->runs_completed = 2;
        $ci->run->run_number = 3;
        $svc = new DripfeedService();

        $res = $svc->execute_due_run($ci->drip);
        $this->assertTrue($res['finished']);
        $this->assertSame('COMPLETED', $ci->drip->status);
        $this->assertNull($ci->drip->next_run_at, 'a finished schedule must stop being picked up');
    }

    public function testAFailedRunIsRecordedAndTheScheduleSurvives()
    {
        $ci = $this->fresh();
        $ci->place_error = array('ok'=>false, 'error'=>'Provider rejected link', 'code'=>'SUBMIT_FAILED');
        $svc = new DripfeedService();

        $res = $svc->execute_due_run($ci->drip);
        $this->assertFalse($res['ok']);
        $this->assertSame('FAILED', $ci->run->status);
        $this->assertStringContainsString('Provider rejected', $ci->run->error);
        // One bad run must not kill the whole schedule.
        $this->assertSame('ACTIVE', $ci->drip->status);
    }

    public function testAPausedScheduleIsSkipped()
    {
        $ci = $this->fresh();
        $ci->drip->status = 'PAUSED';
        $svc = new DripfeedService();

        $res = $svc->execute_due_run($ci->drip);
        $this->assertTrue($res['skipped']);
        $this->assertSame(array(), $ci->placed);
    }

    public function testAScheduleThatIsNotYetDueIsSkipped()
    {
        $ci = $this->fresh();
        $ci->drip->next_run_at = gmdate('Y-m-d H:i:s', time() + 3600);
        $svc = new DripfeedService();

        $this->assertTrue($svc->execute_due_run($ci->drip)['skipped']);
        $this->assertSame(array(), $ci->placed);
    }

    /* ============================ subscriptions =========================== */

    public function testASubscriptionRunChargesNormally()
    {
        $ci = $this->fresh();
        $svc = new SubscriptionService();

        $res = $svc->execute_due($ci->sub);
        $this->assertTrue($res['ok']);
        // Subscriptions are billed per run, unlike drip-feed.
        $this->assertFalse($ci->placed[0]['prepaid']);
        $this->assertSame('subscription:SUB1:run:1', $ci->placed[0]['input']['idempotency_key']);
    }

    public function testTheCycleClockAdvancesBeforeOrdering()
    {
        $ci = $this->fresh();
        $before = $ci->sub->next_execution_at;
        $svc = new SubscriptionService();
        $svc->execute_due($ci->sub);

        $this->assertNotSame($before, $ci->sub->next_execution_at,
            'the clock must move so the next tick cannot re-run this cycle');
        $this->assertSame(1, (int)$ci->sub->runs_completed);
    }

    public function testALostSubscriptionClaimPlacesNoOrder()
    {
        $ci = $this->fresh();
        $ci->claim_succeeds = false;
        $svc = new SubscriptionService();

        $this->assertTrue($svc->execute_due($ci->sub)['skipped']);
        $this->assertSame(array(), $ci->placed);
    }

    public function testInsufficientBalancePausesRatherThanFails()
    {
        $ci = $this->fresh();
        $ci->place_error = array('ok'=>false, 'error'=>'Insufficient balance', 'code'=>'INSUFFICIENT_BALANCE');
        $svc = new SubscriptionService();

        $res = $svc->execute_due($ci->sub);
        $this->assertTrue($res['paused']);
        // Recoverable: the customer tops up and resumes; the plan is not burned.
        $this->assertSame('PAUSED', $ci->sub->status);
        $this->assertSame('paused', $ci->events[0]['type']);
    }

    public function testAnExpiredSubscriptionIsClosedNotOrdered()
    {
        $ci = $this->fresh();
        $ci->sub->expires_at = gmdate('Y-m-d H:i:s', time() - 60);
        $svc = new SubscriptionService();

        $res = $svc->execute_due($ci->sub);
        $this->assertSame('expired', $res['reason']);
        $this->assertSame('EXPIRED', $ci->sub->status);
        $this->assertSame(array(), $ci->placed);
    }

    public function testASubscriptionStopsAfterItsLastRun()
    {
        $ci = $this->fresh();
        $ci->sub->runs = 2;
        $ci->sub->runs_completed = 2;
        $svc = new SubscriptionService();

        $res = $svc->execute_due($ci->sub);
        $this->assertSame('all runs completed', $res['reason']);
        $this->assertSame('COMPLETED', $ci->sub->status);
        $this->assertSame(array(), $ci->placed);
    }

    public function testExecutionIsLogged()
    {
        $ci = $this->fresh();
        $svc = new SubscriptionService();
        $svc->execute_due($ci->sub);

        $this->assertSame('executed', $ci->events[0]['type']);
        $this->assertStringContainsString('ORD-NEW', $ci->events[0]['payload']);
    }

    /* ------------------------------- fakes ------------------------------- */

    private function fresh()
    {
        $ci = new SchedFakeCI();
        $GLOBALS['__fake_ci'] = $ci;
        return $ci;
    }
}

/* ------------------------------- doubles --------------------------------- */

#[AllowDynamicProperties]
class SchedFakeCI {
    public $db, $load, $config;
    public $drip, $run, $sub;
    public $placed = array(), $events = array();
    public $claim_succeeds = true, $place_error = null;

    public function __construct() {
        $GLOBALS['__fake_ci'] = $this;
        $past = gmdate('Y-m-d H:i:s', time() - 60);

        $this->drip = (object)array(
            'id'=>4, 'public_id'=>'DF1', 'user_id'=>7, 'service_id'=>2, 'link'=>'https://x.test/p',
            'quantity_per_run'=>100, 'runs'=>5, 'runs_completed'=>0, 'interval_minutes'=>60,
            'fields'=>null, 'status'=>'ACTIVE', 'next_run_at'=>$past,
        );
        $this->run = (object)array('id'=>44, 'dripfeed_order_id'=>4, 'run_number'=>1,
            'status'=>'PENDING', 'order_id'=>null, 'error'=>null, 'executed_at'=>null);
        $this->sub = (object)array(
            'id'=>9, 'public_id'=>'SUB1', 'user_id'=>7, 'service_id'=>2, 'target'=>'https://x.test/p',
            'quantity'=>50, 'interval_type'=>'daily', 'runs'=>null, 'runs_completed'=>0,
            'status'=>'ACTIVE', 'next_execution_at'=>$past, 'expires_at'=>null, 'metadata'=>null,
        );

        $this->db     = new SchedFakeDb($this);
        $this->load   = new SchedFakeLoader();
        $this->config = new SchedFakeConfig();

        $this->User_model           = new SchedFakeUserModel();
        $this->Dripfeed_order_model = new SchedFakeDripModel($this);
        $this->Dripfeed_run_model   = new SchedFakeRunModel($this);
        $this->Subscription_model   = new SchedFakeSubModel($this);
        $this->orderservice         = new SchedFakeOrderService($this);
    }
}

class SchedFakeLoader { function model($n){} function library($n){} }
class SchedFakeConfig { function item($k){ return null; } }
class SchedFakeUserModel { function find_by_id($id){ return (object)array('id'=>$id, 'username'=>'demo'); } }
class SchedFakeDripModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function find_by_id($id){ return $this->ci->drip; }
    function due_runs($l = 100){ return array($this->ci->drip); }
}
class SchedFakeRunModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function next_pending($drip_id){ return $this->ci->run->status === 'PENDING' ? $this->ci->run : null; }
}
class SchedFakeSubModel {
    private $ci; function __construct($ci){ $this->ci = $ci; }
    function find_by_id($id){ return $this->ci->sub; }
    function due($l = 100){ return array($this->ci->sub); }
}

class SchedFakeDb {
    private $ci; private $wheres = array(); private $affected = 0;
    public function __construct($ci){ $this->ci = $ci; }
    public function where($k, $v = null, $e = null){ if (!is_array($k)) $this->wheres[$k] = $v; return $this; }
    public function where_in($k, $v){ return $this; }
    public function order_by($k, $d = 'ASC', $e = null){ return $this; }
    public function limit($l, $o = 0){ return $this; }
    public function select($s, $e = null){ return $this; }
    public function affected_rows(){ return $this->affected; }
    public function insert_id(){ return 1; }
    public function trans_start(){} public function trans_complete(){} public function trans_status(){ return true; }
    public function get($t = null){ $this->wheres = array(); return new SchedFakeResult(array()); }

    public function insert($t, $d = array()){
        if ($t === 'subscription_events') {
            // Column names are pinned to migration 006 on purpose: an insert
            // with the wrong key would only fail in production.
            foreach (array('subscription_id', 'type', 'payload', 'created_at') as $col) {
                if (!array_key_exists($col, $d)) {
                    throw new RuntimeException("subscription_events insert missing '{$col}'");
                }
            }
            $this->ci->events[] = array('type'=>$d['type'], 'payload'=>(string)$d['payload']);
        }
        return true;
    }

    public function update($t, $d){
        $w = $this->wheres; $this->wheres = array();

        // Model the compare-and-set claims: they only succeed once.
        if ($t === 'dripfeed_runs' && isset($w['status']) && $w['status'] === 'PENDING') {
            if (!$this->ci->claim_succeeds || $this->ci->run->status !== 'PENDING') {
                $this->affected = 0; return true;
            }
        }
        if ($t === 'subscriptions' && array_key_exists('next_execution_at', $w)) {
            if (!$this->ci->claim_succeeds || $w['next_execution_at'] !== $this->ci->sub->next_execution_at) {
                $this->affected = 0; return true;
            }
        }

        $target = null;
        if ($t === 'dripfeed_runs')   $target = $this->ci->run;
        if ($t === 'dripfeed_orders') $target = $this->ci->drip;
        if ($t === 'subscriptions')   $target = $this->ci->sub;
        if ($target) foreach ($d as $k => $v) $target->$k = $v;

        $this->affected = 1;
        return true;
    }
}

class SchedFakeResult {
    private $rows; public function __construct(array $r){ $this->rows = $r; }
    public function result(){ return $this->rows; }
    public function row(){ return $this->rows ? $this->rows[0] : null; }
}

class SchedFakeOrderService {
    private $ci; function __construct($ci){ $this->ci = $ci; }

    public function place($user, array $input, $context = array()){
        return $this->record($user, $input, false);
    }
    public function place_prepaid($user, array $input, array $context = array()){
        return $this->record($user, $input, true);
    }
    private function record($user, array $input, $prepaid){
        if ($this->ci->place_error) return $this->ci->place_error;
        $this->ci->placed[] = array('input'=>$input, 'prepaid'=>$prepaid);
        return array('ok'=>true, 'order'=>(object)array('id'=>77, 'public_id'=>'ORD-NEW'));
    }
}
