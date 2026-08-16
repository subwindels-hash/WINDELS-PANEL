<?php
defined('BASEPATH') OR exit('No direct script access allowed');

$active_group = 'default';
$query_builder = TRUE;

$db['default'] = array(
    'dsn'      => '',
    'hostname' => getenv('DB_HOST') ?: 'mysql',
    'username' => getenv('DB_USER') ?: 'windels',
    'password' => getenv('DB_PASSWORD') ?: 'windels_secret',
    'database' => getenv('DB_NAME') ?: 'windels_panel',
    'dbdriver' => getenv('DB_DRIVER') ?: 'mysqli',
    'dbprefix' => '',
    'pconnect' => FALSE,
    'db_debug' => (ENVIRONMENT !== 'production'),
    'cache_on' => FALSE,
    'cachedir' => '',
    'char_set' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'dbcollat' => getenv('DB_COLLATION') ?: 'utf8mb4_unicode_ci',
    'swap_pre' => '',
    'encrypt'  => FALSE,
    'compress' => FALSE,
    'stricton' => TRUE,
    'failover' => array(),
    'save_queries' => TRUE
);
