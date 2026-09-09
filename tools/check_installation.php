<?php
/**
 * check_installation.php — full installation verification (CLI).
 *
 * Runs the complete InstallCheck battery against a checkout or an extracted
 * deployment package: PHP version, required + recommended extensions,
 * vendor/autoload.php, the auto-detected CodeIgniter system path, writable
 * directories, .env required values, a live database connection, and the
 * imported schema (tables, columns, indexes, foreign keys) verified against
 * database/marvysocials.sql.
 *
 *   php tools/check_installation.php            # full report
 *   php tools/check_installation.php --quiet    # failures + summary only
 *   php tools/check_installation.php --no-db    # skip database + schema checks
 *   php tools/check_installation.php --root=/path/to/site
 *
 * Exit code 0 = ready (warnings allowed), 1 = something must be fixed.
 * For the browser equivalent on shared hosting, see deploy-verify.php.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only. Use deploy-verify.php in the browser.\n"); exit(2); }
if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__) . '/system/');

$ROOT  = dirname(__DIR__);
$QUIET = in_array('--quiet', $argv, true);
$NODB  = in_array('--no-db', $argv, true);
foreach ($argv as $a) if (strpos($a, '--root=') === 0) $ROOT = rtrim(substr($a, 7), '/');

require_once dirname(__DIR__) . '/application/libraries/InstallCheck.php';

$CHECK = new InstallCheck($ROOT);
$CHECK->bootstrap_app_env();
$CHECK->check_php();
$CHECK->check_extensions();
$CHECK->check_composer();
$CHECK->check_framework();
$CHECK->check_writable();
$CHECK->check_env();
if (!$NODB) {
    $link = $CHECK->check_database(true);
    if ($link) { $CHECK->check_schema($link); $CHECK->close(); }
}

$rows = $CHECK->rows();
$counts = $CHECK->counts();

echo "MarvySocials — installation check\n";
echo "root: {$ROOT}\n" . str_repeat('-', 68) . "\n";
$section = null;
foreach ($rows as $r) {
    if ($QUIET && $r['status'] === 'pass') continue;
    if ($r['section'] !== $section) { $section = $r['section']; echo "\n[" . $section . "]\n"; }
    printf("  %-4s %s%s%s\n", strtoupper($r['status']), $r['label'],
        $r['detail'] !== '' ? ' — ' . $r['detail'] : '',
        $r['fix'] !== '' ? "\n       FIX: " . $r['fix'] : '');
}
echo "\n" . str_repeat('-', 68) . "\n";
printf("pass:%d warn:%d fail:%d\n", $counts['pass'], $counts['warn'], $counts['fail']);
if ($counts['fail'] > 0) { echo "RESULT: FAIL — fix the items above and re-run.\n"; exit(1); }
echo "RESULT: PASS — installation is complete and verified.\n";
exit(0);
