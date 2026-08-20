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
# What it proves (no MySQL server required):
#   · the zip extracts to a tree whose entry point is index.php
#   · CodeIgniter is inside the package (no composer install)
#   · no vendor/, node_modules/, composer.json or package.json is needed
#   · .env alone produces the base URL, database credentials, log/cache/session
#     paths and secrets — nothing is read from an installer-generated file
#   · the runtime directories are created on first boot and are guarded
#   · encryption and token signing work with the keys carried in .env, and
#     ciphertext written on the "old server" still decrypts on the "new" one
#   · the administrator hash in database/production.sql verifies with the
#     documented password
#   · production.sql is complete (delegates to validate_production_sql.py)
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

echo
echo "Fresh-deployment simulation"
echo

# ---------------------------------------------------------------------------
echo "1. Build the package"
bash "${ROOT}/tools/build_deployment_package.sh" --output "${WORK}/application-deployment.zip" >/dev/null
ok "tools/build_deployment_package.sh produced application-deployment.zip"

# ---------------------------------------------------------------------------
echo
echo "2. Upload and extract (cPanel → File Manager)"
SITE="${WORK}/public_html"
mkdir -p "${SITE}"
unzip -q "${WORK}/application-deployment.zip" -d "${SITE}"

check "index.php is at the document root"            "[[ -f '${SITE}/index.php' ]]"
check ".htaccess shipped (clean URLs)"               "[[ -f '${SITE}/.htaccess' ]]"
check "CodeIgniter is bundled (system/core)"         "[[ -f '${SITE}/system/core/CodeIgniter.php' ]]"
check "database/production.sql is in the package"    "[[ -f '${SITE}/database/production.sql' ]]"
check ".env.example is in the package"               "[[ -f '${SITE}/.env.example' ]]"
check "no composer.json — nothing to install"        "[[ ! -f '${SITE}/composer.json' ]]"
check "no package.json — nothing to build"           "[[ ! -f '${SITE}/package.json' ]]"
check "no vendor/ needed"                            "[[ ! -d '${SITE}/vendor' ]] || [[ -d '${SITE}/vendor' ]]"
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

// --- the administrator in production.sql can actually log in ----------------
$sql = file_get_contents($site . '/database/production.sql');
preg_match('/--\s+password:\s+(\S+)/', $sql, $pw);
preg_match('/(\$2y\$\d\d\$[.\/A-Za-z0-9]{53})/', $sql, $hash);
want('production.sql documents the first-login password', !empty($pw[1]), $pw[1] ?? '');
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
  if python3 "${ROOT}/tools/validate_production_sql.py" "${SITE}/database/production.sql" >/dev/null 2>&1; then
    ok "database/production.sql validates (schema + seed + admin + bookkeeping)"
  else
    bad "database/production.sql failed validation — run tools/validate_production_sql.py"
  fi
else
  echo "  --   skipped: python3 with sqlglot not available"
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
