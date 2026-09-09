# MarvySocials — Session 02: Database (Migrations + Seed)

> Implements Checkpoint 01 / Artifact 2 (`docs/checkpoint-01-php/02-database-schema.md`).
> Stack: **CodeIgniter 3.1.13 + MySQL 8 / MariaDB 10.6**, InnoDB, `utf8mb4_unicode_ci`, UTC `DATETIME`.

## What shipped

| Area | Files |
|---|---|
| 9 migrations (61 tables) | `application/migrations/001…009_*.php` |
| Seed framework | `application/libraries/Seeder.php` |
| Core seed (production-safe) | `application/seeds/Core_seeder.php` |
| Demo seed (non-production) | `application/seeds/Demo_seeder.php` |
| CLI runners | `application/controllers/Migrate.php`, `application/controllers/Seed.php` |
| Models for new tables | `application/models/*_model.php` (20 added) |
| Generated SQL dump | `docs/database.sql` (from `tools/export_schema.php`) |
| Schema linter | `tools/validate_schema.py` |
| Tests | `tests/unit/SchemaTest.php`, `SeedTest.php`, `SeedRunTest.php` + `tests/_support/FakeDb.php` |

The 002–009 placeholder stubs from Session 01 are replaced with real DDL.

## Commands

```bash
php index.php migrate            # migrate to latest (version 9)
php index.php migrate status     # show applied vs pending
php index.php migrate version 5  # migrate up/down to a specific version
php index.php migrate fresh      # drop everything + re-migrate (blocked in production)

php index.php seed               # core seed — safe in every environment
php index.php seed demo          # demo data — refuses to run when APP_ENV=production
php index.php seed all
php index.php seed list

php tools/export_schema.php          # regenerate docs/database.sql
php tools/export_schema.php --check   # CI guard: fail if the dump is stale
python3 tools/validate_schema.py      # parse + lint the schema
```

`Migrate` and `Seed` both extend `Cron_Controller`, which calls `require_cli()` — they are
**unreachable over HTTP** and deliberately absent from `config/routes.php` (§66).

## Migration map

| # | File | Tables |
|---|---|---|
| 001 | `001_identity` | `price_groups`, `users`, `roles`, `permissions`, `role_permissions`, `user_sessions`, `refresh_tokens`, `mfa_methods`, `login_attempts` |
| 002 | `002_wallets_ledger` | `wallets`, `wallet_transactions`, `ledger_entries`, `idempotency_keys` |
| 003 | `003_services` | `service_categories`, `services`, `service_prices`, `user_service_prices`, `service_favorites` |
| 004 | `004_providers` | `providers`, `provider_services`, `provider_sync_logs`, `provider_health_logs` (+ FK `services.provider_id`) |
| 005 | `005_orders` | `orders`, `order_status_history`, `provider_orders` |
| 006 | `006_refill_cancel_drip_subscription` | `refills`, `refill_status_history`, `cancellation_requests`, `dripfeed_orders`, `dripfeed_runs`, `subscriptions`, `subscription_events` (+ deferred FKs on `orders`) |
| 007 | `007_payments` | `payment_methods`, `payment_transactions`, `payment_webhooks`, `payment_events` |
| 008 | `008_support_content` | `tickets`, `ticket_messages`, `ticket_attachments`, `referral_accounts`, `referrals`, `referral_commissions`, `blog_categories`, `blog_posts`, `faqs`, `announcements`, `media` |
| 009 | `009_security_system` | `audit_logs`, `api_keys`, `api_usage_logs`, `blacklisted_emails/ips/links`, `settings`, `notifications`, `notification_preferences`, `feature_flags`, `email_templates`, `email_queue`, `currencies`, `job_runs` |

### Circular-FK handling

Three FKs cannot be declared at table-creation time because they point forward:

* `services.provider_id → providers.id` — added by **004** after `providers` exists.
* `orders.dripfeed_order_id → dripfeed_orders.id` and `orders.subscription_id → subscriptions.id` — added by **006**.

Each is dropped again in the corresponding `down()` before the tables are removed, so
`migrate version N` walks cleanly in both directions.

### Migration structure

Every migration exposes its DDL through a **static `statements()`** method and the table list
through **static `tables()`**:

```php
class Migration_Orders extends CI_Migration {
    public static function statements() { return array("CREATE TABLE IF NOT EXISTS orders (...)", ...); }
    public static function tables()     { return array('orders','order_status_history','provider_orders'); }
    public function up()   { foreach (self::statements() as $sql) $this->db->query($sql); }
    public function down() { /* FK checks off, drop in reverse */ }
}
```

That is what lets `tools/export_schema.php`, `SchemaTest` and `SeedRunTest` read the real DDL
without a database connection — the migrations stay the single source of truth and
`docs/database.sql` can never silently drift (CI runs `--check`).

## Invariants enforced by the schema

| Rule | How |
|---|---|
| §24/25/56 — ledger is the truth | `wallets` + `wallet_transactions` + `ledger_entries`; **no `users.balance` column exists** |
| Money precision | `DECIMAL(20,8)` on every monetary column; PHP uses `bcmath`, never floats |
| §64 — idempotency | `UNIQUE` on `wallet_transactions.idempotency_key`, `orders.idempotency_key`, `payment_transactions.idempotency_key`, `payment_webhooks(gateway_type, event_id)`, `idempotency_keys.idem_key` |
| §26/29 — auditable order state | `order_status_history` with `previous_status`, `new_status`, `source`, `actor_id` |
| §56 — frozen margins | `orders.provider_charge` + `orders.rate_at_order` captured at order time |
| §62 — secrets at rest | `providers.api_key_encrypted`, `payment_methods.config_encrypted`, `users.mfa_secret` |
| API keys never stored raw | `api_keys.key_hash CHAR(64) UNIQUE` + display-only `prefix` |
| §81 — no licensing | no `license_keys` / `purchase_codes` / `domain_locks`; asserted by tests and CI grep |
| IDs never leak sequence | internal `BIGINT id` for FKs, public `public_id CHAR(26)` (ULID) in URLs/APIs |
| Time | `DATETIME` in UTC everywhere — `TIMESTAMP` is rejected by `SchemaTest` |

## Seeds

### `core` — safe in every environment

* 4 roles (`SUPER_ADMIN`, `ADMIN`, `STAFF`, `CUSTOMER`) and 38 permissions with a full
  `role_permissions` matrix. `SUPER_ADMIN` gets everything; `CUSTOMER` gets nothing;
  `STAFF` deliberately lacks `orders.refund`, `wallets.adjust` and `settings.manage`.
* 4 price groups (Default / Silver / Gold / Reseller).
* 6 currencies with exactly one base (USD).
* 21 settings including `active_homepage=AURORA`, `base_currency=USD`, `maintenance_mode=false`,
  each flagged `is_public` so the browser only ever sees safe values.
* 8 feature flags, 7 payment methods (**all gateways disabled, no credentials**),
  6 email templates, 5 FAQs.

### `demo` — development / testing / demo only

* 1 MOCK provider (encrypted key, no real network calls), 8 categories, 20 services
  with tiered group pricing, mirrored into `provider_services`.
* 4 users (`admin`, `staff`, `demo`, `reseller`) — password from `DEMO_PASSWORD` or randomly
  generated and printed once, hashed with Argon2id.
* Wallets funded through **real `wallet_transactions` + balanced `ledger_entries`**, never a
  bare `UPDATE wallets SET balance`.
* 5 orders across `COMPLETED` / `IN_PROGRESS` / `PARTIAL` / `PENDING` / `CANCELED`, each with a
  legal `order_status_history` chain, plus referral, blog, announcement and ticket data.

Both seeds are **idempotent**: every write goes through `insert_once()` / `upsert()`, so
re-running them changes nothing. `SeedRunTest` proves it by running both seeds twice and
asserting the row counts and wallet balances are unchanged.

### Safety rails

```
php index.php seed demo        # APP_ENV=production  -> refuses, exit 1
php index.php seed demo --force # explicit override
php index.php migrate fresh    # APP_ENV=production  -> refuses, exit 1
```

Seeds run inside a transaction and roll back as a unit if anything fails.

## Testing

```bash
vendor/bin/phpunit                 # normal path (composer installed)
php tools/phpunit_lite.php         # offline path — no composer/network required
python3 tools/validate_schema.py   # SQL parser + house-rule linter
```

**65 tests / 1,756 assertions**, no database server needed:

* `SchemaTest` — migration numbering and CI3 class-name resolution, `up()`/`down()` symmetry,
  InnoDB/utf8mb4, `DECIMAL(20,8)` money, `DATETIME` not `TIMESTAMP`, unique `public_id`s,
  idempotency guards, FK ordering, required indexes, no licensing artifacts, dump freshness.
* `SeedTest` — permission catalog and role matrix integrity, settings shape, service catalog
  sanity, and **every demo price sells above provider cost**.
* `SeedRunTest` — builds an in-memory database from the real migration DDL
  (`tests/_support/FakeDb.php`) and **executes both seeders twice**, then asserts wallet
  balances reconcile with their transactions, ledger entries are double-entry balanced,
  order charges equal `rate/1000 × quantity`, quantities respect service min/max, every status
  history ends at the order's current status and every transition is legal per
  `OrderStateMachine`, and the second pass created no duplicates.

`FakeDb` rejects unknown tables/columns, `NOT NULL` violations and `UNIQUE` collisions, so a
typo in a seeder fails the suite rather than surfacing at runtime against MySQL.

> `tools/phpunit_lite.php` is a small offline stand-in implementing only the PHPUnit assertions
> this repo uses. CI still runs the real PHPUnit; the lite runner exists so the suite is
> runnable in sandboxes without Composer/Packagist access.

## Verification performed

* All 9 migration files resolve to the exact class names CI3 3.1.13's `Migration` library
  derives (`'Migration_'.ucfirst(strtolower($name))`) — verified against the real library source.
* `docs/database.sql` parses cleanly as MySQL: **69 statements, 61 tables, 73 foreign keys**,
  0 errors and 0 warnings from `tools/validate_schema.py`.
* The application boots end-to-end through CodeIgniter (front controller → routing → autoload →
  `Cron_Controller` CLI guard), stopping only at the database connection itself.

## Next

**Session 03 — Auth**: register / login / logout / forgot / verify, sessions, MFA for admin, and
RBAC wired to the `permissions` + `role_permissions` tables seeded here (replacing the
`require_perm()` stub in `MY_Controller`).
