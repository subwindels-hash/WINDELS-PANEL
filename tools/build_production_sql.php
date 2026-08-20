<?php
/**
 * build_production_sql.php — render database/production.sql.
 *
 * One importable file that leaves the database completely initialised:
 * schema, indexes, foreign keys, the applied-migration bookkeeping, all core
 * reference data and the first administrator. After importing it through
 * phpMyAdmin there is nothing left to migrate, seed or install — which is the
 * whole point: a cPanel deployment has no terminal to run those steps in.
 *
 * It is generated, never hand-written, and generated from the same two sources
 * the application itself uses:
 *
 *   - `application/migrations/*.php`  → tables, columns, indexes, keys
 *   - `application/seeds/Core_seeder.php` → roles, permissions, settings,
 *     feature flags, payment methods, email templates, FAQs, catalogues
 *
 * so the shipped SQL cannot drift from the code that reads it. The seeder is
 * executed for real against a capture stub that speaks just enough of CI3's
 * query builder to turn insert()/update() calls into INSERT statements.
 *
 * Usage (maintainers only — deployment never runs this):
 *
 *   php tools/build_production_sql.php
 *   php tools/build_production_sql.php --check              # CI: fail if stale
 *   php tools/build_production_sql.php \
 *       --admin-email=ops@example.com --admin-username=ops \
 *       --admin-password='a strong one'
 *
 * With no --admin-password the file ships the documented default
 * (`ChangeMe!Admin2026`), which the deployment guide tells the operator to
 * change at first login — or replace entirely from the browser setup page.
 */

$root = dirname(__DIR__);

/* --------------------------------------------------------------------------
 * CLI arguments
 * ----------------------------------------------------------------------- */
if (!isset($argv)) {
    $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array();
}
$opts = array(
    'admin-username' => 'admin',
    'admin-email'    => 'admin@example.com',
    'admin-password' => 'ChangeMe!Admin2026',
    'admin-first'    => 'Panel',
    'admin-last'     => 'Administrator',
);
$check = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--check') { $check = true; continue; }
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(2);
}

/* --------------------------------------------------------------------------
 * Bootstrap enough of CodeIgniter to load migrations and the seeder
 * ----------------------------------------------------------------------- */
define('BASEPATH', $root . '/system/');
define('APPPATH', $root . '/application/');
define('ENVIRONMENT', 'production');

/**
 * Fixed clock and fixed ids.
 *
 * The generated file has to be byte-identical between runs or `--check` in CI
 * would fail on every commit. Anything the seeder derives from time() or
 * random_bytes() is therefore pinned here.
 */
define('WINDELS_SEED_NOW', '2026-01-01 00:00:00');

/**
 * Deterministic stand-in for windels_public_id().
 *
 * Defined *before* the helper file is loaded; every generator there is guarded
 * with function_exists(), so this one wins. The shape matches a ULID
 * (26 chars, Crockford base32) because that is what the CHAR(26) public_id
 * columns and the URL routes expect.
 */
function windels_public_id()
{
    static $n = 0;
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $seed = 'WINDELSPRODUCTIONSEED' . (++$n);
    $hash = hash('sha256', $seed);
    $out = '';
    for ($i = 0; $i < 26; $i++) {
        $out .= $alphabet[hexdec($hash[$i * 2] . $hash[$i * 2 + 1]) % 32];
    }
    return $out;
}

function windels_base_currency()
{
    return 'NGN';
}

require_once APPPATH . 'helpers/windels_helper.php';

/** Minimal stand-in so migration classes load outside CodeIgniter. */
if (!class_exists('CI_Migration')) {
    class CI_Migration
    {
        public $db;
        public $dbforge;
    }
}

/* --------------------------------------------------------------------------
 * The capture "database"
 * ----------------------------------------------------------------------- */

/**
 * A CI3 query-builder shaped object that writes SQL instead of talking to
 * MySQL.
 *
 * It implements exactly what Seeder uses — where()/get()/row(), insert(),
 * update(), insert_id() — plus an in-memory row store so the seeder's
 * idempotency checks (`insert_once`, `upsert`) behave the way they do against
 * a real empty database.
 */
class Sql_capture_db
{
    /** @var array table => list of statements */
    public $statements = array();

    /** @var array table => list of rows (assoc) */
    private $rows = array();

    /** @var array table => next auto-increment id */
    private $next_id = array();

    /** @var array pending where() conditions */
    private $where = array();

    /** @var int|null id of the last insert */
    private $insert_id = 0;

    /** @var string|null table the current section is attributed to */
    public $section = 'core';

    /**
     * Tables whose INSERTs carry an explicit `id`.
     *
     * Seeded rows are referenced by foreign key (role_permissions -> roles,
     * vtu_products -> vtu_networks, users -> price_groups), and relying on
     * MySQL to hand out the same AUTO_INCREMENT values in the same order is a
     * bet the file does not need to make. Every table with a surrogate key
     * gets its id written out, so the data is correct even if someone imports
     * it into a database that is not completely empty.
     */
    private $id_tables = array();

    public function set_id_tables(array $tables)
    {
        $this->id_tables = array_flip($tables);
    }

    public function where($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $this->where[$k] = $v;
            }
        } else {
            $this->where[$key] = $value;
        }
        return $this;
    }

    public function get($table)
    {
        $match = $this->match($table);
        $this->where = array();
        return new Sql_capture_result($match);
    }

    public function insert($table, array $data)
    {
        $this->where = array();
        if (!isset($this->rows[$table])) {
            $this->rows[$table] = array();
            $this->next_id[$table] = 1;
        }
        $id = $this->next_id[$table]++;
        $row = array_merge(array('id' => $id), $data);
        $this->rows[$table][] = $row;
        $this->insert_id = $id;

        $emitted = isset($this->id_tables[$table]) ? array_merge(array('id' => $id), $data) : $data;
        $this->statements[] = array(
            'section' => $this->section,
            'table'   => $table,
            'sql'     => $this->render_insert($table, $emitted),
        );
        return true;
    }

    public function update($table, array $data)
    {
        // Reached only when a seed row already exists, which cannot happen
        // against the empty database this generator models. Kept so an
        // accidental upsert-on-existing is loud rather than silently dropped.
        throw new RuntimeException(
            'Sql_capture_db::update() called for "' . $table . '" — the production seed '
            . 'must only ever insert into an empty database.'
        );
    }

    public function insert_id()
    {
        return $this->insert_id;
    }

    /** Rows currently held for a table, for assertions in the generator. */
    public function rows($table)
    {
        return isset($this->rows[$table]) ? $this->rows[$table] : array();
    }

    private function match($table)
    {
        $found = array();
        foreach ($this->rows($table) as $row) {
            $ok = true;
            foreach ($this->where as $k => $v) {
                if (!array_key_exists($k, $row) || (string)$row[$k] !== (string)$v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $found[] = $row;
            }
        }
        return $found;
    }

    private function render_insert($table, array $data)
    {
        $cols = array();
        $vals = array();
        foreach ($data as $col => $value) {
            $cols[] = '`' . $col . '`';
            $vals[] = self::quote($value);
        }
        return 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ")\nVALUES (" . implode(', ', $vals) . ');';
    }

    public static function quote($value)
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . str_replace(
            array('\\', "'", "\0", "\n", "\r", "\x1a"),
            array('\\\\', "\\'", '\\0', '\\n', '\\r', '\\Z'),
            (string)$value
        ) . "'";
    }
}

/** Result object with just the row()/result() surface the seeder touches. */
class Sql_capture_result
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function row()
    {
        return $this->rows ? (object)$this->rows[0] : null;
    }

    public function result()
    {
        return array_map(function ($r) { return (object)$r; }, $this->rows);
    }

    public function num_rows()
    {
        return count($this->rows);
    }
}

/** The single fake CI instance the seeder talks to. */
class Sql_capture_ci
{
    public $db;

    public function __construct(Sql_capture_db $db)
    {
        $this->db = $db;
    }
}

$capture = new Sql_capture_db();
$GLOBALS['__windels_capture_ci'] = new Sql_capture_ci($capture);

if (!function_exists('get_instance')) {
    function get_instance()
    {
        return $GLOBALS['__windels_capture_ci'];
    }
}
if (!function_exists('log_message')) {
    function log_message($level, $message) { /* no-op outside CodeIgniter */ }
}

require_once APPPATH . 'libraries/Seeder.php';
require_once APPPATH . 'seeds/Core_seeder.php';

/**
 * Core_seeder with the two non-deterministic pieces pinned.
 *
 * Subclassing rather than editing the seeder keeps the application's own
 * behaviour (real ULIDs, real timestamps) untouched.
 */
class Production_seeder extends Core_seeder
{
    protected function now()
    {
        return WINDELS_SEED_NOW;
    }

    protected function pid()
    {
        return windels_public_id();
    }

    protected function out($msg)
    {
        // Silence: the generator prints its own summary.
    }
}

/* --------------------------------------------------------------------------
 * 1. Schema — from the migration classes
 * ----------------------------------------------------------------------- */
$migration_files = glob($root . '/application/migrations/*.php');
sort($migration_files);
if (!$migration_files) {
    fwrite(STDERR, "No migrations found.\n");
    exit(1);
}

$schema_sections = array();
$migration_versions = array();
foreach ($migration_files as $file) {
    require_once $file;
    $name = basename($file, '.php');
    $version = (int)substr($name, 0, 3);
    $migration_versions[] = $version;
    $class = 'Migration_' . ucfirst(preg_replace('/^\d+_/', '', $name));
    if (!class_exists($class) || !method_exists($class, 'statements')) {
        fwrite(STDERR, "Skipping {$name}: no statements() method.\n");
        continue;
    }
    $schema_sections[] = array(
        'name'       => $name,
        'statements' => call_user_func(array($class, 'statements')),
    );
}
$schema_version = max($migration_versions);

/* --------------------------------------------------------------------------
 * 2. Seed data — by running the real Core_seeder
 * ----------------------------------------------------------------------- */
$capture->set_id_tables(tables_with_surrogate_key($schema_sections));

$seeder = new Production_seeder(array('verbose' => false));
$seeder->run();
$seed_statements = $capture->statements;

/* --------------------------------------------------------------------------
 * 3. The first administrator
 * ----------------------------------------------------------------------- */
// bcrypt with a salt derived from the password rather than random_bytes(),
// because the file has to be reproducible: `--check` in CI compares the
// generated output byte for byte, and a fresh salt on every run would report
// the committed file as stale forever. The hash is still a real bcrypt hash at
// cost 12 that password_verify() accepts and that PHP rehashes on first login.
$admin_hash = deterministic_bcrypt($opts['admin-password']);

$default_group = null;
foreach ($capture->rows('price_groups') as $row) {
    if ($row['name'] === 'Default') {
        $default_group = (int)$row['id'];
    }
}
if ($default_group === null) {
    fwrite(STDERR, "Core seed produced no Default price group — refusing to write a broken admin row.\n");
    exit(1);
}

$admin_statements = array();
$admin_statements[] = "INSERT INTO `users`\n"
    . "  (`id`, `public_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`,\n"
    . "   `status`, `role`, `price_group_id`, `referral_code`, `timezone`, `locale`,\n"
    . "   `email_verified_at`, `mfa_enabled`, `created_at`, `updated_at`)\n"
    . 'VALUES (1, ' . Sql_capture_db::quote(windels_public_id()) . ', '
    . Sql_capture_db::quote($opts['admin-username']) . ', '
    . Sql_capture_db::quote($opts['admin-email']) . ', '
    . Sql_capture_db::quote($admin_hash) . ", "
    . Sql_capture_db::quote($opts['admin-first']) . ', '
    . Sql_capture_db::quote($opts['admin-last']) . ",\n"
    . "        'ACTIVE', 'SUPER_ADMIN', " . $default_group . ", 'ADMIN-0001', 'UTC', 'en',\n"
    . '        ' . Sql_capture_db::quote(WINDELS_SEED_NOW) . ', 0, '
    . Sql_capture_db::quote(WINDELS_SEED_NOW) . ', '
    . Sql_capture_db::quote(WINDELS_SEED_NOW) . ');';

$admin_statements[] = "INSERT INTO `wallets`\n"
    . "  (`public_id`, `user_id`, `balance`, `currency`, `created_at`, `updated_at`)\n"
    . 'VALUES (' . Sql_capture_db::quote(windels_public_id()) . ", 1, '0.00000000', 'NGN', "
    . Sql_capture_db::quote(WINDELS_SEED_NOW) . ', '
    . Sql_capture_db::quote(WINDELS_SEED_NOW) . ');';

$admin_statements[] = "INSERT INTO `referral_accounts`\n"
    . "  (`user_id`, `code`, `commission_percent`, `created_at`, `updated_at`)\n"
    . "VALUES (1, 'ADMIN-0001', '5.0000', " . Sql_capture_db::quote(WINDELS_SEED_NOW) . ', '
    . Sql_capture_db::quote(WINDELS_SEED_NOW) . ');';

/* --------------------------------------------------------------------------
 * 4. Render
 * ----------------------------------------------------------------------- */
$out = render_file($schema_sections, $seed_statements, $admin_statements, $schema_version, $opts);

$target = $root . '/database/production.sql';
if ($check) {
    $current = file_exists($target) ? file_get_contents($target) : '';
    if (trim($current) !== trim($out)) {
        fwrite(STDERR, "database/production.sql is out of date — run: php tools/build_production_sql.php\n");
        exit(1);
    }
    echo "database/production.sql is up to date.\n";
    exit(0);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, $out);
printf(
    "Wrote %s\n  %d schema statements across %d migrations\n  %d seed rows\n  admin: %s / %s\n",
    $target,
    array_sum(array_map(function ($s) { return count($s['statements']); }, $schema_sections)),
    count($schema_sections),
    count($seed_statements),
    $opts['admin-username'],
    $opts['admin-email']
);

/* --------------------------------------------------------------------------
 * Rendering helpers
 * ----------------------------------------------------------------------- */

function render_file(array $schema_sections, array $seed_statements, array $admin_statements, $schema_version, array $opts)
{
    $lines = array();
    $lines[] = '-- WINDELS PANEL — complete production database';
    $lines[] = '--';
    $lines[] = '-- GENERATED FILE — do not edit by hand.';
    $lines[] = '-- Sources: application/migrations/*.php  +  application/seeds/Core_seeder.php';
    $lines[] = '-- Regenerate with: php tools/build_production_sql.php';
    $lines[] = '--';
    $lines[] = '-- HOW TO USE THIS FILE';
    $lines[] = '--   1. cPanel -> MySQL Databases: create a database and a user, and give';
    $lines[] = '--      that user ALL PRIVILEGES on the database.';
    $lines[] = '--   2. cPanel -> phpMyAdmin: select the database, open Import, choose this';
    $lines[] = '--      file and press Go.';
    $lines[] = '--   3. Edit .env with the database name/user/password and your domain.';
    $lines[] = '--';
    $lines[] = '-- After the import the database is fully initialised: schema, indexes,';
    $lines[] = '-- foreign keys, migration bookkeeping (version ' . $schema_version . '), roles,';
    $lines[] = '-- permissions, settings, feature flags, payment methods, email templates,';
    $lines[] = '-- FAQs, currencies, catalogues and the first administrator. No migration,';
    $lines[] = '-- seed or installer command has to run afterwards.';
    $lines[] = '--';
    $lines[] = '-- FIRST LOGIN';
    $lines[] = '--   username: ' . $opts['admin-username'];
    $lines[] = '--   email:    ' . $opts['admin-email'];
    $lines[] = '--   password: ' . $opts['admin-password'];
    $lines[] = '--   Change it immediately (Dashboard -> Account -> Password), or set your';
    $lines[] = '--   own credentials before first login from /setup with VP_SETUP_TOKEN.';
    $lines[] = '--';
    $lines[] = '-- Engine: InnoDB · Charset: utf8mb4_unicode_ci · Timestamps: UTC DATETIME';
    $lines[] = '-- Money:  DECIMAL(20,8) everywhere (bcmath in PHP, never floats)';
    $lines[] = '';
    $lines[] = 'SET NAMES utf8mb4;';
    $lines[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
    $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $lines[] = '';

    $lines[] = section_header('SCHEMA');
    foreach ($schema_sections as $section) {
        $lines[] = '-- ---------------------------------------------------------------------';
        $lines[] = '-- migration ' . $section['name'];
        $lines[] = '-- ---------------------------------------------------------------------';
        $lines[] = '';
        foreach ($section['statements'] as $sql) {
            $lines[] = normalize_sql($sql) . ';';
            $lines[] = '';
        }
    }

    $lines[] = section_header('MIGRATION BOOKKEEPING');
    $lines[] = '-- CodeIgniter\'s migration table, pre-filled with the version this file was';
    $lines[] = '-- built from. `php index.php migrate` on a future upgrade therefore applies';
    $lines[] = '-- only what came after it, and never re-runs what is already here.';
    $lines[] = '';
    $lines[] = "CREATE TABLE IF NOT EXISTS migrations (\n"
        . "  version BIGINT NOT NULL\n"
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
    $lines[] = '';
    $lines[] = 'DELETE FROM migrations;';
    $lines[] = '';
    $lines[] = 'INSERT INTO migrations (version) VALUES (' . $schema_version . ');';
    $lines[] = '';

    $lines[] = section_header('CORE DATA');
    $lines[] = '-- Everything below is produced by application/seeds/Core_seeder.php, the';
    $lines[] = '-- same seed the development stack runs. Ids are explicit where a foreign';
    $lines[] = '-- key depends on them.';
    $lines[] = '';
    $current_table = null;
    foreach ($seed_statements as $stmt) {
        if ($stmt['table'] !== $current_table) {
            $current_table = $stmt['table'];
            $lines[] = '-- ' . $current_table;
        }
        $lines[] = $stmt['sql'];
        $lines[] = '';
    }

    $lines[] = section_header('FIRST ADMINISTRATOR');
    $lines[] = '-- A SUPER_ADMIN account so the panel can be administered the moment the';
    $lines[] = '-- import finishes — no CLI user-creation step, because a cPanel account has';
    $lines[] = '-- no CLI. The password hash is bcrypt; PHP rehashes it to whatever the host';
    $lines[] = '-- prefers on the first successful login.';
    $lines[] = '';
    foreach ($admin_statements as $sql) {
        $lines[] = $sql;
        $lines[] = '';
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $lines[] = '';
    return implode("\n", $lines);
}

/**
 * Tables that own an AUTO_INCREMENT surrogate key, read out of the schema the
 * migrations just produced.
 */
function tables_with_surrogate_key(array $schema_sections)
{
    $tables = array();
    foreach ($schema_sections as $section) {
        foreach ($section['statements'] as $sql) {
            if (!preg_match('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?\s*\(/i', $sql, $m)) {
                continue;
            }
            if (preg_match('/\bid\s+BIGINT[^,]*AUTO_INCREMENT/i', $sql)) {
                $tables[] = $m[1];
            }
        }
    }
    return array_values(array_unique($tables));
}

/**
 * A bcrypt hash that is identical for identical passwords.
 *
 * The salt is derived from the password with SHA-256 and encoded in bcrypt's
 * alphabet. Two different deployments that keep the shipped default therefore
 * share a hash — which is why the guide, the file header and the setup page
 * all push for changing it — while any operator who regenerates the file with
 * --admin-password gets their own.
 */
function deterministic_bcrypt($password)
{
    $alphabet = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $digest = hash('sha256', 'windels-production-seed:' . $password, true);
    $salt = '';
    for ($i = 0; $i < 22; $i++) {
        $salt .= $alphabet[ord($digest[$i]) % 64];
    }
    $hash = crypt($password, '$2y$12$' . $salt);
    if (!is_string($hash) || strlen($hash) < 60 || !password_verify($password, $hash)) {
        fwrite(STDERR, "Could not produce a usable bcrypt hash for the administrator password.\n");
        exit(1);
    }
    return $hash;
}

function section_header($title)
{
    $bar = str_repeat('=', 70);
    return "-- {$bar}\n-- {$title}\n-- {$bar}\n";
}

function normalize_sql($sql)
{
    $lines = preg_split('/\R/', rtrim(trim($sql)));
    $min = null;
    foreach (array_slice($lines, 1) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $indent = strlen($line) - strlen(ltrim($line));
        $min = ($min === null) ? $indent : min($min, $indent);
    }
    $min = $min ?: 0;

    $out = array(ltrim($lines[0]));
    foreach (array_slice($lines, 1) as $line) {
        $line = rtrim($line);
        if (trim($line) === '') {
            $out[] = '';
            continue;
        }
        $out[] = substr($line, $min);
    }
    return implode("\n", $out);
}
