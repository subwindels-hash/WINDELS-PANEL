# WINDELS PANEL

Enterprise SMM / VTU / digital-goods reseller platform. One panel sells social-media
services, airtime & data (VTU), virtual numbers, identity verification, gift cards,
and a curated marketplace — with wallets, an audited double-entry ledger, a reseller
API, and a full admin back office.

**Stack:** PHP ≥ 7.4 / CodeIgniter 3.1.13 · MySQL 8 (or MariaDB) · Redis 7 ·
Tailwind CSS (PHP-rendered views — no JS framework) · Docker Compose.

> **WINDELS-PANEL is a traditional PHP MVC enterprise reseller and commerce
> platform built on CodeIgniter 3.x, using MySQL/MariaDB as its primary
> database. Redis is used for supporting caching or background-processing
> functionality where required. The application is served through PHP-FPM and
> Nginx. Node.js/npm is used only for optional frontend asset compilation
> (Tailwind CSS) and is not part of the application's backend architecture.**

**The wallet is a platform spending balance.** WINDELS-PANEL does not support
customer wallet withdrawals: customers add funds and spend that balance on
services and supported products within the WINDELS-PANEL ecosystem.

---

## Table of contents

1. [Architecture](#architecture)
2. [Requirements](#requirements)
3. [Setup](#setup)
4. [Configuration reference](#configuration-reference)
5. [Database: migrations & seeds](#database-migrations--seeds)
6. [Cron / background workers](#cron--background-workers)
7. [Health checks & observability](#health-checks--observability)
8. [Development](#development)
9. [Testing](#testing)
10. [CI/CD](#cicd)
11. [Security model](#security-model)
12. [Production deployment](#production-deployment)
13. [External integrations](#external-integrations)
14. [Module map](#module-map)
15. [Repository layout](#repository-layout)
16. [Documentation index](#documentation-index)

---

## Architecture

- **Classic CI3 monolith.** PHP controllers render server-side views; Tailwind
  supplies utility CSS (`assets/css/tailwind.css`, built by npm) layered over the
  committed design system (`assets/css/design-system.css`, which makes the panel
  usable even before the asset build runs).
- **Domain services** live in `application/libraries/` (OrderService, LedgerService,
  PaymentService, GiftcardService, MarketplaceService, …). Providers (SMM panels,
  VTpass, 5sim, Dojah, Reloadly) sit behind adapter interfaces with mock adapters
  for development and tests — **mock adapters are refused outright in production**
  (deploy preflight fails while an active provider row uses one, and
  `Provider_manager` refuses to build one at runtime).
- **Money** is `DECIMAL(20,8)` everywhere and moves only through
  `LedgerService` — wallet balances are rows plus ledger entries, never raw
  increments. The schema linter and CI both enforce this.
- **CI3 conventions:** controllers in `application/controllers/{,admin,dashboard}`,
  models in `application/models/`, views in `application/views/`; environment
  comes from `.env` via vlucas/phpdotenv (`index.php` boot).

## Requirements

| Component | Version | Purpose |
|---|---|---|
| PHP | ≥ 7.4 (8.1/8.2 recommended) | application runtime. Extensions: `pdo_mysql`, `mysqli`, `curl`, `mbstring`, `zip`, `bcmath`, `intl`, `gd`, `redis` |
| MySQL | 8.0+ (MariaDB 10.6+ works) | primary database |
| Redis | 7.x | cache, rate limits, sessions, job locks |
| Composer | 2.x | PHP dependencies (`codeigniter/framework`, `aws/aws-sdk-php`, `guzzle`, `phpdotenv`, `predis`, `ramsey/uuid`, …) |
| Node.js + npm | 18+ / 9+ | **frontend asset build only** (Tailwind CLI) — never part of the runtime |
| Docker | 24+ | container runtime for the full stack |
| Docker Compose | v2 (bundled with Docker) | orchestrates app, cron, nginx, mysql, redis, mailhog, minio |

## Setup

### First boot with Docker (recommended)

Every command a new developer needs, end to end:

```bash
git clone <repo-url> WINDELS-PANEL && cd WINDELS-PANEL

cp .env.example .env        # fill at minimum: APP_URL, ENCRYPTION_KEY, DB_*
                            # generate the key: php -r "echo bin2hex(random_bytes(32)),PHP_EOL;"

composer install            # PHP dependencies
npm install                 # asset build dependencies (frontend only)
npm run build:css           # compile Tailwind → assets/css/tailwind.css

docker compose build        # build the PHP image
docker compose up -d mysql redis
# first-boot tasks run as one-off containers: the web container's deploy gate
# correctly refuses to serve an unmigrated database, so migrate/seed first.
docker compose run --rm app php index.php deploy storage
docker compose run --rm app php index.php migrate       # database schema (19 migrations)
docker compose run --rm app php index.php seed core     # admin user, roles, settings
docker compose run --rm app php index.php seed demo     # demo tenancy (dev only)
docker compose up -d        # app + cron + nginx + mailhog + minio join
docker compose ps           # every health-checked service must reach 'healthy'
docker compose exec app php index.php deploy check      # deployment gate: exit 0
curl -fsS http://localhost:8080/health/ready            # must answer "ready"
```

Then: panel <http://localhost:8080> · MailHog <http://localhost:8025> · MinIO
<http://localhost:9001>. The `app` container runs `deploy storage` and
`deploy check` before php-fpm starts — it refuses to serve traffic while the
deployment is unsafe. The separate `cron` container installs
`cron/crontab.example`; the web container never runs jobs.

> **Demo data never runs in production.** `seed demo` aborts when
> `APP_ENV=production` (override only with `--force`). The demo password comes
> from `DEMO_PASSWORD`, never a hardcoded default.

### Local development (no Docker)

Prerequisites: PHP 8.1+, Composer, Node 18+, MySQL 8, Redis 7 running locally.

```bash
composer install
npm ci && npm run build:css
cp .env.example .env          # point DB_HOST/REDIS_HOST at localhost services
php index.php deploy storage  # create storage/ + cache dirs
php index.php deploy check    # must exit 0
php index.php migrate
php index.php seed core
php -S localhost:8000         # or your vhost of choice
```

## Configuration reference

All runtime config is environment-driven (`.env`, loaded by vlucas/phpdotenv);
`.env.example` is the annotated development copy and `.env.production.example`
the production template. Notables:

| Variable | Purpose |
|---|---|
| `APP_ENV` / `CI_ENV` | `development` / `testing` / `production` (CI_ENV wins) |
| `APP_URL` | canonical public URL (emails, webhooks, preflight HTTPS gate) |
| `ENCRYPTION_KEY` | 64-hex master key for AES-256-GCM at-rest encryption (provider keys, MFA secrets, gift card codes). **Preflight refuses production without it.** |
| `APP_KEY` | signing key for sessions/signed tokens |
| `DB_*` / `MYSQL_ROOT_PASSWORD` | MySQL connection / superuser (compose) |
| `REDIS_*` | cache / rate-limits / sessions / job locks (`REDIS_PASSWORD` required in production) |
| `STORAGE_*` | S3-compatible object storage (MinIO in dev) |
| `SMTP_*` / `MAIL_*` | outbound mail (MailHog in dev) |
| `PAYSTACK_*`, `STRIPE_*`, `FLUTTERWAVE_*`, `RAZORPAY_*`, `PAYPAL_*`, `COINPAYMENTS_*` | payment gateways |
| `VTPASS_*`, `FIVESIM_*`, `DOJAH_*`, `RELOADLY_*` | fulfilment providers |
| `DEMO_MODE` / `DEMO_PASSWORD` | demo tenancy controls |
| `HTTP_ALLOW_PRIVATE_HOSTS` | SSRF egress override for self-hosted dev providers |

## Database: migrations & seeds

```bash
php index.php migrate            # to latest (currently 018)
php index.php migrate status     # pending/applied list
php index.php migrate fresh      # DANGER: drop everything and rebuild (dev only)
php index.php seed core          # idempotent baseline (admin, RBAC, settings, payment methods)
php index.php seed demo          # idempotent demo tenancy (refuses APP_ENV=production)
php index.php seed list          # what seeders exist
```

Migrations run in strict order, are safe to re-run, and cover (in order):
identity/RBAC, wallet/ledger, catalogue/services, providers, orders,
advanced orders (mass/drip-feed/subscriptions), payments, support/content,
affiliates, VTU, virtual numbers, identity/KYC, gift cards, marketplace
catalogue and escrow, and the `018`/`019` withdrawal + marketplace-vendor
removal retrofits.

`docs/database.sql` is the canonical exported schema; regenerate with
`composer schema:export` — CI fails if it drifts (`composer schema:check`).
`python3 tools/validate_schema.py` lints house rules (InnoDB/utf8mb4,
`DECIMAL(20,8)` money, `CHAR(26)` ULID public ids, no `TIMESTAMP`, …).

## Cron / background workers

Install `cron/crontab.example` (the Docker `cron` image does this for you).
Every job runs CLI-only through the `cron` controller inside a `JobRunner`
harness that provides:

- **mutual exclusion** — an exclusive `flock` per job; a slow run makes the
  next tick *skip*, never pile up,
- **run tracking** — `job_runs` rows: RUNNING → SUCCESS/FAILED with counts/duration,
- **failure containment** — a throwing worker is recorded, never wedges the lock; repeated
  failure is visible in `php index.php cron status` and the log.

Jobs: `dripfeed`, `order_status`, `vtu_status`, `numbers_status`,
`giftcard_codes`, `marketplace_release`, `subscriptions`, `provider_health`,
`refill_status`, `payment_reconciliation`, `email_queue`, `analytics`,
`provider_sync`, `affiliate_payouts`, `identity_purge`.

## Health checks & observability

| Endpoint | Meaning | Use |
|---|---|---|
| `GET /health`, `/health/live` | process is up; touches nothing | container liveness probe |
| `GET /health/ready` | DB answers, schema at expected version, log dir writable, Redis reachable | load balancer / readiness gate (503 to remove from rotation) |

- `php index.php deploy check` — **Preflight**: encryption key present and not
  the placeholder, required secrets set, DB reachable, migrations applied,
  writable storage, secure session cookies, env consistency, demo mode, and
  **no active mock providers**. The `app` container runs it before php-fpm
  starts; CI runs it as a negative test (must *fail* without ENCRYPTION_KEY).
- Errors, cron outcomes and provider/payment failures are written to
  `storage/logs` with request ids; identity lookups are logged under a
  redacted marker (`identity-redacted`) and gateway secrets are never logged —
  secret-bearing columns are encrypted at rest and views are tested not to
  render them.
- nginx access/error logs rotate via the compose `json-file` limits in
  production; ship them to your aggregator with a standard collector.

## Development

```
application/controllers/         web entry points
  dashboard/                     customer panel (17 controllers)
  admin/                         back office (21 controllers, RBAC-gated)
  Api_v1.php                     reseller REST API (API-key auth, scopes, IP allowlists)
  Cron.php Deploy.php Health.php Migrate.php Seed.php Webhooks.php
application/models/              query objects (one per aggregate table)
application/libraries/           domain services + provider adapters
  Provider_manager.php           family registry → adapter (SMM/VTU/NUMBER/IDENTITY/GIFTCARD)
  *Adapter.php                   StandardSmmAdapter, VtpassAdapter, FiveSimAdapter,
                                 DojahAdapter, ReloadlyAdapter + MOCK_* offline doubles
  SecureHttpClient.php           TLS-verify + SSRF egress guard for every outbound call
  TransactionEngine.php          atomic wallet-debit + ledger + order commit
  LedgerService.php              the ONLY path that mutates wallet balances
application/seeds/               Core_seeder (baseline), Demo_seeder (demo tenancy)
application/migrations/          001→019, sequential, exported to docs/database.sql
```

Two rules the tests enforce:

1. **Money never bypasses LedgerService** (CI greps for `update('wallets'`).
2. **Provider calls go out through SecureHttpClient** (TLS verification never
   disabled, private destinations blocked unless explicitly allowed).

## Testing

```bash
composer install
vendor/bin/phpunit --testdox        # full suite: tests/unit (≈1,100 tests)

# No composer / no network? The dependency-free runner executes the same suite:
php tools/phpunit_lite.php          # or one class: php tools/phpunit_lite.php SchemaTest
```

The suite runs entirely in-memory through `tests/_support/FakeDb.php`, which
is built from the real migration DDL — seeders and services execute and their
SQL surface is verified without a MySQL server. HTTP provider adapters are
tested against recorded fixtures (`tests/fixtures/**`). Integration flows
(order → ledger → provider, payment → crediting, marketplace escrow, refunds,
referrals) run through `tests/_support/IntegrationHarness.php`.

## CI/CD

The complete pipeline ships in **`ci.yml.workflow-ready`** (two jobs,
31 steps, summarised below). One maintainer action activates it:

```bash
mkdir -p .github/workflows && git mv ci.yml.workflow-ready .github/workflows/ci.yml
git commit -m "Enable CI" && git push
```

> GitHub requires the `workflows` permission on the token that writes
> `.github/workflows/**`; the automation token that produced this repository
> snapshot intentionally lacks it, so activation is a deliberate one-time
> maintainer step (a standard repo push works). Once moved, the workflow runs
> on every push and pull request.

Stages, in order:

1. **Dependency installation** — `composer install`, `composer validate`, `npm ci`
2. **Code quality** — `php -l` over every file; Tailwind asset build
3. **Static analysis** — PHPStan (level 1, non-blocking until a baseline is
   curated), schema linter (`tools/validate_schema.py`)
4. **Schema validation** — `docs/database.sql` must be in sync with migrations
5. **Migration validation** — full migrate against a real MySQL 8 service,
   `migrate status`, and a re-run proving idempotency
6. **Seeder validation** — `seed core` + `seed demo` twice (idempotency), and
   proof the demo seed is refused under `APP_ENV=production`
7. **Tests** — full PHPUnit suite
8. **Security checks** — no license artifacts, TLS verify never disabled,
   money mutates only via LedgerService, withdrawals stay removed, mock
   providers guarded for production, passwords hashed, MFA secrets encrypted,
   no RBAC stubs
9. **Deployment safety** — production preflight must FAIL without
   `ENCRYPTION_KEY` (the guard is a negative test)

…and a second **`docker`** job that builds every image, boots the full stack,
runs the one-off migrate/seed, proves `deploy check` exits 0, polls
`/health/ready` to green, asserts every health-checked container is healthy,
runs `cron status`, proves the production compose refuses to render without
secrets, and tears down.

## Security model

- Passwords: bcrypt via `AuthService::hash_password`; anti-enumeration dummy
  verify (`DUMMY_BCRYPT`) on unknown accounts; login throttling; session
  regeneration after authentication and privilege changes; logout is POST-only
  with CSRF verification.
- RBAC: roles → permissions enforced server-side in every controller
  (`require_perm`) and middleware (`MY_Controller`, admin prefix guard).
  Direct-URL access without the permission yields 403 — vertical and
  horizontal privilege escalation are covered by security tests across every
  admin module.
- Ledger integrity: wallets change only through `LedgerService` (CI-enforced);
  money is `DECIMAL(20,8)`; every mutation leaves an audit row; payment
  crediting is idempotent (unique transaction references, atomic
  row-claim-then-credit) so duplicate callbacks and webhooks never
  double-credit; negative balances are structurally impossible.
- Webhooks are signature-verified per gateway; invalid signatures are rejected;
  duplicate events return as already-processed; retryable internal failures
  answer 503 so the gateway retries.
- Outbound HTTP goes through `SecureHttpClient`: TLS verification always on,
  private-IP egress blocked unless `HTTP_ALLOW_PRIVATE_HOSTS=1`.
- TOTP MFA (secrets AES-256-GCM encrypted at rest); rate limiting; blacklist;
  audit log; encrypted provider credentials (authenticated decryption — a
  failed decrypt is never silently treated as plaintext).
- Customer impersonation is explicit, permission-gated, time-boxed and fully
  audited (`docs/customer-impersonation.md`).
- `.env` and real secrets are gitignored and blocked by review; CI greps the
  tree for license artifacts and insecure TLS overrides.

## Production deployment

Use **`docker-compose.production.yml`**, which exists *because* the dev stack
is unsafe to ship: MailHog instead of real SMTP, MinIO instead of managed
object storage, weak in-file passwords, source hot-mounted. The production
file has **no weak defaults** — every credential is `${VAR:?}`-required, only
nginx publishes ports, TLS terminates at nginx
(`docker/nginx/nginx.prod.conf`), and JSON logs rotate on every service.

```bash
cp .env.production.example .env   # fill every REQUIRED value (or inject from a secret manager)
docker compose -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml up -d
docker compose -f docker-compose.production.yml ps    # all 'healthy'
docker compose -f docker-compose.production.yml exec app php index.php migrate
docker compose -f docker-compose.production.yml exec app php index.php seed core
curl -fsS https://panel.example.com/health/ready      # must answer ready
```

Operator checklist:

| Area | Production rule |
|---|---|
| Environment variables | from `.env.production.example`; `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL` |
| Secrets | secret manager (1Password/SSM/Vault). **Never** `root`, `windels_secret`, `minioadmin` — preflight fails known defaults |
| Database | MySQL 8 / MariaDB with PITR (binlog, 7 days in the prod compose); managed RDS/Aurora is the low-risk option |
| Redis | `--requirepass` set by compose; sessions carry `?auth=`; internal network only |
| Object storage | managed S3/R2 bucket, TLS, least-privilege key; no MinIO |
| Queue / cron | one `cron` container per fleet; jobs lock per-instance, idempotent and ledger-safe to replay |
| Nginx | TLS 1.2/1.3, HSTS, HTTP→HTTPS redirect, `/health*` allowed unauthenticated |
| SSL | docker/nginx/certs/ (bind-mount from certbot/acme.sh) or terminate at a load balancer |
| Backups | **docs/backups.md** — mysqldump nightly + binlog PITR + bucket sync + key escrow + quarterly restore rehearsal |
| Monitoring | poll `/health/ready`; alert on cron `FAILED` rows in `php index.php cron status`; watch Provider health in admin |
| Logs | app/cron → `storage/logs` (rotate), nginx & containers → journald/aggregator. Sensitive values never logged (redacted) |

## External integrations

All adapters live behind `Provider_manager` (family registry) or the gateway
interface; credentials are env-driven and provider keys are encrypted at rest.
Sandbox vs production endpoints are chosen by the base URL env var.

| Integration | Adapter | Sandbox → Production | Keys |
|---|---|---|---|
| SMM panels (any JAP-format API) | `StandardSmmAdapter` | n/a — the vendor decides | per-provider `api_url`/`api_key` (Admin → Providers) |
| VTU: airtime/data/cable/electricity/education | `VtpassAdapter` | `sandbox.vtpass.com` → `vtpass.com` | `VTPASS_*` |
| Virtual numbers / OTP | `FiveSimAdapter` | `FIVESIM_BASE_URL` | `FIVESIM_API_KEY`, `FIVESIM_RATE_TO_BASE` |
| KYC / identity (NIN, BVN) | `DojahAdapter` | `sandbox.dojah.io` → `api.dojah.io` | `DOJAH_*` (billable per lookup) |
| Gift cards | `ReloadlyAdapter` | `giftcards-sandbox.reloadly.com` → `giftcards.reloadly.com` | `RELOADLY_*` (OAuth2, token cached, audience pinned to base URL) |
| Card/bank payments | Paystack, Stripe, Flutterwave, Razorpay, PayPal, CoinPayments, manual bank transfer | per-gateway dashboard keys | `*_SECRET_KEY`, webhook secrets — idempotent, signature-verified |
| Object storage | AWS S3 / Cloudflare R2 (dev: MinIO) | `STORAGE_ENDPOINT` | `STORAGE_*` |
| Email | SMTP (dev: MailHog) | any provider | `SMTP_*` |

**External transactions that need live vendor credentials (VTpass purchase,
5sim order, Dojah lookup, Reloadly order, Paystack/Stripe charge) cannot be
executed from this repository's CI** — they are fixture-tested here and must
be smoke-tested against each vendor's sandbox after the first production
deploy with the keys from the table above.

## Module map

Customer panel: register/login/logout (POST + CSRF) with password reset,
email verification, MFA and session security · services catalogue with filter,
order detail, refill request, cancellation · mass orders, drip-feed,
subscriptions · wallet deposits (gateway server-side verified, ledgered,
refunds) — the balance pays for purchases; there are no customer
withdrawals/cash-outs · VTU (airtime/data/cable/electricity/education with
receipts) · virtual numbers + OTP · identity verification (BVN/NIN) · gift
cards (purchase → paid → delivered → reveal; reveal authorization and audit)
· marketplace storefront (browse/search/buy, secure delivery, reveal,
disputes — the platform is the only seller; there is NO vendor/seller
system) · referrals (tracking +
commission accounting) · tickets (create/reply/close/reopen + notifications)
· API keys · account security.

Admin: dashboard/analytics, orders & operations (refills/cancellations),
services + catalogue, pricing/price groups, providers + sync/health
(authentication/balance/timeout/retry handling per adapter), payments, gift
cards inventory/collections, VTU, numbers, identity reviews, marketplace
operations (create/price/promote/feature/categorise/publish platform-owned
listings, fulfil escrow orders, resolve disputes, moderate listings), users/wallets (manual
credit/debit is ledgered), staff & RBAC, affiliates, content/blog/media,
settings, system (audit log, blacklist, flags), impersonation.

Public: homepage themes (aurora/nexus/pulse), services/pricing pages, blog,
FAQ/legal pages, styleguide at `/design-system`.

Reseller API: JSON API v1 (`Api_v1` controller) with API-key auth, per-key IP
allowlists, scopes and rate limits; documented at `/api/docs`.

## Repository layout

```
application/          CI3 app: controllers | models | libraries (domain services)
                      views | config | migrations | seeds | core (MY_Controller)
assets/               css (app.css→tailwind.css, design-system.css), js, uploads
cron/crontab.example  15 scheduled jobs (installed by the cron container)
docker/               php.Dockerfile, nginx + nginx.prod conf, nginx/certs, mysql init
docker-compose.yml            development stack (MailHog, MinIO, dev creds)
docker-compose.production.yml production stack (required secrets, TLS, no dev services)
ci.yml.workflow-ready  the CI pipeline (one `git mv` activates it — see CI/CD)
docs/                 database.sql (canonical schema), deployment, backups,
                      impersonation, certification reports, session logs
tests/                unit suite, FakeDb, IntegrationHarness, provider fixtures
tools/                phpunit_lite (offline runner), export_schema, validate_schema
index.php             front controller (.env boot + ENVIRONMENT detection)
```

## Documentation index

| Document | Purpose |
|---|---|
| `docs/deployment.md` | operations runbook: preflight reference, runtime directories, upgrade flow |
| `docs/backups.md` | backup & disaster-recovery plan + restore rehearsal |
| `docs/database.sql` | canonical exported schema (19 migrations) |
| `docs/certification-audit-2026-08-19.md` | certification audit of the platform |
| `docs/final-certification-2026-08-19.md` | final production-certification report (this release) |
| `docs/customer-impersonation.md` | support-impersonation security model |
| `docs/session-*.md` | per-feature build records (history) |
