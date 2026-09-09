<?php
/**
 * Fallback vendor/autoload.php — mirrors the "autoload" section of the
 * project's composer.json for deployments where `composer install` was never
 * run (upload-only cPanel installs ship this file inside the package).
 *
 * Provided rules (identical to composer.json):
 *   - psr-4:    Marvy\  -> application/libraries/
 *   - files:    application/helpers/marvy_helper.php
 *   - classmap: application/libraries/Seeder.php
 *
 * The remaining composer packages (predis, ramsey/uuid, ulid, aws-sdk-php,
 * guzzle, phpdotenv) are optional feature dependencies, each guarded by
 * class_exists() at its call site — see the header comment in index.php.
 *
 * Running the real `composer install` at any time overwrites this file with
 * the full generated autoloader; nothing else needs to change.
 *
 * This file is generated from tools/templates/vendor-autoload.fallback.php
 * (build step: tools/build_deployment_package.sh).
 */

$root = dirname(__DIR__);

/* psr-4: Marvy\ -> application/libraries/ */
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'Marvy\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = $root . '/application/libraries/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/* classmap: Seeder */
spl_autoload_register(function ($class) use ($root) {
    static $classmap = array('Seeder' => '/application/libraries/Seeder.php');
    if (isset($classmap[$class]) && is_file($root . $classmap[$class])) {
        require $root . $classmap[$class];
    }
});

/* files: helpers */
if (is_file($root . '/application/helpers/marvy_helper.php')) {
    require_once $root . '/application/helpers/marvy_helper.php';
}
