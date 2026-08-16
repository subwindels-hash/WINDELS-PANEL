<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$config['redis'] = array(
    'host' => getenv('REDIS_HOST') ?: 'redis',
    'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: NULL,
    'database' => (int)(getenv('REDIS_DB') ?: 0),
    'prefix' => getenv('REDIS_PREFIX') ?: 'windels:',
    'timeout' => 2,
);
