<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Config is parsed before helpers are autoloaded, so pull in env_bool()/env_str()
// directly. Both are no-ops if the helper is already loaded.
require_once APPPATH.'helpers/marvy_helper.php';

// Every server-specific value below comes from .env through Env, which also
// understands the portable VP_* spellings used by the cPanel deployment guide.
// Requiring it here (rather than trusting index.php) keeps config parsing
// working for tests and CLI tools that boot CodeIgniter directly.
require_once APPPATH.'core/Env.php';
Env::bootstrap(rtrim(realpath(APPPATH.'..'), DIRECTORY_SEPARATOR));
$marvy_paths = Env::writable_paths();

/* Base URL — VP_BASE_URL (or APP_URL). Auto-detected from the request when
   unset, so a freshly uploaded panel still links to itself correctly.
   NOTE: leaving this as '/' would force every asset URL to the domain root,
   which breaks subdirectory installs and path-prefixed preview proxies —
   those requests then hit the front controller and come back as HTML, so the
   browser silently drops the stylesheets. CI3 only auto-detects when the
   value is the empty string. */
$marvy_base_url = trim((string) Env::get('APP_URL', ''));
$config['base_url'] = $marvy_base_url === '' ? '' : rtrim($marvy_base_url, '/').'/';
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

$config['log_threshold'] = env_bool('APP_DEBUG') ? 2 : 1;
$config['log_path'] = $marvy_paths['logs'].'/';
$config['log_file_extension'] = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format'] = 'Y-m-d H:i:s';
$config['error_views_path'] = '';
$config['cache_path'] = $marvy_paths['ci_cache'].'/';
$config['cache_query_string'] = FALSE;
// No placeholder fallback: EncryptionService::resolve_key() refuses to boot
// production with an unset or example key, and supplies a clearly-labelled
// development key otherwise.
require_once APPPATH.'libraries/EncryptionService.php';
$config['encryption_key'] = EncryptionService::resolve_key();
// Sessions default to files on disk: shared hosting has no Redis, and a
// session driver that cannot connect is a login screen that never logs anyone
// in. Set VP_SESSION_DRIVER=redis (or database) where the infrastructure is
// actually there.
$config['sess_driver'] = Env::get('SESS_DRIVER', 'files');
$config['sess_cookie_name'] = Env::get('SESS_COOKIE_NAME', 'marvy_session');
$config['sess_expiration'] = Env::get_int('SESS_EXPIRATION', 7200);
$config['sess_save_path'] = $config['sess_driver'] === 'files'
    ? Env::get('SESS_SAVE_PATH', $marvy_paths['sessions'].'/')
    : Env::get('SESS_SAVE_PATH', 'ci_sessions');
$config['sess_match_ip'] = FALSE;
$config['sess_time_to_update'] = 300;
$config['sess_regenerate_destroy'] = FALSE;

$config['cookie_prefix'] = '';
$config['cookie_domain'] = '';
$config['cookie_path'] = '/';
// Secure cookies: respect VP_COOKIE_SECURE when explicitly configured.
// In production or when the base URL is HTTPS, cookies are Secure whenever
// the connection is HTTPS. On plain HTTP, cookies must NOT have the Secure
// flag set, otherwise browsers reject them and CodeIgniter refuses to set
// the CSRF cookie — locking users out with a 419 token expired error.
$config['cookie_secure'] = Env::has('COOKIE_SECURE')
    ? Env::get_bool('COOKIE_SECURE')
    : (Env::request_is_https() && ((env_str('APP_ENV') === 'production')
        || (stripos((string) Env::get('APP_URL', ''), 'https://') === 0)));
$config['cookie_httponly'] = TRUE;
$config['cookie_samesite'] = 'Lax';

$config['standardize_newlines'] = FALSE;
$config['global_xss_filtering'] = FALSE;

/* CSRF — enabled for cookie-mutating POSTs */
$config['csrf_protection'] = TRUE;
$config['csrf_token_name'] = 'csrf_marvy';
$config['csrf_cookie_name'] = 'csrf_cookie_marvy';
$config['csrf_expire'] = Env::get_int('CSRF_EXPIRE', 7200);
// Rotating the token on every POST breaks any page that posts twice without
// re-rendering — an AJAX reply box, a support/chat widget, a second tab, the
// Back button. The first post succeeds and retires the token; the second is
// rejected, and the caller reports its own generic "something went wrong".
// A stable per-session token is still a token the attacker's origin cannot
// read, so this defaults off and stays available for deployments that want
// per-request rotation and control every client that posts.
$config['csrf_regenerate'] = Env::get_bool('CSRF_REGENERATE', FALSE);
// CI3 anchors these as #^<pattern>$#i. Keep them tight: 'health.*' would also
// exempt any future route merely starting with "health". Webhooks and the API
// are exempt because they authenticate by HMAC signature and API key
// respectively, not by session cookie, so CSRF does not apply.
// Server-to-server endpoints have no browser session and therefore no CSRF
// token to present; they authenticate by signature or API key instead.
// api/payments/webhooks/.+ is listed explicitly: a provider callback that gets
// a 419 looks to them like an outage and to us like a payment that never
// arrived. The signed-payload check in MY_Security/the gateway adapters is what
// protects these paths.
$config['csrf_exclude_uris'] = array(
    'webhook/.+',
    'api/payments/webhooks/.+',
    'api/v1/.+',
    'health(/.+)?',
    // Referral-code lookup runs before the visitor has an account, and often
    // before any page of ours has rendered a token (a landing page, an app).
    // It is safe to exempt because it is strictly read-only — it changes
    // nothing and returns only "does this code exist" plus the referrer's
    // display name — and it is IP rate-limited to stop it being used to
    // enumerate codes.
    'api/referrals/validate',
);

$config['compress_output'] = FALSE;
$config['time_reference'] = 'UTC';
$config['rewrite_short_tags'] = FALSE;
$config['proxy_ips'] = '';
