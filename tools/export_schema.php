<?php
/**
 * export_schema.php — render docs/database.sql from the CI3 migration classes.
 *
 * The migrations are the single source of truth; this script just concatenates
 * their statements() into a reviewable, importable SQL file. No DB connection
 * and no CodeIgniter bootstrap required.
 *
 *   php tools/export_schema.php            # write docs/database.sql
 *   php tools/export_schema.php --check    # exit 1 if docs/database.sql is stale
 */

$root = dirname(__DIR__);
define('BASEPATH', $root . '/system/');

// CLI-only tool, but be explicit: $argv is absent when register_argc_argv is
// off (or under odd SAPIs) and the --check test below must not fatal there.
if (!isset($argv)) {
    $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array();
}

// Minimal stand-in so migration files can be loaded outside CodeIgniter.
if (!class_exists('CI_Migration')) {
    class CI_Migration
    {
        public $db;
        public $dbforge;
    }
}

$files = glob($root . '/application/migrations/*.php');
sort($files);
if (!$files) {
    fwrite(STDERR, "No migrations found.\n");
    exit(1);
}

$sections = array();
foreach ($files as $file) {
    require_once $file;
    $name = basename($file, '.php');                       // 001_identity
    $class = migration_class_for($name);
    if (!$class || !method_exists($class, 'statements')) {
        fwrite(STDERR, "Skipping {$name}: no statements() method.\n");
        continue;
    }
    $sections[] = array(
        'name'       => $name,
        'statements' => call_user_func(array($class, 'statements')),
    );
}

$out = render($sections);
$target = $root . '/docs/database.sql';

if (in_array('--check', $argv, true)) {
    $current = file_exists($target) ? file_get_contents($target) : '';
    if (trim($current) !== trim($out)) {
        fwrite(STDERR, "docs/database.sql is out of date — run: php tools/export_schema.php\n");
        exit(1);
    }
    echo "docs/database.sql is up to date.\n";
    exit(0);
}

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, $out);
printf("Wrote %s (%d statements across %d migrations).\n", $target, count_statements($sections), count($sections));

/* -------------------------------------------------------------------- */

function migration_class_for($file_name)
{
    // 006_refill_cancel_drip_subscription -> Migration_Refill_cancel_drip_subscription
    $suffix = preg_replace('/^\d+_/', '', $file_name);
    $class = 'Migration_' . ucfirst($suffix);
    return class_exists($class) ? $class : null;
}

function count_statements(array $sections)
{
    $n = 0;
    foreach ($sections as $s) {
        $n += count($s['statements']);
    }
    return $n;
}

function render(array $sections)
{
    $lines = array();
    $lines[] = '-- WINDELS PANEL — MySQL / MariaDB schema';
    $lines[] = '--';
    $lines[] = '-- GENERATED FILE — do not edit by hand.';
    $lines[] = '-- Source of truth: application/migrations/*.php';
    $lines[] = '-- Regenerate with: php tools/export_schema.php';
    $lines[] = '--';
    $lines[] = '-- Engine: InnoDB · Charset: utf8mb4_unicode_ci · Timestamps: UTC DATETIME';
    $lines[] = '-- Money:  DECIMAL(20,8) everywhere (bcmath in PHP, never floats)';
    $lines[] = '';
    $lines[] = 'SET NAMES utf8mb4;';
    $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $lines[] = '';

    foreach ($sections as $section) {
        $lines[] = '-- ---------------------------------------------------------------------';
        $lines[] = '-- migration ' . $section['name'];
        $lines[] = '-- ---------------------------------------------------------------------';
        $lines[] = '';
        foreach ($section['statements'] as $sql) {
            $lines[] = normalize($sql) . ';';
            $lines[] = '';
        }
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $lines[] = '';
    return implode("\n", $lines);
}

function normalize($sql)
{
    $lines = preg_split('/\R/', rtrim(trim($sql)));

    // Find the smallest indentation used by continuation lines so the dump keeps
    // the relative shape of the DDL while starting at a 2-space base indent.
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
