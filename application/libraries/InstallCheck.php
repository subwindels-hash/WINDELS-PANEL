<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * InstallCheck — the shared implementation behind every installation checker:
 *
 *   deploy-verify.php   (browser + CLI, ships in the deployment package)
 *   tools/check_installation.php   (CLI, development / CI)
 *   database/schema_verification.php  (post-import schema audit)
 *
 * Every check returns array{status:pass|warn|fail, section, label, detail, fix}
 * so each caller picks its own renderer. No CodeIgniter, no composer — the
 * checks must still run (and still explain themselves) on a broken install.
 *
 * Sections: package, php, extensions, composer, framework, writable, env,
 *           database, schema.
 */
class InstallCheck {

    /** PHP extensions without which the app cannot function. Keep in sync with Preflight. */
    const REQUIRED_EXTENSIONS = array('mysqli', 'mbstring', 'curl', 'openssl', 'bcmath', 'json');
    /** Recommended: specific features degrade without them. */
    const RECOMMENDED_EXTENSIONS = array('gd', 'intl', 'zip', 'pdo_mysql', 'fileinfo');

    /** Placeholder values rejected for secrets (mirrors EncryptionService::REJECTED_KEYS). */
    const SECRET_PLACEHOLDERS = array(
        'change-me-32-byte-key-replace!!',
        'change-me-32-byte-key-replace-in-env',
        'change-me-32-byte-base64-key-please-replace',
        'base64:change-me-32chars-encryption-key-for-at-rest',
    );

    private $root;
    private $rows = array();
    private $env_file_values = array();   // raw .env parse (direct, no app)
    public $env_ok = false;               // Env.php loaded
    /** @var null|resource|\mysqli */ private $db_link = null;

    public function __construct($root) {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $env_file = $this->root . '/.env';
        if (is_readable($env_file)) $this->env_file_values = self::parse_env_file($env_file);
    }

    /** Every row collected so far. */
    public function rows() { return $this->rows; }
    public function counts() {
        $c = array('pass' => 0, 'warn' => 0, 'fail' => 0);
        foreach ($this->rows as $r) $c[$r['status']]++;
        return $c;
    }

    private function add($status, $section, $label, $detail = '', $fix = '') {
        $this->rows[] = array('status' => $status, 'section' => $section,
            'label' => $label, 'detail' => $detail, 'fix' => $fix);
        return $status;
    }

    /* ------------------------------------------------------------------ */
    /* .env helpers                                                        */
    /* ------------------------------------------------------------------ */

    /** Dependency-free .env reader (fallback when application/core/Env.php is absent). */
    public static function parse_env_file($file) {
        $out = array();
        foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') $v = substr($v, 1, -1);
            if (strlen($v) >= 2 && $v[0] === "'" && substr($v, -1) === "'") $v = substr($v, 1, -1);
            $v = preg_replace('/\s+#.*$/', '', $v);
            $out[trim($k)] = trim($v);
        }
        return $out;
    }

    /** Load the app's own Env bootstrap so checks resolve values as the app does. */
    public function bootstrap_app_env() {
        $envlib = $this->root . '/application/core/Env.php';
        if (!is_file($envlib)) {
            return $this->add('fail', 'package', 'application/core/Env.php is missing',
                'the application/ directory looks incomplete',
                'Re-upload the application/ directory from the deployment package.');
        }
        require_once $envlib;
        try {
            Env::bootstrap($this->root);   // parses .env, VP_ aliases, creates runtime dirs
            $this->env_ok = true;
            return $this->add('pass', 'package', 'application bootstrap (application/core/Env.php) loads');
        } catch (Exception $e) {
            return $this->add('warn', 'package', 'application bootstrap raised an error', $e->getMessage());
        }
    }

    /** A configured value the way config reads it: canonical name via Env, else VP_ name, else raw parse. */
    public function val($canonical) {
        if ($this->env_ok) {
            $v = Env::get($canonical, null);
            if ($v !== null && $v !== '') return $v;
        }
        foreach (array($canonical, 'VP_' . $canonical) as $k) {
            if (isset($this->env_file_values[$k]) && $this->env_file_values[$k] !== '')
                return $this->env_file_values[$k];
        }
        $g = getenv($canonical);
        return ($g === false || $g === '') ? null : $g;
    }

    /* ------------------------------------------------------------------ */
    /* Check groups                                                        */
    /* ------------------------------------------------------------------ */

    public function check_php() {
        $target = version_compare(PHP_VERSION, '8.1', '>=');
        $floor  = version_compare(PHP_VERSION, '7.4', '>=');
        if ($target) return $this->add('pass', 'php', 'PHP version ' . PHP_VERSION, 'targets PHP 8.1+');
        if ($floor)  return $this->add('fail', 'php', 'PHP version ' . PHP_VERSION . ' is below the 8.1 target',
            'composer.json floor is 7.4 but the application targets 8.1+',
            'cPanel → Select PHP Version → switch to PHP 8.1 or newer.');
        return $this->add('fail', 'php', 'PHP version ' . PHP_VERSION . ' is too old',
            'minimum supported is 7.4; target is 8.1+',
            'cPanel → Select PHP Version → switch to PHP 8.1 or newer.');
    }

    public function check_extensions() {
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) $this->add('pass', 'extensions', "extension: {$ext}");
            else $this->add('fail', 'extensions', "extension: {$ext} is missing", '',
                'cPanel → Select PHP Version → Extensions → enable ' . $ext . '.');
        }
        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) $this->add('pass', 'extensions', "extension: {$ext}");
            else $this->add('warn', 'extensions', "extension: {$ext} is missing (recommended)", '',
                'Optional features degrade without it. Enable via cPanel → Select PHP Version → Extensions.');
        }
    }

    public function check_composer() {
        $autoload = $this->root . '/vendor/autoload.php';
        if (is_file($autoload) && is_dir($this->root . '/vendor/composer')) {
            return $this->add('pass', 'composer', 'vendor/autoload.php present (full composer install)');
        }
        if (is_file($autoload)) {
            return $this->add('pass', 'composer', 'vendor/autoload.php present (bundled fallback)',
                'covers project classes; optional feature packages (predis, aws-sdk, guzzle) '
                . 'stay disabled until composer install is run — the app is designed for this');
        }
        return $this->add('warn', 'composer', 'vendor/autoload.php is missing',
            'the request path does not require it, but optional features stay disabled',
            'The official deployment package ships one; re-upload vendor/, or run composer install.');
    }

    /** The exact auto-detection index.php runs. Returns the resolved path (or null). */
    public function check_framework() {
        $candidates = array(
            $this->root . '/system',
            $this->root . '/vendor/codeigniter/framework/system',
        );
        $resolved = null;
        foreach ($candidates as $c) {
            if (is_dir($c) && is_file($c . '/core/CodeIgniter.php')) { $resolved = $c; break; }
        }
        if ($resolved === null) {
            $this->add('fail', 'framework', 'CodeIgniter system folder was NOT found',
                'looked in: system, vendor/codeigniter/framework/system',
                'Re-upload the system/ directory from the deployment package (it ships as real files — copying works everywhere, no symlink support needed).');
            return null;
        }
        $kind = is_link($resolved) ? 'symlink' : 'real directory';
        $version = '';
        $src = @file_get_contents($resolved . '/core/CodeIgniter.php');
        if ($src && preg_match("/CI_VERSION = '([^']+)'/", $src, $m)) $version = ', CodeIgniter ' . $m[1];
        $this->add('pass', 'framework', 'CodeIgniter system path resolved automatically',
            str_replace($this->root . '/', '', $resolved) . ' (' . $kind . $version . ') — no composer install, no manual symlink needed');
        return $resolved;
    }

    public function check_writable() {
        $paths = null;
        if ($this->env_ok) {
            $paths = Env::writable_paths();
        } else {
            $s = $this->root . '/storage';
            $paths = array('logs' => $s . '/logs', 'cache' => $s . '/cache',
                'sessions' => $s . '/cache/sessions', 'ratelimit' => $s . '/cache/ratelimit',
                'ci_cache' => $this->root . '/application/cache', 'uploads' => $this->root . '/assets/uploads');
        }
        foreach ($paths as $name => $path) {
            if (!is_dir($path)) @mkdir($path, 0775, true);
            if (!is_dir($path)) {
                $this->add('fail', 'writable', $name . ' directory is missing', $path,
                    'Create it in cPanel → File Manager (755).');
                continue;
            }
            $probe = rtrim($path, '/\\') . '/.verify-' . mt_rand();
            if (@file_put_contents($probe, 'x') !== false) {
                @unlink($probe);
                $this->add('pass', 'writable', $name . ' is writable', str_replace($this->root . '/', '', $path));
            } else {
                $this->add('fail', 'writable', $name . ' is NOT writable', $path,
                    'cPanel → File Manager → select → Permissions → 755 (or 775).');
            }
        }
        if (is_file($this->root . '/storage/logs/.htaccess'))
            $this->add('pass', 'writable', 'log directory is blocked from web access (.htaccess guard present)');
        else $this->add('warn', 'writable', 'storage/logs/.htaccess guard is missing', '', 'Re-upload storage/logs/ from the package.');
        if (is_file($this->root . '/assets/uploads/.htaccess'))
            $this->add('pass', 'writable', 'uploads directory cannot execute PHP (.htaccess guard present)');
        else $this->add('warn', 'writable', 'assets/uploads/.htaccess guard is missing', '', 'Re-upload assets/uploads/ from the package.');
    }

    /** .env presence + required values (secrets never printed). */
    public function check_env() {
        $env_file = $this->root . '/.env';
        if (!is_file($env_file)) {
            return $this->add('fail', 'env', '.env is missing', 'the panel reads every deployment value from it',
                'Copy .env.example (or .env.production.example) to .env in File Manager and fill in the values.');
        }
        if (!is_readable($env_file)) {
            return $this->add('fail', 'env', '.env exists but is not readable', '', 'Fix permissions (644) in File Manager.');
        }
        $this->add('pass', 'env', '.env exists and is readable');

        $ci_env = $this->val('CI_ENV');
        if ($ci_env === null && $this->val('APP_ENV') === null)
            $this->add('warn', 'env', 'CI_ENV is not set', '', 'Set CI_ENV=production on a live domain.');
        elseif ($ci_env === 'production' || $this->val('APP_ENV') === 'production')
            $this->add('pass', 'env', 'environment is production');
        else
            $this->add('warn', 'env', 'environment is ' . ($ci_env ?: $this->val('APP_ENV')), '', 'Use production on a live domain.');

        $base = $this->val('APP_URL');
        if ($base && strpos($base, 'https://') === 0)
            $this->add('pass', 'env', 'base URL is set and uses https', preg_replace('#^(https?://[^/]+).*#', '$1', $base));
        elseif ($base)
            $this->add('warn', 'env', 'base URL is not https', '', 'Use https://yourdomain.com on a live domain.');
        else
            $this->add('fail', 'env', 'base URL is not set', '', 'Set VP_BASE_URL=https://yourdomain.com (or APP_URL=…) in .env.');

        foreach (array(
                array('ENCRYPTION_KEY', 'VP_ENCRYPTION_KEY'),
                array('APP_KEY', 'VP_AUTH_SECRET'),
            ) as $pair) {
            list($canon, $vp) = $pair;
            // canonical name as the app resolves it, then both .env spellings,
            // plus the bare legacy alias (AUTH_SECRET)
            $v = $this->val($canon);
            if (!$v || $v === '') $v = $this->env_file_values[$vp] ?? null;
            if (!$v || $v === '') $v = $this->env_file_values[$canon] ?? null;
            if ((!$v || $v === '') && $canon === 'APP_KEY') {
                $v = $this->env_file_values['AUTH_SECRET'] ?? null;
                if (!$v) $v = $this->env_file_values['APP_KEY'] ?? null;
            }
            $name = $vp;
            if (!$v) $this->add('fail', 'env', $name . ' is not set', '',
                'Generate 32+ random characters and put them in .env — the panel encrypts/signs with these.');
            elseif (in_array($v, self::SECRET_PLACEHOLDERS, true) || strlen($v) < 32)
                $this->add('fail', 'env', $name . ' is too short or still the example placeholder', '',
                'Replace it with 32+ random characters.');
            else $this->add('pass', 'env', $name . ' is set (' . strlen($v) . ' chars, value hidden)');
        }
    }

    /**
     * Database credentials + live connection. Returns mysqli link or null.
     * @return null|\mysqli
     */
    public function check_database($connect = true) {
        $host = $this->val('DB_HOST'); $user = $this->val('DB_USER'); $pass = $this->val('DB_PASSWORD');
        $name = $this->val('DB_NAME'); $port = (int) ($this->val('DB_PORT') ?: 3306);

        if (!$host || !$user || !$name) {
            $this->add('fail', 'database', 'database credentials are incomplete in .env',
                'need VP_DB_HOST (or DB_HOST), VP_DB_NAME, VP_DB_USER, VP_DB_PASS',
                'cPanel → MySQL Databases → create database + user, "Add User To Database" with ALL PRIVILEGES, then copy the values into .env.');
            return null;
        }
        $this->add('pass', 'database', 'database credentials are present in .env', $user . '@' . $host . ':' . $port . ' / ' . $name);
        if (!$connect) return null;
        if (!extension_loaded('mysqli')) {
            $this->add('fail', 'database', 'cannot test the connection — mysqli is not loaded', '',
                'Enable the mysqli extension (see the extensions section).');
            return null;
        }
        if (defined('MYSQLI_REPORT_OFF')) mysqli_report(MYSQLI_REPORT_OFF);
        $link = false; $errtxt = '';
        try {
            $link = @mysqli_connect($host, $user, $pass, $name, $port);
            if ($link === false) $errtxt = function_exists('mysqli_connect_error') ? (string) mysqli_connect_error() : 'connection failed';
        } catch (Exception $e) { $errtxt = $e->getMessage(); }
        catch (Throwable $e) { $errtxt = $e->getMessage(); }
        if (!$link) {
            $this->add('fail', 'database', 'database connection failed', $errtxt,
                'Check the VP_DB_* values in .env against cPanel → MySQL Databases, and that the user was ADDED to the database with ALL PRIVILEGES.');
            return null;
        }
        $this->add('pass', 'database', 'database connection succeeds', 'MySQL/MariaDB ' . @mysqli_get_server_info($link));
        $this->db_link = $link;
        return $link;
    }

    /**
     * Compare the LIVE database against database/windels_panel.sql:
     * every table, every column (+type family), every index, every FK.
     *
     * @param \mysqli $link
     * @param null|string $sql_path  defaults to <root>/database/windels_panel.sql
     */
    public function check_schema($link, $sql_path = null) {
        $sql_path = $sql_path ?: $this->root . '/database/windels_panel.sql';
        require_once dirname(__FILE__) . '/SchemaManifest.php';
        $manifest = SchemaManifest::from_file($sql_path);
        if ($manifest['error']) {
            return $this->add('fail', 'schema', 'cannot verify the schema — ' . $manifest['error'],
                '', 'Re-upload the database/ directory from the deployment package.');
        }
        $expected = $manifest['tables'];
        $db = @mysqli_query($link, 'SELECT DATABASE()');
        $dbrow = $db ? mysqli_fetch_row($db) : array('?');
        $dbname = $dbrow[0];

        // live state
        $live_tables = array();
        $res = @mysqli_query($link, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=\'' . mysqli_real_escape_string($link, $dbname) . '\'');
        if ($res) while ($row = mysqli_fetch_row($res)) $live_tables[$row[0]] = true;
        if (!$live_tables) {
            return $this->add('fail', 'schema', 'database is empty — the schema was never imported', '',
                'cPanel → phpMyAdmin → select the database → Import → database/windels_panel.sql → Go.');
        }

        $missing_t = array_diff(array_keys($expected), array_keys($live_tables));
        if ($missing_t) {
            $this->add('fail', 'schema', count($missing_t) . ' table(s) missing from the database',
                implode(', ', array_slice($missing_t, 0, 8)) . (count($missing_t) > 8 ? '…' : ''),
                'Re-import database/windels_panel.sql in phpMyAdmin (it is idempotent).');
        } else {
            $this->add('pass', 'schema', 'all ' . count($expected) . ' required tables exist in the database');
        }

        // columns (only for tables present on both sides)
        $missing_c = 0; $type_mismatch = 0; $examples = array();
        foreach ($expected as $tname => $t) {
            if (!isset($live_tables[$tname])) continue;
            $cols = array();
            $r = @mysqli_query($link, 'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=\'' . mysqli_real_escape_string($link, $dbname) . '\' AND TABLE_NAME=\'' . mysqli_real_escape_string($link, $tname) . '\'');
            if ($r) while ($row = mysqli_fetch_row($r)) $cols[$row[0]] = strtolower($row[1]);
            foreach ($t['columns'] as $cname => $c) {
                if (!isset($cols[$cname])) {
                    $missing_c++;
                    if (count($examples) < 6) $examples[] = "{$tname}.{$cname} (missing)";
                    continue;
                }
                $want = SchemaManifest::base_type($c['type']);
                $have = SchemaManifest::base_type($cols[$cname]);
                if ($want === 'timestamp') $want = 'datetime';                  // aliases across MySQL/MariaDB
                if ($have === 'timestamp') $have = 'datetime';
                if ($want !== $have) {
                    $type_mismatch++;
                    if (count($examples) < 6) $examples[] = "{$tname}.{$cname}: want {$c['type']}, have {$cols[$cname]}";
                }
            }
        }
        if ($missing_c || $type_mismatch) {
            $this->add('fail', 'schema', "{$missing_c} missing column(s), {$type_mismatch} type mismatch(es)",
                implode(' · ', $examples),
                'Re-import database/windels_panel.sql; it upgrades the schema in place.');
        } elseif (!$missing_t) {
            $this->add('pass', 'schema', 'all columns present with compatible types (every table verified)');
        }

        // indexes + FKs (name-level comparison)
        $missing_i = 0; $missing_f = 0; $notes = array();
        foreach ($expected as $tname => $t) {
            if (!isset($live_tables[$tname])) continue;
            $have_idx = array('PRIMARY' => true);
            $r = @mysqli_query($link, 'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=\'' . mysqli_real_escape_string($link, $dbname) . '\' AND TABLE_NAME=\'' . mysqli_real_escape_string($link, $tname) . '\'');
            if ($r) while ($row = mysqli_fetch_row($r)) $have_idx[$row[0]] = true;
            foreach (array_keys($t['unique'] + $t['indexes']) as $iname) {
                if (!isset($have_idx[$iname])) { $missing_i++; if (count($notes) < 6) $notes[] = "{$tname}.{$iname}"; }
            }
            if ($t['pk'] && !isset($have_idx['PRIMARY'])) { $missing_i++; $notes[] = "{$tname}.PRIMARY"; }
            if ($t['fks']) {
                $have_fk = array();
                $r = @mysqli_query($link, 'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=\'' . mysqli_real_escape_string($link, $dbname) . '\' AND TABLE_NAME=\'' . mysqli_real_escape_string($link, $tname) . '\' AND CONSTRAINT_TYPE=\'FOREIGN KEY\'');
                if ($r) while ($row = mysqli_fetch_row($r)) $have_fk[$row[0]] = true;
                foreach (array_keys($t['fks']) as $fname) {
                    if (!isset($have_fk[$fname])) { $missing_f++; if (count($notes) < 6) $notes[] = "{$tname}.{$fname} (FK)"; }
                }
            }
        }
        if ($missing_i || $missing_f) {
            $this->add('fail', 'schema', "{$missing_i} missing index(es), {$missing_f} missing foreign key(s)",
                implode(' · ', $notes), 'Re-import database/windels_panel.sql.');
        } elseif (!$missing_t) {
            $this->add('pass', 'schema', 'all indexes and foreign keys present');
        }
    }

    /** Close the mysqli link opened by check_database(). */
    public function close() {
        if ($this->db_link) { @mysqli_close($this->db_link); $this->db_link = null; }
    }
}
