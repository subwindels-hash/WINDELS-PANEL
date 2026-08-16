# WINDELS PANEL — Checkpoint 01 (REVISED): Foundation Review — PHP MVC / CodeIgniter 3.x

> **Date:** 2026-08-16 (revised)
> **Branch:** `arena/01a00cd1-windels-panel`
> **Stack:** **CodeIgniter 3.x + MySQL/MariaDB + PHP 8.1 + Redis + S3/R2 + Tailwind (PHP views)**
> **Required action:** REVIEW & APPROVE before implementation
> This revision **supersedes** `docs/checkpoint-01/` (Node stack withdrawn per correction).

---

## What Changed

The original checkpoint assumed a Node.js monorepo (Next.js + NestJS + Prisma + PostgreSQL). **Per clarification this is a traditional PHP MVC SMM panel built around CodeIgniter 3.x with MySQL/MariaDB.** All five artifacts have been rewritten for CI3 conventions. No Prisma, no NestJS, no Next.js.

| # | Revised File | What it now covers |
|---|---|---|
| 1 | [01-folder-structure.md](./01-folder-structure.md) | CI3 `application/` layout: `controllers/` (Home, Auth, Dashboard/*, Admin/*, Api_v1, Cron, Webhooks), `models/`, `libraries/` (SecureHttpClient, ProviderAdapter, PricingService, LedgerService, Gateways), `views/homepages/{aurora,nexus,pulse}`, `migrations/`, `assets/` (Tailwind PHP), `cron/` (CLI) |
| 2 | [02-database-schema.md](./02-database-schema.md) | Full **MySQL** DDL: 9 CI3 migrations (identity, wallets/ledger, services, providers, orders, refill/cancel/drip/subscription, payments, support/content, security/system). `DECIMAL(20,8)`, ULID `public_id`, InnoDB, `utf8mb4`, `FULLTEXT` |
| 3 | [03-module-dependency-map.md](./03-module-dependency-map.md) | CI3 MVC graph: Controllers → Libraries → Models → DB; library catalog (SecureHttpClient, ProviderAdapterFactory, LedgerService, etc.); **cron/CLI worker map** (no BullMQ — Redis `SET NX` lock, `php index.php cron <job>`) |
| 4 | [04-api-endpoint-map.md](./04-api-endpoint-map.md) | CI3 `config/routes.php` + `Api_v1` reseller API (`/api/v1/*` with `X-Api-Key`), webhooks (`/webhook/{gateway}`), customer `/dashboard/*`, admin `/admin/*`, health checks |
| 5 | [05-homepage-wireframes.md](./05-homepage-wireframes.md) | Same three genuinely different designs — **now as CI3 PHP views** (`Home::index()` switches `views/homepages/{aurora,nexus,pulse}/*` via `settings.active_homepage`), with preview iframe |

Original Node artifacts remain in `docs/checkpoint-01/` for history and are considered **superseded**.

---

## Stack in One Line

```
Browser → Nginx → PHP-FPM (CodeIgniter 3.x) → MySQL/MariaDB + Redis + S3/R2
                          ↘ Cron CLI (php index.php cron *) → same Libraries/Models
```

* **PHP:** 8.1, `ext-pdo_mysql`, `ext-curl` (TLS verify ON), `ext-openssl`, `ext-bcmath`, `predis/predis`, `ramsey/ulid`, `aws-sdk-php`
* **DB:** MySQL 8 / MariaDB 10.6, `InnoDB`, `utf8mb4_unicode_ci`, UTC `DATETIME`
* **Cache/Queue:** Redis (cache `provider:{id}:services`, rate limiting, distributed locks, optional queue)
* **Storage:** S3-compatible (AWS SDK) for avatars/blog/ticket attachments
* **Frontend:** PHP views + Tailwind CSS (CLI build) + vanilla JS + Lucide + Chart.js

## Feature Parity & Security (unchanged in intent, new implementation)

* **ProviderAdapter** (`ProviderAdapterInterface` + `StandardSmmAdapter`) — no provider code in controllers (§19).
* **Ledger** (`wallets` → `wallet_transactions` → `ledger_entries`, `DECIMAL(20,8)`, `SELECT ... FOR UPDATE`, bcmath) — never `users.balance -= amount` (§24–25, §56).
* **State machine** + `order_status_history` with source (§26/29).
* **Idempotency** (`idempotency_keys` + `uq_gateway_event` on webhooks) (§64).
* **Cron replacement** (§66): no PHP cron URLs on web; CLI `Cron.php` (`is_cli()` guard) + real `crontab` + Redis locks.
* **TLS** (§62): `SecureHttpClient` enforces `CURLOPT_SSL_VERIFYPEER=true`, `SSL_VERIFYHOST=2` in production — never `false`.
* **No license** (§81): no `Envato` / `LICENSE_SERVER` / purchase code; installer has no license step; `APP_ENV=demo` gates demo via `feature_flags` only.

## Open Questions for Reviewer

1. **PHP version:** 8.1 vs 7.4 (CI3 supports both; 8.1 recommended for Argon2id + typed libs) — confirm.
2. **Session driver:** CI `database` vs `redis` — default to `redis` for scale?
3. **Default homepage:** `AURORA` — confirm.
4. **Gateway priority for MVP:** Stripe + PayPal + Paystack vs full six?

## How to Approve

Reply "Approved — PHP MVC" (or comment on specific revised artifact). On approval, implementation starts at **Session 01 — Foundation** (CI3 skeleton, composer, MySQL, Redis, Docker, migrations, CI).
