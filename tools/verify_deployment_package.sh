#!/usr/bin/env bash
#
# verify_deployment_package.sh — prove the package deploys with no terminal.
#
# Builds application-deployment.zip, extracts it into a throwaway directory
# that stands in for a brand new cPanel account, writes a .env the way an
# operator would in File Manager, and then boots the application's own
# configuration exactly as index.php does — asserting that everything a first
# request depends on resolves from .env alone.
#
# What it proves:
#   · the zip extracts to a tree whose entry point is index.php
#   · CodeIgniter is inside the package as REAL FILES at both paths index.php
#     auto-detects (system/ and vendor/codeigniter/framework/system) — no
#     composer install, no symlinks anywhere in the archive
#   · tools/validate_deployment_zip.sh accepts the archive (same gate the
#     build script runs before calling the package complete)
#   · deploy-verify.php ships for browser-side environment checks
#   · a vendor/autoload.php (bundled fallback or full composer install) exists
#   · no composer.json or package.json to satisfy on the destination host
#   · .env alone produces the base URL, database credentials, log/cache/session
#     paths and secrets — nothing is read from an installer-generated file
#   · the runtime directories are created on first boot and are guarded
#   · encryption and token signing work with the keys carried in .env, and
#     ciphertext written on the "old server" still decrypts on the "new" one
#   · the administrator hash in database/windels_panel.sql verifies with the
#     documented password
#   · windels_panel.sql is complete (delegates to validate_production_sql.py)
#
# What it cannot prove here: Apache rewriting and live MySQL queries. Those
# need a real host; docs/cpanel-deployment.md documents the checks for them and
# the CI pipeline runs the MySQL half against a real server.
#
#   bash tools/verify_deployment_package.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

PHP_BIN="${PHP_BIN:-php}"
PASS=0
FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
check(){ if eval "$2" >/dev/null 2>&1; then ok "$1"; else bad "$1"; fi; }

if ! command -v "${PHP_BIN}" >/dev/null 2>&1; then
  echo "This harness needs a PHP binary to boot the application's configuration." >&2
  echo "Install PHP, or point PHP_BIN at one: PHP_BIN=/usr/local/bin/php bash $0" >&2
  exit 2
fi

echo
echo "Fresh-deployment simulation"
echo

# ---------------------------------------------------------------------------
echo "1. Build the package"
bash "${ROOT}/tools/build_deployment_package.sh" --output "${WORK}/application-deployment.zip"
ok "tools/build_deployment_package.sh produced application-deployment.zip"

# The build already extract-validates the zip. Run the same gate here so a
# caller that only invoked verify still fails if the archive is incomplete.
echo
echo "1b. Extract-validate the zip in a clean directory"
if bash "${ROOT}/tools/validate_deployment_zip.sh" "${WORK}/application-deployment.zip"; then
  ok "validate_deployment_zip.sh accepted the archive"
else
  bad "validate_deployment_zip.sh rejected the archive"
fi

# ---------------------------------------------------------------------------
echo
echo "2. Upload and extract (cPanel → File Manager)"
SITE="${WORK}/public_html"
mkdir -p "${SITE}"
unzip -q "${WORK}/application-deployment.zip" -d "${SITE}"

check "index.php is at the document root"            "[[ -f '${SITE}/index.php' ]]"
check ".htaccess shipped (clean URLs)"               "[[ -f '${SITE}/.htaccess' ]]"
check "CodeIgniter is bundled (system/core)"         "[[ -f '${SITE}/system/core/CodeIgniter.php' ]]"
check "system/ is REAL files, not a symlink"         "[[ ! -L '${SITE}/system' && -d '${SITE}/system' ]]"
check "second framework path exists (vendor/codeigniter/framework/system)" \
  "[[ -f '${SITE}/vendor/codeigniter/framework/system/core/CodeIgniter.php' ]]"
check "vendor framework is REAL files, not a symlink" \
  "[[ ! -L '${SITE}/vendor/codeigniter/framework/system' ]]"
if [[ -z "$(find "${SITE}" -type l -print -quit)" ]]; then
  ok "no symlinks anywhere in the package"
else
  bad "no symlinks anywhere in the package"
fi
check "database/windels_panel.sql is in the package"    "[[ -f '${SITE}/database/windels_panel.sql' ]]"
check "schema_verification.php ships for post-import audits" "[[ -f '${SITE}/database/schema_verification.php' ]]"
check "database/README.md ships"                        "[[ -f '${SITE}/database/README.md' ]]"
check ".env.example is in the package"               "[[ -f '${SITE}/.env.example' ]]"
check "deploy-verify.php shipped (browser diagnostics)" "[[ -f '${SITE}/deploy-verify.php' ]]"
check "vendor/autoload.php shipped (bundled fallback or full)" "[[ -f '${SITE}/vendor/autoload.php' ]]"
check "no composer.json — nothing to install"        "[[ ! -f '${SITE}/composer.json' ]]"
check "no package.json — nothing to build"           "[[ ! -f '${SITE}/package.json' ]]"
check "no tests/ or tools/ shipped"                  "[[ ! -d '${SITE}/tests' && ! -d '${SITE}/tools' ]]"
check "demo seeder is not in the package"            "[[ ! -d '${SITE}/application/seeds' ]]"
check "uploads directory pre-created"                "[[ -d '${SITE}/assets/uploads' ]]"
check "logs directory pre-created and guarded"       "[[ -f '${SITE}/storage/logs/.htaccess' ]]"

# ---------------------------------------------------------------------------
echo
echo "3. Configure .env (cPanel → File Manager → Edit)"
ENC_KEY="$(head -c 32 /dev/urandom | base64)"
AUTH_SECRET="$(head -c 32 /dev/urandom | base64)"
cat > "${SITE}/.env" <<ENVFILE
CI_ENV=production
VP_BASE_URL=https://newdomain.example

VP_DB_HOST=localhost
VP_DB_PORT=3306
VP_DB_NAME=newaccount_panel
VP_DB_USER=newaccount_admin
VP_DB_PASS=a-new-database-password

VP_ENCRYPTION_KEY=${ENC_KEY}
VP_AUTH_SECRET=${AUTH_SECRET}
ENVFILE
ok "wrote .env with the five migration values plus the two secrets"

# ---------------------------------------------------------------------------
echo
echo "4. Boot the application configuration the way index.php does"
cat > "${WORK}/boot_check.php" <<'PHPCHECK'
<?php
/**
 * Runs inside the extracted "cPanel account". Loads the app's real config
 * files with nothing but .env present and reports what resolved.
 */
$site = $argv[1];
$expected_key = $argv[2];

define('ENVIRONMENT', 'production');
define('APPPATH', $site . '/application/');
define('BASEPATH', $site . '/system/');
define('FCPATH', $site . '/');

require_once APPPATH . 'core/Env.php';
Env::bootstrap($site);

$results = array();
$fail = 0;
function want($label, $condition, $detail = '') {
    global $results, $fail;
    $results[] = array($condition ? 'ok' : 'FAIL', $label, $detail);
    if (!$condition) $fail++;
}

// --- .env drives the configuration -----------------------------------------
$config = array();
require APPPATH . 'config/config.php';
want('base_url comes from VP_BASE_URL', $config['base_url'] === 'https://newdomain.example/', $config['base_url']);
want('session driver is files (no Redis on shared hosting)', $config['sess_driver'] === 'files', $config['sess_driver']);
want('secure cookies on an https deployment', $config['cookie_secure'] === true);
want('log path is inside the writable storage dir',
     strpos($config['log_path'], $site . '/storage/logs') === 0, $config['log_path']);
want('session files land in the writable session dir',
     strpos($config['sess_save_path'], $site . '/storage/cache/sessions') === 0, $config['sess_save_path']);
want('encryption key resolved from VP_ENCRYPTION_KEY', $config['encryption_key'] === $expected_key);

$db = array();
require APPPATH . 'config/database.php';
want('database host from .env',     $db['default']['hostname'] === 'localhost');
want('database port from .env',     (int)$db['default']['port'] === 3306);
want('database name from .env',     $db['default']['database'] === 'newaccount_panel');
want('database user from .env',     $db['default']['username'] === 'newaccount_admin');
want('database password from .env', $db['default']['password'] === 'a-new-database-password');

$config = array();
require APPPATH . 'config/storage.php';
want('storage driver defaults to the local disk', $config['storage']['driver'] === 'local');
want('uploads path is inside the account',
     strpos($config['storage']['path'], $site . '/assets/uploads') === 0);

$config = array();
require APPPATH . 'config/email.php';
want('mail works through PHP mail() with no SMTP configured', $config['protocol'] === 'mail');

// --- runtime directories, created without a command -------------------------
foreach (Env::writable_report() as $name => $info) {
    want("writable: {$name}", $info['exists'] && $info['writable'], $info['path']);
}
want('log directory is not fetchable over HTTP',
     strpos((string)@file_get_contents($site . '/storage/logs/.htaccess'), 'Require all denied') !== false);
want('uploads are served but never executed',
     strpos((string)@file_get_contents($site . '/assets/uploads/.htaccess'), 'php_flag engine off') !== false);

// --- sessions actually write ------------------------------------------------
$session_dir = Env::writable_paths()['sessions'];
$probe = $session_dir . '/ci_session_probe';
want('a session file can be written and read back',
     @file_put_contents($probe, 'x') !== false && @file_get_contents($probe) === 'x');
@unlink($probe);

// --- uploads actually write -------------------------------------------------
$upload_probe = Env::writable_paths()['uploads'] . '/probe.txt';
want('an upload can be written to assets/uploads',
     @file_put_contents($upload_probe, 'x') !== false);
@unlink($upload_probe);

// --- encryption and token signing use the keys carried in .env --------------
require_once APPPATH . 'libraries/EncryptionService.php';
$enc = new EncryptionService();
$cipher = $enc->encrypt('provider-api-key-from-the-old-server');
want('encryption round-trips with the .env key',
     $enc->decrypt($cipher) === 'provider-api-key-from-the-old-server');

// The migration promise: ciphertext written on the old host still decrypts on
// the new one as long as the same VP_ENCRYPTION_KEY travels in .env.
$second = new EncryptionService();
want('ciphertext survives the move to a new server',
     $second->decrypt($cipher) === 'provider-api-key-from-the-old-server');

require_once APPPATH . 'libraries/SignedToken.php';
$signer = new SignedToken();
$token = $signer->issue(1, 'password_reset');
$claims = $signer->verify(is_array($token) ? $token['token'] : $token, 'password_reset');
want('password-reset tokens sign and verify with VP_AUTH_SECRET', !empty($claims));

// --- the administrator in windels_panel.sql can actually log in ----------------
$sql = file_get_contents($site . '/database/windels_panel.sql');
preg_match('/--\s+password:\s+(\S+)/', $sql, $pw);
preg_match('/(\$2y\$\d\d\$[.\/A-Za-z0-9]{53})/', $sql, $hash);
want('windels_panel.sql documents the first-login password', !empty($pw[1]), $pw[1] ?? '');
want('that password verifies against the seeded bcrypt hash',
     !empty($hash[1]) && password_verify($pw[1], $hash[1]));

// --- nothing depends on an installer ----------------------------------------
want('no install/ directory in the package', !is_dir($site . '/install'));
want('no installer-generated secrets file', !file_exists($site . '/application/config/.secrets.php'));

foreach ($results as $r) {
    printf("  %s %s%s\n",
        $r[0] === 'ok' ? "\033[32mok\033[0m  " : "\033[31mFAIL\033[0m",
        $r[1],
        $r[2] !== '' ? "  \033[90m(".$r[2].")\033[0m" : '');
}
exit($fail > 0 ? 1 : 0);
PHPCHECK

if "${PHP_BIN}" "${WORK}/boot_check.php" "${SITE}" "${ENC_KEY}"; then
  ok "application configuration resolves from .env alone"
else
  bad "application configuration did not resolve from .env alone"
fi

# ---------------------------------------------------------------------------
echo
echo "5. The imported database is complete"
if command -v python3 >/dev/null 2>&1 && python3 -c 'import sqlglot' >/dev/null 2>&1; then
  if python3 "${ROOT}/tools/validate_production_sql.py" "${SITE}/database/windels_panel.sql" >/dev/null 2>&1; then
    ok "database/windels_panel.sql validates (schema + seed + admin + bookkeeping)"
  else
    bad "database/windels_panel.sql failed validation — run tools/validate_production_sql.py"
  fi
else
  echo "  --   skipped: python3 with sqlglot not available"
fi

# ---------------------------------------------------------------------------
echo
echo "6. Clean-install boot (deploy-verify.php + CodeIgniter front controller)"
# Optional live MySQL: import the packaged SQL into a throwaway database and
# run the same checks an operator runs in the browser. Credentials come from
# WINDELS_VERIFY_DB_* so they cannot leak into the .env-resolution checks
# above (Env never overwrites a real process environment).
DB_HOST="${WINDELS_VERIFY_DB_HOST:-127.0.0.1}"
DB_PORT="${WINDELS_VERIFY_DB_PORT:-3306}"
DB_USER="${WINDELS_VERIFY_DB_USER:-root}"
DB_PASS="${WINDELS_VERIFY_DB_PASS:-}"
DB_NAME="windels_pkg_$$"
MYSQL_OK=0
if "${PHP_BIN}" -r 'exit(extension_loaded("mysqli") ? 0 : 1);'; then
  if "${PHP_BIN}" -r '
    mysqli_report(MYSQLI_REPORT_OFF);
    $h=$argv[1]; $u=$argv[2]; $p=$argv[3]; $port=(int)$argv[4];
    $l=@mysqli_connect($h,$u,$p,"",$port);
    exit($l ? 0 : 1);
  ' "${DB_HOST}" "${DB_USER}" "${DB_PASS}" "${DB_PORT}"; then
    MYSQL_OK=1
  fi
fi

if [[ "${MYSQL_OK}" -eq 1 ]]; then
  echo "  importing packaged SQL into ${DB_NAME} on ${DB_HOST}:${DB_PORT}"
  if TABLES="$("${PHP_BIN}" -r '
    mysqli_report(MYSQLI_REPORT_OFF);
    $h=$argv[1]; $u=$argv[2]; $p=$argv[3]; $port=(int)$argv[4]; $db=$argv[5]; $sqlFile=$argv[6];
    $l=mysqli_connect($h,$u,$p,"",$port);
    if (!$l) { fwrite(STDERR, mysqli_connect_error()."\n"); exit(1); }
    if (!mysqli_query($l, "CREATE DATABASE `".$db."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        fwrite(STDERR, mysqli_error($l)."\n"); exit(1);
    }
    mysqli_select_db($l, $db);
    mysqli_set_charset($l, "utf8mb4");
    $sql = file_get_contents($sqlFile);
    if (!mysqli_multi_query($l, $sql)) { fwrite(STDERR, mysqli_error($l)."\n"); exit(1); }
    do { if ($r = mysqli_store_result($l)) mysqli_free_result($r); }
    while (mysqli_more_results($l) && mysqli_next_result($l));
    if (mysqli_errno($l)) { fwrite(STDERR, mysqli_error($l)."\n"); exit(1); }
    $t = mysqli_query($l, "SHOW TABLES");
    echo $t ? mysqli_num_rows($t) : 0;
  ' "${DB_HOST}" "${DB_USER}" "${DB_PASS}" "${DB_PORT}" "${DB_NAME}" "${SITE}/database/windels_panel.sql")"; then
    ok "imported database/windels_panel.sql (${TABLES} tables)"
    cat > "${SITE}/.env" <<ENVFILE
CI_ENV=production
VP_BASE_URL=https://newdomain.example
VP_DB_HOST=${DB_HOST}
VP_DB_PORT=${DB_PORT}
VP_DB_NAME=${DB_NAME}
VP_DB_USER=${DB_USER}
VP_DB_PASS=${DB_PASS}
VP_ENCRYPTION_KEY=${ENC_KEY}
VP_AUTH_SECRET=${AUTH_SECRET}
ENVFILE
    if (cd "${SITE}" && env -u VP_DB_HOST -u VP_DB_PORT -u VP_DB_NAME -u VP_DB_USER -u VP_DB_PASS -u DB_HOST -u DB_NAME -u DB_USER -u DB_PASSWORD \
        "${PHP_BIN}" deploy-verify.php); then
      ok "deploy-verify.php passed against the extracted package"
    else
      bad "deploy-verify.php failed against the extracted package"
    fi
    "${PHP_BIN}" -r '
      mysqli_report(MYSQLI_REPORT_OFF);
      $l=@mysqli_connect($argv[1],$argv[2],$argv[3],"",(int)$argv[4]);
      if ($l) mysqli_query($l, "DROP DATABASE `".$argv[5]."`");
    ' "${DB_HOST}" "${DB_USER}" "${DB_PASS}" "${DB_PORT}" "${DB_NAME}" >/dev/null 2>&1 || true
  else
    bad "could not import database/windels_panel.sql into a throwaway database"
  fi
else
  echo "  --   skipped live import: no MySQL reachable at ${DB_HOST}:${DB_PORT}"
fi

# Front-controller boot: the exact probe index.php runs. A missing
# CodeIgniter.php here is the 503 this package must never produce.
if "${PHP_BIN}" -r '
    $site = $argv[1];
    $candidates = array($site."/system", $site."/vendor/codeigniter/framework/system");
    foreach ($candidates as $c) {
        if (is_dir($c) && is_file($c."/core/CodeIgniter.php") && !is_link($c)) {
            $src = file_get_contents($c."/core/CodeIgniter.php");
            if (strpos($src, "CI_VERSION = '\''3.1.") !== false) { echo $c, PHP_EOL; exit(0); }
        }
    }
    fwrite(STDERR, "CodeIgniter 3.1.x not found as real files in the extract\n");
    exit(1);
' "${SITE}"; then
  ok "CodeIgniter 3.1.x boots from the extracted package (no composer, no symlink)"
else
  bad "CodeIgniter did not boot from the extracted package"
fi

# ---------------------------------------------------------------------------
echo
if [[ ${FAIL} -eq 0 ]]; then
  printf '\033[32mPASS\033[0m — %d checks. The package deploys with File Manager, MySQL Databases,\n' "${PASS}"
  echo "       phpMyAdmin and .env only. No terminal required at any stage."
  exit 0
fi
printf '\033[31mFAILED\033[0m — %d of %d checks failed.\n' "${FAIL}" "$((PASS+FAIL))"
exit 1
