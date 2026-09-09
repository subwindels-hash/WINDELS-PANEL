<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 005 — Orders
 * orders, order_status_history, provider_orders
 *
 * provider_charge is frozen at order time (§56). Status changes always append to
 * order_status_history with a source (SYSTEM|ADMIN|PROVIDER|CUSTOMER|CRON|WORKER) (§26/29).
 * dripfeed_order_id / subscription_id FKs are added in 006.
 */
class Migration_Orders extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS orders (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS order_status_history (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS provider_orders (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('orders','order_status_history','provider_orders');
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
