<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * Redis is optional. Nothing in the request path requires it — rate limiting
 * is table-backed and sessions/cache default to files — so these values only
 * matter on a stack that actually runs Redis (docker-compose, a VPS).
 */
require_once APPPATH.'core/Env.php';
Env::bootstrap(rtrim(realpath(APPPATH.'..'), DIRECTORY_SEPARATOR));

$config['redis'] = array(
    'enabled'  => Env::get_bool('REDIS_ENABLED', Env::has('REDIS_HOST')),
    'host'     => Env::get('REDIS_HOST', '127.0.0.1'),
    'port'     => Env::get_int('REDIS_PORT', 6379),
    'password' => Env::get('REDIS_PASSWORD'),
    'database' => Env::get_int('REDIS_DB', 0),
    'prefix'   => Env::get('REDIS_PREFIX', 'windels:'),
    'timeout'  => 2,
);
