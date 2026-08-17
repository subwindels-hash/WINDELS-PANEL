<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__.'/EncryptionService.php';

/**
 * Preflight — is this deployment safe to serve traffic? (§20)
 *
 * Everything that has to be true before a release goes live, checked in one
 * place so it can run in CI, in the container entrypoint, and by hand. Each
 * check returns a severity so the caller can distinguish "will leak your
 * customers' secrets" from "you probably meant to set this".
 *
 *   FAIL — unsafe or broken. Exit non-zero; do not serve traffic.
 *   WARN — works, but not what you want in production.
 *   OK   — verified.
 *
 * The checks are deliberately pure-ish: each takes its inputs from config, env
 * or the filesystem and returns a result array, so tests can drive them
 * directly without booting a web request.
 */
class Preflight {

    const FAIL = 'FAIL';
    const WARN = 'WARN';
    const OK   = 'OK';

    /** Directories the app writes to at runtime, relative to the project root. */
    const WRITABLE_PATHS = array(
        'storage/logs',
        'storage/cache',
        'storage/cache/sessions',
        'application/cache',
    );

    /** PHP extensions without which the app cannot function. */
    const REQUIRED_EXTENSIONS = array(
        'mysqli', 'mbstring', 'curl', 'openssl', 'bcmath', 'json',
    );

    private $ci;
    private $root;

    public function __construct($params = array()) {
        $this->ci = function_exists('get_instance') ? get_instance() : null;
        $this->root = isset($params['root'])
            ? rtrim($params['root'], '/')
            : rtrim(realpath(dirname(dirname(__DIR__))), '/');
    }

    /**
     * Run every check.
     *
     * @return array{results:array,failed:int,warned:int,ok:bool}
     */
    public function run($environment = null) {
        $env = $environment !== null
            ? $environment
            : (defined('ENVIRONMENT') ? ENVIRONMENT : 'production');
        $is_prod = ($env === 'production');

        $results = array();
        foreach ($this->checks($is_prod) as $check) {
            $results[] = $check;
        }

        $failed = 0; $warned = 0;
        foreach ($results as $r) {
            if ($r['status'] === self::FAIL) $failed++;
            if ($r['status'] === self::WARN) $warned++;
        }
        return array(
            'results'     => $results,
            'failed'      => $failed,
            'warned'      => $warned,
            'ok'          => $failed === 0,
            'environment' => $env,
        );
    }

    /* ---------------------------------------------------------------- */

    private function checks($is_prod) {
        return array_merge(
            array($this->check_encryption_key($is_prod)),
            array($this->check_php_version()),
            $this->check_extensions(),
            $this->check_writable_paths(),
            array($this->check_debug_off($is_prod)),
            array($this->check_https($is_prod)),
            array($this->check_default_db_password($is_prod)),
            array($this->check_schema_version()),
            array($this->check_demo_mode($is_prod))
        );
    }

    /** The one that motivated this whole command. */
    private function check_encryption_key($is_prod) {
        $problem = EncryptionService::key_problem(getenv('ENCRYPTION_KEY'));
        if ($problem === null) {
            return $this->result('encryption_key', self::OK, 'set and non-placeholder');
        }
        return $this->result(
            'encryption_key',
            $is_prod ? self::FAIL : self::WARN,
            'ENCRYPTION_KEY is '.$problem,
            'Provider API keys and MFA secrets are encrypted with this. '
            .'Generate one: openssl rand -base64 32'
        );
    }

    private function check_php_version() {
        $ok = version_compare(PHP_VERSION, '8.1', '>=');
        return $this->result(
            'php_version',
            $ok ? self::OK : self::FAIL,
            PHP_VERSION,
            $ok ? null : 'This application targets PHP 8.1 or newer.'
        );
    }

    private function check_extensions() {
        $out = array();
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $out[] = $this->result(
                'ext:'.$ext,
                $loaded ? self::OK : self::FAIL,
                $loaded ? 'loaded' : 'missing'
            );
        }
        return $out;
    }

    private function check_writable_paths() {
        $out = array();
        foreach (self::WRITABLE_PATHS as $rel) {
            $path = $this->root.'/'.$rel;
            if (!is_dir($path)) {
                $out[] = $this->result('writable:'.$rel, self::FAIL, 'missing',
                    'Create it: mkdir -p '.$rel);
                continue;
            }
            $out[] = is_writable($path)
                ? $this->result('writable:'.$rel, self::OK, 'writable')
                : $this->result('writable:'.$rel, self::FAIL, 'not writable',
                    'chown the directory to the PHP-FPM user.');
        }
        return $out;
    }

    private function check_debug_off($is_prod) {
        $debug = env_bool('APP_DEBUG');
        if (!$is_prod) {
            return $this->result('debug', self::OK, $debug ? 'on (non-production)' : 'off');
        }
        return $debug
            ? $this->result('debug', self::WARN, 'APP_DEBUG is on in production',
                'Verbose logs can capture secrets. Set APP_DEBUG=false.')
            : $this->result('debug', self::OK, 'off');
    }

    private function check_https($is_prod) {
        $url = (string)env_str('APP_URL', '');
        if ($url === '') {
            return $this->result('https', $is_prod ? self::FAIL : self::WARN,
                'APP_URL is not set', 'Cookies, links and webhooks need an absolute URL.');
        }
        $secure = stripos($url, 'https://') === 0;
        if (!$is_prod) {
            return $this->result('https', self::OK, $url);
        }
        return $secure
            ? $this->result('https', self::OK, $url)
            : $this->result('https', self::FAIL, 'APP_URL is not https ('.$url.')',
                'Session cookies are marked Secure in production and will not '
                .'be sent over http, so logins will fail.');
    }

    private function check_default_db_password($is_prod) {
        $pw = (string)env_str('DB_PASSWORD', '');
        $weak = array('', 'windels_secret', 'root', 'password', 'secret');
        $is_weak = in_array($pw, $weak, TRUE);
        if (!$is_weak) {
            return $this->result('db_password', self::OK, 'not a known default');
        }
        return $this->result(
            'db_password',
            $is_prod ? self::FAIL : self::OK,
            $is_prod ? 'DB_PASSWORD is a known default' : 'default (fine for local dev)',
            $is_prod ? 'Set a unique DB_PASSWORD.' : null
        );
    }

    /** Migrations applied should match the version the code expects. */
    private function check_schema_version() {
        // Preflight runs precisely when the environment may be broken, so it
        // must degrade to a warning rather than fatal on a half-built CI.
        if (!$this->ci || !isset($this->ci->db) || !isset($this->ci->config)
            || !is_object($this->ci->config) || !method_exists($this->ci->config, 'item')) {
            return $this->result('schema', self::WARN, 'no database connection to check');
        }
        $expected = (int)$this->ci->config->item('migration_version');
        $table = $this->ci->config->item('migration_table') ?: 'migrations';
        try {
            if (!$this->ci->db->table_exists($table)) {
                return $this->result('schema', self::FAIL, 'no migrations table',
                    'Run: php index.php migrate');
            }
            $row = $this->ci->db->get($table)->row();
            $current = $row ? (int)$row->version : 0;
        } catch (Exception $e) {
            return $this->result('schema', self::FAIL, 'query failed: '.$e->getMessage());
        }
        if ($current === $expected) {
            return $this->result('schema', self::OK, 'at version '.$current);
        }
        return $this->result('schema', self::FAIL,
            'at version '.$current.', code expects '.$expected,
            $current < $expected ? 'Run: php index.php migrate' : 'Deployed code is older than the schema.');
    }

    private function check_demo_mode($is_prod) {
        $demo = env_bool('DEMO_MODE');
        if ($is_prod && $demo) {
            return $this->result('demo_mode', self::WARN, 'DEMO_MODE is on in production');
        }
        return $this->result('demo_mode', self::OK, $demo ? 'on' : 'off');
    }

    private function result($name, $status, $detail, $hint = null) {
        return array('name' => $name, 'status' => $status, 'detail' => $detail, 'hint' => $hint);
    }
}
