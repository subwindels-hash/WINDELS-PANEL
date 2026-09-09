<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 003 — Services & Categories
 * service_categories, services, service_prices, user_service_prices, service_favorites
 *
 * Note: services.provider_id FK is added in 004_providers (providers table is created there).
 * PricingService resolves user > group > default; never duplicate that logic (§17).
 */
class Migration_Services extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS service_categories (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS services (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS service_prices (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS user_service_prices (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS service_favorites (
              user_id BIGINT UNSIGNED NOT NULL,
              service_id BIGINT UNSIGNED NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (user_id, service_id),
              INDEX idx_fav_svc (service_id),
              CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_fav_svc FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('service_categories','services','service_prices','user_service_prices','service_favorites');
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
