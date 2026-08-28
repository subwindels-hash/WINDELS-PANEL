<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';
require_once dirname(__DIR__).'/_support/IntegrationHarness.php';

/**
 * Pausing a background job (module 22).
 *
 * Module 16 gave the panel a cron screen that could report and not act. When a
 * provider starts refusing every call, or a gateway answers nonsense and the
 * reconciliation sweep is about to write off live deposits, the only way to
 * stop a job was to SSH in and edit the crontab — which most cPanel operators
 * cannot do at 2am, and which then has to be remembered and undone.
 *
 * The dangerous half of this feature is not pausing. It is **forgetting**: a
 * crontab line commented out during an incident stays commented out for weeks,
 * and nothing ever mentions it again while earnings never mature and deposits
 * are never reconciled. So the rules these tests pin are:
 *
 *   a pause always expires; a reason is always required; the consequence is
 *   named before the operator commits; the runner records the skipped tick;
 *   and reading the screen is not the same permission as stopping the sweep.
 */
class CronControlTest extends TestCase
{
    private static $root;

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
        require_once self::$root.'/application/libraries/CronControlService.php';
    }

    private function app()
    {
        $app = new IntegrationHarness();
        $app->seed_minimal();
        $admin = $app->register('opsadmin', 'ops@x.test', 'Str0ng!pass1', 'ADMIN');
        $app->library(array('CronControlService'));
        $app->model(array('Audit_log_model'));
        return array($app, $admin);
    }

    private function control($app, $job)
    {
        return $app->db->where('job', $job)->get('cron_job_controls')->row();
    }

    private function source($relative)
    {
        return file_get_contents(self::$root.'/'.$relative);
    }

    /* ========================= pausing and resuming ====================== */

    public function testPausingAJobStopsItAndRecordsWhoAndWhy()
    {
        list($app, $admin) = $this->app();

        $res = $app->croncontrolservice->pause('order_status', 'Provider outage, ticket 1234', $admin->id, 2);

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertTrue($app->croncontrolservice->is_paused('order_status'));

        $row = $this->control($app, 'order_status');
        $this->assertSame(1, (int)$row->is_paused);
        $this->assertSame('Provider outage, ticket 1234', $row->reason);
        $this->assertSame((int)$admin->id, (int)$row->paused_by_id);
        $this->assertNotEmpty($row->resume_at, 'a pause with no expiry is a pause that gets forgotten');
    }

    public function testOtherJobsAreUnaffected()
    {
        list($app, $admin) = $this->app();
        $app->croncontrolservice->pause('order_status', 'one job only', $admin->id, 2);

        $this->assertFalse($app->croncontrolservice->is_paused('email_queue'));
        $this->assertFalse($app->croncontrolservice->is_paused('analytics'));
    }

    public function testResumingPutsTheJobBackAndRemembersWho()
    {
        list($app, $admin) = $this->app();
        $app->croncontrolservice->pause('email_queue', 'mail host down', $admin->id, 4);

        $res = $app->croncontrolservice->resume('email_queue', $admin->id);

        $this->assertTrue($res['ok']);
        $this->assertFalse($app->croncontrolservice->is_paused('email_queue'));
        $row = $this->control($app, 'email_queue');
        $this->assertSame((int)$admin->id, (int)$row->resumed_by_id);
        $this->assertNull($row->resume_at, 'a resumed job has no pending expiry');
    }

    public function testResumingSomethingThatIsNotPausedSaysSo()
    {
        list($app, $admin) = $this->app();
        $res = $app->croncontrolservice->resume('analytics', $admin->id);

        $this->assertFalse($res['ok']);
        $this->assertSame('NOT_PAUSED', $res['code']);
    }

    /* ===================== the rules that make it safe =================== */

    /**
     * The headline safety property. An operator who pauses deposit
     * reconciliation during an incident and then goes to bed must not wake up
     * to a week of uncredited deposits.
     */
    public function testAPauseExpiresByItself()
    {
        list($app, $admin) = $this->app();
        $app->croncontrolservice->pause('payment_reconciliation', 'gateway returning nonsense', $admin->id, 1);
        $this->assertTrue($app->croncontrolservice->is_paused('payment_reconciliation'));

        // Wind the expiry into the past, exactly as the clock would.
        $app->db->where('job', 'payment_reconciliation')->update('cron_job_controls',
            array('resume_at' => gmdate('Y-m-d H:i:s', time() - 60)));

        $this->assertFalse($app->croncontrolservice->is_paused('payment_reconciliation'),
            'an expired pause must lift itself');
        $this->assertSame(0, (int)$this->control($app, 'payment_reconciliation')->is_paused,
            'and the lift must be written down, not recomputed every time');
    }

    /** The expiry is capped, however long the form asks for. */
    public function testAPauseCannotBeLongerThanTheCap()
    {
        list($app, $admin) = $this->app();
        $res = $app->croncontrolservice->pause('analytics', 'holiday shutdown', $admin->id, 24 * 90);

        $this->assertTrue($res['ok']);
        $this->assertSame(CronControlService::MAX_HOURS, $res['hours']);
        $this->assertLessThanOrEqual(
            time() + CronControlService::MAX_HOURS * 3600 + 5,
            strtotime($res['resume_at'].' UTC'));
    }

    /** Nor shorter than useful — a zero-hour pause is a mis-typed form. */
    public function testAPauseIsAtLeastAnHour()
    {
        list($app, $admin) = $this->app();
        $res = $app->croncontrolservice->pause('analytics', 'typo test', $admin->id, 0);
        $this->assertSame(CronControlService::MIN_HOURS, $res['hours']);
    }

    public function testAReasonIsRequired()
    {
        list($app, $admin) = $this->app();

        foreach (array('', '   ', 'x', 'oops') as $reason) {
            $res = $app->croncontrolservice->pause('analytics', $reason, $admin->id, 2);
            $this->assertFalse($res['ok'], 'refused: "'.$reason.'"');
            $this->assertSame('NO_REASON', $res['code']);
        }
        $this->assertFalse($app->croncontrolservice->is_paused('analytics'),
            'a refused pause must not half-apply');
    }

    /** A typo in a job name must not create a control row for nothing. */
    public function testAnUnknownJobIsRefused()
    {
        list($app, $admin) = $this->app();
        $res = $app->croncontrolservice->pause('order_statuss', 'typo in the job name', $admin->id, 2);

        $this->assertFalse($res['ok']);
        $this->assertSame('UNKNOWN_JOB', $res['code']);
        $this->assertNull($this->control($app, 'order_statuss'));
    }

    /**
     * Money-moving jobs are flagged, not blocked. An operator who needs to
     * stop a bad refund sweep must be able to — but never without reading
     * what stops happening.
     */
    public function testMoneyMovingJobsCarryTheirConsequence()
    {
        foreach (array('payment_reconciliation', 'earnings_release', 'service_recovery',
                       'marketplace_release', 'order_status') as $job) {
            $this->assertTrue(CronControlService::moves_money($job), $job);
            $this->assertNotEmpty(CronControlService::consequence($job), $job);
        }
        $this->assertFalse(CronControlService::moves_money('analytics'),
            'a reporting job has no customer-visible consequence');
        $this->assertNull(CronControlService::consequence('analytics'));

        list($app, $admin) = $this->app();
        $this->assertTrue($app->croncontrolservice->pause(
            'payment_reconciliation', 'gateway is double-answering', $admin->id, 1)['ok'],
            'it must still be pausable — that is the whole point of the switch');
    }

    /* ============================== the trail ============================ */

    public function testEveryTransitionIsAudited()
    {
        list($app, $admin) = $this->app();
        $app->croncontrolservice->pause('email_queue', 'mail host down', $admin->id, 1);
        $app->croncontrolservice->resume('email_queue', $admin->id);

        $actions = array();
        foreach ($app->db->get('audit_logs')->result() as $row) $actions[] = $row->action;
        $this->assertContains('cron.paused', $actions);
        $this->assertContains('cron.resumed', $actions);
    }

    /**
     * An automatic resume is recorded with no actor: nobody did it, the expiry
     * did. Naming the person who paused it would read as though they came back
     * and turned it on again.
     */
    public function testAnAutomaticResumeIsRecordedAsNobodysDoing()
    {
        list($app, $admin) = $this->app();
        $app->croncontrolservice->pause('analytics', 'reporting freeze', $admin->id, 1);
        $app->db->where('job', 'analytics')->update('cron_job_controls',
            array('resume_at' => gmdate('Y-m-d H:i:s', time() - 60)));

        $app->croncontrolservice->is_paused('analytics');

        $auto = null;
        foreach ($app->db->get('audit_logs')->result() as $row) {
            if ($row->action === 'cron.auto_resumed') $auto = $row;
        }
        $this->assertNotNull($auto, 'an expiry that lifts a pause has to be visible');
        $this->assertNull($auto->actor_id,
            'the expiry lifted it, not the person who paused it');
    }

    /* ============================ the wiring ============================= */

    /**
     * The pause is honoured at the last moment before the work runs, and the
     * tick is still recorded. A crontab line commented out looks identical to
     * a broken cron; a SKIPPED row does not.
     */
    public function testTheRunnerHonoursThePauseAndRecordsTheSkippedTick()
    {
        $cron = $this->source('application/controllers/Cron.php');
        $this->assertStringContainsString('croncontrolservice->is_paused($job)', $cron);
        $this->assertStringContainsString('record_skip($job', $cron);

        $runner = $this->source('application/libraries/JobRunner.php');
        $this->assertStringContainsString("'status'      => 'SKIPPED'", $runner);
    }

    /**
     * Reading the screen is `audit.view` — an everyday support task. Stopping
     * the sweep that reconciles deposits is `settings.manage`.
     */
    public function testStoppingAJobNeedsAStrongerPermissionThanReadingTheScreen()
    {
        $src = $this->source('application/controllers/admin/System.php');

        $this->assertMatchesRegularExpression(
            '~function cron_pause\(\)\s*\{\s*\$this->require_perm\(\'settings\.manage\'\)~s', $src);
        $this->assertMatchesRegularExpression(
            '~function cron_resume\(\)\s*\{\s*\$this->require_perm\(\'settings\.manage\'\)~s', $src);
        $this->assertMatchesRegularExpression(
            '~function cron\(\)\s*\{\s*\$this->require_perm\(\'audit\.view\'\)~s', $src);
    }

    public function testBothActionsArePostOnlyAndRouted()
    {
        $src = $this->source('application/controllers/admin/System.php');
        // Both new actions, and every other writing action on this
        // controller, refuse anything but POST: a state change reachable by
        // GET is one link away from being triggered by accident.
        foreach (array('cron_pause', 'cron_resume') as $method) {
            $this->assertMatchesRegularExpression(
                '~function '.$method.'\(\).*?method\(true\) !== \'POST\'.*?show_404~s', $src, $method);
        }

        $routes = $this->source('application/config/routes.php');
        $this->assertStringContainsString("\$route['admin/cron/pause'] = 'admin/system/cron_pause';", $routes);
        $this->assertStringContainsString("\$route['admin/cron/resume'] = 'admin/system/cron_resume';", $routes);
    }

    /**
     * "Run now" exists now (the operator asked for it), so the rule this test
     * protects is restated rather than dropped: the screen's only write
     * endpoints are pause, resume and run — and run goes through the same
     * JobRunner harness the crontab uses, never a bespoke second
     * implementation, because a weaker copy is how deposits get credited
     * twice.
     */
    public function testRunNowIsSafeOrItIsNotThere()
    {
        $view = $this->source('application/views/admin/system/cron.php');
        $this->assertStringContainsString('admin/cron/run', $view,
            'the run-now button must post to the run endpoint');

        $ctrl = $this->source('application/controllers/admin/System.php');
        $this->assertMatchesRegularExpression(
            '~function cron_run\(\\)\\s*\\{\\s*if \\(\\$this->input->method\\(true\\) !== \'POST\'~s',
            $ctrl, 'run now must be POST-only');
        $this->assertMatchesRegularExpression(
            '~function cron_run\\(\\).*?require_perm\(\'settings\.manage\'\)~s',
            $ctrl, 'run now needs the same permission as pausing');
        // The manual run must reuse the harness: exclusive lock + run record.
        $this->assertMatchesRegularExpression(
            '~function cron_run\(\\).*?jobrunner->run\(~s', $ctrl,
            'run now must execute through JobRunner');
        $this->assertMatchesRegularExpression(
            '~function cron_run\(\\).*?cronregistry->worker\(~s', $ctrl,
            'run now must resolve the job from CronRegistry, like the CLI');
        // A paused job must not be runnable by hand: that would work around an
        // incident switch someone deliberately threw.
        $this->assertMatchesRegularExpression(
            '~function cron_run\(\\).*?croncontrolservice->is_paused\(~s', $ctrl,
            'run now must refuse a paused job');
        // And the screen must still say why a manual run is safe.
        $this->assertStringContainsString('exclusive lock', $view);
    }

    /** The operator is told the pause expires, before and after they commit. */
    public function testTheScreenPromisesTheExpiry()
    {
        $view = $this->source('application/views/admin/system/cron.php');
        $this->assertStringContainsString('Resumes automatically', $view);
        $this->assertStringContainsString('A pause always expires', $view);

        $ctrl = $this->source('application/controllers/admin/System.php');
        $this->assertStringContainsString('It resumes automatically at', $ctrl);
    }

    public function testTheMigrationShipsTheTable()
    {
        $src = $this->source('application/migrations/031_cron_job_controls.php');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cron_job_controls', $src);
        $this->assertStringContainsString('resume_at', $src);
        $this->assertStringContainsString("reason VARCHAR(255) NOT NULL", $src);

        $config = $this->source('application/config/migration.php');
        preg_match("/migration_version'\]\s*=\s*(\d+)/", $config, $m);
        $this->assertGreaterThanOrEqual(31, (int)($m[1] ?? 0));
    }
}
