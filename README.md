# WINDELS PANEL

Enterprise SMM / VTU / digital-goods reseller platform. One panel sells social-media
services, airtime & data (VTU), virtual numbers, identity verification, gift cards,
and a curated marketplace — with wallets, an audited double-entry ledger, a reseller
API, and a full admin back office.

**Stack:** PHP ≥ 7.4 / CodeIgniter 3.1.13 · MySQL 8 (or MariaDB) · Redis 7 ·
Tailwind CSS (PHP-rendered views — no JS framework) · Docker Compose.

---

## Table of contents

1. [Architecture](#architecture)
2. [Requirements](#requirements)
3. [Quick start (Docker)](#quick-start-docker)
4. [Local development (no Docker)](#local-development-no-docker)
5. [Database: migrations & seeds](#database-migrations--seeds)
6. [Cron / background workers](#cron--background-workers)
7. [Testing](#testing)
8. [CI](#ci)
9. [Configuration reference](#configuration-reference)
10. [Security model](#security-model)
11. [Health checks & preflight](#health-checks--preflight)
12. [Module map](#module-map)
13. [Repository layout](#repository-layout)

---

## Architecture

- **Classic CI3 monolith.** PHP controllers render server-side views; Tailwind
  supplies utility CSS (`assets/css/tailwind.css`, built by npm) layered over the
  committed design system (`assets/css/design-system.css`, which makes the panel
  usable even before the asset build runs).
- **Domain services** live in `application/libraries/` (OrderService, LedgerService,
  PaymentService, GiftcardService, MarketplaceService, …). Providers (SMM panels,
  VTpass, 5sim, Dojah, Reloadly) sit behind adapter interfaces with mock adapters
  for development and tests.
- **Money** is `DECIMAL(20,8)` everywhere and moves only through
  `LedgerService` — wallet balances are rows plus ledger entries, never raw
  increments. The schema linter and CI both enforce this.
- **CI3 conventions:** controllers in `application/controllers/{,admin,dashboard}`,
  models in `application/models/`, views in `application/views/`.

## Requirements

| Component | Version |
|---|---|
| PHP | ≥ 7.4 (8.1/8.2 recommended) with `pdo_mysql`, `mysqli`, `curl`, `mbstring`, `zip`, `bcmath`, `intl`, `gd`, `redis` |
| MySQL | 8.0+ (MariaDB 10.6+ works) |
| Redis | 7.x |
| Composer | 2.x |
| Node.js | 18+ (asset build only) |

## Quick start (Docker)

```bash
cp .env.example .env          # then edit: at minimum APP_URL, DB_*, ENCRYPTION_KEY
docker compose up -d --build
docker compose exec app php index.php migrate
docker compose exec app php index.php seed core       # admin user, roles, settings
docker compose exec app php index.php seed demo       # optional — demo customers/orders
```

- Panel: <http://localhost:8080> · Mailhog: <http://localhost:8025> ·
  MinIO console: <http://localhost:9001>
- The `app` container refuses to serve traffic until `deploy check`
  (the Preflight suite) passes — see [Health checks & preflight](#health-checks--preflight).
- A dedicated `cron` container runs the crontab; the web container never runs jobs.

> **Demo data never runs in production.** `seed demo` aborts when
> `APP_ENV=production` (override only with `--force`). The demo password comes
> from `DEMO_PASSWORD`, never a hardcoded default.

## Local development (no Docker)

```bash
composer install
npm ci && npm run build:css
cp .env.example .env          # point DB_HOST/REDIS_HOST at localhost services
php index.php deploy storage  # create storage/ + cache dirs
php index.php migrate
php index.php seed core
php -S localhost:8000         # or your vhost of choice
```

## Database: migrations & seeds

```bash
php index.php migrate            # to latest
php index.php migrate status     # pending/applied list
php index.php migrate fresh      # DANGER: drop everything and rebuild (dev only)
php index.php seed core          # idempotent baseline (admin, RBAC, settings, payment methods)
php index.php seed demo          # idempotent demo tenancy (refuses APP_ENV=production)
php index.php seed list          # what seeders exist
```

`docs/database.sql` is the canonical exported schema; it is regenerated with
`composer schema:export` and CI fails the build if it drifts from the migrations
(`composer schema:check`). `python3 tools/validate_schema.py` additionally lints
house rules (InnoDB/utf8mb4, `DECIMAL(20,8)` money, `CHAR(26)` ULID public ids,
no `TIMESTAMP`, …).

## Cron / background workers

Install `cron/crontab.example` (the Docker image does this for you). Every job runs
CLI-only through the `cron` controller inside a `JobRunner` harness that provides:

- **mutual exclusion** (an exclusive `flock` per job — a slow run makes the next
  tick *skip*, never pile up),
- **run tracking** (`job_runs` rows: RUNNING → SUCCESS/FAILED with counts/duration),
- **failure containment** (a throwing worker is recorded, never wedges the lock).

`php index.php cron status` shows run history. Jobs: dripfeed, order_status,
vtu_status, numbers_status, giftcard_codes, marketplace_release, subscriptions,
provider_health, refill_status, payment_reconciliation, email_queue, analytics,
provider_sync, affiliate_payouts, identity_purge.

## Testing

```bash
composer install
vendor/bin/phpunit --testdox        # full suite (tests/unit)

# No composer / no network? The dependency-free runner executes the same suite:
php tools/phpunit_lite.php          # or a single class: php tools/phpunit_lite.php SchemaTest
```

The suite (≈1000 tests) runs entirely in-memory through `tests/_support/FakeDb.php`,
which is built from the real migration DDL — so seeders and services execute and
their SQL surface is verified without a MySQL server. HTTP-provider adapters are
tested against recorded fixtures (`tests/fixtures/**`).

## CI

The full workflow is prepared in **`ci.yml.workflow-ready`**. Activate it (a
token/App that may touch workflow files is required — GitHub enforces the
`workflows` permission on this path):

```bash
mkdir -p .github/workflows && mv ci.yml.workflow-ready .github/workflows/ci.yml
git add -A && git commit -m "Enable CI" && git push
```

Once active it runs on every push/PR: PHPUnit, deployment preflight guard checks,
schema drift + schema lint, a real MySQL migrate + core/demo seed + seed-idempotency
run, demo-seed production refusal, and source guards (no license artifacts, no
insecure TLS, wallet mutations only via LedgerService, hashed passwords,
MFA-secret encryption).

## Configuration reference

All runtime config is environment-driven (`.env`, loaded by vlucas/phpdotenv).
See `.env.example` for the annotated list. Notables:

| Variable | Purpose |
|---|---|
| `APP_ENV` | `development` / `testing` / `production` |
| `APP_URL` | canonical public URL (used in emails, webhooks) |
| `ENCRYPTION_KEY` | 64-hex master key for AES-256-GCM at-rest encryption (provider keys, MFA secrets). **Preflight refuses production without it.** |
| `DB_*` | MySQL connection |
| `REDIS_*` | cache/rate-limits/sessions |
| `STORAGE_*` | S3-compatible object storage (MinIO locally) |
| `SMTP_*` / `MAIL_*` | outbound mail |
| `PAYSTACK_*`, `STRIPE_*`, `FLUTTERWAVE_*`, … | payment gateways |
| `VTPASS_*`, `FIVESIM_*`, `DOJAH_*`, `RELOADLY_*` | fulfilment providers |
| `DEMO_MODE` / `DEMO_PASSWORD` | demo tenancy controls |

## Security model

- Passwords: bcrypt via `AuthService::hash_password`; anti-enumeration dummy
  verify on unknown accounts; MFA secrets encrypted at rest (AES-256-GCM).
- RBAC: roles → permissions enforced in controllers (`require_perm`) and hidden
  from navigation; staff permission editor under admin.
- Ledger integrity: wallets change only through `LedgerService` (CI-enforced);
  money is `DECIMAL(20,8)`; every mutation leaves an audit row.
- Webhooks are signature-verified per gateway; outbound HTTP goes through
  `SecureHttpClient` (TLS verify always on, private-IP egress blocked unless
  `HTTP_ALLOW_PRIVATE_HOSTS=1` for local dev).
- TOTP MFA, rate limiting, blacklist, audit log, encrypted provider credentials.
- Customer impersonation is explicit, permission-gated, time-boxed and fully
  audited (`docs/customer-impersonation.md`).

## Health checks & preflight

- `GET /health`, `/health/live`, `/health/ready` — liveness/readiness for
  load balancers (bypassed from auth in nginx).
- `php index.php deploy check` — **Preflight**: DB reachable, migrations applied,
  `ENCRYPTION_KEY` present and not the placeholder, writable storage, bcrypt cost,
  MFA encryption round-trip, no demo data in production… The Docker `app`
  container runs it before `php-fpm` starts.

## Module map

Customer panel: services catalogue, new/mass orders, drip-feed, subscriptions,
wallet (deposits via payment gateways), VTU (airtime/data/cable/electricity/
education), virtual numbers, identity verification (BVN/NIN), gift cards,
marketplace (buy + sell with delivery/reveal/disputes), withdrawals, referrals,
tickets, notifications, API keys, account security (MFA, sessions).

Admin: dashboard/analytics, orders & operations (refills/cancellations),
services + catalogue management, pricing/price groups, providers + sync/health,
payments, withdrawals, giftcards, VTU, numbers, identity reviews, marketplace
moderation, users/wallets, staff & RBAC, affiliates, content/blog/media,
settings, system (audit, blacklist, flags, categories), impersonation.

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
docker/               php.Dockerfile, nginx.conf, mysql init
docs/                 database.sql (canonical schema), session logs, deployment,
                      customer-impersonation, checkpoint audits
tests/                unit suite, FakeDb, provider fixtures
tools/                phpunit_lite (offline runner), export_schema, validate_schema
index.php             front controller (.env boot + ENVIRONMENT detection)
```
