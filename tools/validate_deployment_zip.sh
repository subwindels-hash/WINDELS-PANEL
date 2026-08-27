#!/usr/bin/env bash
#
# validate_deployment_zip.sh — prove an application-deployment.zip is a
# complete, terminal-free cPanel package.
#
# Extracts the archive into a throwaway directory (never the source tree)
# and fails unless every file the front controller needs is present as a
# *regular file*, not a symlink.
#
#   bash tools/validate_deployment_zip.sh path/to/application-deployment.zip
#
# Called automatically by tools/build_deployment_package.sh after the zip
# is written, and by tools/verify_deployment_package.sh.
set -euo pipefail

ZIP="${1:-}"
if [[ -z "${ZIP}" || ! -f "${ZIP}" ]]; then
  echo "usage: $0 path/to/application-deployment.zip" >&2
  exit 2
fi

ZIP="$(cd "$(dirname "${ZIP}")" && pwd)/$(basename "${ZIP}")"

if ! command -v unzip >/dev/null 2>&1; then
  echo "  ! unzip is required to validate the deployment package" >&2
  exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
SITE="${WORK}/extract"
mkdir -p "${SITE}"

echo "Validating $(basename "${ZIP}")"
unzip -q "${ZIP}" -d "${SITE}"

fail() { echo "  ! $*" >&2; exit 1; }
ok()   { printf '  ok  %s\n' "$*"; }

# Files that must exist as regular files after a clean extract. A missing
# CodeIgniter.php is the exact 503 the operator sees ("framework files are
# missing") — this list is the contract that prevents that page from shipping.
REQUIRED=(
  index.php
  .htaccess
  .env.example
  deploy-verify.php
  README-DEPLOYMENT.txt
  application/core/Env.php
  application/config/config.php
  application/config/database.php
  database/marvysocials.sql
  system/core/CodeIgniter.php
  vendor/autoload.php
  vendor/codeigniter/framework/system/core/CodeIgniter.php
)

for rel in "${REQUIRED[@]}"; do
  path="${SITE}/${rel}"
  [[ -e "${path}" ]] || fail "missing after extract: ${rel}"
  [[ ! -L "${path}" ]] || fail "${rel} is a symlink — cPanel extract would leave a dangling link"
  [[ -f "${path}" ]] || fail "${rel} is not a regular file"
  ok "${rel}"
done

# system/ itself must be a real directory. A symlink at this path is how
# previous packages broke on hosts that drop links on extract.
[[ -d "${SITE}/system" ]] || fail "system/ is not a directory"
[[ ! -L "${SITE}/system" ]] || fail "system/ is a symlink — zip it as real files"
[[ ! -L "${SITE}/vendor/codeigniter/framework/system" ]] || fail "vendor/codeigniter/framework/system is a symlink"

# Nothing in the extracted tree may be a symlink. zip preserves links; File
# Manager on many shared hosts does not, and the result is a 503.
if links="$(find "${SITE}" -type l -print)"; then
  if [[ -n "${links}" ]]; then
    echo "${links}" | sed 's/^/    /' >&2
    fail "package contains symlink(s) — they will not survive cPanel extract"
  fi
fi
ok "no symlinks anywhere in the extracted tree"

# The CodeIgniter file must be the real 3.1.x front controller, not a stub.
CI_SRC="$(cat "${SITE}/system/core/CodeIgniter.php")"
echo "${CI_SRC}" | grep -q "CI_VERSION" || fail "system/core/CodeIgniter.php does not define CI_VERSION"
echo "${CI_SRC}" | grep -Eq "CI_VERSION = '3\\.1\\." || fail "system/core/CodeIgniter.php is not CodeIgniter 3.1.x"
ok "system/core/CodeIgniter.php is CodeIgniter 3.1.x"

CI_VENDOR="$(cat "${SITE}/vendor/codeigniter/framework/system/core/CodeIgniter.php")"
echo "${CI_VENDOR}" | grep -Eq "CI_VERSION = '3\\.1\\." || fail "vendor copy of CodeIgniter.php is not 3.1.x"
ok "vendor/codeigniter/framework/system/core/CodeIgniter.php is CodeIgniter 3.1.x"

# The zip members themselves must be stored as regular files, not as
# symlink entries. unzip -l cannot tell; Python's zipfile can.
python3 - "${ZIP}" <<'PY'
import sys, zipfile

zip_path = sys.argv[1]
required = [
    "system/core/CodeIgniter.php",
    "vendor/autoload.php",
    "vendor/codeigniter/framework/system/core/CodeIgniter.php",
    "index.php",
    "deploy-verify.php",
    "database/marvysocials.sql",
]
# Unix symlink: S_IFLNK = 0o120000 in the upper 16 bits of external_attr.
S_IFMT = 0o170000
S_IFLNK = 0o120000

with zipfile.ZipFile(zip_path) as z:
    names = set(z.namelist())
    missing = [n for n in required if n not in names]
    if missing:
        sys.stderr.write("zip is missing entries:\n  " + "\n  ".join(missing) + "\n")
        sys.exit(1)
    for info in z.infolist():
        mode = (info.external_attr >> 16) & S_IFMT
        if mode == S_IFLNK:
            sys.stderr.write("zip stores a symlink: %s\n" % info.filename)
            sys.exit(1)
    ci = z.read("system/core/CodeIgniter.php").decode("utf-8", "replace")
    if "CI_VERSION = '3.1." not in ci:
        sys.stderr.write("zip member system/core/CodeIgniter.php is not CodeIgniter 3.1.x\n")
        sys.exit(1)
print("  ok  zip members are regular files (no symlink entries)")
print("  ok  zip listing contains every required framework path")
PY

# Front controller must not carry a development-server absolute path.
if grep -E '/home/[^/]+/public_html' "${SITE}/index.php" >/dev/null 2>&1; then
  fail "index.php contains a hard-coded /home/.../public_html path"
fi
ok "index.php has no host-specific /home/.../public_html path"

# Destination host must not be asked to install anything.
for banned in composer.json package.json application/seeds/Demo_seeder.php; do
  if [[ -e "${SITE}/${banned}" ]]; then
    fail "package must not contain ${banned}"
  fi
done
ok "no composer.json / package.json / demo seeder in the package"

echo
echo "  $(basename "${ZIP}") is a complete cPanel package (no Composer, no symlinks)."
