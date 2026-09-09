# MarvySocials — Artifact 2 (REVISED): MySQL / MariaDB Schema Plan — CodeIgniter 3.x Migrations

> Revised 2026-08-16 | Engine: **MySQL 8 / MariaDB 10.6**, `InnoDB`, `utf8mb4_unicode_ci`, all timestamps `DATETIME` stored UTC.
> Money: `DECIMAL(20,8)` everywhere. No JS-float handling — PHP uses `bcmath` / string arithmetic. Supersedes Prisma plan.

## 1. Migration Conventions (CI3)

* Files: `application/migrations/001_*.php` … `009_*.php` — each defines `up()` / `down()` via `$this->dbforge`.
* Also ship raw SQL in `docs/database.sql` for review/dump.
* Public IDs: `public_id CHAR(26)` (ULID) or `CHAR(36)` (UUID) — **exposed in URLs/APIs**; internal `id BIGINT UNSIGNED AUTO_INCREMENT` kept for FK performance. Never expose sequential `id`.
* All tables: `id`, `public_id` (unique, indexed), `created_at`, `updated_at` where applicable. FKs with `FOREIGN KEY` + `INDEX`.
* Migrations gated: `php index.php migrate` (CLI only, guarded `is_cli()`).

## 2. Enums (as VARCHAR + CHECK or lookup)

MySQL enums kept as `VARCHAR(32)` + application-level validation + `CHECK` where engine supports. Canonical values:

```
user_role: SUPER_ADMIN | ADMIN | STAFF | CUSTOMER
user_status: ACTIVE | SUSPENDED | BANNED | PENDING_VERIFICATION
order_status: PENDING | PROCESSING | IN_PROGRESS | COMPLETED | PARTIAL | CANCELED | REFUNDED | FAILED | ERROR | PAUSED | REJECTED | EXPIRED
order_status_source: SYSTEM | ADMIN | PROVIDER | CUSTOMER | CRON | WORKER
service_type: DEFAULT | CUSTOM_COMMENTS | CUSTOM_COMMENTS_PACKAGE | MENTIONS_HASHTAG | MENTIONS_CUSTOM_LIST | MENTIONS_MEDIA_LIKERS | MENTIONS_USER_FOLLOWERS | COMMENT_LIKES | PACKAGE | SUBSCRIPTION | POLL | OTHER
service_status: ACTIVE | INACTIVE | DRAFT | ARCHIVED
provider_status: ACTIVE | INACTIVE | DEGRADED
provider_api_type: STANDARD_SMM | CUSTOM
health_status: ONLINE | DEGRADED | OFFLINE | UNKNOWN
payment_status: CREATED | PENDING | VERIFIED | WALLET_CREDITED | FAILED | EXPIRED | CANCELED | REFUNDED | CHARGEBACK
payment_gateway_type: STRIPE | PAYPAL | FLUTTERWAVE | RAZORPAY | PAYSTACK | COINPAYMENTS | MANUAL
wallet_tx_type: DEPOSIT | ORDER_CHARGE | REFUND | MANUAL_CREDIT | MANUAL_DEBIT | BONUS | REFERRAL_COMMISSION | CHARGEBACK | CANCELLATION_REFUND | PARTIAL_REFUND
ledger_direction: CREDIT | DEBIT
refill_status: PENDING | PROCESSING | COMPLETED | FAILED | REJECTED
cancellation_status: PENDING | PROCESSING | COMPLETED | FAILED | REJECTED | REFUNDED
dripfeed_status: ACTIVE | PAUSED | COMPLETED | CANCELED | FAILED
subscription_status: ACTIVE | PAUSED | COMPLETED | CANCELED | EXPIRED | FAILED
ticket_status: OPEN | PENDING | ANSWERED | RESOLVED | CLOSED
ticket_priority: LOW | MEDIUM | HIGH | URGENT
```

## 3. Identity & Access

```sql
-- migration 001_identity
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  username VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  phone VARCHAR(32) NULL,
  avatar_url VARCHAR(512) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
  role VARCHAR(32) NOT NULL DEFAULT 'CUSTOMER',
  price_group_id BIGINT UNSIGNED NULL,
  referral_code VARCHAR(32) NULL UNIQUE,
  referred_by_id BIGINT UNSIGNED NULL,
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mfa_secret VARCHAR(255) NULL COMMENT 'encrypted',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_price_group FOREIGN KEY (price_group_id) REFERENCES price_groups(id),
  CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by_id) REFERENCES users(id),
  INDEX idx_users_status_created (status, created_at),
  INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perm_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'e.g. orders.view',
  description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sess_user_exp (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refresh_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_rt_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mfa_methods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(16) NOT NULL DEFAULT 'TOTP',
  secret VARCHAR(255) NOT NULL COMMENT 'encrypted',
  verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL,
  reason VARCHAR(255) NULL,
  user_agent VARCHAR(512) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_ip_created (ip, created_at),
  INDEX idx_la_email_created (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 4. Wallet & Ledger (§24/25/56)

```sql
-- migration 002_wallets_ledger
CREATE TABLE wallets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  balance DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_wallets_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallet_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  wallet_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL COMMENT 'wallet_tx_type',
  direction VARCHAR(8) NOT NULL COMMENT 'CREDIT|DEBIT',
  amount DECIMAL(20,8) NOT NULL,
  balance_before DECIMAL(20,8) NOT NULL,
  balance_after DECIMAL(20,8) NOT NULL,
  reference_type VARCHAR(64) NULL COMMENT 'Order|PaymentTransaction|...',
  reference_id VARCHAR(64) NULL,
  idempotency_key VARCHAR(128) NULL UNIQUE,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
  INDEX idx_wt_wallet_created (wallet_id, created_at),
  INDEX idx_wt_type_created (type, created_at),
  INDEX idx_wt_ref (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wallet_transaction_id BIGINT UNSIGNED NOT NULL,
  account VARCHAR(128) NOT NULL COMMENT 'wallet:{id}|revenue|provider_cost|commission',
  direction VARCHAR(8) NOT NULL,
  amount DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_le_wt FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE CASCADE,
  INDEX idx_le_account_created (account, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idem_key VARCHAR(128) NOT NULL UNIQUE,
  scope VARCHAR(64) NOT NULL COMMENT 'order:create|payment:webhook|...',
  response JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_idem_scope_exp (scope, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> All mutations in `LedgerService`: `START TRANSACTION; SELECT balance FROM wallets WHERE id=? FOR UPDATE;` → insert `wallet_transactions` + `ledger_entries` → `UPDATE wallets SET balance=?`. No direct `users.balance` column.

## 5. Services & Categories

```sql
-- migration 003_services
CREATE TABLE service_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  slug VARCHAR(128) NOT NULL UNIQUE,
  parent_id BIGINT UNSIGNED NULL,
  description TEXT NULL,
  icon VARCHAR(64) NULL,
  sorting INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES service_categories(id) ON DELETE SET NULL,
  INDEX idx_cat_parent_sort (parent_id, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  category_id BIGINT UNSIGNED NOT NULL,
  description TEXT NULL,
  service_type VARCHAR(32) NOT NULL DEFAULT 'DEFAULT',
  rate DECIMAL(20,8) NOT NULL COMMENT 'per 1000 or per unit; PricingService interprets',
  min_quantity INT NOT NULL,
  max_quantity INT NOT NULL,
  average_time VARCHAR(64) NULL COMMENT 'e.g. 0-1h',
  average_time_minutes INT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_service_id VARCHAR(64) NULL,
  provider_rate DECIMAL(20,8) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  cancel_supported TINYINT(1) NOT NULL DEFAULT 0,
  refill_supported TINYINT(1) NOT NULL DEFAULT 0,
  dripfeed_supported TINYINT(1) NOT NULL DEFAULT 0,
  subscription_supported TINYINT(1) NOT NULL DEFAULT 0,
  package_supported TINYINT(1) NOT NULL DEFAULT 0,
  custom_comments_supported TINYINT(1) NOT NULL DEFAULT 0,
  sorting INT NOT NULL DEFAULT 0,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  trending TINYINT(1) NOT NULL DEFAULT 0,
  metadata JSON NULL COMMENT 'service-type field defs, platform, etc.',
  provider_source_snapshot JSON NULL COMMENT 'last sync values for admin-override comparison',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_svc_cat FOREIGN KEY (category_id) REFERENCES service_categories(id),
  CONSTRAINT fk_svc_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  INDEX idx_svc_cat_status_sort (category_id, status, sorting),
  INDEX idx_svc_provider (provider_id, provider_service_id),
  INDEX idx_svc_status_feat (status, featured),
  FULLTEXT INDEX ft_svc_search (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id BIGINT UNSIGNED NOT NULL,
  price_group_id BIGINT UNSIGNED NOT NULL,
  rate DECIMAL(20,8) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_svc_group (service_id, price_group_id),
  CONSTRAINT fk_sp_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_group FOREIGN KEY (price_group_id) REFERENCES price_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_service_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  rate DECIMAL(20,8) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_svc (user_id, service_id),
  INDEX idx_usp_user (user_id),
  CONSTRAINT fk_usp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_usp_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, service_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 6. Providers

```sql
-- migration 004_providers
CREATE TABLE providers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  api_url VARCHAR(512) NOT NULL,
  api_key_encrypted VARCHAR(512) NOT NULL COMMENT 'encrypted at rest',
  api_type VARCHAR(32) NOT NULL DEFAULT 'STANDARD_SMM',
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  timeout_ms INT NOT NULL DEFAULT 15000,
  retry_policy JSON NULL COMMENT '{maxRetries, backoff}',
  rate_multiplier DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
  markup DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  sync_interval_minutes INT NOT NULL DEFAULT 60,
  health_status VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
  last_successful_sync_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  provider_service_id VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  rate DECIMAL(20,8) NOT NULL,
  min_quantity INT NOT NULL,
  max_quantity INT NOT NULL,
  service_type VARCHAR(32) NOT NULL DEFAULT 'DEFAULT',
  cancel_supported TINYINT(1) NOT NULL DEFAULT 0,
  refill_supported TINYINT(1) NOT NULL DEFAULT 0,
  dripfeed_supported TINYINT(1) NOT NULL DEFAULT 0,
  raw_payload JSON NULL,
  last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_svc (provider_id, provider_service_id),
  CONSTRAINT fk_ps_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  INDEX idx_ps_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_sync_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL COMMENT 'services|balance',
  status VARCHAR(16) NOT NULL,
  message TEXT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_psl_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  INDEX idx_psl_provider_created (provider_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_health_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  latency_ms INT NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_phl_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  INDEX idx_phl_provider_created (provider_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 7. Orders

```sql
-- migration 005_orders
CREATE TABLE orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_order_id VARCHAR(128) NULL,
  provider_service_id VARCHAR(64) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  link TEXT NOT NULL,
  quantity INT NOT NULL,
  charge DECIMAL(20,8) NOT NULL COMMENT 'what customer paid',
  provider_charge DECIMAL(20,8) NULL COMMENT 'frozen at order time (§56)',
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  fields JSON NULL COMMENT 'dynamic per service_type',
  remains INT NULL COMMENT 'for PARTIAL',
  start_count INT NULL,
  idempotency_key VARCHAR(128) NULL UNIQUE,
  dripfeed_order_id BIGINT UNSIGNED NULL UNIQUE,
  subscription_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ord_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_ord_service FOREIGN KEY (service_id) REFERENCES services(id),
  CONSTRAINT fk_ord_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  INDEX idx_ord_user_status_created (user_id, status, created_at),
  INDEX idx_ord_service_status (service_id, status),
  INDEX idx_ord_provider (provider_id, provider_order_id),
  INDEX idx_ord_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  previous_status VARCHAR(16) NULL,
  new_status VARCHAR(16) NOT NULL,
  reason TEXT NULL,
  source VARCHAR(16) NOT NULL,
  provider_status VARCHAR(64) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_osh_order_created (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NOT NULL,
  provider_order_id VARCHAR(128) NOT NULL,
  request_payload JSON NULL,
  response_payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_po_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  INDEX idx_po_provider (provider_id, provider_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 8. Refill / Cancellation / Drip Feed / Subscriptions

```sql
-- migration 006_refill_cancel_drip_subscription
CREATE TABLE refills (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_refill_id VARCHAR(128) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  metadata JSON NULL,
  CONSTRAINT fk_ref_order FOREIGN KEY (order_id) REFERENCES orders(id),
  INDEX idx_ref_order_status (order_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refill_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  refill_id BIGINT UNSIGNED NOT NULL,
  previous_status VARCHAR(16) NULL,
  new_status VARCHAR(16) NOT NULL,
  source VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rsh_ref FOREIGN KEY (refill_id) REFERENCES refills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cancellation_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  reason TEXT NULL,
  refund_amount DECIMAL(20,8) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_can_order FOREIGN KEY (order_id) REFERENCES orders(id),
  INDEX idx_can_order_status (order_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dripfeed_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  link TEXT NOT NULL,
  total_quantity INT NOT NULL,
  quantity_per_run INT NOT NULL,
  runs INT NOT NULL,
  interval_minutes INT NOT NULL,
  start_at DATETIME NULL,
  next_run_at DATETIME NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_df_user_status (user_id, status),
  INDEX idx_df_next_run (next_run_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dripfeed_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dripfeed_order_id BIGINT UNSIGNED NOT NULL,
  run_number INT NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  executed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dfr_order FOREIGN KEY (dripfeed_order_id) REFERENCES dripfeed_orders(id) ON DELETE CASCADE,
  INDEX idx_dfr_order_run (dripfeed_order_id, run_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- add FK after dripfeed_orders exists
ALTER TABLE orders ADD CONSTRAINT fk_ord_drip FOREIGN KEY (dripfeed_order_id) REFERENCES dripfeed_orders(id) ON DELETE SET NULL;

CREATE TABLE subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_subscription_id VARCHAR(128) NULL,
  target TEXT NOT NULL,
  quantity INT NOT NULL,
  interval_type VARCHAR(32) NOT NULL COMMENT 'daily|weekly|...',
  runs INT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  start_at DATETIME NULL,
  next_execution_at DATETIME NULL,
  expires_at DATETIME NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sub_user_status (user_id, status),
  INDEX idx_sub_next_exec (next_execution_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscription_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_se_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders ADD CONSTRAINT fk_ord_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;
```

## 9. Payments

```sql
-- migration 007_payments
CREATE TABLE payment_methods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  type VARCHAR(32) NOT NULL COMMENT 'gateway type',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  config_encrypted JSON NULL COMMENT 'encrypted credentials',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'CREATED',
  provider_tx_id VARCHAR(128) NULL,
  idempotency_key VARCHAR(128) NULL UNIQUE,
  metadata JSON NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_pt_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  INDEX idx_pt_user_status_created (user_id, status, created_at),
  INDEX idx_pt_provider_tx (provider_tx_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_transaction_id BIGINT UNSIGNED NULL,
  gateway_type VARCHAR(32) NOT NULL,
  event_id VARCHAR(128) NULL,
  payload JSON NOT NULL,
  signature_valid TINYINT(1) NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gateway_event (gateway_type, event_id),
  INDEX idx_pw_processed_created (processed, created_at),
  CONSTRAINT fk_pw_pt FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_transaction_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(16) NULL,
  to_status VARCHAR(16) NOT NULL,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pe_pt FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 10. Support, Content, Referrals

```sql
-- migration 008_support_content
CREATE TABLE tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'OPEN',
  priority VARCHAR(16) NOT NULL DEFAULT 'MEDIUM',
  department VARCHAR(64) NULL,
  assigned_to_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_t_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_t_user_status (user_id, status),
  INDEX idx_t_status_prio_created (status, priority, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  is_staff TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  INDEX idx_tm_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_message_id BIGINT UNSIGNED NOT NULL,
  file_url VARCHAR(512) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  size INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ta_msg FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referral_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  code VARCHAR(32) NOT NULL UNIQUE,
  total_referred INT NOT NULL DEFAULT 0,
  total_earned DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referrals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id BIGINT UNSIGNED NOT NULL,
  referred_id BIGINT UNSIGNED NOT NULL UNIQUE,
  referral_account_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ref_referrer (referrer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE referral_commissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referral_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  amount DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rc_ref FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
  INDEX idx_rc_ref_status (referral_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  slug VARCHAR(128) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  excerpt TEXT NULL,
  content MEDIUMTEXT NOT NULL,
  featured_image VARCHAR(512) NULL,
  meta_title VARCHAR(255) NULL,
  meta_description TEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
  author_id BIGINT UNSIGNED NULL,
  category_id BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bp_cat FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
  INDEX idx_bp_status_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question TEXT NOT NULL,
  answer TEXT NOT NULL,
  sorting INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'INFO',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  audience VARCHAR(32) NULL COMMENT 'all|customers|staff',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  uploader_id BIGINT UNSIGNED NULL,
  url VARCHAR(512) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  size INT NOT NULL,
  purpose VARCHAR(32) NULL COMMENT 'avatar|blog|ticket|service',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 11. Security & System

```sql
-- migration 009_security_system
CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(128) NOT NULL COMMENT 'service.price.update',
  resource VARCHAR(64) NOT NULL,
  resource_id VARCHAR(64) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  request_id CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_actor_created (actor_id, created_at),
  INDEX idx_audit_resource (resource, resource_id),
  INDEX idx_audit_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NULL,
  key_hash CHAR(64) NOT NULL UNIQUE COMMENT 'hash only, never raw',
  prefix VARCHAR(16) NOT NULL COMMENT 'wind_... for display',
  last_used_at DATETIME NULL,
  ip_whitelist JSON NULL,
  scopes JSON NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ak_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ak_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NULL,
  endpoint VARCHAR(255) NOT NULL,
  ip VARCHAR(45) NULL,
  status SMALLINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aul_key_created (api_key_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blacklisted_emails (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blacklisted_ips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL UNIQUE,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blacklisted_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(512) NOT NULL UNIQUE COMMENT 'domain or regex',
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key VARCHAR(128) PRIMARY KEY,
  setting_value JSON NOT NULL,
  category VARCHAR(64) NOT NULL COMMENT 'general|branding|currency|homepage|...',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  channel VARCHAR(16) NOT NULL DEFAULT 'IN_APP',
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  data JSON NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_n_user_read_created (user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  in_app TINYINT(1) NOT NULL DEFAULT 1,
  email TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feature_flags (
  flag_key VARCHAR(128) PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  payload JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'order.completed',
  subject VARCHAR(255) NOT NULL,
  body_html TEXT NOT NULL,
  body_text TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE currencies (
  code CHAR(3) PRIMARY KEY,
  symbol VARCHAR(8) NOT NULL,
  name VARCHAR(64) NOT NULL,
  decimal_precision TINYINT NOT NULL DEFAULT 2,
  exchange_rate DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
  is_base TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 12. Indexes & Constraints Summary

* All `public_id` unique + indexed; APIs expose only `public_id`.
* `CHECK (balance >= 0)` on wallets (MySQL 8.0.16+ enforces; fallback app-level).
* Unique idempotency keys on `wallet_transactions`, `payment_transactions`, `payment_webhooks(gateway_type,event_id)`, `orders.idempotency_key`.
* `service_favorites` PK `(user_id, service_id)`.
* FKs with `ON DELETE CASCADE` only where safe (sessions, history); else `RESTRICT/SET NULL`.
* `FULLTEXT` on `services(name,description)` — upgrade path to Meilisearch later.
* Settings seeded: `active_homepage=AURORA`, `base_currency=USD`, `maintenance=0`.

## 13. What This Schema Deliberately Omits

* No `license_keys`, `purchase_codes`, `domain_locks`.
* No `plugins` storing executable code.
* No denormalized `users.balance` without ledger — `wallets` is source of truth.
