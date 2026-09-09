<?php
/**
 * Front-controller router for PHP's built-in server.
 * Mirrors .htaccess: real files are served as-is; everything else goes to index.php.
 */
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = dirname(__DIR__);
$path = $root . $uri;

if ($uri !== '/' && $uri !== '' && is_file($path)) {
    return false;
}

chdir($root);
require $root . '/index.php';
