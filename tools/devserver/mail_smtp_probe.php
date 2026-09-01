<?php
/**
 * mail_smtp_probe.php — dev-only helper for the SMTP delivery e2e.
 *
 * DEV TOOLING ONLY. Never shipped (tools/ is excluded from the deployment
 * package) and never loaded by the application.
 *
 * Drives ONE MailService::deliver() through the real Email library chain
 * (MY_Email) against a scripted SMTP server (tools/devserver/fake_smtp.mjs),
 * printing the raw conversation and the result. This is how the "503 HELO or
 * EHLO required" failure is pinned: the server records exactly which command
 * arrived before the greeting.
 *
 * Usage:
 *   node tools/devserver/php_run.mjs tools/devserver/mail_smtp_probe.php
 *     smtp <host> <port> [tls|none] [user] [pass] [helo]
 */

$root = dirname(dirname(__DIR__));
define('BASEPATH', $root.'/system/');
define('APPPATH', $root.'/application/');
define('FCPATH', $root.'/');
define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');

require_once APPPATH.'core/Env.php';
Env::bootstrap($root);
require_once APPPATH.'helpers/marvy_helper.php';

[$_, $cmd, $host, $port, $crypto, $user, $pass, $helo] = array_pad($argv, 8, null);

if ($cmd !== 'smtp' || !$host || !$port) {
    fwrite(STDERR, "usage: mail_smtp_probe.php smtp <host> <port> [tls|none] [user] [pass] [helo]\n");
    exit(2);
}

// Function shims a bare (no-CI) process needs.
if (!function_exists('get_instance')) {
    function &get_instance() { return $GLOBALS['probe_ci']; }
}
if (!function_exists('log_message')) {
    function log_message($level, $msg) { fwrite(STDERR, "[{$level}] {$msg}\n"); }
}
if (!function_exists('site_url')) {
    function site_url($uri = '') { return 'http://www.marvysocials.com/'.ltrim($uri, '/'); }
}
if (!function_exists('base_url')) {
    function base_url($uri = '') { return site_url($uri); }
}
if (!function_exists('config_item')) {
    function config_item($item) { return null; }
}
if (!function_exists('is_php')) {
    function is_php($v) { return version_compare(PHP_VERSION, $v, '>='); }
}

// The real chain, exactly as MailService loads it.
require_once BASEPATH.'libraries/Email.php';
require_once APPPATH.'libraries/MY_Email.php';
require_once APPPATH.'libraries/MailService.php';

$GLOBALS['probe_host']   = $host;
$GLOBALS['probe_port']   = (int)$port;
$GLOBALS['probe_crypto'] = $crypto ?: 'none';
$GLOBALS['probe_user']   = (string)$user;
$GLOBALS['probe_pass']   = (string)$pass;
$GLOBALS['probe_helo']   = (string)$helo;

$ci = new class {
    public $email = null;
    public $Setting_model = null;
    public $load;
    public $lang;
    public $config;
    public function __construct() {
        $this->lang = new class {
            public function load($file) {}
            public function line($key) { return $key; }
        };
        $this->config = new class {
            public function item($key) { return null; }
        };
        $this->load = new class {
            public function library($name) {
                if ($name === 'email') {
                    $ci =& get_instance();
                    $ci->email = new MY_Email();
                    $ci->email->initialize(array(
                        'protocol'    => 'smtp',
                        'smtp_host'   => $GLOBALS['probe_host'],
                        'smtp_port'   => $GLOBALS['probe_port'],
                        'smtp_crypto' => $GLOBALS['probe_crypto'] === 'none' ? '' : $GLOBALS['probe_crypto'],
                        'smtp_user'   => $GLOBALS['probe_user'],
                        'smtp_pass'   => $GLOBALS['probe_pass'],
                        'smtp_timeout'=> 10,
                        'mailtype'    => 'text',
                        'newline'     => "\r\n",
                        'crlf'        => "\r\n",
                    ));
                }
            }
            public function model($name) {}
            public function config($name) {}
        };
        $this->Setting_model = new class {
            public function get($key, $default = null) {
                $map = array(
                    'mail_transport'  => 'smtp',
                    'mail_from_email' => 'noreply@marvy.test',
                    'mail_from_name'  => 'MarvySocials',
                    'mail_helo'       => '',
                    'support_email'   => 'support@marvy.test',
                    'site_name'       => 'MarvySocials',
                );
                return array_key_exists($key, $map) ? $map[$key] : $default;
            }
        };
    }
};
$GLOBALS['probe_ci'] = $ci;

$mail = (object)array(
    'to_email'   => 'customer@example.test',
    'to_name'    => 'Customer',
    'subject'    => 'Sending test '.gmdate('His'),
    'body_text'  => "Body line one.\nBody line two.",
    'body_html'  => null,
);

$service = new MailService();
$result  = $service->deliver($mail);

echo json_encode(array(
    'ok'        => !empty($result['ok']),
    'transport' => $result['transport'] ?? 'smtp',
    'error'     => $result['error'] ?? null,
    'hint'      => $result['hint'] ?? null,
    'greeted'   => ($ci->email instanceof MY_Email) ? $ci->email->greeted() : null,
    'debug'     => (string)$ci->email->print_debugger(),
), JSON_PRETTY_PRINT);
echo "\n";
exit(empty($result['ok']) ? 1 : 0);
