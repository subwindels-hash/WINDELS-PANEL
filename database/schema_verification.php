<?php
/**
 * schema_verification.php — prove the imported database matches
 * database/windels_panel.sql, table for table and column for column.
 *
 * Where it runs:
 *   CLI:     php database/schema_verification.php          (exit 0 = match)
 *   Browser: https://yourdomain.com/database/schema_verification.php
 *            (note: the packaged .htaccess blocks web access to database/ on
 *            Apache — the browser schema check is also available at
 *            /deploy-verify.php. This browser mode exists for nginx hosts.)
 *
 * Credentials come from the project `.env` (VP_DB_* or DB_*), exactly as the
 * application reads them; CLI overrides: --host= --user= --pass= --name= --port=
 *
 * Read-only: it queries information_schema and changes nothing.
 * Nothing secret is ever printed.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$ROOT = dirname(__DIR__);
$CLI  = (PHP_SAPI === 'cli');
if (!defined('BASEPATH')) define('BASEPATH', $ROOT . '/system/');
if (!$CLI) header('Content-Type: text/plain; charset=utf-8');

$argv = $CLI ? $argv : array();
$OV = array();
foreach ($argv as $a) if (strpos($a, '--') === 0 && strpos($a, '=') !== false) {
    list($k, $v) = explode('=', substr($a, 2), 2); $OV[$k] = $v;
}
if (!$CLI && (getenv('VP_VERIFY_DISABLE') === '1')) { echo "disabled (VP_VERIFY_DISABLE=1)\n"; exit(0); }

require_once $ROOT . '/application/libraries/InstallCheck.php';
require_once $ROOT . '/application/libraries/SchemaManifest.php';

$CHECK = new InstallCheck($ROOT);

/* ------------------------------------------------------------------ */
echo "WINDELS PANEL — schema verification\n";
echo str_repeat('-', 68) . "\n";

/* 1. the expectation */
$sql_file = $ROOT . '/database/windels_panel.sql';
$manifest = SchemaManifest::from_file($sql_file);
if ($manifest['error']) { echo "FAIL  {$manifest['error']}\n"; exit(1); }
printf("expectation: database/windels_panel.sql — %d tables\n", count($manifest['tables']));

/* 2. the live database */
$creds = array(
    'host' => $OV['host'] ?? $CHECK->val('DB_HOST'),
    'user' => $OV['user'] ?? $CHECK->val('DB_USER'),
    'pass' => $OV['pass'] ?? $CHECK->val('DB_PASSWORD'),
    'name' => $OV['name'] ?? $CHECK->val('DB_NAME'),
    'port' => (int) ($OV['port'] ?? ($CHECK->val('DB_PORT') ?: 3306)),
);
if (!$creds['host'] || !$creds['user'] || !$creds['name']) {
    echo "FAIL  database credentials missing — set VP_DB_* in .env (or --host/--user/--pass/--name)\n";
    exit(1);
}
if (!extension_loaded('mysqli')) { echo "FAIL  mysqli extension not loaded\n"; exit(1); }
if (defined('MYSQLI_REPORT_OFF')) mysqli_report(MYSQLI_REPORT_OFF);
$link = false; $err = '';
try {
    $link = @mysqli_connect($creds['host'], $creds['user'], $creds['pass'], $creds['name'], $creds['port']);
    if (!$link) $err = (string) mysqli_connect_error();
} catch (Exception $e) { $err = $e->getMessage(); } catch (Throwable $e) { $err = $e->getMessage(); }
if (!$link) { echo "FAIL  cannot connect: {$err}\n"; exit(1); }
echo "connected:  {$creds['user']}@{$creds['host']}:{$creds['port']} / {$creds['name']}\n";
echo str_repeat('-', 68) . "\n";

/* 3. full comparison via the shared checker */
$CHECK->check_schema($link, $sql_file);
mysqli_close($link);

$counts = $CHECK->counts();
foreach ($CHECK->rows() as $r) {
    printf("[%s] %s%s%s\n", strtoupper($r['status']), $r['label'],
        $r['detail'] !== '' ? ' — ' . $r['detail'] : '',
        $r['fix'] !== '' ? ' | FIX: ' . $r['fix'] : '');
}
echo str_repeat('-', 68) . "\n";
if ($counts['fail']) {
    echo "RESULT: FAIL — the live database does not match database/windels_panel.sql.\n";
    echo "Fix:    re-import database/windels_panel.sql through phpMyAdmin (idempotent).\n";
    exit(1);
}
echo "RESULT: PASS — every table, column, index and foreign key matches the shipped schema.\n";
exit(0);
