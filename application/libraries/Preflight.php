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
            array($this->check_db_connectivity($is_prod)),
            array($this->check_secure_cookies($is_prod)),
            array($this->check_required_secrets($is_prod)),
            array($this->check_environment_consistency()),
            array($this->check_schema_version()),
            array($this->check_demo_mode($is_prod)),
            array($this->check_mock_providers($is_prod))
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

    /**
     * The app is dead without its database, so prove one answers before the
     * release goes live. In production an unreachable database is a FAIL; in
     * development it's a warning (you may be about to start it).
     */
    private function check_db_connectivity($is_prod) {
        if (!$this->ci || !isset($this->ci->db) || !is_object($this->ci->db)
            || !method_exists($this->ci->db, 'query')) {
            return $this->result('db_connectivity',
                $is_prod ? self::FAIL : self::WARN,
                'no database handle to probe',
                $is_prod ? 'The deployment must be able to reach MySQL before serving traffic.' : null);
        }
        try {
            $probe = @$this->ci->db->query('SELECT 1');
            if ($probe === FALSE || $probe === null) {
                return $this->result('db_connectivity',
                    $is_prod ? self::FAIL : self::WARN,
                    'SELECT 1 returned no result',
                    'Check DB_HOST/DB_PORT/DB_USER/DB_PASSWORD/DB_NAME.');
            }
        } catch (Exception $e) {
            return $this->result('db_connectivity',
                $is_prod ? self::FAIL : self::WARN,
                'SELECT 1 failed: '.$e->getMessage(),
                'Check DB_HOST/DB_PORT/DB_USER/DB_PASSWORD/DB_NAME.');
        } catch (Error $e) {
            return $this->result('db_connectivity',
                $is_prod ? self::FAIL : self::WARN,
                'SELECT 1 failed: '.$e->getMessage(),
                'Check DB_HOST/DB_PORT/DB_USER/DB_PASSWORD/DB_NAME.');
        }
        return $this->result('db_connectivity', self::OK, 'SELECT 1 answered');
    }

    /**
     * Session cookies are the keys to every account, so their flags are part
     * of the release gate, not an afterthought.
     */
    private function check_secure_cookies($is_prod) {
        if (!$this->ci || !isset($this->ci->config) || !is_object($this->ci->config)
            || !method_exists($this->ci->config, 'item')) {
            return $this->result('secure_cookies', self::WARN, 'no config to inspect');
        }
        $httponly = (bool)$this->ci->config->item('cookie_httponly');
        $secure = (bool)$this->ci->config->item('cookie_secure');
        $samesite = (string)($this->ci->config->item('cookie_samesite') ?: '');
        $problems = array();
        if (!$httponly) $problems[] = 'cookie_httponly is off (session readable from JavaScript)';
        if (!$secure) $problems[] = 'cookie_secure is off (session can ride plain http)';
        // 'None' requires Secure and invites CSRF; not acceptable here.
        if ($samesite === '' || strtolower($samesite) === 'none') {
            $problems[] = 'cookie_samesite must be Lax or Strict';
        }
        if ($problems === array()) {
            return $this->result('secure_cookies', self::OK,
                'httponly + '.($secure ? 'secure' : 'not-secure').' + samesite='.$samesite);
        }
        return $this->result('secure_cookies',
            $is_prod ? self::FAIL : self::WARN,
            implode('; ', $problems),
            'Config lives in application/config/config.php ('.$samesite.'// '.$secure.').');
    }

    /**
     * Secrets the platform cannot safely run without. ENCRYPTION_KEY has its
     * own dedicated check above; this covers the signing key for sessions and
     * signed tokens and the database credentials themselves.
     */
    private function check_required_secrets($is_prod) {
        $missing = array();
        $app_key = trim((string)getenv('APP_KEY'));
        $enc_key = trim((string)getenv('ENCRYPTION_KEY'));
        if ($app_key === '' && $enc_key === '') {
            // SignedToken would fall back to a key published in the source
            // tree, so every signed link in the wild would be forgeable.
            $missing[] = 'APP_KEY (or ENCRYPTION_KEY as its fallback)';
        }
        foreach (array('DB_NAME', 'DB_USER') as $key) {
            if (trim((string)getenv($key)) === '') $missing[] = $key;
        }
        if ($missing === array()) {
            return $this->result('required_secrets', self::OK, 'all present');
        }
        return $this->result('required_secrets',
            $is_prod ? self::FAIL : self::WARN,
            'missing: '.implode(', ', $missing),
            $is_prod ? 'Set them in the environment; never in the repository.' : null);
    }

    /**
     * CI_ENV and APP_ENV both feed ENVIRONMENT detection (CI_ENV wins). A
     * production deployment with the two disagreeing runs in whichever one
     * the web server injected — usually not the one the operator wanted.
     */
    private function check_environment_consistency() {
        $ci_env = getenv('CI_ENV');
        $app_env = getenv('APP_ENV');
        if ($ci_env !== false && $app_env !== false
            && trim($ci_env) !== '' && trim($app_env) !== ''
            && strtolower(trim($ci_env)) !== strtolower(trim($app_env))) {
            return $this->result('environment_consistency', self::WARN,
                'CI_ENV='.$ci_env.' disagrees with APP_ENV='.$app_env.' (CI_ENV wins)',
                'Unset one or make them match so the boot environment is unambiguous.');
        }
        return $this->result('environment_consistency', self::OK, 'CI_ENV and APP_ENV agree');
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

    /**
     * Mock adapters (MOCK, MOCK_NUMBER, MOCK_IDENTITY, MOCK_GIFTCARD...) are
     * offline doubles for development, testing and demo seeding. An ACTIVE
     * provider row pointing at one in production would "fulfil" paid orders
     * without paying any upstream — that is a deployment defect, so it fails
     * this gate. Provider_manager additionally refuses to build mock
     * adapters at runtime in production; this check catches the situation
     * before traffic arrives.
     */
    private function check_mock_providers($is_prod) {
        if (!$is_prod) {
            return $this->result('mock_providers', self::OK, 'non-production environment');
        }
        if (!$this->ci || !isset($this->ci->db) || !is_object($this->ci->db)
            || !method_exists($this->ci->db, 'query')) {
            return $this->result('mock_providers', self::WARN,
                'no database handle to inspect providers');
        }
        try {
            if (method_exists($this->ci->db, 'table_exists')
                && !$this->ci->db->table_exists('providers')) {
                return $this->result('mock_providers', self::OK, 'no providers table yet');
            }
            $q = $this->ci->db->query(
                "SELECT COUNT(*) AS n FROM providers
                 WHERE api_type LIKE 'MOCK%' AND status = 'ACTIVE'"
            );
            $row = is_object($q) && method_exists($q, 'row') ? $q->row() : null;
            $n = $row && isset($row->n) ? (int)$row->n : 0;
        } catch (Exception $e) {
            return $this->result('mock_providers', self::WARN,
                'inspection failed: '.$e->getMessage());
        } catch (Error $e) {
            return $this->result('mock_providers', self::WARN,
                'inspection failed: '.$e->getMessage());
        }
        if ($n > 0) {
            return $this->result('mock_providers', self::FAIL,
                $n.' active provider(s) use offline mock adapters',
                'MOCK adapters are for development/testing/demo. Point them at real '
                .'providers or disable them (Admin → Providers) before going live.');
        }
        return $this->result('mock_providers', self::OK, 'no active mock providers');
    }

    private function result($name, $status, $detail, $hint = null) {
        return array('name' => $name, 'status' => $status, 'detail' => $detail, 'hint' => $hint);
    }
}
