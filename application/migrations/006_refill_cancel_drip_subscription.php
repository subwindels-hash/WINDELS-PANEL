<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 006 — Refill / Cancellation / Drip-feed / Subscriptions
 * refills, refill_status_history, cancellation_requests, dripfeed_orders,
 * dripfeed_runs, subscriptions, subscription_events
 * + deferred FKs from orders (dripfeed_order_id, subscription_id)
 */
class Migration_Refill_cancel_drip_subscription extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS refills (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS refill_status_history (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              refill_id BIGINT UNSIGNED NOT NULL,
              previous_status VARCHAR(16) NULL,
              new_status VARCHAR(16) NOT NULL,
              source VARCHAR(32) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_rsh_ref (refill_id, created_at),
              CONSTRAINT fk_rsh_ref FOREIGN KEY (refill_id) REFERENCES refills(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cancellation_requests (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS dripfeed_orders (
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
              currency CHAR(3) NOT NULL DEFAULT 'USD',
              fields JSON NULL,
              start_at DATETIME NULL,
              next_run_at DATETIME NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_df_user_status (user_id, status),
              INDEX idx_df_next_run (next_run_at, status),
              CONSTRAINT fk_df_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_df_svc FOREIGN KEY (service_id) REFERENCES services(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS dripfeed_runs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS subscriptions (
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
              INDEX idx_sub_next_exec (next_execution_at, status),
              CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_sub_svc FOREIGN KEY (service_id) REFERENCES services(id),
              CONSTRAINT fk_sub_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS subscription_events (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              subscription_id BIGINT UNSIGNED NOT NULL,
              type VARCHAR(64) NOT NULL,
              payload JSON NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_se_sub_created (subscription_id, created_at),
              CONSTRAINT fk_se_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE orders
              ADD CONSTRAINT fk_ord_drip FOREIGN KEY (dripfeed_order_id) REFERENCES dripfeed_orders(id) ON DELETE SET NULL",

            "ALTER TABLE orders
              ADD CONSTRAINT fk_ord_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL",
        );
    }

    public static function tables() {
        return array('refills','refill_status_history','cancellation_requests','dripfeed_orders','dripfeed_runs','subscriptions','subscription_events');
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->query('ALTER TABLE orders DROP FOREIGN KEY fk_ord_drip');
        $this->db->query('ALTER TABLE orders DROP FOREIGN KEY fk_ord_sub');
        foreach (array_reverse(self::tables()) as $t) { $this->db->query('DROP TABLE IF EXISTS '.$t); }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
