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

/**
 * True when the suite executes on a PHP-WASM (emscripten) build — the offline
 * audit harness runs tests that way when no native PHP binary exists.
 *
 * Some kernel primitives behave differently there: notably flock(), whose
 * emscripten emulation aliases lock state between two handles that share an
 * open file description inside one process, so LOCK_EX|LOCK_NB wrongly
 * succeeds for a second in-process JobRunner. Tests that exist specifically
 * to pin such a primitive scope themselves to native runtimes with
 * markTestSkipped() — loudly, never silently passing — while every
 * platform-independent assertion in the same class keeps running here.
 * Production and CI cron always execute on native PHP, where the pinned
 * behaviour is the behaviour the OS provides.
 */
if (!function_exists('windels_runtime_is_wasm')) {
    function windels_runtime_is_wasm()
    {
        return php_sapi_name() === 'wasm'
            || stripos((string)php_uname('s'), 'Emscripten') === 0
            || stripos((string)php_uname('m'), 'wasm') === 0;
    }
}
