<?php
/**
 * WINDELS PANEL — Front Controller (CodeIgniter 3.x)
 *
 * Boot order matters and is deliberate:
 *
 *   1. `.env` is read by application/core/Env.php — a dependency-free parser,
 *      so a cPanel deployment that never ran `composer install` still gets its
 *      database credentials, base URL and secrets. It also creates the runtime
 *      directories, which is what removes the old `php index.php deploy
 *      storage` step from a fresh install.
 *   2. composer's autoloader, *if* a vendor/ directory happens to be present.
 *      Nothing in the request path requires it: phpdotenv is replaced by the
 *      loader above, and predis/ramsey/aws are optional feature dependencies
 *      guarded by class_exists() at their call sites.
 *   3. CodeIgniter.
 */
require_once __DIR__ . '/application/core/Env.php';
Env::bootstrap(__DIR__);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

/*
 *---------------------------------------------------------------
 * APPLICATION ENVIRONMENT
 *---------------------------------------------------------------
 */
// An empty Cgi/FastCGI CI_ENV (nginx forwards `$CI_ENV` even when unset) must
// not win over the environment — an empty ENVIRONMENT is a 503 wall in production.
//
// The fallback is `production`, not `development`: an uploaded panel whose
// .env has not been filled in yet must not answer the internet with full error
// output and query dumps. Local work sets CI_ENV=development explicitly, which
// .env.example does for you.
define('ENVIRONMENT',
    !empty($_SERVER['CI_ENV'])  ? $_SERVER['CI_ENV'] :
   (!empty($_SERVER['APP_ENV']) ? $_SERVER['APP_ENV'] :
    (getenv('CI_ENV') ?: (getenv('APP_ENV') ?: 'production'))));

// Configuration problems (an unset encryption key, an unreadable .env) are
// thrown while the config files are being parsed — before CodeIgniter has an
// error handler of its own, which used to make them a blank white page. Turn
// them into a page that names the file to fix; CI3 replaces this handler with
// its own as soon as it boots.
set_exception_handler(function ($e) {
    Env::render_boot_error($e, ENVIRONMENT);
});

/*
 *---------------------------------------------------------------
 * ERROR REPORTING
 *---------------------------------------------------------------
 */
switch (ENVIRONMENT) {
    case 'development':
        // CI 3.1.13 is not annotated for PHP 8.2+ dynamic properties. Keep
        // real errors visible, but do not dump E_DEPRECATED onto every page
        // (that sends output before headers and breaks sessions/cookies).
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_STRICT);
        ini_set('display_errors', 1);
        break;
    case 'testing':
    case 'production':
        ini_set('display_errors', 0);
        error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT & ~E_USER_NOTICE & ~E_USER_DEPRECATED);
        break;
    default:
        header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
        echo 'The application environment is not set correctly.';
        exit(1);
}

/*
 *---------------------------------------------------------------
 * SYSTEM DIRECTORY
 *---------------------------------------------------------------
 */
$system_path = 'system';
$application_folder = 'application';
$view_folder = '';

if (defined('STDIN')) {
    chdir(dirname(__FILE__));
}

// Composer ships CodeIgniter at vendor/codeigniter/framework/system.
// tools/link_system.php normally symlinks that to ./system after install,
// but cPanel uploads, missing symlink support, or a clone that never ran
// composer would otherwise 503 here. Prefer ./system, then the vendor path.
$system_candidates = array(
    $system_path,
    'vendor/codeigniter/framework/system',
    __DIR__ . '/system',
    __DIR__ . '/vendor/codeigniter/framework/system',
);
foreach ($system_candidates as $candidate) {
    if (is_dir($candidate) && is_file(rtrim($candidate, '/\\') . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'CodeIgniter.php')) {
        $system_path = $candidate;
        break;
    }
}

if (($_temp = realpath($system_path)) !== FALSE) {
    $system_path = $_temp . DIRECTORY_SEPARATOR;
} else {
    $system_path = strtr(rtrim($system_path, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

if (!is_dir($system_path) || !is_file($system_path . 'core' . DIRECTORY_SEPARATOR . 'CodeIgniter.php')) {
    header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
    // No framework found in any of the candidate locations. Spell out every
    // path that was probed, because the person seeing this is usually staring
    // at a cPanel File Manager with no Terminal: the fix is re-uploading the
    // system/ directory from application-deployment.zip, not running commands.
    $probed = array();
    foreach ($system_candidates as $candidate) {
        $probed[] = rtrim((string) $candidate, '/\\') . '/core/CodeIgniter.php';
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>WINDELS PANEL — framework missing</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;margin:2em auto;max-width:44em;padding:0 1em;color:#222}'
        . 'code{background:#f3f3f3;padding:1px 5px;border-radius:4px}li{margin:.25em 0}</style></head><body>'
        . '<h1>CodeIgniter framework files are missing</h1>'
        . '<p>The application looked for <code>core/CodeIgniter.php</code> in each of these places:</p>'
        . '<ul><li><code>' . implode('</code></li><li><code>', array_map('htmlspecialchars', $probed)) . '</code></li></ul>'
        . '<p><strong>If you deployed with application-deployment.zip:</strong> the upload was incomplete — '
        . 're-upload the <code>system/</code> directory (and <code>vendor/</code> if present) from the zip '
        . 'extract. No <code>composer install</code> or <code>symlink</code> is required; the package ships '
        . '<code>system/</code> as real files.</p>'
        . '<p><strong>If you deployed from a git clone:</strong> run <code>composer install</code> '
        . '(it creates <code>vendor/codeigniter/framework/system</code>), then optionally '
        . '<code>php tools/link_system.php</code> — which creates <code>system/</code> as a symlink, or as a '
        . 'real directory copy on hosts where symlinks are unavailable.</p>'
        . '<p>Open <code>/deploy-verify.php</code> in the browser afterwards for a full environment check.</p>'
        . '</body></html>';
    exit(3);
}

if (is_dir($application_folder)) {
    if (($_temp = realpath($application_folder)) !== FALSE) {
        $application_folder = $_temp;
    } else {
        $application_folder = strtr(rtrim($application_folder, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
    }
} elseif (is_dir(BASEPATH . $application_folder . DIRECTORY_SEPARATOR)) {
    $application_folder = BASEPATH . strtr(trim($application_folder, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
} else {
    header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
    echo 'Your application folder path does not appear to be set correctly.';
    exit(3);
}

if (!isset($view_folder[0]) && is_dir($application_folder . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR)) {
    $view_folder = $application_folder . DIRECTORY_SEPARATOR . 'views';
} elseif (is_dir($view_folder)) {
    if (($_temp = realpath($view_folder)) !== FALSE) {
        $view_folder = $_temp;
    } else {
        $view_folder = strtr(rtrim($view_folder, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
    }
} elseif (is_dir(APPPATH . $view_folder . DIRECTORY_SEPARATOR)) {
    $view_folder = APPPATH . strtr(trim($view_folder, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
} else {
    header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
    echo 'Your view folder path does not appear to be set correctly.';
    exit(3);
}

define('SELF', pathinfo(__FILE__, PATHINFO_BASENAME));
define('BASEPATH', $system_path);
define('APPPATH', $application_folder . DIRECTORY_SEPARATOR);
define('VIEWPATH', $view_folder . DIRECTORY_SEPARATOR);

require_once BASEPATH . 'core/CodeIgniter.php';
