<?php
/**
 * Env — the one place server-specific configuration comes from.
 *
 * MarvySocials is deployed two ways, and both have to work from the same
 * tree: a container stack where the orchestrator injects real environment
 * variables, and a shared cPanel account where the only thing an operator can
 * edit is a `.env` file next to `index.php`. This class is what makes the
 * second case work without a terminal:
 *
 *   .env  ->  Env::bootstrap()  ->  getenv()/$_ENV  ->  application/config/*
 *
 * Three rules shaped it.
 *
 * **No dependencies.** It is loaded by `index.php` before CodeIgniter, before
 * composer's autoloader and before any helper, because a panel that needs
 * `composer install` to read its own database credentials cannot be deployed
 * through File Manager. vlucas/phpdotenv is still used when it happens to be
 * installed — the parser below is deliberately compatible with it — but it is
 * never required.
 *
 * **Real environment wins.** Variables already present in the process
 * environment are never overwritten by `.env`, so a container's injected
 * secrets keep beating a stale file that got copied into an image.
 *
 * **`VP_*` is the portable spelling.** A migration between hosts should only
 * touch the handful of values in the deployment guide, so every setting has a
 * `VP_`-prefixed name (`VP_DB_NAME`, `VP_BASE_URL`, `VP_ENCRYPTION_KEY`, …).
 * The historical names (`DB_NAME`, `APP_URL`, `ENCRYPTION_KEY`, …) still work
 * and still win when both are set, which is what keeps existing deployments
 * and the docker-compose stack running untouched.
 */
class Env
{
    /** Absolute path of the deployment root (the directory holding index.php). */
    private static $root = null;

    /** True once bootstrap() has run; a second call is a no-op. */
    private static $booted = false;

    /**
     * Portable name => canonical name.
     *
     * Only the settings whose canonical spelling differs from `VP_` + name are
     * listed. Everything else falls through the generic rule in
     * apply_aliases(): `VP_FOO` also defines `FOO`.
     */
    private static $aliases = array(
        // Environment / URL
        'VP_ENV'                => array('APP_ENV', 'CI_ENV'),
        'VP_BASE_URL'           => array('APP_URL'),
        'VP_DEBUG'              => array('APP_DEBUG'),
        'VP_TIMEZONE'           => array('APP_TIMEZONE'),
        'VP_MAINTENANCE_MODE'   => array('MAINTENANCE_MODE'),

        // Secrets
        'VP_ENCRYPTION_KEY'     => array('ENCRYPTION_KEY'),
        'VP_AUTH_SECRET'        => array('APP_KEY'),

        // Database
        'VP_DB_DRIVER'          => array('DB_DRIVER'),
        // Full PDO DSN. Optional and empty by default: hosts that need a
        // socket path, a non-default charset or a PDO subdriver can set it
        // instead of the discrete host/port pair, and CI3 uses it verbatim.
        'VP_DB_DSN'             => array('DB_DSN'),
        'VP_DB_HOST'            => array('DB_HOST'),
        'VP_DB_PORT'            => array('DB_PORT'),
        'VP_DB_NAME'            => array('DB_NAME'),
        'VP_DB_USER'            => array('DB_USER'),
        'VP_DB_PASS'            => array('DB_PASSWORD'),
        'VP_DB_PASSWORD'        => array('DB_PASSWORD'),
        'VP_DB_CHARSET'         => array('DB_CHARSET'),
        'VP_DB_COLLATION'       => array('DB_COLLATION'),

        // Sessions / cache / storage
        'VP_SESSION_DRIVER'     => array('SESS_DRIVER'),
        'VP_SESSION_SAVE_PATH'  => array('SESS_SAVE_PATH'),
        'VP_SESSION_COOKIE'     => array('SESS_COOKIE_NAME'),
        'VP_SESSION_EXPIRATION' => array('SESS_EXPIRATION'),
        'VP_CACHE_DRIVER'       => array('CACHE_DRIVER'),
        'VP_CACHE_PATH'         => array('CACHE_PATH'),
        'VP_LOG_PATH'           => array('LOG_PATH'),
        'VP_UPLOAD_PATH'        => array('UPLOAD_PATH'),

        // Mail
        'VP_MAIL_DRIVER'        => array('MAIL_DRIVER'),
        'VP_MAIL_HOST'          => array('SMTP_HOST'),
        'VP_MAIL_PORT'          => array('SMTP_PORT'),
        'VP_MAIL_USER'          => array('SMTP_USER'),
        'VP_MAIL_PASS'          => array('SMTP_PASSWORD'),
        'VP_MAIL_PASSWORD'      => array('SMTP_PASSWORD'),
        'VP_MAIL_CRYPTO'        => array('SMTP_CRYPTO'),
        'VP_MAIL_FROM_ADDRESS'  => array('MAIL_FROM_ADDRESS'),
        'VP_MAIL_FROM_NAME'     => array('MAIL_FROM_NAME'),
    );

    /**
     * Defaults applied when neither the portable nor the canonical name is set.
     *
     * These are shared-hosting answers on purpose. A cPanel account has MySQL
     * on localhost, no Redis, no object storage and no orchestrator, so the
     * zero-configuration path has to be "files on disk next to the app" —
     * anything else turns an upload-and-go deployment into a support ticket.
     */
    private static $defaults = array(
        'DB_DRIVER'      => 'mysqli',
        'DB_HOST'        => 'localhost',
        'DB_PORT'        => '3306',
        'DB_CHARSET'     => 'utf8mb4',
        'DB_COLLATION'   => 'utf8mb4_unicode_ci',
        'SESS_DRIVER'    => 'files',
        'CACHE_DRIVER'   => 'file',
        'STORAGE_DRIVER' => 'local',
        'MAIL_DRIVER'    => 'mail',
        'APP_TIMEZONE'   => 'UTC',
    );

    /**
     * Load `.env`, resolve the portable aliases and make the runtime
     * directories exist.
     *
     * @param string      $root     deployment root (directory holding index.php)
     * @param string|null $env_file explicit .env path; defaults to $root/.env
     */
    public static function bootstrap($root, $env_file = null)
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$root = rtrim($root, DIRECTORY_SEPARATOR);

        $file = $env_file !== null ? $env_file : self::$root . DIRECTORY_SEPARATOR . '.env';
        if (is_readable($file)) {
            foreach (self::parse(file_get_contents($file)) as $key => $value) {
                self::put($key, $value, false);
            }
        }

        self::apply_aliases();
        self::apply_defaults();
        self::ensure_writable_paths();
    }

    /** The deployment root, even from code that only has APPPATH. */
    public static function root()
    {
        if (self::$root === null) {
            self::$root = rtrim(realpath(__DIR__ . '/../..'), DIRECTORY_SEPARATOR);
        }
        return self::$root;
    }

    /**
     * Read a variable, portable name first.
     *
     * Lookup order is `VP_<name>` then `<name>`, so `Env::get('DB_NAME')` finds
     * `VP_DB_NAME`. Empty strings count as unset: a commented-out value in
     * `.env` and a blank one should behave the same way.
     */
    public static function get($name, $default = null)
    {
        foreach (array('VP_' . $name, $name) as $key) {
            $value = self::raw($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return $default;
    }

    /**
     * Booleans the way `.env` files actually get written.
     *
     * `1`, `true`, `yes`, `on` are true; everything else — including the
     * literal string `false`, which is truthy to a plain PHP cast — is false.
     */
    public static function get_bool($name, $default = false)
    {
        $value = self::get($name);
        if ($value === null) {
            return (bool)$default;
        }
        return in_array(strtolower(trim((string)$value)), array('1', 'true', 'yes', 'on'), true);
    }

    public static function get_int($name, $default = 0)
    {
        $value = self::get($name);
        return $value === null ? (int)$default : (int)$value;
    }

    /** Is this variable set (under either spelling) to a non-empty value? */
    public static function has($name)
    {
        return self::get($name) !== null;
    }

    /**
     * Define a variable in every place PHP code might read it from.
     *
     * CodeIgniter's config files use getenv(); libraries and tests reach for
     * $_ENV; some hosts populate $_SERVER only. Writing all three means no
     * caller has to know which one won.
     */
    public static function put($name, $value, $overwrite = true)
    {
        if (!$overwrite && self::raw($name) !== null) {
            return;
        }
        $value = (string)$value;
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** Raw single-name lookup with no alias handling. NULL when unset. */
    public static function raw($name)
    {
        if (array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        }
        if (array_key_exists($name, $_SERVER)) {
            return $_SERVER[$name];
        }
        $value = getenv($name);
        return $value === false ? null : $value;
    }

    /**
     * Parse `.env` text into key => value.
     *
     * Supports what a hand-edited file on cPanel realistically contains:
     * comments, blank lines, `export KEY=value`, single/double quotes,
     * escapes inside double quotes, and `${OTHER}` interpolation.
     */
    public static function parse($contents)
    {
        $out = array();
        $lines = preg_split('/\R/', (string)$contents);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            if (strpos($line, 'export ') === 0) {
                $line = ltrim(substr($line, 7));
            }
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                continue;
            }
            $value = trim($value);

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($quote === '"') {
                    $value = str_replace(
                        array('\\n', '\\r', '\\t', '\\"', '\\\\'),
                        array("\n", "\r", "\t", '"', '\\'),
                        $value
                    );
                }
            } else {
                // Unquoted values end at the first ` #` comment marker.
                $value = preg_replace('/\s+#.*$/', '', $value);
                $value = trim($value);
            }

            // ${VAR} interpolation, resolved against what we have already read
            // and then against the real environment.
            $value = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', function ($m) use ($out) {
                if (isset($out[$m[1]])) {
                    return $out[$m[1]];
                }
                $existing = self::raw($m[1]);
                return $existing === null ? '' : $existing;
            }, $value);

            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Resolve `VP_*` names onto the canonical ones the app already reads.
     *
     * The canonical name always wins when both are present: an operator who
     * has been running with `DB_NAME` for a year should not have a stale
     * `VP_DB_NAME` in the same file silently take over.
     */
    private static function apply_aliases()
    {
        $seen = array_merge(array_keys($_ENV), array_keys($_SERVER));
        foreach ($seen as $key) {
            if (strpos($key, 'VP_') !== 0) {
                continue;
            }
            $value = self::raw($key);
            if ($value === null || $value === '') {
                continue;
            }
            $targets = isset(self::$aliases[$key]) ? self::$aliases[$key] : array(substr($key, 3));
            foreach ($targets as $target) {
                self::put($target, $value, false);
            }
        }

        // CI_ENV and APP_ENV are the same decision spelled two ways; index.php
        // reads CI_ENV, the libraries read APP_ENV, and a deployment that sets
        // only one of them must not end up half in production mode.
        $env = self::raw('CI_ENV');
        if ($env === null || $env === '') {
            $env = self::raw('APP_ENV');
        }
        if ($env !== null && $env !== '') {
            self::put('CI_ENV', $env, true);
            self::put('APP_ENV', $env, true);
        }

        // A single auth secret is enough to deploy: sessions and signed tokens
        // fall back to the encryption key when APP_KEY is absent.
        if ((string)self::raw('APP_KEY') === '' && (string)self::raw('ENCRYPTION_KEY') !== '') {
            self::put('APP_KEY', self::raw('ENCRYPTION_KEY'), true);
        }
    }

    private static function apply_defaults()
    {
        foreach (self::$defaults as $key => $value) {
            self::put($key, $value, false);
        }

        // Base URL: derive it from the request when nothing is configured, so
        // a freshly uploaded panel still renders correct links on the first
        // page view instead of pointing every asset at localhost.
        if ((string)self::get('APP_URL') === '') {
            $guess = self::detect_base_url();
            if ($guess !== null) {
                self::put('APP_URL', $guess, true);
            }
        }

        // Safety net for the common cPanel mistake of setting
        // VP_BASE_URL=http://… on a domain that AutoSSL already serves over
        // HTTPS. Generated links, asset URLs and cookie flags then disagree
        // with the browser (mixed content, Secure cookies dropped on the
        // http:// host). Upgrade the scheme to match the request; leave the
        // host alone so an intentional www/apex choice is preserved.
        // Operators should still put https:// in .env — this only covers the
        // live request, not cron.
        $configured = (string)self::get('APP_URL');
        if ($configured !== '' && stripos($configured, 'http://') === 0 && self::request_is_https()) {
            $upgraded = 'https://' . substr($configured, 7);
            self::put('APP_URL', $upgraded, true);
            self::put('VP_BASE_URL', $upgraded, true);
        }
    }

    /**
     * True when this request arrived over TLS.
     *
     * Honours `HTTPS`, `X-Forwarded-Proto` (cPanel / Cloudflare / LiteSpeed
     * proxies) and port 443. CLI with no request context is never HTTPS, so a
     * mis-set `VP_BASE_URL=http://…` is not rewritten by cron jobs.
     */
    public static function request_is_https()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
            return true;
        }
        return !empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
    }

    /** Best-effort base URL from the current request. NULL on CLI. */
    public static function detect_base_url()
    {
        if (empty($_SERVER['HTTP_HOST'])) {
            return null;
        }
        $https = self::request_is_https();
        $host = preg_replace('/[^A-Za-z0-9\-\._:\[\]]/', '', $_SERVER['HTTP_HOST']);
        $dir = '';
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            $dir = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
        }
        return ($https ? 'https://' : 'http://') . $host . $dir . '/';
    }

    /* ------------------------------------------------------------------ */
    /* Runtime directories                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Every directory the application writes to, keyed by purpose.
     *
     * This list is the single source of truth for three things that used to
     * drift apart: the `php index.php deploy storage` command, the deployment
     * package (which ships the directories pre-created so nobody has to make
     * them in File Manager), and the "which folders need 755/775?" section of
     * the cPanel guide.
     */
    public static function writable_paths()
    {
        $root = self::root();
        $storage = self::get('STORAGE_PATH', $root . '/storage');
        $storage = rtrim(str_replace('\\', '/', $storage), '/');

        return array(
            'storage'   => $storage,
            'logs'      => rtrim(self::get('LOG_PATH', $storage . '/logs'), '/'),
            'cache'     => rtrim(self::get('CACHE_PATH', $storage . '/cache'), '/'),
            'sessions'  => rtrim(self::get('SESSION_PATH', $storage . '/cache/sessions'), '/'),
            'ratelimit' => rtrim(self::get('RATELIMIT_PATH', $storage . '/cache/ratelimit'), '/'),
            'ci_cache'  => $root . '/application/cache',
            'uploads'   => rtrim(self::get('UPLOAD_PATH', $root . '/assets/uploads'), '/'),
        );
    }

    /**
     * Create the runtime directories if they are missing.
     *
     * Deliberately silent and best-effort: on a host where the document root
     * is not writable this must not fatal the request — the setup page reports
     * exactly which directory to fix from File Manager instead.
     */
    public static function ensure_writable_paths()
    {
        foreach (self::writable_paths() as $name => $path) {
            if ($path === '' || is_dir($path)) {
                continue;
            }
            @mkdir($path, 0775, true);
            if (is_dir($path)) {
                self::protect_directory($path, $name === 'uploads');
            }
        }
    }

    /**
     * Keep a runtime directory unreadable over HTTP even when it sits inside
     * the document root — which on cPanel it usually does, because there is no
     * second directory above `public_html` to hide it in.
     */
    private static function protect_directory($path, $public = false)
    {
        $htaccess = $path . '/.htaccess';
        if (!file_exists($htaccess)) {
            // Uploads are the one runtime directory that must stay fetchable —
            // a customer's avatar is served straight off disk. So it gets the
            // "data, never code" treatment (matching MediaService) instead of
            // a blanket deny, and everything else gets the deny.
            $body = $public
                ? "# Uploaded files are data, never code.\n"
                    . "php_flag engine off\n"
                    . "AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py .sh\n"
                    . "<IfModule mod_rewrite.c>\n  RewriteEngine Off\n</IfModule>\n"
                    . "Options -ExecCGI -Indexes\n"
                : "# Runtime directory — never served over HTTP.\n"
                    . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
            @file_put_contents($htaccess, $body);
        }
        if (!$public) {
            $index = $path . '/index.html';
            if (!file_exists($index)) {
                @file_put_contents($index, '');
            }
        }
    }

    /**
     * Render a configuration failure as a page an operator can act on.
     *
     * The only failures that reach here are boot failures — a missing or
     * placeholder `VP_ENCRYPTION_KEY`, an unreadable `.env`, a config file that
     * throws. In production the exception message is shown but never the stack
     * trace or file paths: the message is written by us and names the setting,
     * while a trace would leak the deployment layout to the internet.
     */
    public static function render_boot_error($e, $environment = 'production')
    {
        if (!headers_sent()) {
            header('HTTP/1.1 503 Service Unavailable', true, 503);
            header('Content-Type: text/html; charset=UTF-8');
            header('Retry-After: 60');
        }
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $detail = '';
        if ($environment !== 'production') {
            $detail = '<pre>' . htmlspecialchars(
                $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString(),
                ENT_QUOTES,
                'UTF-8'
            ) . '</pre>';
        }
        echo '<!doctype html><meta charset="utf-8"><title>Configuration required</title>'
            . '<style>body{font:16px/1.6 system-ui,sans-serif;margin:6vh auto;max-width:44rem;padding:0 1.5rem;color:#0f172a}'
            . 'code,pre{background:#f1f5f9;border-radius:6px;padding:.15rem .35rem;font-size:.9em}'
            . 'pre{padding:1rem;overflow:auto}h1{font-size:1.4rem}li{margin:.35rem 0}</style>'
            . '<h1>The panel is not configured yet</h1>'
            . '<p>' . $message . '</p>'
            . '<p>Fix this in the <code>.env</code> file next to <code>index.php</code>'
            . ' (cPanel → File Manager → Edit), then reload this page. Nothing else has to run.</p>'
            . '<ul><li><code>VP_BASE_URL</code> — the address this panel answers on</li>'
            . '<li><code>VP_DB_HOST</code>, <code>VP_DB_NAME</code>, <code>VP_DB_USER</code>,'
            . ' <code>VP_DB_PASS</code> — the database you created in cPanel → MySQL Databases</li>'
            . '<li><code>VP_ENCRYPTION_KEY</code>, <code>VP_AUTH_SECRET</code> — keep the values'
            . ' from your previous server when migrating</li></ul>'
            . $detail;
        exit(1);
    }

    /**
     * Report on the runtime directories: which exist, which are writable.
     * Used by the browser setup page so a permissions problem is a sentence on
     * screen rather than a blank 500.
     */
    public static function writable_report()
    {
        $out = array();
        foreach (self::writable_paths() as $name => $path) {
            $out[$name] = array(
                'path'     => $path,
                'exists'   => is_dir($path),
                'writable' => is_dir($path) && is_writable($path),
            );
        }
        return $out;
    }
}
