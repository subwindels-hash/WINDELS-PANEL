<?php
/**
 * Test bootstrap.
 *
 * index.php defines ENVIRONMENT for web and CLI requests; the test suite never
 * boots index.php, so without this the constant is missing. Code that has to
 * fail closed — EncryptionService::resolve_key() refuses to run production
 * with a placeholder key — then treats the whole suite as production and
 * throws. Declare the environment the tests actually run in.
 */
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', getenv('CI_ENV') ?: 'testing');
}

// CI3 defines these in index.php. Config files use APPPATH to pull in the
// helper that supplies env_bool(), so the suite needs them too.
if (!defined('APPPATH'))  define('APPPATH', dirname(__DIR__).'/application/');
if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__).'/system/');

// Deterministic timestamps: the app stores UTC everywhere.
date_default_timezone_set('UTC');

if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
}
