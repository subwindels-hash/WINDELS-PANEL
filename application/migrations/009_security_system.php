<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 009 — Security & System
 * audit_logs, api_keys, api_usage_logs, blacklisted_emails/ips/links,
 * settings, notifications, notification_preferences, feature_flags,
 * email_templates, email_queue, currencies, job_runs
 *
 * No license_keys / purchase_codes / domain_locks tables (§81).
 */
class Migration_Security_system extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS audit_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS api_keys (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS api_usage_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS blacklisted_emails (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              email VARCHAR(255) NOT NULL UNIQUE,
              reason TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS blacklisted_ips (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              ip VARCHAR(45) NOT NULL UNIQUE,
              reason TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS blacklisted_links (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              pattern VARCHAR(512) NOT NULL UNIQUE COMMENT 'domain or regex',
              reason TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS settings (
              setting_key VARCHAR(128) PRIMARY KEY,
              setting_value JSON NOT NULL,
              category VARCHAR(64) NOT NULL COMMENT 'general|branding|currency|homepage|security|...',
              is_public TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'safe to expose to the browser',
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_settings_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS notifications (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS notification_preferences (
              user_id BIGINT UNSIGNED NOT NULL,
              type VARCHAR(64) NOT NULL,
              in_app TINYINT(1) NOT NULL DEFAULT 1,
              email TINYINT(1) NOT NULL DEFAULT 1,
              PRIMARY KEY (user_id, type),
              CONSTRAINT fk_np_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS feature_flags (
              flag_key VARCHAR(128) PRIMARY KEY,
              enabled TINYINT(1) NOT NULL DEFAULT 0,
              description VARCHAR(255) NULL,
              payload JSON NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS email_templates (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              template_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'e.g. order.completed',
              subject VARCHAR(255) NOT NULL,
              body_html TEXT NOT NULL,
              body_text TEXT NULL,
              variables JSON NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS email_queue (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS currencies (
              code CHAR(3) PRIMARY KEY,
              symbol VARCHAR(8) NOT NULL,
              name VARCHAR(64) NOT NULL,
              decimal_precision TINYINT NOT NULL DEFAULT 2,
              exchange_rate DECIMAL(20,8) NOT NULL DEFAULT 1.00000000,
              is_base TINYINT(1) NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS job_runs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('audit_logs','api_keys','api_usage_logs','blacklisted_emails','blacklisted_ips','blacklisted_links','settings','notifications','notification_preferences','feature_flags','email_templates','email_queue','currencies','job_runs');
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
