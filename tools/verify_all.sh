#!/usr/bin/env bash
#
# verify_all.sh — run every proof this project has, in one command.
#
# DEV TOOLING ONLY. Fourteen modules of work each left behind a checker, and
# they had to be remembered and run by hand in the right order with the right
# flags. This is that order, written down and executable, so "is the panel
# still sound?" is a command rather than an afternoon.
#
# Prerequisites (see tools/devdb/README.md for the full rebuild recipe):
#   · node + the repo's npm dependencies
#   · vendor/codeigniter/framework + the system/ symlink
#   · a dev database on :3399 (with --stats-port 3400 for the performance run)
#   · the application server on :8080
#   · .env with HTTP_ALLOW_PRIVATE_HOSTS=true (the fake provider panels are
#     on localhost and SecureHttpClient blocks private hosts by default)
#
#   bash tools/verify_all.sh --admin-password '…'
#   bash tools/verify_all.sh --admin-password '…' --with-load   # + performance
#
# Exit code is the number of failed stages, so CI can gate on it.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

ADMIN_PASSWORD=""
WITH_LOAD=0
SKIP_E2E=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --admin-password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --with-load)      WITH_LOAD=1; shift ;;
    --unit-only)      SKIP_E2E=1; shift ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

PASS=0
FAIL=0
FAILED_STAGES=()

bold()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()    { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()   { FAIL=$((FAIL+1)); FAILED_STAGES+=("$1"); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }

# Run a stage, showing only its last line unless it fails.
stage() {
  local label="$1"; shift
  local out
  if out="$("$@" 2>&1)"; then
    printf '  \033[32mPASS\033[0m  %-34s %s\n' "${label}" "$(printf '%s' "${out}" | tail -n 1 | cut -c1-60)"
    PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m  %-34s\n' "${label}"
    printf '%s\n' "${out}" | tail -n 12 | sed 's/^/        /'
    FAIL=$((FAIL+1)); FAILED_STAGES+=("${label}")
  fi
}

bold "1. Static checks"
stage "PHP syntax (application/)"  node tools/php_syntax_check.mjs application tools/phpunit_lite.php
stage "JS behaviour tests"         npm run --silent test:js

bold "2. The PHP suite"
stage "phpunit-lite (full suite)"  node tools/devserver/php_run.mjs tools/phpunit_lite.php

bold "3. Packaging"
stage "production SQL is current"  node tools/devserver/php_run.mjs tools/build_production_sql.php
stage "deployment package builds"  bash tools/build_deployment_package.sh

if [[ "${SKIP_E2E}" -eq 1 ]]; then
  bold "End-to-end checks skipped (--unit-only)"
else
  if [[ -z "${ADMIN_PASSWORD}" ]]; then
    echo
    echo "  --admin-password is required for the end-to-end stages (or pass --unit-only)." >&2
    exit 2
  fi

  # A dead server turns every stage below into a phantom failure. Ask once.
  if ! curl -fsS -o /dev/null --max-time 10 http://127.0.0.1:8080/ 2>/dev/null; then
    echo
    echo "  The application server is not answering on :8080." >&2
    echo "  Start it first:  node tools/devserver/server.mjs --port 8080 --host 0.0.0.0" >&2
    echo "  (and the database: node tools/devdb/server.js --port 3399 --stats-port 3400 \\" >&2
    echo "     --db storage/devdb/marvy.sqlite)" >&2
    exit 2
  fi

  bold "4. End-to-end · the panel as a browser sees it"
  for check in smoke journey page_audit link_crawl image_audit responsive_check \
               ux_separation_check content_check; do
    stage "${check}" node "tools/devserver/${check}.mjs" \
      --admin-password "${ADMIN_PASSWORD}" --password "${ADMIN_PASSWORD}"
  done

  bold "5. End-to-end · money"
  for check in commerce_check gateway_check reconciliation_check refunds_check \
               service_recovery_check marketplace_fulfilment_check physical_order_refund_check \
               physical_product_check shop_check marketplace_bulk_check pricing_check \
               coupon_discovery_check coupon_domains_check currency_check earnings_check affiliate_withdrawal_check \
               fundsvera_check blockonomics_check; do
    stage "${check}" node "tools/devserver/${check}.mjs" \
      --admin-password "${ADMIN_PASSWORD}" --password "${ADMIN_PASSWORD}"
  done

  bold "6. End-to-end · providers, staff surfaces and security"
  for check in smm_provider_check admin_check settings_validation_check feature_flags_check \
               notifications_check support_check api_check analytics_check security_check \
               chrome_check attachment_check legal_check pin_check pin_rotation_check; do
    stage "${check}" node "tools/devserver/${check}.mjs" \
      --admin-password "${ADMIN_PASSWORD}" --password "${ADMIN_PASSWORD}"
  done

  bold "7. Deployment"
  stage "deployment_check (fresh account)" node tools/devserver/deployment_check.mjs

  if [[ "${WITH_LOAD}" -eq 1 ]]; then
    bold "8. Performance under load"
    stage "seed a year of trading"  node tools/devserver/seed_load.mjs
    stage "perf_check"              node tools/devserver/perf_check.mjs --admin-password "${ADMIN_PASSWORD}"
    stage "remove the load data"    node tools/devserver/seed_load.mjs --clean
  fi
fi

bold "Result"
printf '  %d passed, %d failed\n' "${PASS}" "${FAIL}"
if [[ "${FAIL}" -gt 0 ]]; then
  printf '\n  Failed stages:\n'
  for s in "${FAILED_STAGES[@]}"; do printf '    · %s\n' "${s}"; done
fi
echo
exit "${FAIL}"
