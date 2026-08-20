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
        error_reporting(-1);
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

if (($_temp = realpath($system_path)) !== FALSE) {
    $system_path = $_temp . DIRECTORY_SEPARATOR;
} else {
    $system_path = strtr(rtrim($system_path, '/\\'), '/\\', DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
}

if (!is_dir($system_path)) {
    header('HTTP/1.1 503 Service Unavailable.', TRUE, 503);
    echo 'Your system folder path does not appear to be set correctly.';
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
