<?php
/**
 * MarvySocials — deployment verification (browser or CLI, no terminal required)
 *
 * Open https://yourdomain.com/deploy-verify.php after extracting the package
 * and editing .env. It verifies:
 *
 *   . PHP version & required extensions        . .env values (secrets stay hidden)
 *   . vendor/autoload.php                      . database credentials + LIVE connection
 *   . CodeIgniter system path (auto-detected)  . imported schema: tables, columns,
 *   . writable directories (real write probes)   indexes and foreign keys, verified
 *                                                against database/marvysocials.sql
 *
 * CLI works too: `php deploy-verify.php` exits 0 when usable, 1 on failures.
 * Nothing secret is ever printed. DELETE THIS FILE when every check passes —
 * or set VP_VERIFY_DISABLE=1 in .env to disable it without deleting.
 *
 * Deliberately old-PHP-safe syntax: it must still parse on an ancient host so
 * it can *report* that the PHP version is too old.
 */

$VERIFY_ROOT = __DIR__;
$VERIFY_CLI  = (PHP_SAPI === 'cli');
if (!defined('BASEPATH')) define('BASEPATH', $VERIFY_ROOT . '/system/');   // guard token for libraries

$_lib = $VERIFY_ROOT . '/application/libraries/InstallCheck.php';
if (!is_file($_lib)) {
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    echo 'Incomplete upload: application/libraries/InstallCheck.php is missing. Re-upload the application/ directory from the deployment package.';
    exit(1);
}
require_once $_lib;
$CHECK = new InstallCheck($VERIFY_ROOT);

/* disable switch (read before anything else, without depending on the app) */
$_raw_env = is_readable($VERIFY_ROOT . '/.env') ? InstallCheck::parse_env_file($VERIFY_ROOT . '/.env') : array();
if ((isset($_raw_env['VP_VERIFY_DISABLE']) && $_raw_env['VP_VERIFY_DISABLE'] === '1')
    || getenv('VP_VERIFY_DISABLE') === '1') {
    header('HTTP/1.1 403 Forbidden', true, 403);
    echo 'deploy-verify.php is disabled (VP_VERIFY_DISABLE=1). Remove that line from .env to re-enable, or delete this file.';
    exit(0);
}

$CHECK->bootstrap_app_env();
$CHECK->check_php();
$CHECK->check_extensions();
$CHECK->check_composer();
$CHECK->check_framework();
$CHECK->check_writable();
$CHECK->check_env();
$link = $CHECK->check_database(true);
if ($link) {
    $CHECK->check_schema($link);
    $CHECK->close();
}

$rows   = $CHECK->rows();
$counts = $CHECK->counts();
$overall = $counts['fail'] === 0;
$headline = $counts['fail'] === 0
    ? ($counts['warn'] === 0 ? 'READY — every check passed' : 'READY — with warnings to review')
    : 'NOT READY — ' . $counts['fail'] . ' check(s) failed';

if ($VERIFY_CLI) {
    foreach ($rows as $r) {
        printf("[%s] %-11s %s%s%s\n", strtoupper($r['status']), $r['section'], $r['label'],
            $r['detail'] !== '' ? ' — ' . $r['detail'] : '',
            $r['fix'] !== '' ? ' | FIX: ' . $r['fix'] : '');
    }
    printf("\n%s (pass:%d warn:%d fail:%d)\n%s\n", $headline,
        $counts['pass'], $counts['warn'], $counts['fail'],
        $overall ? 'Now delete deploy-verify.php from the server.' : 'Fix the failures and run this again.');
    exit($overall ? 0 : 1);
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>MarvySocials — deployment verification</title>
<style>
  body{font:15px/1.55 system-ui,sans-serif;margin:0;background:#f6f7f9;color:#1c2430}
  main{max-width:52em;margin:2em auto;padding:0 1em}
  h1{font-size:1.4rem} h2{font-size:1.05rem;margin:1.6em 0 .4em;text-transform:capitalize}
  .card{background:#fff;border:1px solid #e3e7ec;border-radius:10px;padding:.4em 1em;margin:.5em 0}
  .row{display:flex;gap:.6em;padding:.35em 0;border-top:1px solid #f0f2f5}
  .row:first-of-type{border-top:0}
  .badge{flex:0 0 4.2em;font-weight:700;text-align:center;border-radius:6px;height:1.5em}
  .pass .badge{background:#e5f6e8;color:#157f36}.warn .badge{background:#fff4d6;color:#966c00}.fail .badge{background:#fde3e3;color:#b42323}
  small{color:#66707c}.fix{display:block;color:#8a4b00}
  .overall{padding:1em 1.2em;border-radius:10px;font-weight:700;margin:1.2em 0}
  .ok{background:#e5f6e8;color:#157f36;border:1px solid #bfe7c9}.bad{background:#fde3e3;color:#b42323;border:1px solid #f5c6c6}
  .danger{background:#fff0f0;border:1px dashed #b42323;border-radius:10px;padding:.9em 1.2em;margin-top:1.5em}
  code{background:#eef1f4;padding:1px 5px;border-radius:4px}
</style>
</head>
<body><main>
<h1>MarvySocials — deployment verification</h1>
<p><small><?php echo htmlspecialchars(PHP_VERSION . ' · ' . PHP_SAPI . ' · ' . $VERIFY_ROOT, ENT_QUOTES, 'UTF-8'); ?></small></p>
<div class="overall <?php echo $counts['fail'] === 0 ? 'ok' : 'bad'; ?>">
  <?php echo htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'); ?>
  — <?php echo (int) $counts['pass']; ?> passed,
  <?php echo (int) $counts['warn']; ?> warnings,
  <?php echo (int) $counts['fail']; ?> failed
</div>
<?php
$section = null;
foreach ($rows as $r) {
    if ($r['section'] !== $section) {
        if ($section !== null) echo '</div>';
        $section = $r['section'];
        echo '<h2>', htmlspecialchars($section === 'env' ? '.env' : $section, ENT_QUOTES, 'UTF-8'), '</h2><div class="card">';
    }
    echo '<div class="row ', $r['status'], '"><span class="badge">', strtoupper($r['status']), '</span><span>',
        htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8');
    if ($r['detail'] !== '') echo '<br><small>', htmlspecialchars($r['detail'], ENT_QUOTES, 'UTF-8'), '</small>';
    if ($r['fix'] !== '') echo '<small class="fix">Fix: ', htmlspecialchars($r['fix'], ENT_QUOTES, 'UTF-8'), '</small>';
    echo '</span></div>';
}
if ($section !== null) echo '</div>';
?>
<div class="danger">
  <strong>Delete this file.</strong> Verification only works while
  <code>deploy-verify.php</code> is public, and it should not stay that way.
  When every check passes: cPanel → File Manager → delete <code>deploy-verify.php</code>
  (or add <code>VP_VERIFY_DISABLE=1</code> to <code>.env</code> to disable it).
  Nothing it reports is a secret, but there is no reason to leave a diagnostics
  page on the internet.
</div>
<p><small>Having a failure you cannot clear? Compare with <code>README-DEPLOYMENT.txt</code>
and <code>docs/cpanel-deployment.md</code>, then re-open this page after each fix.</small></p>
</main></body></html>
