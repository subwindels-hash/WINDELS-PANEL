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

if (!function_exists('csrf')) {
    /**
     * Hidden CSRF input for hand-written <form> blocks.
     *
     * Many admin views emit `<?=csrf()?>` inside a <form method="post"> that
     * is not built with form_open(); without this helper every such POST is
     * rejected by the CSRF filter and the form "does nothing". Returns the
     * markup, or an empty string when the security helper is unavailable.
     */
    function csrf() {
        $ci =& get_instance();
        if (!isset($ci->security)) {
            $ci->load->library('security');
        }
        $name = $ci->security->get_csrf_token_name();
        $hash = $ci->security->get_csrf_hash();
        return '<input type="hidden" name="'.htmlspecialchars($name, ENT_QUOTES).'"'
            .' value="'.htmlspecialchars($hash, ENT_QUOTES).'">';
    }
}

if (!function_exists('marvy_public_id')) {    function marvy_public_id(){
        if (class_exists(\Robbins\Ulid\Ulid::class)) return (string)\Robbins\Ulid\Ulid::generate();
        if (class_exists(\Ramsey\Uuid\Uuid::class)) return \Ramsey\Uuid\Uuid::uuid4()->toString();
        return bin2hex(random_bytes(13));
    }
}
if (!function_exists('marvy_site_name')) {
    /**
     * Single source of truth for the public-facing brand name.
     *
     * Reads application/config/marvy.php -> public_name so a deployment can
     * change the marketing site without touching dozens of views. Falls back to
     * the internal product name, then to the codebase default.
     */
    function marvy_site_name(){
        static $name = null;
        if ($name !== null) return $name;
        $name = 'MarvySocials';
        if (function_exists('get_instance')) {
            $ci = @get_instance();
            if ($ci && isset($ci->config)) {
                $cfg = $ci->config->item('marvy');
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

if (!function_exists('marvy_site_tagline')) {
    /**
     * Public-facing tagline, same config-driven source as marvy_site_name().
     */
    function marvy_site_tagline(){
        static $tagline = null;
        if ($tagline !== null) return $tagline;
        $tagline = 'Prepaid commerce for social media, VTU, virtual numbers, identity, gift cards and digital goods';
        if (function_exists('get_instance')) {
            $ci = @get_instance();
            if ($ci && isset($ci->config)) {
                $cfg = $ci->config->item('marvy');
                if (is_array($cfg) && !empty($cfg['public_tagline'])) {
                    $tagline = (string)$cfg['public_tagline'];
                } elseif (is_array($cfg) && !empty($cfg['tagline'])) {
                    $tagline = (string)$cfg['tagline'];
                }
            }
        }
        // Admin → Settings `site_tagline` overrides the config default.
        if (function_exists('marvy_brand_setting')) {
            $setting = marvy_brand_setting('site_tagline');
            if ($setting !== null && $setting !== '') $tagline = (string)$setting;
        }
        return $tagline;
    }
}

if (!function_exists('marvy_brand_logo')) {
    /**
     * Public logo with the configured brand name baked in at render time.
     *
     * The SVG partial accepts variant/height; this helper keeps the header,
     * footer and auth shell from each hardcoding a different asset path.
     */
    function marvy_brand_logo($variant = 'horizontal', $height = 32){
        // Canonical MarvySocials mark set. Variant-first, then a safe fallback
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

if (!function_exists('marvy_brand_setting')) {
    /**
     * Read a branding setting (brand_logo_url, brand_favicon_url, …) saved
     * through Admin → Appearance. Returns NULL when unset or the database is
     * unavailable, so callers always have a bundled fallback.
     */
    function marvy_brand_setting($key, $default = null) {
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

if (!function_exists('marvy_default_theme')) {
    /**
     * The site-wide default theme: 'system', 'light' or 'dark'.
     *
     * Reads Admin → Settings `default_theme` (seeded 'system') and falls back to
     * 'system', which follows the visitor's OS preference. Individual visitors
     * can override it in their browser (stored in localStorage) via the theme
     * toggle; the initial paint uses this value so there is no flash.
     */
    function marvy_default_theme(){
        static $theme = null;
        if ($theme !== null) return $theme;
        $theme = 'system';
        if (function_exists('marvy_brand_setting')) {
            $v = marvy_brand_setting('default_theme');
            if ($v !== null && $v !== '') {
                $v = strtolower(trim((string)$v));
                if (in_array($v, array('system', 'light', 'dark'), true)) $theme = $v;
            }
        }
        return $theme;
    }
}

if (!function_exists('marvy_base_currency')) {
    /**
     * The panel's base currency code.
     *
     * Every wallet, order and service transaction is denominated in this.
     * Reads application/config/marvy.php so a deployment can redenominate in
     * one place; falls back to NGN when the config is unavailable (CLI helpers,
     * early bootstrap) rather than guessing a foreign currency.
     */
    function marvy_base_currency(){
        static $code = NULL;
        if ($code !== NULL) return $code;
        // Config only — never the settings table. The ledger is already
        // written in this currency: a form that could rewrite it would
        // silently reinterpret every stored balance, order and ledger entry.
        // Redenominating is a migration, not a setting.
        $code = 'NGN';
        if (function_exists('get_instance')) {
            $ci = @get_instance();
            if ($ci && isset($ci->config)) {
                $cfg = $ci->config->item('marvy');
                if (is_array($cfg) && !empty($cfg['base_currency'])) {
                    $code = strtoupper($cfg['base_currency']);
                }
            }
        }
        return $code;
    }
}
if (!function_exists('marvy_money')) {
    function marvy_money($amount, $currency=NULL){
        if ($currency === NULL) $currency = marvy_base_currency();
        $formatted = number_format((float)$amount, 2, '.', ',');
        // Admin → Settings `currency_display` (symbol|code). `code` prints
        // "NGN 1,234.56" instead of "₦1,234.56". Fail open to the symbol.
        $display = 'symbol';
        if (function_exists('marvy_brand_setting')) {
            $setting = marvy_brand_setting('currency_display');
            if ($setting !== null && strtolower(trim((string)$setting)) === 'code') $display = 'code';
        }
        if ($display === 'code') return strtoupper($currency).' '.$formatted;
        $sym = array('NGN'=>'₦','USD'=>'$','EUR'=>'€','GBP'=>'£','INR'=>'₹','BRL'=>'R$')[strtoupper($currency)] ?? $currency.' ';
        return $sym . $formatted;
    }
}
if (!function_exists('marvy_display_money')) {
    /**
     * A base-currency amount, converted and formatted for browsing in the
     * admin-configured display currency (Admin → Currencies).
     *
     * This is a *browsing aid only* — it never changes what is actually
     * charged. Checkout, wallets, orders, refunds and payouts all continue to
     * settle in marvy_base_currency() exactly as before; this exists so a
     * catalogue page can show "≈ $12.50" next to the real ₦20,000 price
     * without any settlement code needing to know the difference.
     *
     * Fails open to marvy_money() (the base-currency formatter) if
     * CurrencyService or its dependencies are unavailable — a broken
     * conversion path must never break the price itself from rendering.
     *
     * @param string $amount base-currency amount
     * @param string|null $to target currency code; defaults to the configured display currency
     */
    function marvy_display_money($amount, $to = null) {
        static $service = null;
        if ($service === null && function_exists('get_instance')) {
            try {
                $ci =& get_instance();
                $ci->load->library('CurrencyService');
                $service = $ci->currencyservice;
            } catch (Throwable $e) {
                $service = false;
            }
        }
        if (!$service) return marvy_money($amount);
        try {
            return $service->display($amount, $to);
        } catch (Throwable $e) {
            return marvy_money($amount);
        }
    }
}

if (!function_exists('marvy_request_id')) {
    function marvy_request_id(){ return bin2hex(random_bytes(8)); }
}

if (!function_exists('csp_nonce')) {
    /**
     * The current request's Content-Security-Policy nonce.
     *
     * Set by MY_Controller::send_security_headers(). Returns '' outside a web
     * request (CLI, tests) so views degrade rather than fatal.
     */
    function csp_nonce() {
        return isset($GLOBALS['__marvy_csp_nonce']) ? $GLOBALS['__marvy_csp_nonce'] : '';
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

if (!function_exists('marvy_feature_enabled')) {
    /**
     * Whether a product-module switch under Admin → Settings → Feature flags
     * is on, with a documented default when the row/table is unavailable.
     *
     * Every flag seeded in Core_seeder::seed_feature_flags() must have a
     * caller here or in a controller/library it gates — a switch nobody
     * reads is worse than no switch (see SettingsService's class doc).
     * Centralising the lookup also means turning a module off always fails
     * the same way (feature unavailable), rather than one flag 404ing and
     * another silently doing nothing.
     */
    function marvy_feature_enabled($key, $default = true) {
        try {
            $ci =& get_instance();
            $ci->load->model('Feature_flag_model');
            $row = $ci->db->where('flag_key', $key)->get('feature_flags')->row();
            if (!$row) return (bool)$default;
            return (bool)$row->enabled;
        } catch (Throwable $e) {
            return (bool)$default;
        }
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

if (!function_exists('marvy_load_database')) {
    /**
     * Connect to MySQL without killing the request.
     *
     * Returns TRUE when $CI->db is usable. A failed connect (wrong .env,
     * MySQL not imported yet, host down) returns FALSE and leaves no
     * mysqli warning on the output buffer — those warnings used to become
     * "headers already sent" on top of the database error page.
     */
    function marvy_load_database() {
        if (!function_exists('get_instance')) {
            return false;
        }
        $CI =& get_instance();
        if (isset($CI->db) && is_object($CI->db) && !empty($CI->db->conn_id)) {
            return true;
        }

        if (!marvy_db_reachable()) {
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
    function marvy_db_reachable() {
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

if (!function_exists('marvy_migration_config')) {
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
    function marvy_migration_config() {
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

if (!function_exists('marvy_migration_item')) {
    /** One key from the migration config, with a fallback. */
    function marvy_migration_item($key, $default = null) {
        $cfg = marvy_migration_config();
        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }
}

if (!function_exists('marvy_allocate_user_code')) {
    /**
     * Allocate an unused six-digit account number.
     *
     * The customer-facing account handle: short enough to read over the phone,
     * quotable to support, and usable as a sign-in identifier alongside the
     * username and email. The ULID `public_id` remains the canonical internal
     * identifier — this never replaces it.
     *
     * Random rather than sequential. A sequential code would leak the customer
     * count and growth rate, and would let anyone enumerate accounts by
     * counting upwards. Starting at 100000 keeps every code six digits, so a
     * leading zero cannot be lost when it is written down or pasted into a
     * spreadsheet.
     *
     * Lives in the helper because three separate paths create users — customer
     * registration, the setup wizard and the demo seeder — and a code that only
     * some of them allocate is worse than none.
     *
     * @param object|null $db CI database instance; defaults to the loaded one
     * @return string|null the code, or NULL if none could be allocated
     */
    function marvy_allocate_user_code($db = null) {
        if ($db === null) {
            if (!function_exists('get_instance')) return null;
            $ci =& get_instance();
            if (!isset($ci->db) || !is_object($ci->db)) return null;
            $db = $ci->db;
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = (string) random_int(100000, 999999);
            try {
                if ((int) $db->where('user_code', $code)->count_all_results('users') === 0) {
                    return $code;
                }
            } catch (Exception $e) {
                // Column not present yet (pre-migration install): the caller
                // simply gets no code rather than a fatal.
                return null;
            }
        }

        log_message('error', 'could not allocate a free user_code after 50 attempts');
        return null;
    }
}
