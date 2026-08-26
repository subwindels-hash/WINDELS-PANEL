<?php
/*
 * Loaded two ways: by CI3's helper autoloader (BASEPATH defined) and by
 * composer's autoload "files" BEFORE index.php defines BASEPATH. A plain
 * `defined('BASEPATH') OR exit` here therefore killed every request the
 * moment a real composer vendor/ was present. Only block the case the guard
 * exists for: this file being executed directly as the main script.
 */
if (!defined('BASEPATH')
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit('No direct script access allowed');
}

if (!function_exists('windels_public_id')) {
    function windels_public_id(){
        if (class_exists(\Robbins\Ulid\Ulid::class)) return (string)\Robbins\Ulid\Ulid::generate();
        if (class_exists(\Ramsey\Uuid\Uuid::class)) return \Ramsey\Uuid\Uuid::uuid4()->toString();
        return bin2hex(random_bytes(13));
    }
}
if (!function_exists('windels_site_name')) {
    /**
     * Single source of truth for the public-facing brand name.
     *
     * Reads application/config/windels.php -> public_name so a deployment can
     * change the marketing site without touching dozens of views. Falls back to
     * the internal product name, then to the codebase default.
     */
    function windels_site_name(){
        static $name = null;
        if ($name !== null) return $name;
        $name = 'WINDELS PANEL';
        if (function_exists('get_instance')) {
            $ci = @get_instance();
            if ($ci && isset($ci->config)) {
                $cfg = $ci->config->item('windels');
                if (is_array($cfg) && !empty($cfg['public_name'])) {
                    $name = (string)$cfg['public_name'];
                } elseif (is_array($cfg) && !empty($cfg['name'])) {
                    $name = (string)$cfg['name'];
                }
            }
        }
        return $name;
    }
}

if (!function_exists('windels_site_tagline')) {
    /**
     * Public-facing tagline, same config-driven source as windels_site_name().
     */
    function windels_site_tagline(){
        static $tagline = null;
        if ($tagline !== null) return $tagline;
        $tagline = 'Prepaid commerce for social media, VTU, virtual numbers, identity, gift cards and digital goods';
        if (function_exists('get_instance')) {
            $ci = @get_instance();
            if ($ci && isset($ci->config)) {
                $cfg = $ci->config->item('windels');
                if (is_array($cfg) && !empty($cfg['public_tagline'])) {
                    $tagline = (string)$cfg['public_tagline'];
                } elseif (is_array($cfg) && !empty($cfg['tagline'])) {
                    $tagline = (string)$cfg['tagline'];
                }
            }
        }
        // Admin → Settings `site_tagline` overrides the config default.
        if (function_exists('windels_brand_setting')) {
            $setting = windels_brand_setting('site_tagline');
            if ($setting !== null && $setting !== '') $tagline = (string)$setting;
        }
        return $tagline;
    }
}

if (!function_exists('windels_brand_logo')) {
    /**
     * Public logo with the configured brand name baked in at render time.
     *
     * The SVG partial accepts variant/height; this helper keeps the header,
     * footer and auth shell from each hardcoding a different asset path.
     */
    function windels_brand_logo($variant = 'horizontal', $height = 32){
        // Canonical WINDELS PANEL mark set. Variant-first, then a safe fallback
        // to the primary horizontal logo so a missing asset can't white-screen.
        $map = array(
            'icon'       => 'logo-icon.svg',
            'dark'       => 'logo-dark.svg',
            'horizontal' => 'logo-horizontal.svg',
            'full'       => 'logo.svg',
        );
        $file = $map[$variant] ?? $map['horizontal'];
        $path = FCPATH.'assets/brand/'.$file;
        if (!is_file($path)) $file = $map['horizontal'];
        return base_url('assets/brand/'.$file);
    }
}

if (!function_exists('windels_brand_setting')) {
    /**
     * Read a branding setting (brand_logo_url, brand_favicon_url, …) saved
     * through Admin → Appearance. Returns NULL when unset or the database is
     * unavailable, so callers always have a bundled fallback.
     */
    function windels_brand_setting($key, $default = null) {
        static $cache = array();
        if (array_key_exists($key, $cache)) {
            return $cache[$key] !== null ? $cache[$key] : $default;
        }
        $cache[$key] = null;
        try {
            if (function_exists('get_instance')) {
                $ci =& get_instance();
                if ($ci && isset($ci->db) && is_object($ci->db) && !empty($ci->db->conn_id)) {
                    $ci->load->model('Setting_model');
                    $v = $ci->Setting_model->get($key);
                    if ($v !== null && $v !== '') $cache[$key] = $v;
                }
            }
        } catch (Throwable $e) { $cache[$key] = null; }
        return $cache[$key] !== null ? $cache[$key] : $default;
    }
}

if (!function_exists('windels_default_theme')) {
    /**
     * The site-wide default theme: 'system', 'light' or 'dark'.
     *
     * Reads Admin → Settings `default_theme` (seeded 'system') and falls back to
     * 'system', which follows the visitor's OS preference. Individual visitors
     * can override it in their browser (stored in localStorage) via the theme
     * toggle; the initial paint uses this value so there is no flash.
     */
    function windels_default_theme(){
        static $theme = null;
        if ($theme !== null) return $theme;
        $theme = 'system';
        if (function_exists('windels_brand_setting')) {
            $v = windels_brand_setting('default_theme');
            if ($v !== null && $v !== '') {
                $v = strtolower(trim((string)$v));
                if (in_array($v, array('system', 'light', 'dark'), true)) $theme = $v;
            }
        }
        return $theme;
    }
}

if (!function_exists('windels_base_currency')) {
    /**
     * The panel's base currency code.
     *
     * Every wallet, order and service transaction is denominated in this.
     * Reads application/config/windels.php so a deployment can redenominate in
     * one place; falls back to NGN when the config is unavailable (CLI helpers,
     * early bootstrap) rather than guessing a foreign currency.
     */
    function windels_base_currency(){
        static $code = NULL;
        if ($code !== NULL) return $code;
        $code = 'NGN';
        if (function_exists('get_instance')) {
            $ci = @get_instance();
            if ($ci && isset($ci->config)) {
                $cfg = $ci->config->item('windels');
                if (is_array($cfg) && !empty($cfg['base_currency'])) {
                    $code = strtoupper($cfg['base_currency']);
                }
            }
        }
        return $code;
    }
}
if (!function_exists('windels_money')) {
    function windels_money($amount, $currency=NULL){
        if ($currency === NULL) $currency = windels_base_currency();
        $formatted = number_format((float)$amount, 2, '.', ',');
        // Admin → Settings `currency_display` (symbol|code). `code` prints
        // "NGN 1,234.56" instead of "₦1,234.56". Fail open to the symbol.
        $display = 'symbol';
        if (function_exists('windels_brand_setting')) {
            $setting = windels_brand_setting('currency_display');
            if ($setting !== null && strtolower(trim((string)$setting)) === 'code') $display = 'code';
        }
        if ($display === 'code') return strtoupper($currency).' '.$formatted;
        $sym = array('NGN'=>'₦','USD'=>'$','EUR'=>'€','GBP'=>'£','INR'=>'₹','BRL'=>'R$')[strtoupper($currency)] ?? $currency.' ';
        return $sym . $formatted;
    }
}
if (!function_exists('windels_request_id')) {
    function windels_request_id(){ return bin2hex(random_bytes(8)); }
}

if (!function_exists('csp_nonce')) {
    /**
     * The current request's Content-Security-Policy nonce.
     *
     * Set by MY_Controller::send_security_headers(). Returns '' outside a web
     * request (CLI, tests) so views degrade rather than fatal.
     */
    function csp_nonce() {
        return isset($GLOBALS['__windels_csp_nonce']) ? $GLOBALS['__windels_csp_nonce'] : '';
    }
}

if (!function_exists('csp_nonce_attr')) {
    /**
     * Ready-to-print nonce attribute for an inline <script> tag:
     *   <script <?=csp_nonce_attr()?>> ... </script>
     *
     * Without this the script is silently dropped by the browser, since the
     * policy does not allow 'unsafe-inline'.
     */
    function csp_nonce_attr() {
        $nonce = csp_nonce();
        return $nonce === '' ? '' : 'nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"';
    }
}

if (!function_exists('env_bool')) {
    /**
     * Read a boolean from the environment.
     *
     * getenv() returns strings, and every non-empty string is truthy in PHP —
     * so `(bool)getenv('FLAG')` is TRUE for the literal "false", "0" and "off".
     * .env.example ships `HTTP_ALLOW_PRIVATE_HOSTS=false`, which under the old
     * cast silently switched SSRF protection off, and `APP_DEBUG=false`, which
     * turned on debug logging in production. Anything not recognisably true is
     * false here.
     */
    function env_bool($key, $default = false) {
        $raw = getenv($key);
        if ($raw === false || $raw === '') return (bool)$default;
        return in_array(strtolower(trim($raw)), array('1', 'true', 'yes', 'on'), TRUE);
    }
}

if (!function_exists('windels_load_database')) {
    /**
     * Connect to MySQL without killing the request.
     *
     * Returns TRUE when $CI->db is usable. A failed connect (wrong .env,
     * MySQL not imported yet, host down) returns FALSE and leaves no
     * mysqli warning on the output buffer — those warnings used to become
     * "headers already sent" on top of the database error page.
     */
    function windels_load_database() {
        if (!function_exists('get_instance')) {
            return false;
        }
        $CI =& get_instance();
        if (isset($CI->db) && is_object($CI->db) && !empty($CI->db->conn_id)) {
            return true;
        }

        if (!windels_db_reachable()) {
            return false;
        }

        $handler = set_error_handler(function () { return true; });
        try {
            $CI->load->database();
        } catch (Throwable $e) {
            if ($handler) { set_error_handler($handler); } else { restore_error_handler(); }
            return false;
        }
        if ($handler) { set_error_handler($handler); } else { restore_error_handler(); }

        if (!isset($CI->db) || !is_object($CI->db) || empty($CI->db->conn_id)) {
            return false;
        }
        if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
            $CI->db->db_debug = true;
        }
        return true;
    }

    /** Cheap TCP/auth probe so we never let CI's mysqli driver emit warnings. */
    function windels_db_reachable() {
        if (!function_exists('mysqli_init')) {
            return false;
        }
        $host = (string)(function_exists('env_str') ? env_str('DB_HOST', 'localhost') : 'localhost');
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $user = (string)(getenv('DB_USER') ?: '');
        $pass = (string)(getenv('DB_PASSWORD') ?: '');
        $name = (string)(getenv('DB_NAME') ?: '');
        if ($host === '' || $name === '') {
            return false;
        }
        $probe = @fsockopen($host === 'localhost' ? '127.0.0.1' : $host, $port, $errno, $errstr, 1);
        if (!$probe) {
            return false;
        }
        fclose($probe);

        $mysqli = mysqli_init();
        if (!$mysqli) {
            return false;
        }
        @$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
        $handler = set_error_handler(function () { return true; });
        try {
            $ok = @$mysqli->real_connect($host, $user, $pass, $name, $port);
        } catch (Throwable $e) {
            $ok = false;
        }
        if ($handler) { set_error_handler($handler); } else { restore_error_handler(); }
        if ($ok) {
            $mysqli->close();
            return true;
        }
        return false;
    }
}

if (!function_exists('env_str')) {
    /** Trimmed string from the environment, or $default when unset/blank. */
    function env_str($key, $default = null) {
        $raw = getenv($key);
        if ($raw === false) return $default;
        $raw = trim($raw);
        return $raw === '' ? $default : $raw;
    }
}

if (!function_exists('windels_migration_config')) {
    /**
     * The contents of application/config/migration.php as an array.
     *
     * `migration.php` is not in the autoloaded config set, and CI3 loads it
     * *into the Migration library*, not into the global config registry — so
     * `$this->config->item('migration_version')` reads NULL from a controller
     * unless the file has been explicitly loaded into its own index first.
     * Anything that needs the expected schema version (the migrate CLI, the
     * readiness probe, deploy preflight) has to go through here or it silently
     * compares against 0.
     *
     * @return array
     */
    function windels_migration_config() {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = array();
        if (function_exists('get_instance')) {
            $ci =& get_instance();
            if ($ci && isset($ci->config) && is_object($ci->config)) {
                try {
                    $ci->config->load('migration', TRUE, TRUE);
                    $loaded = $ci->config->item('migration');
                    if (is_array($loaded)) $cache = $loaded;
                } catch (Throwable $e) { /* fall through to the file read */ }
            }
        }

        if (!$cache && defined('APPPATH') && is_file(APPPATH.'config/migration.php')) {
            $config = array();
            include APPPATH.'config/migration.php';
            if (is_array($config)) $cache = $config;
        }
        return $cache;
    }
}

if (!function_exists('windels_migration_item')) {
    /** One key from the migration config, with a fallback. */
    function windels_migration_item($key, $default = null) {
        $cfg = windels_migration_config();
        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }
}
