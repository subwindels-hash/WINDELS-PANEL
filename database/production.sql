-- WINDELS PANEL — complete production database
--
-- GENERATED FILE — do not edit by hand.
-- Sources: application/migrations/*.php  +  application/seeds/Core_seeder.php
-- Regenerate with: php tools/build_production_sql.php
--
-- HOW TO USE THIS FILE
--   1. cPanel -> MySQL Databases: create a database and a user, and give
--      that user ALL PRIVILEGES on the database.
--   2. cPanel -> phpMyAdmin: select the database, open Import, choose this
--      file and press Go.
--   3. Edit .env with the database name/user/password and your domain.
--
-- After the import the database is fully initialised: schema, indexes,
-- foreign keys, migration bookkeeping (version 19), roles,
-- permissions, settings, feature flags, payment methods, email templates,
-- FAQs, currencies, catalogues and the first administrator. No migration,
-- seed or installer command has to run afterwards.
--
-- FIRST LOGIN
--   username: admin
--   email:    admin@example.com
--   password: ChangeMe!Admin2026
--   Change it immediately (Dashboard -> Account -> Password), or set your
--   own credentials before first login from /setup with VP_SETUP_TOKEN.
--
-- Engine: InnoDB · Charset: utf8mb4_unicode_ci · Timestamps: UTC DATETIME
-- Money:  DECIMAL(20,8) everywhere (bcmath in PHP, never floats)

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET FOREIGN_KEY_CHECKS = 0;

-- ======================================================================
-- SCHEMA
-- ======================================================================

-- ---------------------------------------------------------------------
-- migration 001_identity
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS price_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
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
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  locale VARCHAR(8) NOT NULL DEFAULT 'en',
  email_verified_at DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  mfa_secret VARCHAR(255) NULL COMMENT 'encrypted at rest',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_status_created (status, created_at),
  INDEX idx_users_role (role),
  INDEX idx_users_price_group (price_group_id),
  INDEX idx_users_referred_by (referred_by_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
ADD CONSTRAINT fk_users_price_group FOREIGN KEY (price_group_id) REFERENCES price_groups(id) ON DELETE SET NULL;

ALTER TABLE users
ADD CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perm_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'e.g. orders.view',
  description VARCHAR(255) NULL,
  category VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  INDEX idx_rp_perm (permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(512) NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sess_user_exp (user_id, expires_at),
  INDEX idx_sess_token (token_hash),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rt_user (user_id),
  CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_methods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(16) NOT NULL DEFAULT 'TOTP',
  secret VARCHAR(255) NOT NULL COMMENT 'encrypted at rest',
  recovery_codes JSON NULL COMMENT 'hashed recovery codes',
  verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mfa_user_type (user_id, type),
  CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
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

-- ---------------------------------------------------------------------
-- migration 002_wallets_ledger
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS wallets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  balance DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  total_deposited DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  total_spent DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_wallets_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  wallet_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL COMMENT 'wallet_tx_type',
  direction VARCHAR(8) NOT NULL COMMENT 'CREDIT|DEBIT',
  amount DECIMAL(20,8) NOT NULL,
  balance_before DECIMAL(20,8) NOT NULL,
  balance_after DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  reference_type VARCHAR(64) NULL COMMENT 'Order|PaymentTransaction|...',
  reference_id VARCHAR(64) NULL,
  note VARCHAR(255) NULL,
  actor_id BIGINT UNSIGNED NULL COMMENT 'admin who forced a manual entry',
  idempotency_key VARCHAR(128) NULL UNIQUE,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wt_wallet_created (wallet_id, created_at),
  INDEX idx_wt_type_created (type, created_at),
  INDEX idx_wt_ref (reference_type, reference_id),
  CONSTRAINT fk_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
  CONSTRAINT fk_wt_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wallet_transaction_id BIGINT UNSIGNED NOT NULL,
  account VARCHAR(128) NOT NULL COMMENT 'wallet:{id}|revenue|liability|provider_cost|commission',
  direction VARCHAR(8) NOT NULL,
  amount DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_le_account_created (account, created_at),
  INDEX idx_le_wt (wallet_transaction_id),
  CONSTRAINT fk_le_wt FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idem_key VARCHAR(128) NOT NULL UNIQUE,
  scope VARCHAR(64) NOT NULL COMMENT 'order:create|payment:webhook|...',
  request_hash CHAR(64) NULL,
  response JSON NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'IN_PROGRESS',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  INDEX idx_idem_scope_exp (scope, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 003_services
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS service_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  slug VARCHAR(128) NOT NULL UNIQUE,
  parent_id BIGINT UNSIGNED NULL,
  description TEXT NULL,
  icon VARCHAR(64) NULL,
  platform VARCHAR(32) NULL COMMENT 'instagram|tiktok|youtube|...',
  sorting INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cat_parent_sort (parent_id, sorting),
  INDEX idx_cat_active_sort (is_active, sorting),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES service_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  category_id BIGINT UNSIGNED NOT NULL,
  description TEXT NULL,
  service_type VARCHAR(32) NOT NULL DEFAULT 'DEFAULT',
  rate DECIMAL(20,8) NOT NULL COMMENT 'per 1000 unless service_type says otherwise',
  min_quantity INT NOT NULL,
  max_quantity INT NOT NULL,
  increment_step INT NULL COMMENT 'quantity must be a multiple when set',
  average_time VARCHAR(64) NULL COMMENT 'human label e.g. 0-1h',
  average_time_minutes INT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_service_id VARCHAR(64) NULL,
  provider_rate DECIMAL(20,8) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  cancel_supported TINYINT(1) NOT NULL DEFAULT 0,
  refill_supported TINYINT(1) NOT NULL DEFAULT 0,
  refill_days INT NULL,
  dripfeed_supported TINYINT(1) NOT NULL DEFAULT 0,
  subscription_supported TINYINT(1) NOT NULL DEFAULT 0,
  package_supported TINYINT(1) NOT NULL DEFAULT 0,
  custom_comments_supported TINYINT(1) NOT NULL DEFAULT 0,
  sorting INT NOT NULL DEFAULT 0,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  trending TINYINT(1) NOT NULL DEFAULT 0,
  auto_price_sync TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'when 0 admin override wins over provider sync',
  metadata JSON NULL COMMENT 'service-type field defs, platform, badges',
  provider_source_snapshot JSON NULL COMMENT 'last sync values for admin-override comparison',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_svc_cat_status_sort (category_id, status, sorting),
  INDEX idx_svc_provider (provider_id, provider_service_id),
  INDEX idx_svc_status_feat (status, featured),
  INDEX idx_svc_type (service_type),
  FULLTEXT INDEX ft_svc_search (name, description),
  CONSTRAINT fk_svc_cat FOREIGN KEY (category_id) REFERENCES service_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id BIGINT UNSIGNED NOT NULL,
  price_group_id BIGINT UNSIGNED NOT NULL,
  rate DECIMAL(20,8) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_svc_group (service_id, price_group_id),
  INDEX idx_sp_group (price_group_id),
  CONSTRAINT fk_sp_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_group FOREIGN KEY (price_group_id) REFERENCES price_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_service_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  rate DECIMAL(20,8) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_svc (user_id, service_id),
  INDEX idx_usp_user (user_id),
  INDEX idx_usp_svc (service_id),
  CONSTRAINT fk_usp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_usp_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, service_id),
  INDEX idx_fav_svc (service_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 004_providers
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS providers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(128) NOT NULL,
  api_url VARCHAR(512) NOT NULL,
  api_key_encrypted VARCHAR(512) NOT NULL COMMENT 'encrypted at rest, never logged',
  api_type VARCHAR(32) NOT NULL DEFAULT 'STANDARD_SMM',
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  balance DECIMAL(20,8) NULL COMMENT 'last known provider balance',
  timeout_ms INT NOT NULL DEFAULT 15000,
  retry_policy JSON NULL COMMENT '{maxRetries, backoffMs[]}',
  rate_multiplier DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
  markup DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  sync_interval_minutes INT NOT NULL DEFAULT 60,
  health_status VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',
  last_successful_sync_at DATETIME NULL,
  last_health_check_at DATETIME NULL,
  last_error TEXT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_prov_status (status, health_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE services
ADD CONSTRAINT fk_svc_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS provider_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  provider_service_id VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(255) NULL,
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
  INDEX idx_ps_provider (provider_id),
  CONSTRAINT fk_ps_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_sync_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL COMMENT 'services|balance',
  status VARCHAR(16) NOT NULL,
  message TEXT NULL,
  items_synced INT NULL,
  duration_ms INT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_psl_provider_created (provider_id, created_at),
  CONSTRAINT fk_psl_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_health_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  latency_ms INT NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phl_provider_created (provider_id, created_at),
  CONSTRAINT fk_phl_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 005_orders
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS orders (
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
  charge DECIMAL(20,8) NOT NULL COMMENT 'what the customer paid',
  rate_at_order DECIMAL(20,8) NOT NULL COMMENT 'resolved price per 1000 at order time',
  provider_charge DECIMAL(20,8) NULL COMMENT 'frozen provider cost at order time (§56)',
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  fields JSON NULL COMMENT 'dynamic per service_type',
  remains INT NULL COMMENT 'for PARTIAL',
  start_count INT NULL,
  refunded_amount DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  source VARCHAR(16) NOT NULL DEFAULT 'WEB' COMMENT 'WEB|API|MASS|DRIPFEED|SUBSCRIPTION|ADMIN',
  note TEXT NULL,
  idempotency_key VARCHAR(128) NULL UNIQUE,
  dripfeed_order_id BIGINT UNSIGNED NULL,
  dripfeed_run_number INT NULL,
  subscription_id BIGINT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ord_user_status_created (user_id, status, created_at),
  INDEX idx_ord_service_status (service_id, status),
  INDEX idx_ord_provider (provider_id, provider_order_id),
  INDEX idx_ord_status_created (status, created_at),
  INDEX idx_ord_dripfeed (dripfeed_order_id),
  INDEX idx_ord_subscription (subscription_id),
  CONSTRAINT fk_ord_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_ord_service FOREIGN KEY (service_id) REFERENCES services(id),
  CONSTRAINT fk_ord_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  previous_status VARCHAR(16) NULL,
  new_status VARCHAR(16) NOT NULL,
  reason TEXT NULL,
  source VARCHAR(16) NOT NULL COMMENT 'SYSTEM|ADMIN|PROVIDER|CUSTOMER|CRON|WORKER',
  actor_id BIGINT UNSIGNED NULL,
  provider_status VARCHAR(64) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_osh_order_created (order_id, created_at),
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_osh_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NOT NULL,
  provider_order_id VARCHAR(128) NOT NULL,
  request_payload JSON NULL COMMENT 'api key redacted before persisting',
  response_payload JSON NULL,
  http_status SMALLINT NULL,
  duration_ms INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_po_provider (provider_id, provider_order_id),
  INDEX idx_po_order (order_id),
  CONSTRAINT fk_po_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_po_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 006_refill_cancel_drip_subscription
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS refills (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_refill_id VARCHAR(128) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  requested_by_id BIGINT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  last_checked_at DATETIME NULL,
  error TEXT NULL,
  metadata JSON NULL,
  INDEX idx_ref_order_status (order_id, status),
  INDEX idx_ref_status_checked (status, last_checked_at),
  CONSTRAINT fk_ref_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_ref_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_ref_user FOREIGN KEY (requested_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refill_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  refill_id BIGINT UNSIGNED NOT NULL,
  previous_status VARCHAR(16) NULL,
  new_status VARCHAR(16) NOT NULL,
  source VARCHAR(32) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rsh_ref (refill_id, created_at),
  CONSTRAINT fk_rsh_ref FOREIGN KEY (refill_id) REFERENCES refills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cancellation_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  order_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_cancel_id VARCHAR(128) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  reason TEXT NULL,
  refund_amount DECIMAL(20,8) NULL,
  requested_by_id BIGINT UNSIGNED NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_can_order_status (order_id, status),
  INDEX idx_can_status_created (status, created_at),
  CONSTRAINT fk_can_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_can_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_can_user FOREIGN KEY (requested_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dripfeed_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  link TEXT NOT NULL,
  total_quantity INT NOT NULL,
  quantity_per_run INT NOT NULL,
  runs INT NOT NULL,
  runs_completed INT NOT NULL DEFAULT 0,
  interval_minutes INT NOT NULL,
  charge DECIMAL(20,8) NOT NULL DEFAULT 0.00000000 COMMENT 'total reserved charge',
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  fields JSON NULL,
  start_at DATETIME NULL,
  next_run_at DATETIME NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_df_user_status (user_id, status),
  INDEX idx_df_next_run (status, next_run_at),
  CONSTRAINT fk_df_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_df_svc FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dripfeed_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dripfeed_order_id BIGINT UNSIGNED NOT NULL,
  run_number INT NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  error TEXT NULL,
  executed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dfr_order_run (dripfeed_order_id, run_number),
  INDEX idx_dfr_status (status),
  CONSTRAINT fk_dfr_order FOREIGN KEY (dripfeed_order_id) REFERENCES dripfeed_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_dfr_child_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  provider_subscription_id VARCHAR(128) NULL,
  target TEXT NOT NULL COMMENT 'username or profile link',
  quantity INT NOT NULL,
  posts INT NULL COMMENT 'max posts to cover',
  delay_minutes INT NULL,
  interval_type VARCHAR(32) NOT NULL COMMENT 'daily|weekly|monthly|custom',
  runs INT NULL,
  runs_completed INT NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  start_at DATETIME NULL,
  next_execution_at DATETIME NULL,
  expires_at DATETIME NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sub_user_status (user_id, status),
  INDEX idx_sub_next_exec (status, next_execution_at),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_svc FOREIGN KEY (service_id) REFERENCES services(id),
  CONSTRAINT fk_sub_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscription_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_se_sub_created (subscription_id, created_at),
  CONSTRAINT fk_se_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
ADD CONSTRAINT fk_ord_drip FOREIGN KEY (dripfeed_order_id) REFERENCES dripfeed_orders(id) ON DELETE SET NULL;

ALTER TABLE orders
ADD CONSTRAINT fk_ord_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- migration 007_payments
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS payment_methods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  code VARCHAR(32) NOT NULL UNIQUE COMMENT 'stripe|paypal|manual|...',
  type VARCHAR(32) NOT NULL COMMENT 'payment_gateway_type',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  min_amount DECIMAL(20,8) NULL,
  max_amount DECIMAL(20,8) NULL,
  fee_percent DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  fee_fixed DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  bonus_percent DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  currencies JSON NULL,
  sorting INT NOT NULL DEFAULT 0,
  instructions TEXT NULL,
  config_encrypted TEXT NULL COMMENT 'encrypted credentials blob',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pm_active_sort (is_active, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(20,8) NOT NULL COMMENT 'amount paid by customer',
  fee DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  bonus DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  credited_amount DECIMAL(20,8) NULL COMMENT 'amount credited to wallet',
  currency CHAR(3) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'CREATED',
  provider_tx_id VARCHAR(128) NULL,
  wallet_transaction_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(128) NULL UNIQUE,
  metadata JSON NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pt_user_status_created (user_id, status, created_at),
  INDEX idx_pt_provider_tx (provider_tx_id),
  INDEX idx_pt_status_created (status, created_at),
  CONSTRAINT fk_pt_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_pt_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_pt_wt FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_webhooks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_transaction_id BIGINT UNSIGNED NULL,
  gateway_type VARCHAR(32) NOT NULL,
  event_id VARCHAR(128) NULL,
  event_type VARCHAR(128) NULL,
  payload JSON NOT NULL,
  signature_valid TINYINT(1) NULL,
  processed TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gateway_event (gateway_type, event_id),
  INDEX idx_pw_processed_created (processed, created_at),
  CONSTRAINT fk_pw_pt FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_transaction_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(16) NULL,
  to_status VARCHAR(16) NOT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'SYSTEM',
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pe_pt_created (payment_transaction_id, created_at),
  CONSTRAINT fk_pe_pt FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 008_support_content
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'OPEN',
  priority VARCHAR(16) NOT NULL DEFAULT 'MEDIUM',
  department VARCHAR(64) NULL,
  order_id BIGINT UNSIGNED NULL,
  assigned_to_id BIGINT UNSIGNED NULL,
  last_reply_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_t_user_status (user_id, status),
  INDEX idx_t_status_prio_created (status, priority, created_at),
  INDEX idx_t_assigned (assigned_to_id, status),
  CONSTRAINT fk_t_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_t_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_t_assignee FOREIGN KEY (assigned_to_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  ticket_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  is_staff TINYINT(1) NOT NULL DEFAULT 0,
  is_internal_note TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tm_ticket_created (ticket_id, created_at),
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_message_id BIGINT UNSIGNED NOT NULL,
  file_url VARCHAR(512) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  size INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ta_msg (ticket_message_id),
  CONSTRAINT fk_ta_msg FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  code VARCHAR(32) NOT NULL UNIQUE,
  commission_percent DECIMAL(10,4) NOT NULL DEFAULT 5.0000,
  total_referred INT NOT NULL DEFAULT 0,
  total_earned DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  total_paid DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id BIGINT UNSIGNED NOT NULL,
  referred_id BIGINT UNSIGNED NOT NULL UNIQUE,
  referral_account_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ref_referrer (referrer_id),
  CONSTRAINT fk_rr_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rr_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rr_account FOREIGN KEY (referral_account_id) REFERENCES referral_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_commissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referral_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  amount DECIMAL(20,8) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  wallet_transaction_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  INDEX idx_rc_ref_status (referral_id, status),
  CONSTRAINT fk_rc_ref FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_rc_wt FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  slug VARCHAR(128) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_posts (
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
  views INT NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_bp_status_pub (status, published_at),
  FULLTEXT INDEX ft_bp_search (title, excerpt, content),
  CONSTRAINT fk_bp_cat FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_bp_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question TEXT NOT NULL,
  answer TEXT NOT NULL,
  category VARCHAR(64) NULL,
  sorting INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_faq_active_sort (is_active, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  severity VARCHAR(16) NOT NULL DEFAULT 'INFO',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  audience VARCHAR(32) NULL COMMENT 'all|customers|staff',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ann_active_window (is_active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  uploader_id BIGINT UNSIGNED NULL,
  url VARCHAR(512) NOT NULL,
  storage_key VARCHAR(512) NULL,
  file_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(128) NOT NULL,
  size INT NOT NULL,
  purpose VARCHAR(32) NULL COMMENT 'avatar|blog|ticket|service|branding',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_media_purpose_created (purpose, created_at),
  CONSTRAINT fk_media_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 009_security_system
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(128) NOT NULL COMMENT 'e.g. service.price.update',
  resource VARCHAR(64) NOT NULL,
  resource_id VARCHAR(64) NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  request_id VARCHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_actor_created (actor_id, created_at),
  INDEX idx_audit_resource (resource, resource_id),
  INDEX idx_audit_action_created (action, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NULL,
  key_hash CHAR(64) NOT NULL UNIQUE COMMENT 'sha256 of raw key — raw never stored',
  prefix VARCHAR(16) NOT NULL COMMENT 'wind_xxxx for display',
  last_used_at DATETIME NULL,
  last_used_ip VARCHAR(45) NULL,
  ip_whitelist JSON NULL,
  scopes JSON NULL,
  rate_limit_per_minute INT NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ak_user (user_id),
  CONSTRAINT fk_ak_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_usage_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id BIGINT UNSIGNED NULL,
  endpoint VARCHAR(255) NOT NULL,
  method VARCHAR(8) NULL,
  ip VARCHAR(45) NULL,
  status SMALLINT NULL,
  duration_ms INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aul_key_created (api_key_id, created_at),
  INDEX idx_aul_created (created_at),
  CONSTRAINT fk_aul_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blacklisted_emails (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blacklisted_ips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL UNIQUE,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blacklisted_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pattern VARCHAR(512) NOT NULL UNIQUE COMMENT 'domain or regex',
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(128) PRIMARY KEY,
  setting_value JSON NOT NULL,
  category VARCHAR(64) NOT NULL COMMENT 'general|branding|currency|homepage|security|...',
  is_public TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'safe to expose to the browser',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_settings_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  channel VARCHAR(16) NOT NULL DEFAULT 'IN_APP',
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  data JSON NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_n_user_read_created (user_id, is_read, created_at),
  CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(64) NOT NULL,
  in_app TINYINT(1) NOT NULL DEFAULT 1,
  email TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id, type),
  CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_flags (
  flag_key VARCHAR(128) PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  description VARCHAR(255) NULL,
  payload JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'e.g. order.completed',
  subject VARCHAR(255) NOT NULL,
  body_html TEXT NOT NULL,
  body_text TEXT NULL,
  variables JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(128) NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NULL,
  template_key VARCHAR(128) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'QUEUED',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eq_status_sched (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS currencies (
  code CHAR(3) PRIMARY KEY,
  symbol VARCHAR(8) NOT NULL,
  name VARCHAR(64) NOT NULL,
  decimal_precision TINYINT NOT NULL DEFAULT 2,
  exchange_rate DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
  is_base TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job VARCHAR(64) NOT NULL COMMENT 'cron job name',
  status VARCHAR(16) NOT NULL DEFAULT 'RUNNING',
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  duration_ms INT NULL,
  processed INT NULL,
  failed INT NULL,
  message TEXT NULL,
  INDEX idx_jr_job_started (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 010_service_transactions_vtu
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS service_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  service_domain VARCHAR(24) NOT NULL COMMENT 'VTU|SMM|NUMBER|OTP|IDENTITY|GIFTCARD|EDUCATION|MARKETPLACE',
  service_type VARCHAR(32) NOT NULL COMMENT 'AIRTIME|DATA|CABLE|ELECTRICITY|EXAM_PIN|...',
  service_id BIGINT UNSIGNED NULL COMMENT 'domain-local product id, if any',
  provider_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|PROCESSING|SUCCESSFUL|FAILED|CANCELLED|REFUNDED',
  amount DECIMAL(20,8) NOT NULL COMMENT 'what the customer paid',
  provider_cost DECIMAL(20,8) NULL COMMENT 'frozen at request time (§15)',
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  wallet_transaction_id BIGINT UNSIGNED NULL COMMENT 'the debit; NULL until charged',
  refunded_amount DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
  provider_reference VARCHAR(128) NULL,
  idempotency_key VARCHAR(128) NULL UNIQUE,
  source VARCHAR(16) NOT NULL DEFAULT 'WEB' COMMENT 'WEB|API|ADMIN|CRON',
  failure_reason VARCHAR(255) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_stx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_stx_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_stx_wtx FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL,
  INDEX idx_stx_user_created (user_id, created_at),
  INDEX idx_stx_domain_status (service_domain, status),
  INDEX idx_stx_status_created (status, created_at),
  INDEX idx_stx_provider_ref (provider_id, provider_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_transaction_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_transaction_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(16) NULL,
  to_status VARCHAR(16) NOT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'SYSTEM' COMMENT 'SYSTEM|ADMIN|PROVIDER|CUSTOMER|CRON',
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stxh_tx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
  INDEX idx_stxh_tx (service_transaction_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id BIGINT UNSIGNED NOT NULL,
  service_transaction_id BIGINT UNSIGNED NULL,
  action VARCHAR(32) NOT NULL COMMENT 'PURCHASE|VERIFY|STATUS|BALANCE',
  provider_reference VARCHAR(128) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  cost DECIMAL(20,8) NULL,
  latency_ms INT NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ptx_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ptx_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE SET NULL,
  INDEX idx_ptx_provider_created (provider_id, created_at),
  INDEX idx_ptx_ref (provider_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vtu_networks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  code VARCHAR(32) NOT NULL UNIQUE COMMENT 'MTN|GLO|AIRTEL|9MOBILE',
  name VARCHAR(64) NOT NULL,
  service_type VARCHAR(32) NOT NULL DEFAULT 'AIRTIME' COMMENT 'AIRTIME|DATA|CABLE|ELECTRICITY|EXAM_PIN',
  msisdn_prefixes VARCHAR(255) NULL COMMENT 'comma-separated, for client-side detection',
  logo_url VARCHAR(512) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vnet_type_active (service_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vtu_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  network_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  service_type VARCHAR(32) NOT NULL COMMENT 'AIRTIME|DATA|CABLE|ELECTRICITY|EXAM_PIN',
  code VARCHAR(64) NOT NULL COMMENT 'our stable code',
  provider_code VARCHAR(64) NULL COMMENT 'what the provider calls it',
  name VARCHAR(128) NOT NULL,
  description VARCHAR(255) NULL,
  product_type VARCHAR(32) NULL COMMENT 'SME|GIFTING|CORPORATE|... for data',
  validity VARCHAR(32) NULL COMMENT '30 days, 7 days, ...',
  face_value DECIMAL(20,8) NULL COMMENT 'NULL for variable-amount products',
  price DECIMAL(20,8) NULL COMMENT 'customer price; NULL when variable',
  provider_cost DECIMAL(20,8) NULL,
  discount_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'for variable-amount airtime',
  min_amount DECIMAL(20,8) NULL,
  max_amount DECIMAL(20,8) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vprod_network FOREIGN KEY (network_id) REFERENCES vtu_networks(id) ON DELETE CASCADE,
  CONSTRAINT fk_vprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_vprod_code (network_id, service_type, code),
  INDEX idx_vprod_type_active (service_type, is_active, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vtu_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  network_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NULL,
  service_type VARCHAR(32) NOT NULL,
  recipient VARCHAR(64) NOT NULL COMMENT 'msisdn, smartcard or meter number',
  recipient_name VARCHAR(128) NULL COMMENT 'resolved by verification, where supported',
  variation_code VARCHAR(64) NULL,
  face_value DECIMAL(20,8) NULL,
  token VARCHAR(128) NULL COMMENT 'electricity token / exam PIN',
  units VARCHAR(64) NULL COMMENT 'electricity units',
  extra JSON NULL COMMENT 'meter_type, exam serial, ...',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vtx_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_vtx_network FOREIGN KEY (network_id) REFERENCES vtu_networks(id) ON DELETE SET NULL,
  CONSTRAINT fk_vtx_product FOREIGN KEY (product_id) REFERENCES vtu_products(id) ON DELETE SET NULL,
  INDEX idx_vtx_recipient (recipient),
  INDEX idx_vtx_type (service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 011_base_currency_ngn
-- ---------------------------------------------------------------------

ALTER TABLE wallets MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

ALTER TABLE wallet_transactions MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

ALTER TABLE providers MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

ALTER TABLE orders MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

ALTER TABLE dripfeed_orders MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

ALTER TABLE service_transactions MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN';

UPDATE wallets SET currency = 'NGN' WHERE currency = 'USD';

UPDATE wallet_transactions SET currency = 'NGN' WHERE currency = 'USD';

UPDATE ledger_entries SET currency = 'NGN' WHERE currency = 'USD';

UPDATE orders SET currency = 'NGN' WHERE currency = 'USD';

UPDATE dripfeed_orders SET currency = 'NGN' WHERE currency = 'USD';

UPDATE payment_transactions SET currency = 'NGN' WHERE currency = 'USD';

UPDATE referral_commissions SET currency = 'NGN' WHERE currency = 'USD';

UPDATE service_transactions SET currency = 'NGN' WHERE currency = 'USD';

UPDATE currencies SET is_base = 0;

UPDATE currencies SET is_base = 1, exchange_rate = '1.00000000', is_active = 1 WHERE code = 'NGN';

UPDATE currencies SET exchange_rate = '0.00064516' WHERE code = 'USD';

UPDATE currencies SET exchange_rate = '0.00059355' WHERE code = 'EUR';

UPDATE currencies SET exchange_rate = '0.00050968' WHERE code = 'GBP';

UPDATE currencies SET exchange_rate = '0.05354839' WHERE code = 'INR';

UPDATE currencies SET exchange_rate = '0.00348387' WHERE code = 'BRL';

UPDATE settings SET setting_value = JSON_OBJECT('value', 'NGN') WHERE setting_key = 'base_currency';

UPDATE settings SET setting_value = JSON_OBJECT('value', '500.00000000') WHERE setting_key = 'min_deposit';

UPDATE settings SET setting_value = JSON_OBJECT('value', '5000000.00000000') WHERE setting_key = 'max_deposit';

UPDATE settings SET setting_value = JSON_OBJECT('value', '100.00000000') WHERE setting_key = 'referral_min_payout';

UPDATE payment_methods SET min_amount = '500.00000000', max_amount = '5000000.00000000', currencies = JSON_ARRAY('NGN');

-- ---------------------------------------------------------------------
-- migration 012_virtual_numbers_otp
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS number_countries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  code VARCHAR(32) NOT NULL UNIQUE COMMENT 'our stable code: NG, GB, US',
  name VARCHAR(64) NOT NULL,
  dial_prefix VARCHAR(8) NULL COMMENT '+234, for display only',
  flag_emoji VARCHAR(16) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ncty_active (is_active, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS number_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  code VARCHAR(48) NOT NULL UNIQUE COMMENT 'WHATSAPP|TELEGRAM|...',
  name VARCHAR(64) NOT NULL,
  logo_url VARCHAR(512) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_nsvc_active (is_active, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS number_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  country_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  code VARCHAR(96) NOT NULL COMMENT 'our stable code, NG-WHATSAPP',
  provider_country VARCHAR(48) NULL,
  provider_operator VARCHAR(48) NULL COMMENT 'any, unless pinned',
  provider_product VARCHAR(48) NULL,
  price DECIMAL(20,8) NULL COMMENT 'customer price in base currency',
  provider_cost DECIMAL(20,8) NULL COMMENT 'vendor cost, converted to base currency',
  stock INT NULL COMMENT 'last known vendor availability, advisory',
  ttl_minutes INT NOT NULL DEFAULT 15 COMMENT 'vendor hold time; the reservation carries the real deadline',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_nprod_country FOREIGN KEY (country_id) REFERENCES number_countries(id) ON DELETE CASCADE,
  CONSTRAINT fk_nprod_service FOREIGN KEY (service_id) REFERENCES number_services(id) ON DELETE CASCADE,
  CONSTRAINT fk_nprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_nprod_code (country_id, service_id, code),
  INDEX idx_nprod_active (is_active, sorting),
  INDEX idx_nprod_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_numbers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  country_id BIGINT UNSIGNED NULL,
  service_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NULL,
  msisdn VARCHAR(32) NOT NULL COMMENT 'the rented number, E.164',
  operator VARCHAR(48) NULL,
  provider_order_id VARCHAR(64) NULL COMMENT 'vendor order id; also the transaction provider_reference',
  status VARCHAR(16) NOT NULL DEFAULT 'RESERVED' COMMENT 'RESERVED|RECEIVED|COMPLETED|CANCELLED|EXPIRED|BANNED',
  sms_count INT NOT NULL DEFAULT 0,
  last_code VARCHAR(32) NULL COMMENT 'most recent extracted code, for the list view',
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL COMMENT 'vendor deadline; the expiry sweep reads this',
  released_at DATETIME NULL,
  extra JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_vnum_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_vnum_country FOREIGN KEY (country_id) REFERENCES number_countries(id) ON DELETE SET NULL,
  CONSTRAINT fk_vnum_service FOREIGN KEY (service_id) REFERENCES number_services(id) ON DELETE SET NULL,
  CONSTRAINT fk_vnum_product FOREIGN KEY (product_id) REFERENCES number_products(id) ON DELETE SET NULL,
  INDEX idx_vnum_status_expires (status, expires_at),
  INDEX idx_vnum_msisdn (msisdn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  virtual_number_id BIGINT UNSIGNED NOT NULL,
  provider_message_id VARCHAR(64) NULL,
  sender VARCHAR(64) NULL,
  body TEXT NULL,
  code VARCHAR(32) NULL COMMENT 'extracted OTP, when the vendor or we can find one',
  received_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_otp_number FOREIGN KEY (virtual_number_id) REFERENCES virtual_numbers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_otp_msg (virtual_number_id, provider_message_id),
  INDEX idx_otp_number_created (virtual_number_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 013_identity_verification
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS identity_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  code VARCHAR(48) NOT NULL UNIQUE COMMENT 'our stable code: NIN_BASIC, BVN_BASIC',
  name VARCHAR(96) NOT NULL,
  id_type VARCHAR(16) NOT NULL COMMENT 'NIN|BVN',
  lookup_field VARCHAR(16) NOT NULL DEFAULT 'IDENTIFIER' COMMENT 'IDENTIFIER|PHONE — what the customer types',
  provider_id BIGINT UNSIGNED NULL,
  provider_code VARCHAR(64) NULL COMMENT 'vendor endpoint key, e.g. kyc/nin',
  description VARCHAR(255) NULL,
  price DECIMAL(20,8) NULL COMMENT 'customer price in base currency',
  provider_cost DECIMAL(20,8) NULL COMMENT 'what the vendor charges us',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_idprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  INDEX idx_idprod_active (is_active, sorting),
  INDEX idx_idprod_type (id_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS identity_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  product_id BIGINT UNSIGNED NULL,
  id_type VARCHAR(16) NOT NULL COMMENT 'NIN|BVN',
  lookup_field VARCHAR(16) NOT NULL DEFAULT 'IDENTIFIER',
  identifier_hash CHAR(64) NOT NULL COMMENT 'HMAC blind index — the raw NIN/BVN is never stored',
  identifier_last4 VARCHAR(8) NULL COMMENT 'masked tail, for display only',
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|VERIFIED|NOT_FOUND|FAILED',
  result_encrypted MEDIUMTEXT NULL COMMENT 'AES-256-GCM JSON of the vendor entity; photo never stored',
  provider_reference VARCHAR(64) NULL,
  consent_at DATETIME NULL COMMENT 'when the customer confirmed they have the subject consent',
  consent_ip VARCHAR(45) NULL,
  reveal_count INT NOT NULL DEFAULT 0 COMMENT 'how many times staff opened the result',
  last_revealed_at DATETIME NULL,
  last_revealed_by BIGINT UNSIGNED NULL,
  purged_at DATETIME NULL COMMENT 'retention sweep scrubbed result_encrypted',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_idchk_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_idchk_product FOREIGN KEY (product_id) REFERENCES identity_products(id) ON DELETE SET NULL,
  CONSTRAINT fk_idchk_revealer FOREIGN KEY (last_revealed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_idchk_hash (identifier_hash),
  INDEX idx_idchk_status_created (status, created_at),
  INDEX idx_idchk_purge (purged_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 014_giftcards
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS giftcard_brands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  code VARCHAR(64) NOT NULL UNIQUE COMMENT 'our stable code: AMAZON, STEAM',
  name VARCHAR(128) NOT NULL,
  provider_brand_id VARCHAR(48) NULL COMMENT 'vendor brand id, advisory',
  logo_url VARCHAR(512) NULL,
  redeem_instructions TEXT NULL COMMENT 'how the customer spends the code',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_gcbrand_active (is_active, sorting)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS giftcard_products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  brand_id BIGINT UNSIGNED NOT NULL,
  provider_id BIGINT UNSIGNED NULL,
  code VARCHAR(96) NOT NULL COMMENT 'our stable code: AMAZON-US-25',
  name VARCHAR(160) NOT NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'US',
  provider_product_id VARCHAR(48) NULL COMMENT 'vendor productId',
  denomination_type VARCHAR(8) NOT NULL DEFAULT 'FIXED' COMMENT 'FIXED|RANGE',
  recipient_currency CHAR(3) NOT NULL COMMENT 'currency the card is denominated in — never defaulted, see below',
  face_value DECIMAL(20,8) NULL COMMENT 'card denomination in recipient_currency',
  min_face_value DECIMAL(20,8) NULL COMMENT 'RANGE products only',
  max_face_value DECIMAL(20,8) NULL COMMENT 'RANGE products only',
  price DECIMAL(20,8) NULL COMMENT 'customer price in base currency; NULL = not for sale',
  provider_cost DECIMAL(20,8) NULL COMMENT 'vendor cost in base currency',
  max_quantity INT NOT NULL DEFAULT 5 COMMENT 'cards per order',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sorting INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gcprod_brand FOREIGN KEY (brand_id) REFERENCES giftcard_brands(id) ON DELETE CASCADE,
  CONSTRAINT fk_gcprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_gcprod_code (code),
  INDEX idx_gcprod_active (is_active, sorting),
  INDEX idx_gcprod_brand (brand_id, is_active),
  INDEX idx_gcprod_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS giftcard_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  product_id BIGINT UNSIGNED NULL,
  brand_id BIGINT UNSIGNED NULL,
  quantity INT NOT NULL DEFAULT 1,
  face_value DECIMAL(20,8) NULL COMMENT 'per-card denomination, frozen at purchase',
  recipient_currency CHAR(3) NOT NULL COMMENT 'copied from the product at purchase',
  recipient_email VARCHAR(190) NULL COMMENT 'vendor delivery copy; the panel is the source of truth',
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|PLACED|DELIVERED|FAILED|CANCELLED',
  provider_order_id VARCHAR(64) NULL COMMENT 'vendor transactionId, what code retrieval is keyed on',
  placed_at DATETIME NULL,
  delivered_at DATETIME NULL,
  code_attempts INT NOT NULL DEFAULT 0 COMMENT 'code-retrieval tries; bounds the retry worker',
  last_attempt_at DATETIME NULL,
  failure_reason VARCHAR(255) NULL,
  reveal_count INT NOT NULL DEFAULT 0 COMMENT 'how many times a code was opened',
  last_revealed_at DATETIME NULL,
  last_revealed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gcord_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_gcord_product FOREIGN KEY (product_id) REFERENCES giftcard_products(id) ON DELETE SET NULL,
  CONSTRAINT fk_gcord_brand FOREIGN KEY (brand_id) REFERENCES giftcard_brands(id) ON DELETE SET NULL,
  CONSTRAINT fk_gcord_revealer FOREIGN KEY (last_revealed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_gcord_status (status, last_attempt_at),
  INDEX idx_gcord_placed (status, placed_at),
  INDEX idx_gcord_provider_ref (provider_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS giftcard_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  giftcard_order_id BIGINT UNSIGNED NOT NULL,
  card_index INT NOT NULL DEFAULT 1 COMMENT '1..quantity, stable ordering for the customer',
  card_number_encrypted TEXT NOT NULL COMMENT 'AES-256-GCM; never rendered without an audited reveal',
  pin_encrypted TEXT NULL COMMENT 'AES-256-GCM; many brands have no PIN',
  card_last4 VARCHAR(8) NULL COMMENT 'display only, so a customer can tell two cards apart',
  redemption_url VARCHAR(512) NULL,
  expires_on DATE NULL COMMENT 'vendor expiry, where one is given',
  revealed_at DATETIME NULL COMMENT 'first time this specific card was opened',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gccode_order FOREIGN KEY (giftcard_order_id) REFERENCES giftcard_orders(id) ON DELETE CASCADE,
  UNIQUE KEY uq_gccode_slot (giftcard_order_id, card_index),
  INDEX idx_gccode_order (giftcard_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 015_marketplace
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS marketplace_listings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  title VARCHAR(120) NOT NULL,
  category VARCHAR(64) NOT NULL,
  description TEXT NOT NULL,
  price DECIMAL(20,8) NOT NULL,
  stock INT UNSIGNED NULL COMMENT 'NULL means unlimited',
  delivery_days INT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|ACTIVE|PAUSED|REJECTED|ARCHIVED',
  moderation_note VARCHAR(500) NULL,
  approved_at DATETIME NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mplisting_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_mplisting_catalogue (status, category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
  listing_id BIGINT UNSIGNED NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(20,8) NOT NULL,
  gross_amount DECIMAL(20,8) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|PAID|DELIVERED|DISPUTED|COMPLETED|REFUNDED|CANCELLED',
  delivery_encrypted MEDIUMTEXT NULL,
  delivered_at DATETIME NULL,
  release_due_at DATETIME NULL,
  released_at DATETIME NULL,
  dispute_reason VARCHAR(1000) NULL,
  disputed_at DATETIME NULL,
  resolved_at DATETIME NULL,
  resolved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mporder_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_mporder_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mporder_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mporder_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_mporder_buyer (buyer_id, created_at),
  INDEX idx_mporder_release (status, release_due_at),
  INDEX idx_mporder_listing (listing_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketplace_order_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(32) NOT NULL,
  from_status VARCHAR(16) NULL,
  to_status VARCHAR(16) NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mpevent_order FOREIGN KEY (order_id) REFERENCES marketplace_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpevent_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_mpevent_order_created (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 016_mass_orders
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS mass_order_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PROCESSING' COMMENT 'PROCESSING|COMPLETED',
  result_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_mass_order_batch_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_mass_order_batch_token (user_id, token_hash),
  INDEX idx_mass_order_batch_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migration 017_marketplace_catalogue
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS marketplace_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(26) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(64) NOT NULL UNIQUE,
  status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|ARCHIVED',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mpcat_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketplace_listings
ADD COLUMN promo_price DECIMAL(20,8) NULL COMMENT 'promotional price; NULL means not on sale',
ADD COLUMN image VARCHAR(255) NULL COMMENT 'uploaded shelf image (MediaService storage key)',
ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'featured shelf placement',
ADD COLUMN product_type VARCHAR(16) NOT NULL DEFAULT 'DIGITAL' COMMENT 'DIGITAL|PHYSICAL';

-- ---------------------------------------------------------------------
-- migration 018_remove_withdrawals
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- migration 019_remove_marketplace_vendors
-- ---------------------------------------------------------------------

-- ======================================================================
-- MIGRATION BOOKKEEPING
-- ======================================================================

-- CodeIgniter's migration table, pre-filled with the version this file was
-- built from. `php index.php migrate` on a future upgrade therefore applies
-- only what came after it, and never re-runs what is already here.

CREATE TABLE IF NOT EXISTS migrations (
  version BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM migrations;

INSERT INTO migrations (version) VALUES (19);

-- ======================================================================
-- CORE DATA
-- ======================================================================

-- Everything below is produced by application/seeds/Core_seeder.php, the
-- same seed the development stack runs. Ids are explicit where a foreign
-- key depends on them.

-- roles
INSERT INTO `roles` (`id`, `name`, `description`, `is_system`)
VALUES (1, 'SUPER_ADMIN', 'Full unrestricted access (bypasses permission checks)', 1);

INSERT INTO `roles` (`id`, `name`, `description`, `is_system`)
VALUES (2, 'ADMIN', 'Operational administrator', 1);

INSERT INTO `roles` (`id`, `name`, `description`, `is_system`)
VALUES (3, 'STAFF', 'Support and order operations', 1);

INSERT INTO `roles` (`id`, `name`, `description`, `is_system`)
VALUES (4, 'CUSTOMER', 'Panel customer / reseller', 1);

-- permissions
INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (1, 'reports.view', 'dashboard', 'Reports view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (2, 'users.view', 'users', 'Users view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (3, 'users.edit', 'users', 'Users edit');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (4, 'users.impersonate', 'users', 'Users impersonate');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (5, 'staff.manage', 'users', 'Staff manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (6, 'pricing.manage', 'users', 'Pricing manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (7, 'services.view', 'services', 'Services view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (8, 'services.manage', 'services', 'Services manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (9, 'categories.manage', 'services', 'Categories manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (10, 'providers.view', 'providers', 'Providers view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (11, 'providers.manage', 'providers', 'Providers manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (12, 'providers.sync', 'providers', 'Providers sync');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (13, 'orders.view', 'orders', 'Orders view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (14, 'orders.edit', 'orders', 'Orders edit');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (15, 'orders.refund', 'orders', 'Orders refund');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (16, 'orders.cancel', 'orders', 'Orders cancel');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (17, 'orders.refill', 'orders', 'Orders refill');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (18, 'vtu.view', 'vtu', 'Vtu view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (19, 'vtu.manage', 'vtu', 'Vtu manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (20, 'vtu.refund', 'vtu', 'Vtu refund');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (21, 'numbers.view', 'numbers', 'Numbers view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (22, 'numbers.manage', 'numbers', 'Numbers manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (23, 'numbers.refund', 'numbers', 'Numbers refund');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (24, 'identity.view', 'identity', 'Identity view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (25, 'identity.manage', 'identity', 'Identity manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (26, 'identity.refund', 'identity', 'Identity refund');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (27, 'identity.reveal', 'identity', 'Identity reveal');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (28, 'giftcards.view', 'giftcards', 'Giftcards view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (29, 'giftcards.manage', 'giftcards', 'Giftcards manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (30, 'giftcards.refund', 'giftcards', 'Giftcards refund');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (31, 'giftcards.reveal', 'giftcards', 'Giftcards reveal');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (32, 'marketplace.view', 'marketplace', 'Marketplace view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (33, 'marketplace.manage', 'marketplace', 'Marketplace manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (34, 'marketplace.moderate_listings', 'marketplace', 'Marketplace moderate listings');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (35, 'marketplace.resolve', 'marketplace', 'Marketplace resolve');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (36, 'marketplace.reveal', 'marketplace', 'Marketplace reveal');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (37, 'payments.view', 'payments', 'Payments view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (38, 'payments.manage', 'payments', 'Payments manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (39, 'wallets.adjust', 'payments', 'Wallets adjust');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (40, 'tickets.view', 'support', 'Tickets view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (41, 'tickets.reply', 'support', 'Tickets reply');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (42, 'tickets.manage', 'support', 'Tickets manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (43, 'blog.manage', 'content', 'Blog manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (44, 'faq.manage', 'content', 'Faq manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (45, 'announcements.manage', 'content', 'Announcements manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (46, 'media.manage', 'content', 'Media manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (47, 'affiliates.view', 'affiliates', 'Affiliates view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (48, 'affiliates.manage', 'affiliates', 'Affiliates manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (49, 'settings.manage', 'system', 'Settings manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (50, 'appearance.manage', 'system', 'Appearance manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (51, 'audit.view', 'system', 'Audit view');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (52, 'blacklist.manage', 'system', 'Blacklist manage');

INSERT INTO `permissions` (`id`, `perm_key`, `category`, `description`)
VALUES (53, 'api.manage', 'system', 'Api manage');

-- role_permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 1);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 2);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 3);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 4);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 5);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 6);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 7);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 8);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 9);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 10);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 11);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 12);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 13);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 14);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 15);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 16);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 17);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 18);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 19);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 20);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 21);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 22);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 23);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 24);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 25);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 26);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 27);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 28);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 29);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 30);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 31);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 32);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 33);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 34);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 35);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 36);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 37);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 38);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 39);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 40);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 41);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 42);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 43);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 44);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 45);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 46);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 47);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 48);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 49);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 50);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 51);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 52);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (1, 53);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 1);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 2);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 3);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 5);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 6);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 7);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 8);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 9);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 10);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 11);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 12);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 13);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 14);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 15);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 16);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 17);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 18);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 19);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 20);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 21);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 22);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 23);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 24);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 25);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 26);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 27);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 28);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 29);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 30);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 31);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 32);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 33);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 34);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 35);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 36);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 37);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 38);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 39);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 40);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 41);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 42);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 43);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 44);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 45);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 46);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 47);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 48);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 49);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 50);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 51);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 52);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (2, 53);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 1);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 2);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 7);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 10);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 13);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 14);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 17);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 18);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 21);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 24);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 28);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 29);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 32);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 34);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 37);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 40);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 41);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
VALUES (3, 47);

-- price_groups
INSERT INTO `price_groups` (`id`, `name`, `description`, `is_default`)
VALUES (1, 'Default', 'Standard retail pricing', 1);

INSERT INTO `price_groups` (`id`, `name`, `description`, `is_default`)
VALUES (2, 'Silver', 'Volume tier 1', 0);

INSERT INTO `price_groups` (`id`, `name`, `description`, `is_default`)
VALUES (3, 'Gold', 'Volume tier 2', 0);

INSERT INTO `price_groups` (`id`, `name`, `description`, `is_default`)
VALUES (4, 'Reseller', 'API reseller pricing', 0);

-- currencies
INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active`)
VALUES ('NGN', '₦', 'Nigerian Naira', 2, '1.00000000', 1, 1);

INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active`)
VALUES ('USD', '$', 'US Dollar', 2, '0.00064516', 0, 1);

INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active`)
VALUES ('EUR', '€', 'Euro', 2, '0.00059355', 0, 1);

INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active`)
VALUES ('GBP', '£', 'British Pound', 2, '0.00050968', 0, 1);

INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active`)
VALUES ('INR', '₹', 'Indian Rupee', 2, '0.05354839', 0, 1);

INSERT INTO `currencies` (`code`, `symbol`, `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active`)
VALUES ('BRL', 'R$', 'Brazilian Real', 2, '0.00348387', 0, 1);

-- settings
INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('site_name', '{"value":"WINDELS PANEL"}', 'general', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('site_tagline', '{"value":"Enterprise SMM Reseller Platform"}', 'general', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('support_email', '{"value":"support@windels.local"}', 'general', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('maintenance_mode', '{"value":false}', 'general', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('active_homepage', '{"value":"AURORA"}', 'homepage', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('brand_primary_color', '{"value":"#6366f1"}', 'branding', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('brand_logo_url', '{"value":null}', 'branding', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('brand_favicon_url', '{"value":null}', 'branding', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('default_theme', '{"value":"system"}', 'branding', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('base_currency', '{"value":"NGN"}', 'currency', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('currency_display', '{"value":"symbol"}', 'currency', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('min_deposit', '{"value":"500.00000000"}', 'payments', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('max_deposit', '{"value":"5000000.00000000"}', 'payments', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('order_auto_submit', '{"value":true}', 'orders', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('partial_refund_enabled', '{"value":true}', 'orders', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('registration_enabled', '{"value":true}', 'security', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('email_verification_required', '{"value":true}', 'security', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('admin_mfa_required', '{"value":true}', 'security', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('api_enabled', '{"value":true}', 'security', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('referral_commission_percent', '{"value":"5.0000"}', 'affiliate', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('referral_commission_scope', '{"value":"LIFETIME"}', 'affiliate', 1);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('referral_hold_hours', '{"value":24}', 'affiliate', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('referral_min_payout', '{"value":"100.00000000"}', 'affiliate', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('identity_retention_days', '{"value":30}', 'identity', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('giftcard_sender_name', '{"value":"WINDELS PANEL"}', 'giftcards', 0);

INSERT INTO `settings` (`setting_key`, `setting_value`, `category`, `is_public`)
VALUES ('marketplace_auto_release_hours', '{"value":72}', 'marketplace', 0);

-- feature_flags
INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('demo_mode', 0, 'Read-only demo data + blocked mutations (APP_ENV=demo)');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('dripfeed', 1, 'Drip-feed orders');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('subscriptions', 1, 'Subscription orders');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('mass_order', 1, 'Mass order form');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('reseller_api', 1, '/api/v1 reseller API');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('affiliate_program', 1, 'Referral commissions');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('marketplace', 1, 'Moderated customer marketplace and escrow');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('tickets', 1, 'Support ticket system');

INSERT INTO `feature_flags` (`flag_key`, `enabled`, `description`)
VALUES ('blog', 1, 'Public blog');

-- payment_methods
INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (1, 'manual', '8XH1MC7CNM2CY02784DP790NEY', 'Manual / Bank Transfer', 'MANUAL', 1, 10, '500.00000000', '5000000.00000000', '["NGN"]');

INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (2, 'stripe', 'K4YJJDH9TKEDT6E6BTZ8PP50GB', 'Stripe', 'STRIPE', 0, 20, '500.00000000', '5000000.00000000', '["NGN"]');

INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (3, 'paypal', '9JT5HE2HGA3RRJTWRWDDATS4PD', 'PayPal', 'PAYPAL', 0, 30, '500.00000000', '5000000.00000000', '["NGN"]');

INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (4, 'paystack', '6JH8JKRQGMWYEMMPNMJ3SRZEN5', 'Paystack', 'PAYSTACK', 0, 40, '500.00000000', '5000000.00000000', '["NGN"]');

INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (5, 'flutterwave', 'KWZNGAKY2KEMFNECBSAKD0K15A', 'Flutterwave', 'FLUTTERWAVE', 0, 50, '500.00000000', '5000000.00000000', '["NGN"]');

INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (6, 'razorpay', '758H4H1BMTNWKS68QAW4QCYTQ6', 'Razorpay', 'RAZORPAY', 0, 60, '500.00000000', '5000000.00000000', '["NGN"]');

INSERT INTO `payment_methods` (`id`, `code`, `public_id`, `name`, `type`, `is_active`, `sorting`, `min_amount`, `max_amount`, `currencies`)
VALUES (7, 'coinpayments', 'BPD6QQ7ETM72CZXPKWVPQQR1MA', 'CoinPayments', 'COINPAYMENTS', 0, 70, '500.00000000', '5000000.00000000', '["NGN"]');

-- email_templates
INSERT INTO `email_templates` (`id`, `template_key`, `subject`, `body_html`, `body_text`, `variables`, `is_active`)
VALUES (1, 'auth.verify_email', 'Verify your {{site_name}} account', '<p>Hi {{username}},</p><p>Confirm your email to activate your account:</p><p><a href="{{verify_url}}">Verify email</a></p>', 'Hi {{username}},\nConfirm your email to activate your account:\nVerify email', '["site_name","username","verify_url"]', 1);

INSERT INTO `email_templates` (`id`, `template_key`, `subject`, `body_html`, `body_text`, `variables`, `is_active`)
VALUES (2, 'auth.password_reset', 'Reset your {{site_name}} password', '<p>Hi {{username}},</p><p>Use the link below to set a new password. It expires in 60 minutes.</p><p><a href="{{reset_url}}">Reset password</a></p>', 'Hi {{username}},\nUse the link below to set a new password. It expires in 60 minutes.\nReset password', '["site_name","username","reset_url"]', 1);

INSERT INTO `email_templates` (`id`, `template_key`, `subject`, `body_html`, `body_text`, `variables`, `is_active`)
VALUES (3, 'order.completed', 'Order {{order_id}} completed', '<p>Your order <strong>{{order_id}}</strong> for {{service_name}} is complete.</p><p>Quantity: {{quantity}} · Charge: {{charge}}</p>', 'Your order {{order_id}} for {{service_name}} is complete.\nQuantity: {{quantity}} · Charge: {{charge}}', '["order_id","service_name","quantity","charge"]', 1);

INSERT INTO `email_templates` (`id`, `template_key`, `subject`, `body_html`, `body_text`, `variables`, `is_active`)
VALUES (4, 'order.partial', 'Order {{order_id}} partially delivered', '<p>Order <strong>{{order_id}}</strong> was partially delivered. {{remains}} units were not delivered and {{refund_amount}} has been refunded to your wallet.</p>', 'Order {{order_id}} was partially delivered. {{remains}} units were not delivered and {{refund_amount}} has been refunded to your wallet.', '["order_id","remains","refund_amount"]', 1);

INSERT INTO `email_templates` (`id`, `template_key`, `subject`, `body_html`, `body_text`, `variables`, `is_active`)
VALUES (5, 'payment.credited', 'Wallet credited: {{amount}}', '<p>We received your payment of {{amount}}. Your new balance is {{balance}}.</p>', 'We received your payment of {{amount}}. Your new balance is {{balance}}.', '["amount","balance"]', 1);

INSERT INTO `email_templates` (`id`, `template_key`, `subject`, `body_html`, `body_text`, `variables`, `is_active`)
VALUES (6, 'ticket.replied', 'Support ticket {{ticket_id}} updated', '<p>Our team replied to your ticket <strong>{{subject}}</strong>.</p><p><a href="{{ticket_url}}">View ticket</a></p>', 'Our team replied to your ticket {{subject}}.\nView ticket', '["ticket_id","subject","ticket_url"]', 1);

-- faqs
INSERT INTO `faqs` (`id`, `question`, `answer`, `category`, `sorting`, `is_active`)
VALUES (1, 'How fast are orders delivered?', 'Most services start within minutes. Each service card shows its average start time; drip-feed orders follow the interval you choose.', 'orders', 10, 1);

INSERT INTO `faqs` (`id`, `question`, `answer`, `category`, `sorting`, `is_active`)
VALUES (2, 'How do I add funds?', 'Open Dashboard → Add Funds, pick a payment method and follow the checkout. Your wallet is credited automatically once the payment is verified.', 'payments', 20, 1);

INSERT INTO `faqs` (`id`, `question`, `answer`, `category`, `sorting`, `is_active`)
VALUES (3, 'What is a partial order?', 'If a provider delivers only part of the quantity, the order is marked PARTIAL and the undelivered portion is refunded to your wallet automatically.', 'orders', 30, 1);

INSERT INTO `faqs` (`id`, `question`, `answer`, `category`, `sorting`, `is_active`)
VALUES (4, 'Do you offer an API for resellers?', 'Yes. Create an API key in Dashboard → API and call /api/v1 with the X-Api-Key header. Full docs are at /api/docs.', 'api', 40, 1);

INSERT INTO `faqs` (`id`, `question`, `answer`, `category`, `sorting`, `is_active`)
VALUES (5, 'Can I get a refill?', 'Services marked "Refill" support refill requests from the order detail page within the refill window.', 'orders', 50, 1);

-- vtu_networks
INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (1, 'MTN', '9TACSBMKRQ1BE328XKNZ2P4KNG', 'MTN', 'AIRTIME', '0803,0806,0810,0813,0814,0816,0903,0906,0913,0916', 1, 0);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (2, 'GLO', '7FZ21RMFYPEA08MNA85H1XG376', 'Glo', 'AIRTIME', '0805,0807,0811,0815,0705,0905,0915', 1, 1);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (3, 'AIRTEL', 'A5C2K3CJMBXH6K3M9RG02GD2ST', 'Airtel', 'AIRTIME', '0802,0808,0812,0701,0708,0902,0907,0901,0912', 1, 2);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (4, '9MOBILE', '61M4AQEN66ZFQ8TR66R7TS8ZZQ', '9mobile', 'AIRTIME', '0809,0817,0818,0908,0909', 1, 3);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (5, 'MTN-DATA', 'NBTR37Y1K97Q91W31W0D9TA8NH', 'MTN Data', 'DATA', NULL, 1, 4);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (6, 'GLO-DATA', 'AXX9DYP2VJRC4YY86WQTKDF32N', 'Glo Data', 'DATA', NULL, 1, 5);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (7, 'AIRTEL-DATA', 'THWWX3WYZH7ZZ7ST5ADAP26897', 'Airtel Data', 'DATA', NULL, 1, 6);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (8, '9MOBILE-DATA', '6YMCA8JZZG62PVNYB4B803JZQN', '9mobile Data', 'DATA', NULL, 1, 7);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (9, 'DSTV', '04EPFSZRGH66J335PCDP5YG3Y3', 'DSTV', 'CABLE', NULL, 1, 8);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (10, 'GOTV', 'MP5S4XKHWYE40DX1ADNKECX7FF', 'GOtv', 'CABLE', NULL, 1, 9);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (11, 'STARTIMES', '1881TYKYT4KWHY66CZ5Z7D71B2', 'StarTimes', 'CABLE', NULL, 1, 10);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (12, 'IKEDC', 'THJVXAN1AZAME03AMW8Z38TZ8S', 'Ikeja Electric', 'ELECTRICITY', NULL, 1, 11);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (13, 'EKEDC', '0BTBJGCFCMSHP5YDP5HMXCT2P0', 'Eko Electric', 'ELECTRICITY', NULL, 1, 12);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (14, 'AEDC', 'KN3G441YYEGQANGRZYE2M1JFQZ', 'Abuja Electric', 'ELECTRICITY', NULL, 1, 13);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (15, 'PHED', '9G0103ZC7RKSDGC0DYZKV870BK', 'Port Harcourt Electric', 'ELECTRICITY', NULL, 1, 14);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (16, 'WAEC', 'BSGKEKNXKB0CE86GPQP0N5CT8M', 'WAEC', 'EXAM_PIN', NULL, 1, 15);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (17, 'NECO', 'C720V6HHDAKX9KSYXH000Q8V0X', 'NECO', 'EXAM_PIN', NULL, 1, 16);

INSERT INTO `vtu_networks` (`id`, `code`, `public_id`, `name`, `service_type`, `msisdn_prefixes`, `is_active`, `sorting`)
VALUES (18, 'JAMB', 'QC3DASARMZ63H5JQFMD7393CY5', 'JAMB', 'EXAM_PIN', NULL, 1, 17);

-- vtu_products
INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (1, 1, 'AIRTIME', 'MTN-AIRTIME', 'KH2D499X2BN8QJ5A4GXRX1DHF3', 'MTN Airtime', '2.0000', '50.00000000', '50000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (2, 2, 'AIRTIME', 'GLO-AIRTIME', 'EEQDG0RV0G3CMX154G73MR36MS', 'Glo Airtime', '2.0000', '50.00000000', '50000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (3, 3, 'AIRTIME', 'AIRTEL-AIRTIME', 'HDKZC04XJ1VJFYNEA5DN9CJ1PC', 'Airtel Airtime', '2.0000', '50.00000000', '50000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (4, 4, 'AIRTIME', '9MOBILE-AIRTIME', '1ECVD4NZCW9WH9MNNDWN05HZDV', '9mobile Airtime', '2.0000', '50.00000000', '50000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (5, 12, 'ELECTRICITY', 'IKEDC-ELECTRICITY', 'WPHEFG8XFKGXQ05TNGTNWGYESH', 'Ikeja Electric Units', '1.0000', '500.00000000', '100000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (6, 13, 'ELECTRICITY', 'EKEDC-ELECTRICITY', 'FM3R4REN8CPW9R2QVDDHNJ2PB6', 'Eko Electric Units', '1.0000', '500.00000000', '100000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (7, 14, 'ELECTRICITY', 'AEDC-ELECTRICITY', '76JVBTS5XFP0W34YC3A57AJ3DF', 'Abuja Electric Units', '1.0000', '500.00000000', '100000.00000000', 1);

INSERT INTO `vtu_products` (`id`, `network_id`, `service_type`, `code`, `public_id`, `name`, `discount_percent`, `min_amount`, `max_amount`, `is_active`)
VALUES (8, 15, 'ELECTRICITY', 'PHED-ELECTRICITY', 'EPJ6VQ9MWRHYWG1WE8VCKQNC9N', 'Port Harcourt Electric Units', '1.0000', '500.00000000', '100000.00000000', 1);

-- number_countries
INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (1, 'NG', '9T8QT9Z45FRAQ65BSZEKSTKJV2', 'Nigeria', '+234', '🇳🇬', 1, 0);

INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (2, 'GH', '0Y24498Y9WVDGTA5Z15TP0GYQS', 'Ghana', '+233', '🇬🇭', 1, 1);

INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (3, 'KE', 'M3Q92N7C3PDFB3390R76R6HYE4', 'Kenya', '+254', '🇰🇪', 1, 2);

INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (4, 'ZA', 'KYHZJARES0EW546MY4BBH25S8M', 'South Africa', '+27', '🇿🇦', 1, 3);

INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (5, 'GB', 'E3RQK5S3N5BX1AWKBDZTT2J9G0', 'United Kingdom', '+44', '🇬🇧', 1, 4);

INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (6, 'US', '45J64KH2VEQEMV01JEQ981NNRG', 'United States', '+1', '🇺🇸', 1, 5);

INSERT INTO `number_countries` (`id`, `code`, `public_id`, `name`, `dial_prefix`, `flag_emoji`, `is_active`, `sorting`)
VALUES (7, 'IN', 'K63MGXY397MAV75A28HDX36BCM', 'India', '+91', '🇮🇳', 1, 6);

-- number_services
INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (1, 'WHATSAPP', 'T9J45FES9D8BDSEWMTND1NZ4EB', 'WhatsApp', 1, 0);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (2, 'TELEGRAM', 'HDPMPZ93DH6RF5Y7D1Y70RVM78', 'Telegram', 1, 1);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (3, 'FACEBOOK', 'KJBG6Z37P3B4RENQJ4AE19NBV6', 'Facebook', 1, 2);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (4, 'INSTAGRAM', 'HY7ARD3Q1GQZBHVR7CK11YRZDR', 'Instagram', 1, 3);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (5, 'GOOGLE', 'GK80H72C3QNBQ3EFHX08091NV4', 'Google', 1, 4);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (6, 'TWITTER', 'N26FFEYYB8MNBRRATZSAF5JMQW', 'X (Twitter)', 1, 5);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (7, 'TIKTOK', '3ZR53FP62D2JWNX7NQF08VWRX2', 'TikTok', 1, 6);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (8, 'DISCORD', '8FQEPE0YDC4NG0SSS8J1HBMD9X', 'Discord', 1, 7);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (9, 'UBER', 'CR99CC2RPWGXGWK32F1VKHCAM5', 'Uber', 1, 8);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (10, 'AMAZON', 'Q6Y6N78S1T93JWSBHHV0AHMR85', 'Amazon', 1, 9);

INSERT INTO `number_services` (`id`, `code`, `public_id`, `name`, `is_active`, `sorting`)
VALUES (11, 'OTHER', '1XQKAJDRTDKZTJDYXD2ZQ1C036', 'Any other service', 1, 10);

-- identity_products
INSERT INTO `identity_products` (`id`, `code`, `public_id`, `name`, `id_type`, `lookup_field`, `provider_code`, `description`, `is_active`, `sorting`)
VALUES (1, 'NIN_BASIC', 'B1WM58APF7PF0X8ARZ2FCQSRN5', 'NIN verification', 'NIN', 'IDENTIFIER', 'kyc/nin', 'Confirm a National Identification Number and return the registered name, date of birth and gender.', 0, 0);

INSERT INTO `identity_products` (`id`, `code`, `public_id`, `name`, `id_type`, `lookup_field`, `provider_code`, `description`, `is_active`, `sorting`)
VALUES (2, 'BVN_BASIC', 'J5Z9NQD68VS5AS28N7MEJFXFNZ', 'BVN verification', 'BVN', 'IDENTIFIER', 'kyc/bvn', 'Confirm a Bank Verification Number against the NIBSS record.', 0, 1);

INSERT INTO `identity_products` (`id`, `code`, `public_id`, `name`, `id_type`, `lookup_field`, `provider_code`, `description`, `is_active`, `sorting`)
VALUES (3, 'NIN_PHONE', '3R38VDX4RT4W1FPBRSB950G485', 'NIN by phone number', 'NIN', 'PHONE', 'kyc/nin/phone_number', 'Find the NIN record linked to a Nigerian phone number.', 0, 2);

-- giftcard_brands
INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (1, 'AMAZON', 'V842S7ESVC3WEWQ1X03FT2R708', 'Amazon', 'Go to amazon.com/redeem and enter the claim code to add it to your Amazon balance.', 1, 0);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (2, 'APPLE', '8F2SF44HDH635C0EGXEPNM7ZV4', 'App Store & iTunes', 'Go to apple.com/redeem, or open the App Store, tap your profile and choose Redeem Gift Card.', 1, 1);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (3, 'GOOGLE_PLAY', '3C1CBA6JTEGV0BTXVSKTDMYX4P', 'Google Play', 'Open the Google Play Store, tap your profile, choose Payments & subscriptions, then Redeem code.', 1, 2);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (4, 'STEAM', '9AB72PF6P4QP2XVYXS7T9GPYVM', 'Steam', 'Open Steam, choose Games then Redeem a Steam Wallet Code, and enter the code.', 1, 3);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (5, 'NETFLIX', 'J1STHE69CMHM3YPPXSCCXXH8G9', 'Netflix', 'Go to netflix.com/redeem and enter the code to add it to your Netflix account.', 1, 4);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (6, 'SPOTIFY', 'YZH4WSA3ZXA3VGJR1Y58Z67F42', 'Spotify', 'Go to spotify.com/redeem and enter the code to add Premium time to your account.', 1, 5);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (7, 'XBOX', 'WZRWWXPFC0MVQF4YKP3JGZK4QZ', 'Xbox', 'Sign in at redeem.microsoft.com and enter the 25-character code.', 1, 6);

INSERT INTO `giftcard_brands` (`id`, `code`, `public_id`, `name`, `redeem_instructions`, `is_active`, `sorting`)
VALUES (8, 'PLAYSTATION', 'VYRFEXZFY0K1EJA5RRZ6DZB2JF', 'PlayStation Store', 'Sign in to PlayStation Store, choose Redeem Codes, and enter the 12-digit code.', 1, 7);

-- marketplace_categories
INSERT INTO `marketplace_categories` (`id`, `slug`, `public_id`, `name`, `status`, `sort_order`, `created_at`, `updated_at`)
VALUES (1, 'DIGITAL_GOODS', '5YXQZMTA76ZK33CEEH1ATAVBA2', 'Digital goods', 'ACTIVE', 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00');

INSERT INTO `marketplace_categories` (`id`, `slug`, `public_id`, `name`, `status`, `sort_order`, `created_at`, `updated_at`)
VALUES (2, 'GAMING', 'SFFXNNNJ1SC0B4M7Q7CJRKGTXD', 'Gaming', 'ACTIVE', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00');

INSERT INTO `marketplace_categories` (`id`, `slug`, `public_id`, `name`, `status`, `sort_order`, `created_at`, `updated_at`)
VALUES (3, 'ACCOUNTS', '1XENF2R9Q35CB123AFF0P1GVQ2', 'Accounts', 'ACTIVE', 2, '2026-01-01 00:00:00', '2026-01-01 00:00:00');

INSERT INTO `marketplace_categories` (`id`, `slug`, `public_id`, `name`, `status`, `sort_order`, `created_at`, `updated_at`)
VALUES (4, 'SOFTWARE_KEYS', '6717S4G3S6CRYZ27YBT2R96262', 'Software & keys', 'ACTIVE', 3, '2026-01-01 00:00:00', '2026-01-01 00:00:00');

-- ======================================================================
-- FIRST ADMINISTRATOR
-- ======================================================================

-- A SUPER_ADMIN account so the panel can be administered the moment the
-- import finishes — no CLI user-creation step, because a cPanel account has
-- no CLI. The password hash is bcrypt; PHP rehashes it to whatever the host
-- prefers on the first successful login.

INSERT INTO `users`
  (`id`, `public_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`,
   `status`, `role`, `price_group_id`, `referral_code`, `timezone`, `locale`,
   `email_verified_at`, `mfa_enabled`, `created_at`, `updated_at`)
VALUES (1, 'CV8Z9CM2GA5C441DXFXDSQJGSC', 'admin', 'admin@example.com', '$2y$12$MJz0lE9DjLFjHFMryp5l2OEVzNVXmEpt7K2.XRvE4uXo3JUbPCmue', 'Panel', 'Administrator',
        'ACTIVE', 'SUPER_ADMIN', 1, 'ADMIN-0001', 'UTC', 'en',
        '2026-01-01 00:00:00', 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00');

INSERT INTO `wallets`
  (`public_id`, `user_id`, `balance`, `currency`, `created_at`, `updated_at`)
VALUES ('M1MEX6DZJBT0ZW588245F6123G', 1, '0.00000000', 'NGN', '2026-01-01 00:00:00', '2026-01-01 00:00:00');

INSERT INTO `referral_accounts`
  (`user_id`, `code`, `commission_percent`, `created_at`, `updated_at`)
VALUES (1, 'ADMIN-0001', '5.0000', '2026-01-01 00:00:00', '2026-01-01 00:00:00');

SET FOREIGN_KEY_CHECKS = 1;
