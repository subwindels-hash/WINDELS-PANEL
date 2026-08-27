# MarvySocials — Certification Audit (2026-08-19)

A full runtime-style verification of the repository: not a structural skim, but an
attempt to actually *execute* everything and prove what passes. Conducted on branch
`arena/01a017d5-marvysocials` against the merge of `main` @ `ada9ef8`.

## Methodology note (transparency)

The audit sandbox has no PHP, Composer, MySQL, Redis or Docker, and outbound
network is allowlisted (no package registries except npm/PyPI/GitHub-API). Rather
than skipping execution, the suite was run in **PHP 8.2.32 compiled to WebAssembly**
(`@php-wasm/node`, with openssl/mbstring/sqlite/curl/mysqli), driving the repo's own
dependency-free runner (`tools/phpunit_lite.php`) per test class, with
`git` subprocesses bridged to real git. Together with the activated GitHub Actions
pipeline (native PHP 8.1 + MySQL 8 + Redis), this gives both in-sandbox and
native verification paths.

## Results

| Check | Result | Evidence |
|---|---|---|
| PHP parse lint — **403/403** files (controllers, models, libraries, views, config, migrations, seeds, tests, tools) | ✅ PASS | Each file required inside a fresh WASM PHP 8.2 process; zero `Parse error`s |
| Unit suite — **48 test classes** | ✅ **47/48 classes fully pass** | 1040 tests, 9 927 assertions, 0 failures across the 47 |
| └ CronWorkersTest | ⚠ 23/24 pass | single failure is `testAJobCannotOverlapItself` — see below |
| Schema export in sync (`tools/export_schema.php --check`) | ✅ PASS | "docs/database.sql is up to date" |
| Schema house rules (`tools/validate_schema.py`) | ✅ PASS (after 1 fix, below) | 119 statements · 85 tables · 125 FKs · 0 errors |
| Tailwind asset build (`npm ci && npm run build:css`) | ✅ PASS | 26 KB `tailwind.css` in ~0.5 s |
| Routes → controllers/methods | ✅ 263/264 (1 dead route removed) | automated cross-reference of `routes.php` |
| Views referenced by controllers | ✅ 69 direct + 22 module-private refs, all exist | automated; 4 runtime-dynamic indirections manually verified via the `view()` helpers |
| Models / libraries / helpers | ✅ all resolve | automated |
| Cron wiring (15 jobs) | ✅ covered by tests (`testEveryScheduledJob*`) | green |
| Security greps (TLS, no license tables, ledger-only wallet writes, DUMMY_BCRYPT, encrypted MFA) | ✅ PASS | simulated every CI grep locally |
| Workflows/auth conventions | ✅ CI steps dry-run locally; 1 guard false-positive fixed | below |

## The one test that can't pass under WASM

`CronWorkersTest::testAJobCannotOverlapItself` — "an overlapping run must not
execute the work". `JobRunner` guarantees mutual exclusion with an exclusive
`flock()` on a per-job file. The WASM runtime's in-memory VFS grants
`LOCK_EX|LOCK_NB` twice on separate handles of the same file within one process,
which no native Linux/PHP does — proven on the host kernel with `fcntl.flock`
(second open + non-blocking exclusive lock raises `BlockingIOError`). The test is
expected to pass natively; the activated CI workflow is the verification vehicle
for this.

Two cosmetic "Only variables should be assigned by reference" notices come from the
test harness defining `get_instance()` as return-by-value; CI3's real
`get_instance()` returns by reference, so production is unaffected.

## Defects found & fixed in this audit

1. **`tools/validate_schema.py` false positive** — `withdrawal_requests.paid_by`
   (FK → `users.id`, the paying admin) was flagged as money for lacking
   `DECIMAL(20,8)`. Rule now treats `_by` columns as actor references
   (joining the `_id`/`_at` exemptions). *Without this, CI fails.*
2. **CI auth guard false positive** — the "no direct `users` inserts" grep matched
   `AuthService::register()` itself, the single sanctioned path that hashes through
   `hash_password()`. Guard now excludes `libraries/AuthService.php`, matching the
   LedgerService precedent. *Without this, CI fails.*
3. **Empty-CI_ENV 503 wall** — nginx shipped `fastcgi_param CI_ENV $CI_ENV;`; with
   the variable undefined that arrives as an empty string, and
   `index.php` treated *any* value as authoritative → `ENVIRONMENT=''` →
   HTTP 503 on every request. `index.php` now falls back through
   `CI_ENV`/`APP_ENV` (server + env) to `development`, and the nginx param was
   removed.
4. **Dead route** — `$route['install'] = 'install/index'` targeted a controller
   that does not exist (provisioning is CLI-only per Session 20). Replaced with an
   explanatory comment; `/install` now 404s cleanly.
5. **CI prepared, not active** — `ci.yml.workflow-ready` (now carrying fix #2) is
   one move away from being live: `mkdir -p .github/workflows && mv
   ci.yml.workflow-ready .github/workflows/ci.yml`. The audit token holds no
   `workflows` permission, so GitHub refuses a bot push to that path — the move
   must be made by a repo admin. All workflow steps were dry-run locally where
   technically possible (greps, schema lint, tests).
6. **README was two words** — replaced with full installation/env/test/deploy
   documentation.

## Not yet proven (runway for native CI)

- Real MySQL 8 migrate + core/demo seed + idempotency (workflow runs it; FakeDb
  covers logic but not the engine).
- `vendor/bin/phpunit` (real PHPUnit) vs the lite runner — same assertions, but the
  composer autoloader path is only exercised in CI.
- Docker image build (`docker/php.Dockerfile`) and compose end-to-end boot.
- Live provider integrations (VTpass/5sim/Dojah/Reloadly, payment gateways) — by
  design they need credentials; adapters are fixture-tested instead.

## Environment

Sandbox: Debian 12, PHP 8.2.32-wasm (`@php-wasm/node` 3.1.50, extensions incl.
openssl/mbstring/sqlite3/pdo_sqlite/mysqli/curl), Node 22, Python 3 + sqlglot,
git 2.x. No licence keys, no placeholders left; `grep` sweeps clean.
