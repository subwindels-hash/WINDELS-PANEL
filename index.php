<?php
/**
 * WINDELS PANEL — Front Controller (CodeIgniter 3.x)
 * Loads .env via vlucas/phpdotenv if available, then boots CI.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    if (class_exists(\Dotenv\Dotenv::class) && file_exists(__DIR__ . '/.env')) {
        \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    }
}

/*
 *---------------------------------------------------------------
 * APPLICATION ENVIRONMENT
 *---------------------------------------------------------------
 */
define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : (getenv('APP_ENV') ?: 'development'));

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
