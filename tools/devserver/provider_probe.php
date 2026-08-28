<?php
/**
 * provider_probe.php — dev-only helper for the SMM provider end-to-end check.
 *
 * DEV TOOLING ONLY. Never shipped (tools/ is excluded from the deployment
 * package) and never loaded by the application.
 *
 * Two jobs, both of which a test script cannot do from Node:
 *
 *   encrypt <plaintext>   print the ciphertext the panel stores in
 *                         providers.api_key_encrypted, using the real
 *                         EncryptionService and the real .env key — so a test
 *                         can seed a provider row the app can actually use.
 *
 *   call <url> <key> <action> [param=value ...]
 *                         run one call through the real StandardSmmAdapter
 *                         against a live URL and print the {ok,data,error}
 *                         envelope as JSON.
 *
 * Usage:
 *   node tools/devserver/php_run.mjs tools/devserver/provider_probe.php encrypt secret
 */

$root = dirname(dirname(__DIR__));
define('BASEPATH', $root.'/system/');
define('APPPATH', $root.'/application/');
define('FCPATH', $root.'/');
define('ENVIRONMENT', getenv('CI_ENV') ?: 'development');

require_once APPPATH.'core/Env.php';
Env::bootstrap($root);

require_once APPPATH.'helpers/marvy_helper.php';
require_once APPPATH.'libraries/EncryptionService.php';

/* A CI instance small enough for the adapter, big enough to be honest: the
 * adapter really does decrypt through EncryptionService here. */
class ProbeCI {
    public $load, $encryptionservice, $config;
    public function __construct() {
        $this->load = new ProbeLoader();
        $this->encryptionservice = new EncryptionService();
        $this->config = new ProbeConfig();
    }
}
class ProbeLoader { public function library($n = null) {} public function model($n = null) {} }
class ProbeConfig {
    public function item($key) {
        // The fake panel in the check runs on localhost.
        return $key === 'http_allow_private_hosts' ? true : null;
    }
}
function &get_instance() { static $ci; if (!$ci) $ci = new ProbeCI(); return $ci; }
if (!function_exists('log_message')) { function log_message($level, $message) {} }

$argv = $_SERVER['argv'];
$command = $argv[1] ?? '';

if ($command === 'encrypt') {
    $ci =& get_instance();
    echo $ci->encryptionservice->encrypt((string)($argv[2] ?? '')), "\n";
    exit(0);
}

if ($command === 'call') {
    require_once APPPATH.'libraries/ProviderAdapterInterface.php';
    require_once APPPATH.'libraries/StandardSmmAdapter.php';

    $ci =& get_instance();
    $url    = (string)($argv[2] ?? '');
    $key    = (string)($argv[3] ?? '');
    $action = (string)($argv[4] ?? 'balance');

    $params = array();
    foreach (array_slice($argv, 5) as $pair) {
        $bits = explode('=', $pair, 2);
        if (count($bits) === 2) $params[$bits[0]] = $bits[1];
    }

    $provider = (object)array(
        'id' => 0, 'api_url' => $url,
        'api_key_encrypted' => $ci->encryptionservice->encrypt($key),
        'timeout_ms' => 8000, 'currency' => 'USD',
    );
    $adapter = new StandardSmmAdapter($provider);

    switch ($action) {
        case 'services': $res = $adapter->getServices(); break;
        case 'balance':  $res = $adapter->getBalance(); break;
        case 'add':      $res = $adapter->createOrder($params); break;
        case 'status':   $res = $adapter->getMultipleOrderStatus(explode(',', $params['orders'] ?? '')); break;
        case 'refill':   $res = $adapter->requestRefill($params['order'] ?? ''); break;
        case 'cancel':   $res = $adapter->requestCancel($params['order'] ?? ''); break;
        default:         $res = array('ok' => false, 'error' => 'unknown action '.$action);
    }
    echo json_encode($res, JSON_UNESCAPED_SLASHES), "\n";
    exit(empty($res['ok']) ? 1 : 0);
}

fwrite(STDERR, "Usage: provider_probe.php encrypt <plaintext>\n"
              ."       provider_probe.php call <url> <key> <action> [param=value ...]\n");
exit(2);
