<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 007 — Payments
 * payment_methods, payment_transactions, payment_webhooks, payment_events
 *
 * Webhook idempotency is enforced by uq_gateway_event (gateway_type, event_id) (§64).
 * Gateway credentials live encrypted in payment_methods.config_encrypted (never in repo).
 */
class Migration_Payments extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS payment_methods (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS payment_transactions (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS payment_webhooks (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS payment_events (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              payment_transaction_id BIGINT UNSIGNED NOT NULL,
              from_status VARCHAR(16) NULL,
              to_status VARCHAR(16) NOT NULL,
              source VARCHAR(16) NOT NULL DEFAULT 'SYSTEM',
              reason TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_pe_pt_created (payment_transaction_id, created_at),
              CONSTRAINT fk_pe_pt FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('payment_methods','payment_transactions','payment_webhooks','payment_events');
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::tables()) as $t) { $this->db->query('DROP TABLE IF EXISTS '.$t); }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
