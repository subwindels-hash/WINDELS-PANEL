<?php
use PHPUnit\Framework\TestCase;

/**
 * An in-process scripted SMTP endpoint for the greeting tests.
 *
 * php-wasm (the offline runner here) has no proc_open, so the scripted server
 * is a stream wrapper: `scriptedsmtp://<behavior>` accepts CI3's fsockopen()
 * and answers, command by command, exactly like tests/_support script server
 * would on a real host. `MailGreetingTest::transcript()` returns the wire log.
 */
class _MailGreetingScriptedSmtp
{
    private static $live = array();
    public $context;
    private $behavior;
    private $rx = '';
    private $tx = '';
    private $transcript = array();
    private $inData = false;
    private $authStage = 0;
    private $closed = false;

    public static function reset() { self::$live = array(); }
    public static function transcript() {
        $out = array();
        foreach (self::$live as $s) $out = array_merge($out, $s->transcript);
        return implode("\n", $out);
    }

    public function stream_open($path, $mode, $options, &$opened_path) {
        $rest = substr((string)$path, strlen('scriptedsmtp://'));
        $this->behavior = strtok($rest, ':') ?: 'ok';
        $this->tx = "220 script-smtp.test ESMTP test server\r\n";
        self::$live[] = $this;
        return true;
    }

    public function stream_read($count) {
        if ($this->tx === '') return '';
        $chunk = substr($this->tx, 0, $count);
        $this->tx = (string)substr($this->tx, strlen($chunk));
        return $chunk;
    }

    public function stream_write($data) {
        $this->rx .= $data;
        while (($p = strpos($this->rx, "\r\n")) !== false) {
            $line = substr($this->rx, 0, $p);
            $this->rx = substr($this->rx, $p + 2);
            $this->respond(trim($line));
        }
        return strlen($data);
    }

    private function say($line) {
        $this->transcript[] = 'S: '.$line;
        $this->tx .= $line."\r\n";
    }

    private function respond($line) {
        if ($this->inData) {
            if ($line === '.') { $this->inData = false; $this->say('250 OK: queued'); }
            return;
        }
        $this->transcript[] = 'C: '.$line;
        $cmd = strtoupper(strtok($line, ' ') ?: $line);

        if ($this->authStage > 0 && $cmd !== 'AUTH' && $cmd !== 'QUIT') {
            $this->authStage += 1;
            if ($this->authStage === 3) {
                $this->authStage = 0;
                $this->say('235 Authentication succeeded');
            } else {
                $this->say($this->authStage === 2 ? '334 UGFzc3dvcmQ6' : '334 VXNlcm5hbWU6');
            }
            return;
        }
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            if ($this->behavior === 'strict-helo' && $cmd === 'EHLO') {
                $this->say('500 5.5.1 Command unrecognized');
                return;
            }
            $this->say('250-script-smtp.test Hello '.substr($line, 5));
            $this->say('250 HELP');
            return;
        }
        if ($cmd === 'MAIL') {
            if ($this->behavior === 'greet-then-503') {
                $this->say('503 HELO or EHLO required');
                $this->closed = true;
                return;
            }
            $this->say('250 OK');
            return;
        }
        if ($cmd === 'RCPT') { $this->say('250 Accepted'); return; }
        if ($cmd === 'DATA') { $this->inData = true; $this->say('354 Enter message'); return; }
        if ($cmd === 'AUTH') { $this->authStage = 1; $this->say('334 VXNlcm5hbWU6'); return; }
        if ($cmd === 'STARTTLS') { $this->say('454 TLS not available'); return; }
        if ($cmd === 'RSET') { $this->say('250 Reset OK'); return; }
        if ($cmd === 'QUIT') { $this->say('221 bye'); $this->closed = true; return; }
        $this->say('502 Command not implemented');
    }

    public function stream_eof() { return $this->closed && $this->tx === ''; }
    public function stream_set_option($opt, $arg1, $arg2) { return true; }
    public function stream_stat() { return array(); }
    public function stream_close() {}
    public function stream_flush() { return true; }
    public function stream_tell() { return 0; }
    public function stream_seek($offset, $whence = SEEK_SET) { return true; }
    public function stream_cast($cast_as) { return false; }
}

/**
 * The SMTP greeting — pinning the production "503 HELO or EHLO required".
 *
 * The mail queue's failures against server315.web-hosting.com came from the
 * stock CI3 greeting behaviour. These tests drive the REAL Email chain
 * (system/libraries/Email.php + application/libraries/MY_Email.php + the
 * MailService that loads it) against the scripted SMTP endpoint above and
 * assert on the wire:
 *
 *   - the greeting name is the panel's domain, never a junk cron hostname
 *   - a refused EHLO falls back to HELO (RFC 5321 §4.1.4) when no AUTH is
 *     required, instead of failing the send
 *   - a genuine "503 HELO or EHLO required" on MAIL FROM fails fast and is
 *     reported with an operator hint, not a hang or a generic message
 */
class MailGreetingTest extends TestCase
{
    private static $root;
    private static $ci;
    /** Globals we replaced during setUp, restored in tearDown. */
    private $restoreGlobals = array();
    /** Global names that did not exist before setUp (unset in tearDown). */
    private $freshGlobals = array();

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!defined('APPPATH'))  define('APPPATH',  self::$root.'/application/');
        if (!class_exists('CI_Model')) {
            eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        }
        if (!function_exists('get_instance')) {
            eval('function &get_instance(){ return $GLOBALS["__mt_ci"]; }');
        }
        if (!function_exists('log_message')) {
            eval('function log_message($l,$m){}');
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

        require_once BASEPATH.'libraries/Email.php';
        require_once self::$root.'/application/libraries/MY_Email.php';
        require_once self::$root.'/application/libraries/MailService.php';
        require_once self::$root.'/application/helpers/marvy_helper.php';

        if (!in_array('scriptedsmtp', stream_get_wrappers(), true)) {
            stream_wrapper_register('scriptedsmtp', '_MailGreetingScriptedSmtp');
        }

        // The real greeting logic with only the socket open redirected at the
        // scripted wrapper (fsockopen cannot use custom stream wrappers).
        if (!class_exists('_MailGreetingTestEmail', false)) {
            eval('class _MailGreetingTestEmail extends MY_Email {
                protected function _open_smtp_socket($ssl) {
                    return fopen($this->smtp_host, "r+"); // scriptedsmtp://<behavior>
                }
            }');
        }
    }

    protected function setUp(): void
    {
        _MailGreetingScriptedSmtp::reset();
        // The shared offline runner loads every test class into one process,
        // and several classes register their own get_instance() shim pointing
        // at their own global. Point every CI-shaped global at ours for the
        // duration of this test and restore them afterwards, so this test is
        // immune to which shim happens to have been defined first.
        $this->restoreGlobals = array();
        foreach ($GLOBALS as $key => $value) {
            if (is_object($value) && isset($value->load)) {
                $this->restoreGlobals[$key] = $value;
            }
        }
        // The get_instance() shims other test classes defined (the first
        // definition wins process-wide) all read one of these globals.
        $this->freshGlobals = array();
        foreach (array('__fake_ci', '__mt_ci', '__probe_ci') as $key) {
            if (!array_key_exists($key, $this->restoreGlobals)) {
                $this->restoreGlobals[$key] = array_key_exists($key, $GLOBALS) ? $GLOBALS[$key] : null;
                if (!array_key_exists($key, $GLOBALS)) $this->freshGlobals[] = $key;
            }
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
                            $ci->email = new _MailGreetingTestEmail();
                            if (isset($GLOBALS['__mt_smtp'])) {
                                $ci->email->initialize($GLOBALS['__mt_smtp']);
                            }
                        }
                    }
                    public function model($n) {}
                    public function config($n) {}
                };
                $this->Setting_model = new class {
                    public $values = array(
                        'mail_transport'  => 'smtp',
                        'mail_from_email' => 'noreply@marvy.test',
                        'mail_from_name'  => 'MarvySocials',
                        'mail_helo'       => '',
                        'support_email'   => 'support@marvy.test',
                        'site_name'       => 'MarvySocials',
                    );
                    public function get($key, $default = null) {
                        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
                    }
                };
            }
        };
        // Point every CI-shaped global at the NEW instance BEFORE the old one
        // is released: the previous test's CI_Email objects are only freed
        // when `self::$ci` is reassigned below, and their __destruct() runs
        // _send_command('quit') → get_instance() → the CI must already exist.
        foreach (array_keys($this->restoreGlobals) as $key) {
            $GLOBALS[$key] = $newCi;
        }
        $GLOBALS['__mt_ci'] = $newCi; // get_instance() hands this out
        self::$ci = $newCi;
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
        unset($GLOBALS['__mt_smtp']);
        $this->restoreGlobals = array();
        $this->freshGlobals = array();
    }

    /* ------------------------------------------------------------------ */

    private function smtpConfig($behavior, $user = '', $pass = '')
    {
        return array(
            'protocol'    => 'smtp',
            'smtp_host'   => 'scriptedsmtp://'.$behavior,
            'smtp_port'   => 25,
            'smtp_crypto' => '',
            'smtp_user'   => $user,
            'smtp_pass'   => $pass,
            'smtp_timeout'=> 8,
            'mailtype'    => 'text',
            'newline'     => "\r\n",
            'crlf'        => "\r\n",
        );
    }

    private function email($behavior, $user = '', $pass = '', $helo = 'www.marvysocials.com')
    {
        $mail = new _MailGreetingTestEmail();
        $mail->initialize($this->smtpConfig($behavior, $user, $pass));
        $mail->helo_host = $helo;
        $mail->from('noreply@marvy.test', 'MarvySocials');
        $mail->to('customer@example.test');
        $mail->subject('greeting test '.gmdate('His'));
        $mail->message("Body line one.\nBody line two.");
        return $mail;
    }

    /** Run the real MailService::deliver() against the scripted server. */
    private function deliver($behavior)
    {
        $GLOBALS['__mt_smtp'] = $this->smtpConfig($behavior);
        $mail = (object)array(
            'to_email'  => 'customer@example.test',
            'to_name'   => 'Customer',
            'subject'   => 'delivery test '.gmdate('His'),
            'body_text' => "Body line one.\nBody line two.",
            'body_html' => null,
        );
        $service = new MailService();
        $res = $service->deliver($mail);
        unset($GLOBALS['__mt_smtp']);
        return $res;
    }

    /* ------------------------------------------------------------------ */

    public function testTheGreetingUsesThePinnedHostname()
    {
        $sent = $this->email('ok', '', '', 'panel.example.com')->send(false);
        $wire = _MailGreetingScriptedSmtp::transcript();

        $this->assertTrue($sent, 'plain SMTP send must succeed');
        $this->assertStringContainsString('C: EHLO panel.example.com', $wire);
        $this->assertStringContainsString('S: 250-script-smtp.test Hello panel.example.com', $wire);
    }

    public function testThePlainHeloPathStillWorks()
    {
        // A 7-bit charset does not require ESMTP extensions, so the client
        // may greet with plain HELO — and must still deliver.
        $mail = $this->email('ok');
        $mail->charset = 'us-ascii';
        $sent = $mail->send(false);
        $wire = _MailGreetingScriptedSmtp::transcript();

        $this->assertTrue($sent, 'send over the plain HELO path must succeed');
        $this->assertStringContainsString('C: HELO www.marvysocials.com', $wire);
        $this->assertStringNotContainsString('C: EHLO', $wire);
    }

    public function testARefusedEhloFallsBackToHelo()
    {
        // Credentials force the EHLO path, which the server refuses.
        $sent = $this->email('strict-helo', 'user@marvy.test', 'secret')->send(false);
        $wire = _MailGreetingScriptedSmtp::transcript();

        $this->assertTrue($sent, 'send must succeed via the RFC 5321 HELO fallback');
        $this->assertStringContainsString('C: EHLO www.marvysocials.com', $wire);
        $this->assertStringContainsString('S: 500 5.5.1 Command unrecognized', $wire);
        $this->assertStringContainsString('C: HELO www.marvysocials.com', $wire,
            'a refused EHLO must be retried as HELO, not reported as a dead connection');
    }

    public function testA503OnMailFromFailsFastWithAHeloHint()
    {
        $res = $this->deliver('greet-then-503');

        $this->assertFalse($res['ok'], 'a 503 on MAIL FROM must fail the send, not hang');
        $this->assertSame('smtp', $res['transport']);
        $this->assertStringContainsString('503 HELO or EHLO required', $res['error'],
            'the server reply must be the reason, not a generic library message');
        $this->assertStringContainsString('VP_MAIL_HELO', $res['hint'],
            'the hint must point the operator at the greeting configuration');
    }

    public function testTheGreetingDefaultsToThePanelDomain()
    {
        // No VP_MAIL_HELO, no admin setting — the greeting must still be the
        // base URL's host, never CI3's 'localhost.localdomain'.
        $host = parse_url(site_url(''), PHP_URL_HOST);
        $res = $this->deliver('ok');
        $wire = _MailGreetingScriptedSmtp::transcript();

        $this->assertTrue($res['ok'], 'delivery must succeed');
        $this->assertStringContainsString('C: EHLO '.$host, $wire);
        $this->assertStringNotContainsString('localhost.localdomain', $wire);
    }

    public function testNoCredentialsMeansNoAuthCommand()
    {
        // With no smtp_user/smtp_pass the session must never attempt AUTH,
        // even though EHLO is sent (the default UTF-8 charset is 8-bit).
        $sent = $this->email('ok')->send(false);
        $wire = _MailGreetingScriptedSmtp::transcript();

        $this->assertTrue($sent);
        $this->assertStringNotContainsString('C: AUTH', $wire);
        $this->assertStringContainsString('C: EHLO', $wire);
    }
}
