<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 004 — Providers
 * providers, provider_services, provider_sync_logs, provider_health_logs
 * + deferred FK services.provider_id -> providers.id
 *
 * API keys are stored encrypted at rest (EncryptionService, §62/§19).
 */
class Migration_Providers extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS providers (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              name VARCHAR(128) NOT NULL,
              api_url VARCHAR(512) NOT NULL,
              api_key_encrypted VARCHAR(512) NOT NULL COMMENT 'encrypted at rest, never logged',
              api_type VARCHAR(32) NOT NULL DEFAULT 'STANDARD_SMM',
              status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
              currency CHAR(3) NOT NULL DEFAULT 'USD',
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE services
              ADD CONSTRAINT fk_svc_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL",

            "CREATE TABLE IF NOT EXISTS provider_services (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS provider_sync_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS provider_health_logs (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              provider_id BIGINT UNSIGNED NOT NULL,
              status VARCHAR(16) NOT NULL,
              latency_ms INT NULL,
              error TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_phl_provider_created (provider_id, created_at),
              CONSTRAINT fk_phl_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('providers','provider_services','provider_sync_logs','provider_health_logs');
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        $this->db->query('ALTER TABLE services DROP FOREIGN KEY fk_svc_provider');
        foreach (array_reverse(self::tables()) as $t) { $this->db->query('DROP TABLE IF EXISTS '.$t); }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
