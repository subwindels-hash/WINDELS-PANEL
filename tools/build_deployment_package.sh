#!/usr/bin/env bash
#
# build_deployment_package.sh — produce application-deployment.zip
#
# This is the ONE command in the whole deployment story, and it is run by a
# maintainer on a development machine (or by CI), never by the person doing the
# deployment. What it produces is a directory tree that works the moment it is
# extracted into public_html: framework included, dependencies included,
# runtime directories included, database included as one importable .sql file.
#
#   bash tools/build_deployment_package.sh
#   bash tools/build_deployment_package.sh --output dist/panel-2026-08.zip
#
# What goes in the package:
#
#   index.php  .htaccess  .env.example  deploy-verify.php
#   application/   the app, minus caches and the dev-only seeds
#   system/        CodeIgniter 3.1.13 as REAL FILES (no symlink — works on
#                  every shared host, including ones that disable symlinks)
#   vendor/        ALWAYS: codeigniter/framework (system/ included at
#                  vendor/codeigniter/framework/system — the second path
#                  index.php probes) plus a fallback vendor/autoload.php;
#                  full composer dependencies when composer has been run
#   assets/        css/js/images plus the pre-created uploads directory
#   storage/       pre-created, pre-guarded log/cache/session directories
#   database/windels_panel.sql
#   cron/          crontab example for Cron Jobs in cPanel
#   docs/cpanel-deployment.md  README-DEPLOYMENT.txt
#
# What stays out: tests, tools, docker, node/npm files, phpunit/phpstan
# configs, .git, the rest of docs/ — nothing a running panel reads.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="${ROOT}/application-deployment.zip"
CI_VERSION="3.1.13"
STAGE_NAME="application-deployment"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --output) OUTPUT="$2"; shift 2 ;;
    --output=*) OUTPUT="${1#*=}"; shift ;;
    -h|--help) sed -n '2,40p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

BUILD="${ROOT}/build"
STAGE="${BUILD}/${STAGE_NAME}"

say() { printf '  %s\n' "$*"; }

echo "Building the cPanel deployment package"

# ---------------------------------------------------------------------------
# 1. The database file has to exist and has to be current, because it *is* the
#    installer now. A stale windels_panel.sql is a deployment that boots into a
#    half-migrated database with no terminal available to fix it.
# ---------------------------------------------------------------------------
if command -v php >/dev/null 2>&1; then
  say "checking database/windels_panel.sql is current"
  if ! php "${ROOT}/tools/build_production_sql.php" --check >/dev/null; then
    echo "  ! database/windels_panel.sql is out of date." >&2
    echo "    Run: php tools/build_production_sql.php" >&2
    exit 1
  fi
else
  say "php not found — skipping the windels_panel.sql freshness check"
fi
if [[ ! -f "${ROOT}/database/windels_panel.sql" ]]; then
  echo "  ! database/windels_panel.sql is missing — run: php tools/build_production_sql.php" >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 2. CodeIgniter. Prefer what is already in the tree, then composer's copy,
#    then fetch the tagged release. The package must never depend on the
#    destination host having composer.
# ---------------------------------------------------------------------------
SYSTEM_SRC=""
if [[ -d "${ROOT}/system" ]]; then
  SYSTEM_SRC="${ROOT}/system"
  say "framework: ./system"
elif [[ -d "${ROOT}/vendor/codeigniter/framework/system" ]]; then
  SYSTEM_SRC="${ROOT}/vendor/codeigniter/framework/system"
  say "framework: vendor/codeigniter/framework/system"
else
  say "framework: downloading CodeIgniter ${CI_VERSION}"
  TMP="$(mktemp -d)"
  curl -fsSL "https://codeload.github.com/bcit-ci/CodeIgniter/tar.gz/refs/tags/${CI_VERSION}" \
    | tar xz -C "${TMP}"
  SYSTEM_SRC="${TMP}/CodeIgniter-${CI_VERSION}/system"
  [[ -d "${SYSTEM_SRC}" ]] || { echo "  ! could not obtain CodeIgniter ${CI_VERSION}" >&2; exit 1; }
fi

# ---------------------------------------------------------------------------
# 3. Stage the tree
# ---------------------------------------------------------------------------
rm -rf "${STAGE}"
mkdir -p "${STAGE}"

copy() { # copy <relative path> [destination]
  local src="${ROOT}/$1" dst="${STAGE}/${2:-$1}"
  [[ -e "${src}" ]] || return 0
  mkdir -p "$(dirname "${dst}")"
  cp -R "${src}" "${dst}"
}

say "staging application files"
copy index.php
copy .htaccess
copy .env.example
copy deploy-verify.php
copy application
copy assets
copy cron
copy database/windels_panel.sql
copy database/schema_verification.php
copy database/README.md
copy docs/cpanel-deployment.md
[[ -d "${ROOT}/vendor" ]] && { say "staging vendor/ (dependencies)"; copy vendor; }

# system/ at the package root: real files, never a symlink. index.php probes
# ./system first, so the panel boots on hosts that disable symlinks, and zip
# extraction can't leave a dangling link behind.
mkdir -p "${STAGE}/system"
cp -R "${SYSTEM_SRC}/." "${STAGE}/system/"
# CodeIgniter ships under the MIT licence; keep the notice with the code.
if [[ -f "$(dirname "${SYSTEM_SRC}")/license.txt" ]]; then
  cp "$(dirname "${SYSTEM_SRC}")/license.txt" "${STAGE}/system/LICENSE.txt"
fi

# vendor/codeigniter/framework/system — the SECOND path index.php probes.
# When composer wasn't run on the build machine there is no vendor tree to
# copy, so stage the framework from SYSTEM_SRC: both framework locations the
# front controller knows about then exist in the package, redundantly.
if [[ ! -f "${STAGE}/vendor/codeigniter/framework/system/core/CodeIgniter.php" ]]; then
  say "staging vendor/codeigniter/framework (second autodetected framework path)"
  mkdir -p "${STAGE}/vendor/codeigniter/framework"
  cp -R "${SYSTEM_SRC}" "${STAGE}/vendor/codeigniter/framework/system"
  if [[ -f "$(dirname "${SYSTEM_SRC}")/license.txt" ]]; then
    cp "$(dirname "${SYSTEM_SRC}")/license.txt" "${STAGE}/vendor/codeigniter/framework/license.txt"
  fi
fi

# vendor/autoload.php — when composer produced the real one (vendor/composer/
# exists) it ships as-is. Otherwise drop in the bundled fallback so the
# composer_autoload config item resolves to a working file: it registers the
# project's own autoload rules (Windels\ psr-4, helpers, Seeder classmap) and
# the optional feature packages simply stay disabled, exactly as index.php
# documents. A later `composer install` overwrites it cleanly.
if [[ ! -d "${STAGE}/vendor/composer" ]]; then
  say "no composer install detected — staging the fallback vendor/autoload.php"
  mkdir -p "${STAGE}/vendor"
  cp "${ROOT}/tools/templates/vendor-autoload.fallback.php" "${STAGE}/vendor/autoload.php"
fi

# Runtime directories, pre-created and pre-guarded so nobody has to make them
# in File Manager. Env::ensure_writable_paths() would create them on the first
# request anyway, but only where the document root happens to be writable.
say "creating runtime directories"
for dir in storage/logs storage/cache storage/cache/sessions storage/cache/ratelimit \
           application/cache assets/uploads; do
  mkdir -p "${STAGE}/${dir}"
done
for dir in storage/logs storage/cache storage/cache/sessions storage/cache/ratelimit application/cache; do
  cat > "${STAGE}/${dir}/.htaccess" <<'HT'
# Runtime directory — never served over HTTP.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HT
  : > "${STAGE}/${dir}/index.html"
done
cat > "${STAGE}/assets/uploads/.htaccess" <<'HT'
# Uploaded files are data, never code.
php_flag engine off
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py .sh
<IfModule mod_rewrite.c>
  RewriteEngine Off
</IfModule>
Options -ExecCGI -Indexes
HT

# ---------------------------------------------------------------------------
# 4. Strip what a running panel never reads
# ---------------------------------------------------------------------------
say "removing development-only files"
rm -rf "${STAGE}/application/cache/"* 2>/dev/null || true
: > "${STAGE}/application/cache/index.html"
find "${STAGE}" -name '.gitignore' -delete
find "${STAGE}" -name '.gitkeep' -delete
find "${STAGE}" -name '*.map' -delete
rm -rf "${STAGE}/vendor/bin" 2>/dev/null || true

# The seeds are a development convenience (`php index.php seed demo`), and the
# demo seeder in particular must never be a click away from a live panel.
rm -rf "${STAGE}/application/seeds"

# ---------------------------------------------------------------------------
# 5. A README the operator sees first
# ---------------------------------------------------------------------------
cat > "${STAGE}/README-DEPLOYMENT.txt" <<'TXT'
WINDELS PANEL — cPanel deployment
=================================

Six steps, no terminal, no Composer, no npm, no symlinks.

1. UPLOAD
   cPanel -> File Manager -> the folder your domain serves (usually
   public_html) -> Upload this zip -> Extract. index.php must end up directly
   in that folder. The framework ships INSIDE this package as real files —
   system/ and vendor/codeigniter/framework/system are both included, so the
   application finds it automatically. "Your system folder path does not
   appear to be set correctly" means the upload was cut short: re-upload and
   re-extract.

2. CREATE THE DATABASE
   cPanel -> MySQL Databases. Create a database, create a user, set a password,
   then "Add User To Database" with ALL PRIVILEGES.

3. IMPORT THE DATABASE
   cPanel -> phpMyAdmin -> select the new database -> Import ->
   choose database/windels_panel.sql -> Go.
   This creates every table and all the data the panel needs. Nothing else has
   to run afterwards.

4. CONFIGURE .env
   In File Manager, copy .env.example to .env and edit it:
       CI_ENV=production
       VP_BASE_URL=https://yourdomain.com
       VP_DB_HOST=localhost
       VP_DB_PORT=3306
       VP_DB_NAME=your_database
       VP_DB_USER=your_database_user
       VP_DB_PASS=your_database_password
       VP_ENCRYPTION_KEY=...   (32+ random characters; when MOVING an existing
       VP_AUTH_SECRET=...       panel, copy these two from the old server)

5. VERIFY THE DEPLOYMENT (browser, one page)
   Open https://yourdomain.com/deploy-verify.php
   It checks the PHP version, extensions, the CodeIgniter system path,
   writable folders, .env and a live database connection, and names the exact
   fix for anything that failed. When everything is green, DELETE
   deploy-verify.php in File Manager.

6. OPEN THE SITE
   https://yourdomain.com

FIRST LOGIN
   The credentials are printed at the top of database/windels_panel.sql.
   Change the password immediately, or set your own before the first login by
   putting VP_SETUP_TOKEN=<32 random characters> in .env and visiting
   https://yourdomain.com/setup?token=<that value>. Remove the line afterwards.

FOLDER PERMISSIONS (only if something is not writable)
   Directories 755, files 644. These four must be writable by the web server:
       storage/logs/
       storage/cache/
       storage/cache/sessions/
       assets/uploads/
   cPanel -> File Manager -> select the folder -> Permissions -> 755 (or 775).

Full guide: docs/cpanel-deployment.md
TXT

# ---------------------------------------------------------------------------
# 6. Zip
# ---------------------------------------------------------------------------
say "creating $(basename "${OUTPUT}")"
mkdir -p "$(dirname "${OUTPUT}")"
rm -f "${OUTPUT}"
(cd "${STAGE}" && zip -qr "${OUTPUT}" . -x '.DS_Store')

SIZE="$(du -h "${OUTPUT}" | cut -f1)"
FILES="$(cd "${STAGE}" && find . -type f | wc -l | tr -d ' ')"
echo
echo "  ${OUTPUT}"
echo "  ${SIZE}, ${FILES} files"
echo
echo "  Contents: index.php .htaccess .env.example deploy-verify.php application/"
echo "            system/ (real files) vendor/ (framework + autoloader) assets/"
echo "            storage/ database/windels_panel.sql cron/ README-DEPLOYMENT.txt"
echo
echo "  Upload it through cPanel File Manager and extract. Nothing else to run."
echo "  Post-deploy check: open /deploy-verify.php in the browser (then delete it)."
