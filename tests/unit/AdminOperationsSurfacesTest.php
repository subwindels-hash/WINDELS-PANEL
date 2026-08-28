<?php
use PHPUnit\Framework\TestCase;

/**
 * Two operator surfaces that did not exist: the cron screen and the contact
 * map.
 *
 * **Cron.** The panel depends on background work for things customers notice —
 * order polling, refill settlement, deposit reconciliation, escrow release,
 * the mail queue. From a browser there was no way to tell whether any of it
 * was running: the only answer was SSH and `php index.php cron status`. An
 * operator who never installed the crontab had nothing to find out from except
 * the silence of a panel where nothing ever settles.
 *
 * **Contact map.** The contact page had a form and a mailbox and no way to say
 * where the business is, and nothing in the admin could add one.
 */
class AdminOperationsSurfacesTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        require_once self::$root.'/application/libraries/SystemAdminService.php';
    }

    /* ============================== cron screen ========================== */

    public function testTheCronScreenIsRoutedGatedAndListed()
    {
        $routes = file_get_contents(self::$root.'/application/config/routes.php');
        $this->assertStringContainsString("\$route['admin/cron'] = 'admin/system/cron';", $routes);

        $controller = file_get_contents(self::$root.'/application/controllers/admin/System.php');
        $this->assertStringContainsString('public function cron()', $controller);
        $this->assertMatchesRegularExpression('/public function cron\(\)\s*\{\s*\$this->require_perm\(/', $controller,
            'the cron screen exposes operational detail and must be permission gated');

        $nav = file_get_contents(self::$root.'/application/views/layouts/_app_context.php');
        $this->assertStringContainsString("'admin/cron'", $nav, 'a screen nothing links to does not exist');

        $this->assertFileExists(self::$root.'/application/views/admin/system/cron.php');
    }

    /** The screen is a report. It must not be able to change anything. */
    /**
     * The screen gained exactly two write actions in module 22 — pause and
     * resume — so the rule this test protects is restated rather than dropped:
     * the ONLY things it may post to are those two endpoints, and there is
     * still no way to trigger a job from a browser. Running a reconciliation
     * or refund sweep from a web request is how deposits get credited twice.
     */
    public function testTheCronScreenOnlyPausesAndResumes()
    {
        $view = file_get_contents(self::$root.'/application/views/admin/system/cron.php');

        // The view is PHP: actions are written as site_url('admin/cron/...').
        preg_match_all("~<form[^>]*action=\"<\?=site_url\('([^']+)'\)\?>\"~i", $view, $m);
        $targets = array_values(array_unique($m[1]));
        sort($targets);
        $this->assertSame(array('admin/cron/pause', 'admin/cron/resume'), $targets,
            'the cron screen must not post anywhere else');

        $this->assertStringNotContainsString('cron/run', $view, 'there is no run-now button');
        $this->assertStringNotContainsString('cron/delete', $view);

        // And both writes are POST-only and permission-gated in the controller.
        $ctrl = file_get_contents(self::$root.'/application/controllers/admin/System.php');
        $this->assertStringContainsString("require_perm('settings.manage')", $ctrl);
    }

    public function testScheduleExpressionsAreExplainedInWords()
    {
        $this->assertSame('every 2 minutes', SystemAdminService::describe_schedule('*/2 * * * *'));
        $this->assertSame('every minute', SystemAdminService::describe_schedule('* * * * *'));
        $this->assertSame('every 5 minutes', SystemAdminService::describe_schedule('*/5 * * * *'));
        $this->assertSame('hourly, on the hour', SystemAdminService::describe_schedule('0 * * * *'));
        $this->assertSame('daily at 03:30 UTC', SystemAdminService::describe_schedule('30 3 * * *'));
        $this->assertSame('not scheduled', SystemAdminService::describe_schedule(''));
        // Anything it cannot phrase is shown verbatim rather than guessed at.
        $this->assertSame('5 4 * * 1-5', SystemAdminService::describe_schedule('5 4 * * 1-5'));
    }

    public function testCadenceIsReadFromTheScheduleItself()
    {
        $this->assertSame(2, SystemAdminService::cadence_minutes('*/2 * * * *'));
        $this->assertSame(1, SystemAdminService::cadence_minutes('* * * * *'));
        $this->assertSame(60, SystemAdminService::cadence_minutes('0 * * * *'));
        $this->assertSame(1440, SystemAdminService::cadence_minutes('30 3 * * *'));
    }

    /**
     * The verdict per job. "Late" has to tolerate a busy host — a two-minute
     * job four minutes late is not a fault — while still catching a crontab
     * that was never installed.
     */
    public function testJobStateDistinguishesHealthyLateFailingAndNever()
    {
        $run = function ($minutes_ago, $status = 'SUCCESS') {
            return (object)array(
                'status' => $status,
                'started_at' => gmdate('Y-m-d H:i:s', time() - ($minutes_ago * 60)),
            );
        };

        $this->assertSame('never', SystemAdminService::job_state('*/2 * * * *', null, null));
        $this->assertSame('ok', SystemAdminService::job_state('*/2 * * * *', $run(4), 4),
            'four minutes on a two-minute job is a busy host, not an outage');
        $this->assertSame('ok', SystemAdminService::job_state('*/2 * * * *', $run(12), 12),
            'the floor is fifteen minutes of slack');
        $this->assertSame('late', SystemAdminService::job_state('*/2 * * * *', $run(90), 90));
        $this->assertSame('ok', SystemAdminService::job_state('30 3 * * *', $run(600), 600),
            'a daily job ten hours after its run is not late');
        $this->assertSame('failing', SystemAdminService::job_state('*/2 * * * *', $run(1, 'FAILED'), 1),
            'a failing job is failing however recently it ran');
    }

    /** The crontab is generated from the schedules the application reads. */
    public function testTheCrontabIsGeneratedFromTheRealSchedule()
    {
        $lines = SystemAdminService::crontab_lines(array(
            'order_status' => '*/2 * * * *',
            'email_queue'  => '*/5 * * * *',
        ));
        $text = implode("\n", $lines);
        $this->assertStringContainsString('php index.php cron order_status', $text);
        $this->assertStringContainsString('*/5 * * * *', $text);
        $this->assertStringContainsString('MYPANEL=', $text, 'the operator has to know what to replace');
    }

    /* ============================= contact map =========================== */

    public function testTheContactMapIsEntirelyOperatorControlled()
    {
        $settings = file_get_contents(self::$root.'/application/libraries/SettingsService.php');
        foreach (array('contact_map_enabled', 'contact_address', 'contact_map_query',
                       'contact_map_zoom', 'contact_phone', 'contact_hours') as $key) {
            $this->assertStringContainsString("'".$key."'", $settings, $key.' must be editable');
        }

        $view = file_get_contents(self::$root.'/application/views/public/contact.php');
        $this->assertStringContainsString('contact_details', $view);
        $this->assertStringContainsString('openstreetmap.org/export/embed.html', $view,
            'coordinates get a keyless OpenStreetMap embed');
        $this->assertStringContainsString('output=embed', $view,
            'a typed address gets the keyless Google embed, which geocodes free text');
    }

    /** No map configured must mean no iframe and no relaxed policy. */
    public function testTheMapIsOffUntilAnOperatorTurnsItOn()
    {
        $settings = file_get_contents(self::$root.'/application/libraries/SettingsService.php');
        $this->assertMatchesRegularExpression(
            "/'contact_map_enabled' => array\('bool', 'contact',[^)]*?, false\)/s", $settings,
            'the map ships off: most panels have no public address');

        $controller = file_get_contents(self::$root.'/application/core/MY_Controller.php');
        $this->assertStringContainsString('map_frame_src', $controller);
        $this->assertMatchesRegularExpression('/frame-src[^"]*openstreetmap/', $controller);
        // The allowance has to be conditional, or every install carries it.
        $this->assertMatchesRegularExpression('/contact_map_enabled[\s\S]{0,200}return null;/', $controller,
            'an install without a map keeps a policy that forbids every iframe');
    }

    public function testTheContactPageStillWorksWithNoMapConfigured()
    {
        $home = file_get_contents(self::$root.'/application/controllers/Home.php');
        $this->assertStringContainsString('private function contact_details()', $home);
        // Every read is defaulted: a settings table that is not there yet must
        // not take the contact page down.
        $this->assertMatchesRegularExpression('/catch \(Exception \$e\) \{\s*return \$default;/', $home);
    }
}
