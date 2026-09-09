<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 017 — durable mass-order batch idempotency.
 *
 * Only hashes of client submission tokens are retained. Completed result data
 * makes an exact retry return the original per-row outcome without charging or
 * resubmitting successful rows to a provider.
 */
class Migration_Mass_orders extends CI_Migration {

    public static function statements() {
        return array(
            "CREATE TABLE IF NOT EXISTS mass_order_batches (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('mass_order_batches');
    }

    public function up() {
        foreach (self::statements() as $sql) $this->db->query($sql);
    }

    public function down() {
        foreach (array_reverse(self::tables()) as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$table);
        }
    }
}
