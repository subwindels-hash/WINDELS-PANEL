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
        $sym = array('NGN'=>'₦','USD'=>'$','EUR'=>'€','GBP'=>'£','INR'=>'₹','BRL'=>'R$')[strtoupper($currency)] ?? $currency.' ';
        return $sym . number_format((float)$amount, 2, '.', ',');
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

if (!function_exists('env_str')) {
    /** Trimmed string from the environment, or $default when unset/blank. */
    function env_str($key, $default = null) {
        $raw = getenv($key);
        if ($raw === false) return $default;
        $raw = trim($raw);
        return $raw === '' ? $default : $raw;
    }
}
