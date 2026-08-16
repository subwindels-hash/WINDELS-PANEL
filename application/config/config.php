<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/* Base URL — set APP_URL in .env */
$config['base_url'] = getenv('APP_URL') ?: 'http://localhost:8080/';
$config['index_page'] = '';
$config['uri_protocol'] = 'REQUEST_URI';
$config['url_suffix'] = '';
$config['language'] = 'english';
$config['charset'] = 'UTF-8';
$config['enable_hooks'] = TRUE;
$config['subclass_prefix'] = 'MY_';
$config['composer_autoload'] = 'vendor/autoload.php';
$config['permitted_uri_chars'] = 'a-z 0-9~%.:_\-';
$config['allow_get_array'] = TRUE;
$config['enable_query_strings'] = FALSE;
$config['controller_trigger'] = 'c';
$config['function_trigger'] = 'm';
$config['directory_trigger'] = 'd';

$config['log_threshold'] = getenv('APP_DEBUG') ? 2 : 1;
$config['log_path'] = APPPATH . '../storage/logs/';
$config['log_file_extension'] = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format'] = 'Y-m-d H:i:s';
$config['error_views_path'] = '';
$config['cache_path'] = APPPATH . 'cache/';
$config['cache_query_string'] = FALSE;
$config['encryption_key'] = getenv('ENCRYPTION_KEY') ?: 'change-me-32-byte-key-replace-in-env';
$config['sess_driver'] = getenv('SESS_DRIVER') ?: 'files';
$config['sess_cookie_name'] = getenv('SESS_COOKIE_NAME') ?: 'windels_session';
$config['sess_expiration'] = 7200;
$config['sess_save_path'] = getenv('SESS_SAVE_PATH') ?: APPPATH . '../storage/cache/sessions/';
$config['sess_match_ip'] = FALSE;
$config['sess_time_to_update'] = 300;
$config['sess_regenerate_destroy'] = FALSE;

$config['cookie_prefix'] = '';
$config['cookie_domain'] = '';
$config['cookie_path'] = '/';
$config['cookie_secure'] = (getenv('APP_ENV') === 'production');
$config['cookie_httponly'] = TRUE;
$config['cookie_samesite'] = 'Lax';

$config['standardize_newlines'] = FALSE;
$config['global_xss_filtering'] = FALSE;

/* CSRF — enabled for cookie-mutating POSTs */
$config['csrf_protection'] = TRUE;
$config['csrf_token_name'] = 'csrf_windels';
$config['csrf_cookie_name'] = 'csrf_cookie_windels';
$config['csrf_expire'] = 7200;
$config['csrf_regenerate'] = TRUE;
$config['csrf_exclude_uris'] = array('webhook/.*', 'api/v1/.*', 'health.*');

$config['compress_output'] = FALSE;
$config['time_reference'] = 'UTC';
$config['rewrite_short_tags'] = FALSE;
$config['proxy_ips'] = '';
