<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Marketplace — platform-operated digital goods with buyer-protection escrow.
 *
 * The platform is the sole seller: there is deliberately no vendor/seller
 * entity, application flow, payout or fee split. Listings are created and
 * fulfilled by staff, buyers pay the platform, and an order closes when the
 * buyer accepts delivery, an administrator resolves a dispute, or the bounded
 * auto-release worker runs. (Migration 019 retires the multi-vendor columns
 * and tables on databases built before this shape became canonical.)
 *
 * Money remains authoritative in service_transactions and the append-only
 * ledger. Digital fulfilment is encrypted at rest; it is never selected by
 * list queries and can only be opened by the audited MarketplaceService
 * reveal path.
 */
class Migration_Marketplace extends CI_Migration {
    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS marketplace_listings (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS marketplace_orders (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS marketplace_order_events (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function tables() {
        return array(
            'marketplace_listings',
            'marketplace_orders', 'marketplace_order_events'
        );
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::tables()) as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$table);
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
