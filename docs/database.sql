-- WINDELS PANEL — MySQL / MariaDB schema
--
-- GENERATED FILE — do not edit by hand.
-- Source of truth: application/migrations/*.php
-- Regenerate with: php tools/export_schema.php
--
-- Engine: InnoDB · Charset: utf8mb4_unicode_ci · Timestamps: UTC DATETIME
-- Money:  DECIMAL(20,8) everywhere (bcmath in PHP, never floats)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
