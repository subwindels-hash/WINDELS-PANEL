<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for password-reset mail that was queued and then delivered
 * NOWHERE — not to the user's inbox, not to a log, not anywhere at all.
 *
 * Two independent defects produced that, and each is pinned below.
 *
 * 1. **The `log` transport wrote to a level CI3 discards.** MailService logged
 *    the payload with log_message('info'). CI3 grades ERROR=1, DEBUG=2,
 *    INFO=3 and writes a level only when its number is <= the configured
 *    threshold; application/config/config.php sets that threshold to 1 in
 *    production and 2 with APP_DEBUG on. INFO (3) is above BOTH, so the line
 *    was dropped every single time — while deliver() returned ok=true and the
 *    cron worker marked the queue row SENT. The message ceased to exist.
 *
 * 2. **The resolved transport was never applied to the email library.**
 *    transport() lets the admin-editable `mail_transport` setting win, but
 *    config/email.php had already fixed the library's protocol from
 *    VP_MAIL_DRIVER. An operator who set Transport=smtp in Admin → Settings
 *    still had every message handed to PHP mail() — which shared hosts
 *    frequently blackhole — while the panel reported "smtp".
 */
class MailDeliveryTransportTest extends TestCase
{
    private static $root;
    private $restoreGlobals = array();
    private $freshGlobals = array();
    private $logDir;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);

        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH'))  define('APPPATH', self::$root.'/application/');

        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__mt_ci"]; }');
        }
        // Capture what the panel logs so a test can assert on the level used.
        if (!function_exists('log_message')) {
            eval('function log_message($l,$m){ $GLOBALS["__mail_log_calls"][] = array($l,$m); }');
        }
        if (!function_exists('config_item')) {
            eval('function config_item($i){ return null; }');
        }
        if (!function_exists('is_php')) {
            eval('function is_php($v){ return version_compare(PHP_VERSION, $v, ">="); }');
        }
        if (!function_exists('site_url')) {
            eval('function site_url($u=""){ return "http://www.marvysocials.com/".ltrim($u, "/"); }');
        }
        if (!function_exists('base_url')) {
            eval('function base_url($u=""){ return site_url($u); }');
        }

        require_once self::$root.'/application/core/Env.php';
        require_once BASEPATH.'libraries/Email.php';
        require_once self::$root.'/application/libraries/MY_Email.php';
        require_once self::$root.'/application/libraries/MailService.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__mail_log_calls'] = array();

        // An isolated, writable log directory per test, pointed at through the
        // same env var Env::writable_paths() reads.
        $this->logDir = sys_get_temp_dir().'/marvy-mail-log-'.getmypid().'-'.mt_rand();
        @mkdir($this->logDir, 0775, true);
        putenv('VP_LOG_PATH='.$this->logDir);
        $_ENV['VP_LOG_PATH'] = $this->logDir;
        $_SERVER['VP_LOG_PATH'] = $this->logDir;

        $this->restoreGlobals = array();
        $this->freshGlobals = array();
        foreach (array('__fake_ci', '__mt_ci', '__probe_ci') as $key) {
            $this->restoreGlobals[$key] = array_key_exists($key, $GLOBALS) ? $GLOBALS[$key] : null;
            if (!array_key_exists($key, $GLOBALS)) $this->freshGlobals[] = $key;
        }

        $newCi = new class {
            public $email;
            public $Setting_model;
            public $load;
            public $lang;
            public $config;
            public function __construct() {
                $this->lang = new class {
                    public function load($f) {}
                    public function line($k) { return $k; }
                };
                $this->config = new class {
                    public function item($k) { return null; }
                };
                $this->load = new class {
                    public function library($n) {
                        if ($n === 'email') {
                            $ci =& get_instance();
                            // A fresh library instance configured the way
                            // config/email.php would build it from .env —
                            // which is the state the mismatch bug lived in.
                            $ci->email = new MY_Email();
                            $ci->email->initialize(isset($GLOBALS['__mt_email_config'])
                                ? $GLOBALS['__mt_email_config'] : array());
                        }
                    }
                    public function model($n) {}
                    public function config($n) {}
                };
                $this->Setting_model = new class {
                    public $values = array();
                    public function get($key, $default = null) {
                        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
                    }
                };
            }
        };
        foreach (array_keys($this->restoreGlobals) as $key) {
            $GLOBALS[$key] = $newCi;
        }
        $GLOBALS['__mt_ci'] = $newCi;
    }

    protected function tearDown(): void
    {
        foreach ($this->restoreGlobals as $key => $value) {
            if (in_array($key, $this->freshGlobals, true)) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $value;
            }
        }
        unset($GLOBALS['__mt_email_config'], $GLOBALS['__mail_log_calls']);
        $this->restoreGlobals = array();
        $this->freshGlobals = array();

        foreach (glob($this->logDir.'/*') as $f) @unlink($f);
        @rmdir($this->logDir);
        putenv('VP_LOG_PATH');
        unset($_ENV['VP_LOG_PATH'], $_SERVER['VP_LOG_PATH']);
    }

    /* ------------------------------------------------------------------ */

    private function message()
    {
        return (object)array(
            'to_email'  => 'customer@example.test',
            'to_name'   => 'Customer',
            'subject'   => 'Reset your MarvySocials password',
            'body_text' => 'Use the link below to set a new password.',
            'body_html' => '<p>Use the link below to set a new password.</p>'
                          .'<p><a href="http://www.marvysocials.com/reset-password/TOKEN123">Reset password</a></p>',
        );
    }

    private function mailLogContents()
    {
        $path = $this->logDir.'/mail.log';
        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    /* ------------------------------------------------------------------ */
    /* Defect 1 — the log transport was a black hole                       */
    /* ------------------------------------------------------------------ */

    public function testLogTransportActuallyPersistsTheResetLink()
    {
        $ci =& get_instance();
        $ci->Setting_model->values['mail_transport'] = 'log';

        $service = new MailService();
        $res = $service->deliver($this->message());

        $this->assertTrue($res['ok'], 'the log transport must report success only when it wrote');
        $this->assertSame('log', $res['transport']);

        // The whole point: the reset link must be READABLE somewhere. Before
        // the fix this went to log_message('info'), which CI3 discards at
        // every threshold this panel configures, so the link existed nowhere.
        $written = $this->mailLogContents();
        $this->assertStringContainsString('customer@example.test', $written,
            'the log transport must record the recipient somewhere readable');
        $this->assertStringContainsString('reset-password/TOKEN123', $written,
            'the reset link itself must be recoverable — that is the only copy that exists');
    }

    public function testTheMailLogNeverUsesTheInfoLevelCi3Discards()
    {
        $ci =& get_instance();
        $ci->Setting_model->values['mail_transport'] = 'log';

        $service = new MailService();
        $service->deliver($this->message());

        // CI3: ERROR=1, DEBUG=2, INFO=3; threshold is 1 (prod) or 2 (debug).
        // Anything logged at 'info' is dropped, so the payload must not rely
        // on that level to survive.
        foreach ($GLOBALS['__mail_log_calls'] as $call) {
            $this->assertNotSame('info', strtolower((string)$call[0]),
                'mail payloads must not be written at a level CI3 throws away');
        }
    }

    public function testLogTransportReportsFailureWhenItCannotWriteAnything()
    {
        // An unwritable log path means the message is genuinely gone. The
        // queue must NOT be told the delivery succeeded — marking the row SENT
        // would discard the last copy of the reset link.
        $blocked = $this->logDir.'/nested';
        @mkdir($blocked, 0775, true);
        @chmod($blocked, 0555);
        if (is_writable($blocked)) {
            // Running as root (or a filesystem that ignores the mode) — the
            // negative case cannot be staged honestly here.
            $this->markTestSkipped('cannot create a read-only directory in this environment');
        }

        putenv('VP_LOG_PATH='.$blocked.'/deeper');
        $_ENV['VP_LOG_PATH'] = $blocked.'/deeper';
        $_SERVER['VP_LOG_PATH'] = $blocked.'/deeper';

        $ci =& get_instance();
        $ci->Setting_model->values['mail_transport'] = 'log';

        $service = new MailService();
        $res = $service->deliver($this->message());

        @chmod($blocked, 0775);

        $this->assertFalse($res['ok'],
            'a log transport that wrote nothing must not report a successful delivery');
    }

    /* ------------------------------------------------------------------ */
    /* Defect 2 — the reported transport was not the transport used        */
    /* ------------------------------------------------------------------ */

    public function testTheSettingsTransportIsTheOneTheLibraryActuallyUses()
    {
        // .env said mail() (what config/email.php builds), the operator chose
        // smtp in Admin → Settings. The panel reported smtp and sent via
        // mail() — silently blackholed on many shared hosts.
        $GLOBALS['__mt_email_config'] = array(
            'protocol'  => 'mail',
            'mailtype'  => 'html',
            'newline'   => "\r\n",
            'crlf'      => "\r\n",
        );

        $ci =& get_instance();
        $ci->Setting_model->values['mail_transport'] = 'smtp';
        $ci->Setting_model->values['mail_from_email'] = 'noreply@marvy.test';

        $service = new MailService();
        $service->deliver($this->message());

        $this->assertSame('smtp', $ci->email->protocol,
            'the email library must send through the transport the panel resolved and reports');
    }

    /* ------------------------------------------------------------------ */
    /* Defect 3 — the plain-text part of the email had no link in it       */
    /* ------------------------------------------------------------------ */

    public function testFlattenedTextKeepsTheUrlNotJustTheAnchorText()
    {
        // The seeder built body_text with a bare strip_tags(), which keeps the
        // anchor TEXT and drops the href. The reset mail's text alternative
        // therefore read "Use the link below…  Reset password" — with no link
        // below, and no token anywhere.
        $html = '<p>Hi bob,</p>'
              .'<p>Use the link below to set a new password. It expires in 60 minutes.</p>'
              .'<p><a href="http://www.marvysocials.com/reset-password/TOKEN123">Reset password</a></p>';

        $text = MailService::readable_text($html);

        $this->assertStringContainsString('http://www.marvysocials.com/reset-password/TOKEN123', $text,
            'the plain-text alternative must contain the actual URL, not just the words "Reset password"');
        $this->assertStringContainsString('Reset password', $text,
            'the human-readable anchor text should survive alongside the URL');
        $this->assertStringNotContainsString('<a', $text, 'markup must not leak into the text part');
    }

    public function testFlattenedTextDoesNotPrintTheUrlTwice()
    {
        $text = MailService::readable_text(
            '<p><a href="https://example.test/x">https://example.test/x</a></p>');

        $this->assertSame('https://example.test/x', $text,
            'a link whose text already IS the URL must not be rendered twice');
    }

    public function testChoosingMailTransportOverridesAnSmtpEnvDefault()
    {
        // The mirror image: .env configured SMTP, the operator fell back to
        // cPanel's sendmail from the settings screen because SMTP was broken.
        $GLOBALS['__mt_email_config'] = array(
            'protocol'  => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'mailtype'  => 'html',
            'newline'   => "\r\n",
            'crlf'      => "\r\n",
        );

        $ci =& get_instance();
        $ci->Setting_model->values['mail_transport'] = 'mail';

        $service = new MailService();
        $service->deliver($this->message());

        $this->assertSame('mail', $ci->email->protocol,
            'switching the transport to mail must stop the panel using the .env SMTP server');
    }
}
