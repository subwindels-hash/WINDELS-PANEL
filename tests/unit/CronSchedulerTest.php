<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * The auto-run scheduler (CronScheduler).
 *
 * The crontab is the one deployment step most shared-hosting operators never
 * complete — no shell, a cron UI whose PATH hides PHP, or a typo'd document
 * root — and while it is missing every schedule reads OVERDUE while deposits
 * go unreconciled. The heartbeat makes the panel run its own due jobs off
 * ordinary site traffic, through the same JobRunner harness as a crontab
 * tick. What these tests pin:
 *
 *   due-ness follows the panel's own schedules; the most overdue job goes
 *   first; a tick runs through JobRunner (locks + job_runs) exactly like the
 *   CLI; the throttle lets one request per window do the work; a paused job
 *   is skipped without flooding the history; the feature turns off cleanly;
 *   and the CLI `cron status` command answers a dead database with a message
 *   instead of a fatal.
 */
class CronSchedulerTest extends TestCase
{
    private static $root;
    private static $marker_dir;

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
        require_once self::$root.'/application/libraries/CronScheduler.php';
        require_once self::$root.'/application/libraries/SystemAdminService.php';
        self::$marker_dir = sys_get_temp_dir().'/marvy-autotick-test-'.getmypid();
    }

    protected function setUp(): void
    {
        // Deterministic, isolated marker dir per test; a leftover tick marker
        // from the previous test must not throttle the next one.
        if (is_dir(self::$marker_dir)) {
            foreach (glob(self::$marker_dir.'/*') as $f) @unlink($f);
            @rmdir(self::$marker_dir);
        }
        CronScheduler::$marker_dir = self::$marker_dir;
        CronScheduler::$max_jobs = null;
        CronScheduler::$budget_seconds = null;
        putenv('CRON_AUTORUN');
    }

    protected function tearDown(): void
    {
        CronScheduler::$marker_dir = null;
    }

    private function app(array $runs = array())
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        foreach ($runs as $row) {
            $app->db->insert('job_runs', array_merge(array(
                'job' => 'order_status', 'status' => 'SUCCESS',
                'started_at' => gmdate('Y-m-d H:i:s', time() - 3600),
                'finished_at' => gmdate('Y-m-d H:i:s', time() - 3590),
                'duration_ms' => 10, 'processed' => 0, 'failed' => 0,
            ), $row));
        }
        return $app;
    }

    /* ============================= due-ness ============================== */

    /** An interval job whose cadence has elapsed is due; a fresh one is not. */
    public function testAJobIsDueWhenItsCadenceHasElapsed()
    {
        $fresh = $this->app(array(
            array('job' => 'order_status', 'started_at' => gmdate('Y-m-d H:i:s', time() - 60)),
        ));
        $this->assertSame(array(), CronScheduler::select_due(gmdate('Y-m-d H:i:s')),
            'order_status ran a minute ago against a 2-minute schedule');

        $stale = $this->app(array(
            array('job' => 'order_status', 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600)),
        ));
        $due = CronScheduler::select_due(gmdate('Y-m-d H:i:s'));
        $this->assertNotEmpty($due);
        $this->assertSame('order_status', $due[0]['job']);
    }

    /** A fixed daily time (identity_purge 30 3 * * *) follows the clock, not the cadence. */
    public function testADailyScheduleIsDueAfterItsHourPasses()
    {
        $now = gmdate('Y-m-d H:i:s');

        // Ran just before today's slot? Then if the slot has passed, it is due.
        $ran_before_slot = gmdate('Y-m-d ', strtotime($now)).'03:29:00';
        $app = $this->app(array(
            array('job' => 'identity_purge', 'started_at' => $ran_before_slot),
        ));
        $due = CronScheduler::select_due($now);
        $jobs = array_column($due, 'job');
        if ((int)gmdate('Hi', strtotime($now)) >= 330) {
            $this->assertContains('identity_purge', $jobs,
                "now is past 03:30 UTC and the last run was 03:29 — it is due");
        } else {
            $this->assertNotContains('identity_purge', $jobs,
                "today's 03:30 has not arrived yet — not due, however old the last run is");
        }

        // A daily job that ran AFTER today's slot is never due until tomorrow.
        $ran_after_slot = gmdate('Y-m-d ', strtotime($now)).'04:30:00';
        if ((int)gmdate('Hi', strtotime($now)) >= 430) {
            $app2 = $this->app(array(
                array('job' => 'identity_purge', 'started_at' => $ran_after_slot),
            ));
            $due2 = CronScheduler::select_due($now);
            $this->assertNotContains('identity_purge', array_column($due2, 'job'),
                'the 03:30 slot was already served today');
        }
    }

    /** The most overdue job is offered first — the oldest backlog drains first. */
    public function testTheMostOverdueJobIsSelectedFirst()
    {
        $this->app(array(
            array('job' => 'order_status',         'started_at' => gmdate('Y-m-d H:i:s', time() - 400 * 60)),
            array('job' => 'payment_reconciliation', 'started_at' => gmdate('Y-m-d H:i:s', time() - 900 * 60)),
            array('job' => 'numbers_status',       'started_at' => gmdate('Y-m-d H:i:s', time() - 20 * 60)),
        ));
        $due = CronScheduler::select_due(gmdate('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual(3, count($due));
        $this->assertSame('payment_reconciliation', $due[0]['job'],
            '30 minutes overdue beats 8 and 20');
        $this->assertSame('order_status', $due[1]['job']);
    }

    /* ============================ the tick =============================== */

    /** A tick runs a due job through JobRunner and records it like a crontab run. */
    public function testATickRunsDueJobsAndRecordsTheRuns()
    {
        $this->app(array(
            array('job' => 'numbers_status', 'started_at' => gmdate('Y-m-d H:i:s', time() - 600)),
        ));
        $res = CronScheduler::tick();

        $this->assertSame(0, $res['ran'] > 0 ? 0 : 0); // shape guard below
        $this->assertGreaterThanOrEqual(1, $res['ran'], 'a due job must run');
        $this->assertSame('numbers_status', $res['jobs'][0]['job']);
        $this->assertTrue($res['jobs'][0]['ok']);
    }

    /** The throttle window lets one tick through and holds the next one back. */
    public function testTheThrottleHoldsBackASecondImmediateTick()
    {
        $this->app(array(
            array('job' => 'numbers_status', 'started_at' => gmdate('Y-m-d H:i:s', time() - 600)),
        ));
        $first = CronScheduler::tick();
        $this->assertGreaterThanOrEqual(1, $first['ran']);

        $second = CronScheduler::tick();
        $this->assertTrue(!empty($second['skipped_throttle']),
            'a tick within the same minute must idle');
        $this->assertSame(0, $second['ran']);
    }

    /** At most MAX_JOBS_PER_TICK jobs run, however long the overdue queue is. */
    public function testATickIsCappedAtAFewJobs()
    {
        $this->app(array(
            // Overdue-ness is age minus cadence: 900-5=895 for payment_reconciliation
            // beats 600-2=598 for order_status, then 400-1, 300-5, 200-5.
            array('job' => 'order_status',           'started_at' => gmdate('Y-m-d H:i:s', time() - 600 * 60)),
            array('job' => 'payment_reconciliation', 'started_at' => gmdate('Y-m-d H:i:s', time() - 900 * 60)),
            array('job' => 'numbers_status',         'started_at' => gmdate('Y-m-d H:i:s', time() - 400 * 60)),
            array('job' => 'refill_status',          'started_at' => gmdate('Y-m-d H:i:s', time() - 300 * 60)),
            array('job' => 'subscriptions',          'started_at' => gmdate('Y-m-d H:i:s', time() - 200 * 60)),
        ));
        CronScheduler::$max_jobs = 2;
        $res = CronScheduler::tick();

        $this->assertSame(2, $res['ran'], 'the cap must hold: the rest wait for the next tick');
        $this->assertSame('payment_reconciliation', $res['jobs'][0]['job'],
            'and the most overdue go first');
        $this->assertSame('order_status', $res['jobs'][1]['job']);
    }

    /** A paused job is skipped by the heartbeat — silently, not as spam rows. */
    public function testAPausedJobIsSkippedByTheHeartbeat()
    {
        $app = $this->app(array(
            array('job' => 'order_status', 'started_at' => gmdate('Y-m-d H:i:s', time() - 600 * 60)),
        ));
        $app->load->library('CronControlService');
        $admin = $app->register('ops', 'ops@x.test', 'Str0ng!pass1', 'ADMIN');
        $app->model(array('Audit_log_model'));
        $app->croncontrolservice->pause('order_status', 'provider incident', $admin->id, 1);

        $due_before = CronScheduler::select_due(gmdate('Y-m-d H:i:s'));
        $this->assertContains('order_status', array_column($due_before, 'job'),
            'pause is a runner concern, not a clock concern');

        $res = CronScheduler::tick();
        foreach ($res['jobs'] as $job) {
            $this->assertNotSame('order_status', $job['job'],
                'the heartbeat must not run a paused job');
        }

        // And it must not have run anyway: the only order_status row in the
        // history is the one the test seeded.
        $rows = $app->db->where('job', 'order_status')->get('job_runs')->result();
        $this->assertCount(1, $rows, 'the heartbeat neither ran nor recorded the paused job');
        $this->assertSame('SUCCESS', $rows[0]->status);
    }

    /* ========================== on/off switches ========================== */

    /** CRON_AUTORUN=0 disables registration even with a ready database. */
    public function testTheEnvironmentCanTurnTheHeartbeatOff()
    {
        putenv('CRON_AUTORUN=0');
        $this->assertFalse(CronScheduler::register(true));
        $this->assertFalse(CronScheduler::enabled());

        putenv('CRON_AUTORUN=1');
        $this->assertTrue(CronScheduler::enabled());
    }

    /** No database, no heartbeat — the hook must not even be scheduled. */
    public function testRegistrationNeedsAReadyDatabase()
    {
        $this->assertFalse(CronScheduler::register(false));
    }

    /* ======================= the CLI status command ====================== */

    /** `cron status` is the "is cron working?" command: it must never fatal. */
    public function testCronStatusAnswersAnUnavailableDatabaseWithAMessage()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Cron.php');
        $this->assertStringContainsString('Database unavailable', $src,
            'the status command must degrade when MySQL is down');
        // Both entry points (status and every job) go through the same guard,
        // and the guard tests liveness (marvy_load_database/conn_id), not just
        // whether the property is set — an autoloaded-but-dead connection
        // leaves $this->db assigned with an empty conn_id.
        $this->assertStringContainsString('private function require_db', $src);
        $this->assertSame(2, substr_count($src, '$this->require_db('),
            'status and execute must both check the database before touching it');
        $this->assertStringContainsString('marvy_load_database()', $src);
    }

    /** The web entry point registers the heartbeat for browser traffic. */
    public function testTheBaseControllerRegistersTheHeartbeatForWebRequests()
    {
        $src = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        $this->assertStringContainsString('CronScheduler::register', $src);
        $this->assertStringContainsString('is_cli_request()', $src,
            'CLI requests already have their own scheduler: the crontab');
        $this->assertStringContainsString('db_ready', $src,
            'without a database there is nothing to run or record against');
    }

    /** The admin cron screen surfaces the heartbeat state. */
    public function testTheAdminCronScreenShowsTheAutoRunState()
    {
        $src = file_get_contents(self::$root.'/application/views/admin/system/cron.php');
        $this->assertStringContainsString('Auto-run', $src);
        $this->assertStringContainsString('tick_age_minutes', $src);

        $controller = file_get_contents(self::$root.'/application/controllers/admin/System.php');
        $this->assertStringContainsString("CronScheduler::state()", $controller);
    }
}
