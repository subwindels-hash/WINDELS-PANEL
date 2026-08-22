#!/usr/bin/env bash
#
# build_deployment_package.sh — produce application-deployment.zip
#
# This is the ONE command in the whole deployment story, and it is run by a
# maintainer on a development machine (or by CI), never by the person doing the
# deployment. What it produces is a directory tree that works the moment it is
# extracted into public_html: framework included as REAL FILES (never a
# symlink), dependencies included, runtime directories included, database
# included as one importable .sql file.
#
#   bash tools/build_deployment_package.sh
#   bash tools/build_deployment_package.sh --output dist/panel-2026-08.zip
#
# The script FAILS (and deletes a half-written zip) unless a clean extract of
# the archive contains:
#
#   system/core/CodeIgniter.php
#   vendor/autoload.php
#   vendor/codeigniter/framework/system/core/CodeIgniter.php
#
# A package that cannot satisfy those three paths is the 503 page
# "CodeIgniter framework files are missing". It must never be marked complete.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="${ROOT}/application-deployment.zip"
CI_VERSION="3.1.13"
STAGE_NAME="application-deployment"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --output) OUTPUT="$2"; shift 2 ;;
    --output=*) OUTPUT="${1#*=}"; shift ;;
    -h|--help) sed -n '2,45p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; exit 2 ;;
  esac
done

BUILD="${ROOT}/build"
STAGE="${BUILD}/${STAGE_NAME}"

say() { printf '  %s\n' "$*"; }
die() { echo "  ! $*" >&2; rm -f "${OUTPUT}"; exit 1; }

# Copy a directory tree as REAL FILES. Resolves the source through any
# symlink (./system is often a link created by tools/link_system.php) so
# the zip never stores a dangling pointer that cPanel extract cannot follow.
materialize_tree() {
  local src="$1" dst="$2"
  [[ -d "${src}" ]] || return 1
  mkdir -p "${dst}"
  src="$(cd "${src}" && pwd -P)"
  cp -a "${src}/." "${dst}/"
  if find "${dst}" -type l -print -quit | grep -q .; then
    echo "  ! symlink survived copy into ${dst}:" >&2
    find "${dst}" -type l >&2
    return 1
  fi
  return 0
}

require_regular_file() {
  local path="$1" label="${2:-$1}"
  [[ -e "${path}" ]] || die "missing ${label}"
  [[ ! -L "${path}" ]] || die "${label} is a symlink — the package must ship real files"
  [[ -f "${path}" ]] || die "${label} is not a regular file"
}

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
# 2. Locate CodeIgniter 3.1.13. Prefer a tree that already has
#    core/CodeIgniter.php (a real directory or a resolved symlink), then
#    composer's copy, then fetch the tagged release. Existence of an empty
#    system/ directory is NOT enough — that is how a previous package shipped
#    without the framework.
# ---------------------------------------------------------------------------
SYSTEM_SRC=""
if [[ -f "${ROOT}/system/core/CodeIgniter.php" ]]; then
  SYSTEM_SRC="$(cd "${ROOT}/system" && pwd -P)"
  say "framework: ${SYSTEM_SRC} (from ./system)"
elif [[ -f "${ROOT}/vendor/codeigniter/framework/system/core/CodeIgniter.php" ]]; then
  SYSTEM_SRC="$(cd "${ROOT}/vendor/codeigniter/framework/system" && pwd -P)"
  say "framework: ${SYSTEM_SRC} (from vendor/)"
else
  say "framework: downloading CodeIgniter ${CI_VERSION}"
  TMP="$(mktemp -d)"
  if ! curl -fsSL "https://codeload.github.com/bcit-ci/CodeIgniter/tar.gz/refs/tags/${CI_VERSION}" \
      | tar xz -C "${TMP}"; then
    die "could not download CodeIgniter ${CI_VERSION}"
  fi
  SYSTEM_SRC="${TMP}/CodeIgniter-${CI_VERSION}/system"
  [[ -f "${SYSTEM_SRC}/core/CodeIgniter.php" ]] || die "downloaded archive is missing system/core/CodeIgniter.php"
fi

grep -q "CI_VERSION = '${CI_VERSION}'" "${SYSTEM_SRC}/core/CodeIgniter.php" \
  || die "framework at ${SYSTEM_SRC} is not CodeIgniter ${CI_VERSION}"

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

# Full composer vendor/ when present (production dependencies). Copied first
# so the explicit framework materialisation below can overwrite any symlink
# the post-install hook left behind.
if [[ -d "${ROOT}/vendor/composer" ]]; then
  say "staging vendor/ (composer production dependencies)"
  copy vendor
fi

# system/ at the package root: REAL FILES, never a symlink. index.php probes
# ./system first. zip extraction on cPanel cannot follow a link, so a
# symlink here is a 503 on the destination host.
say "materialising system/ as real files from ${SYSTEM_SRC}"
rm -rf "${STAGE}/system"
materialize_tree "${SYSTEM_SRC}" "${STAGE}/system" \
  || die "failed to copy CodeIgniter into system/"
if [[ -f "$(dirname "${SYSTEM_SRC}")/license.txt" ]]; then
  cp "$(dirname "${SYSTEM_SRC}")/license.txt" "${STAGE}/system/LICENSE.txt"
fi

# vendor/codeigniter/framework/system — the SECOND path index.php probes.
# Always (re)materialise so a composer copy that happens to be a link, or a
# build machine that never ran composer, still ships a complete tree.
say "materialising vendor/codeigniter/framework/system as real files"
rm -rf "${STAGE}/vendor/codeigniter/framework/system"
mkdir -p "${STAGE}/vendor/codeigniter/framework"
materialize_tree "${SYSTEM_SRC}" "${STAGE}/vendor/codeigniter/framework/system" \
  || die "failed to copy CodeIgniter into vendor/codeigniter/framework/system"
if [[ -f "$(dirname "${SYSTEM_SRC}")/license.txt" ]]; then
  cp "$(dirname "${SYSTEM_SRC}")/license.txt" "${STAGE}/vendor/codeigniter/framework/license.txt"
fi

# vendor/autoload.php — when composer produced the real one (vendor/composer/
# exists) it ships as-is. Otherwise drop in the bundled fallback so the
# composer_autoload config item resolves to a working file.
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
# 6. Stage-time gate — refuse to zip a tree that cannot boot
# ---------------------------------------------------------------------------
say "validating staged tree"
require_regular_file "${STAGE}/system/core/CodeIgniter.php" \
  "system/core/CodeIgniter.php"
require_regular_file "${STAGE}/vendor/autoload.php" \
  "vendor/autoload.php"
require_regular_file "${STAGE}/vendor/codeigniter/framework/system/core/CodeIgniter.php" \
  "vendor/codeigniter/framework/system/core/CodeIgniter.php"
[[ ! -L "${STAGE}/system" ]] || die "staged system/ is a symlink"
[[ -d "${STAGE}/system/core" && -d "${STAGE}/system/database" \
   && -d "${STAGE}/system/helpers" && -d "${STAGE}/system/language" \
   && -d "${STAGE}/system/libraries" ]] \
  || die "staged system/ is missing core/database/helpers/language/libraries"
grep -q "CI_VERSION = '${CI_VERSION}'" "${STAGE}/system/core/CodeIgniter.php" \
  || die "staged system/core/CodeIgniter.php is not CodeIgniter ${CI_VERSION}"
if find "${STAGE}" -type l -print -quit | grep -q .; then
  find "${STAGE}" -type l >&2
  die "staged tree still contains symlink(s)"
fi
say "staged tree contains CodeIgniter ${CI_VERSION} as real files"

# ---------------------------------------------------------------------------
# 7. Zip, then extract-and-verify the archive itself (not just the stage)
# ---------------------------------------------------------------------------
say "creating $(basename "${OUTPUT}")"
mkdir -p "$(dirname "${OUTPUT}")"
rm -f "${OUTPUT}"
(cd "${STAGE}" && zip -qr "${OUTPUT}" . -x '.DS_Store')

bash "${ROOT}/tools/validate_deployment_zip.sh" "${OUTPUT}" \
  || die "zip failed extract validation — package is not shippable"

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
