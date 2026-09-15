<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/_support/FakeDb.php';

/**
 * Regression tests for mail that sat in "Queued" long after the customer was
 * told it had been sent.
 *
 * The queue itself was never the problem — CronWorkers::email_queue() claims,
 * sends and retries correctly. The delay was pure scheduling:
 *
 *   - the email_queue job ran every 5 minutes (now every minute), and
 *   - on a host with no crontab it runs only when site traffic drives the
 *     in-app heartbeat, which is itself throttled to one pass per minute.
 *
 * So a password reset could be minutes old before anything tried to send it,
 * which reads to the customer as a reset link that never came. Auth mail now
 * attempts delivery on the request that enqueued it, via
 * MailService::flush_now(), while staying a normal queue row so that a
 * failure still retries with backoff instead of vanishing.
 *
 * What these tests pin:
 *   - an urgent message is delivered during enqueue, not left for the worker;
 *   - a non-urgent message is NOT sent inline (bulk mail keeps its batching);
 *   - a failed immediate attempt returns the row to the queue with an
 *     incremented attempt count and a future retry time — never SENT, never
 *     stranded in SENDING;
 *   - a row already claimed by the worker is not sent twice;
 *   - a throwing transport cannot strand the row or break the caller's flow.
 */
class MailImmediateDeliveryTest extends TestCase
{
    private static $root;
    private $ci;
    private $restore = array();

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH'))  define('APPPATH', self::$root.'/application/');

        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__mt_ci"]; }');
        }
        if (!function_exists('log_message')) {
            eval('function log_message($l,$m){ $GLOBALS["__mail_log_calls"][] = array($l,$m); }');
        }
        if (!function_exists('config_item')) eval('function config_item($i){ return null; }');
        if (!function_exists('is_php')) {
            eval('function is_php($v){ return version_compare(PHP_VERSION, $v, ">="); }');
        }
        if (!function_exists('site_url')) {
            eval('function site_url($u=""){ return "http://www.marvysocials.com/".ltrim($u, "/"); }');
        }
        if (!function_exists('base_url')) eval('function base_url($u=""){ return site_url($u); }');

        require_once self::$root.'/application/core/Env.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
        require_once self::$root.'/application/libraries/MailService.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__mail_log_calls'] = array();

        foreach (array('__fake_ci', '__mt_ci') as $k) {
            $this->restore[$k] = array_key_exists($k, $GLOBALS) ? $GLOBALS[$k] : null;
        }

        // The real schema for the two tables involved, so the fake enforces
        // the same columns and defaults production does.
        $db = new FakeDb(array(
            "CREATE TABLE IF NOT EXISTS email_queue (
               id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               to_email VARCHAR(255) NOT NULL,
               to_name VARCHAR(128) NULL,
               subject VARCHAR(255) NOT NULL,
               body_html MEDIUMTEXT NOT NULL,
               body_text MEDIUMTEXT NULL,
               template_key VARCHAR(128) NULL,
               status VARCHAR(16) NOT NULL DEFAULT 'QUEUED',
               attempts INT NOT NULL DEFAULT 0,
               last_error TEXT NULL,
               scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               sent_at DATETIME NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB;",
            "CREATE TABLE IF NOT EXISTS email_templates (
               id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               template_key VARCHAR(128) NOT NULL,
               subject VARCHAR(255) NOT NULL,
               body_html MEDIUMTEXT NOT NULL,
               body_text MEDIUMTEXT NULL,
               is_active TINYINT(1) NOT NULL DEFAULT 1
             ) ENGINE=InnoDB;",
        ));
        $db->insert('email_templates', array(
            'template_key' => 'auth.password_reset',
            'subject'      => 'Reset your password',
            'body_html'    => '<p>Hi {{username}}</p><p><a href="{{reset_url}}">Reset password</a></p>',
            'body_text'    => 'Hi {{username}} — reset: {{reset_url}}',
            'is_active'    => 1,
        ));

        $this->ci = new class($db) {
            public $db;
            public $load;
            public $lang;
            public $config;
            public $Setting_model;
            public function __construct($db) {
                $this->db = $db;
                $this->lang   = new class { public function load($f){} public function line($k){ return $k; } };
                $this->config = new class { public function item($k){ return null; } };
                $this->load   = new class { public function library($n){} public function model($n){} public function config($n){} };
                $this->Setting_model = new class {
                    public $values = array();
                    public function get($key, $default = null) {
                        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
                    }
                };
            }
        };
        $GLOBALS['__mt_ci'] = $GLOBALS['__fake_ci'] = $this->ci;
    }

    protected function tearDown(): void
    {
        foreach ($this->restore as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v;
        }
        $this->restore = array();
        unset($GLOBALS['__mail_log_calls']);
    }

    /** A MailService whose delivery outcome the test controls. */
    private function service($outcome)
    {
        return new class($outcome) extends MailService {
            public $outcome;
            public $delivered = 0;
            public function __construct($outcome) {
                parent::__construct();
                $this->outcome = $outcome;
            }
            public function deliver($mail) {
                $this->delivered++;
                if ($this->outcome instanceof Throwable) throw $this->outcome;
                return $this->outcome;
            }
        };
    }

    private function row($id = 1)
    {
        return $this->ci->db->where('id', $id)->get('email_queue')->row();
    }

    /* ------------------------------------------------------------------ */

    /** The whole point: urgent mail leaves on the request that queued it. */
    public function testAnUrgentMessageIsDeliveredImmediately()
    {
        $svc = $this->service(array('ok' => true, 'transport' => 'smtp'));

        $this->assertTrue((bool)$svc->enqueue_raw(
            'customer@example.test', 'Reset your password',
            '<p><a href="http://x.test/reset-password/TOK">Reset</a></p>',
            null, 'Customer', 'auth.password_reset', true));

        $this->assertSame(1, $svc->delivered, 'an urgent message must not wait for the cron worker');
        $row = $this->row();
        $this->assertSame('SENT', $row->status, 'the queue row must reflect the immediate send');
        $this->assertSame(1, (int)$row->attempts);
        $this->assertNotEmpty($row->sent_at);
    }

    /** Password resets are the message this was built for. */
    public function testAPasswordResetIsSentWithoutWaitingForTheWorker()
    {
        $svc = $this->service(array('ok' => true, 'transport' => 'smtp'));
        $user = (object)array('email' => 'customer@example.test', 'username' => 'customer');

        $this->assertTrue($svc->enqueue_password_reset($user, 'SIGNED.TOKEN.VALUE'));
        $this->assertSame(1, $svc->delivered,
            'a reset link is worthless if it arrives minutes after it was requested');
        $this->assertSame('SENT', $this->row()->status);
    }

    /** Bulk mail keeps its batching — only auth mail pays the inline cost. */
    public function testANonUrgentMessageIsLeftForTheWorker()
    {
        $svc = $this->service(array('ok' => true, 'transport' => 'smtp'));
        $svc->enqueue_raw('customer@example.test', 'Monthly news', '<p>hi</p>');

        $this->assertSame(0, $svc->delivered, 'ordinary mail must not block the request');
        $this->assertSame('QUEUED', $this->row()->status);
    }

    /** A failed immediate attempt must retry later, not be lost or marked sent. */
    public function testAFailedImmediateAttemptGoesBackOnTheQueueWithBackoff()
    {
        $svc = $this->service(array('ok' => false, 'error' => 'smtp down'));
        $svc->enqueue_raw('customer@example.test', 'Reset your password',
            '<p>x</p>', null, null, 'auth.password_reset', true);

        $row = $this->row();
        $this->assertSame('QUEUED', $row->status, 'a transient failure must remain retryable');
        $this->assertSame(1, (int)$row->attempts);
        $this->assertStringContainsString('smtp down', (string)$row->last_error);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $row->scheduled_at,
            'the retry must be scheduled into the future, as the worker would');
    }

    /** The worker and the request must never both send the same row. */
    public function testARowAlreadyClaimedIsNotSentTwice()
    {
        $svc = $this->service(array('ok' => true, 'transport' => 'smtp'));
        $svc->enqueue_raw('customer@example.test', 'Reset', '<p>x</p>');

        // The cron worker claims it first.
        $this->ci->db->where('id', 1)->update('email_queue', array('status' => 'SENDING'));

        $this->assertFalse($svc->flush_now(1), 'the CAS claim must lose against the worker');
        $this->assertSame(0, $svc->delivered, 'a lost claim must not send');
    }

    /** A throwing transport must not strand the row in SENDING. */
    public function testAThrowingTransportReleasesTheClaim()
    {
        $svc = $this->service(new RuntimeException('connection reset'));
        $svc->enqueue_raw('customer@example.test', 'Reset your password',
            '<p>x</p>', null, null, 'auth.password_reset', true);

        $row = $this->row();
        $this->assertSame('QUEUED', $row->status,
            'an exception must hand the row back, not leave it locked in SENDING');
        $this->assertStringContainsString('connection reset', (string)$row->last_error);
    }

    /** Enqueueing still succeeds when immediate delivery fails. */
    public function testEnqueueStillSucceedsWhenTheImmediateSendFails()
    {
        $svc = $this->service(new RuntimeException('connection reset'));
        $ok = $svc->enqueue_raw('customer@example.test', 'Reset your password',
            '<p>x</p>', null, null, 'auth.password_reset', true);

        $this->assertTrue((bool)$ok,
            'a slow mail server must not fail the password-reset request itself');
    }
}
