<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Marketplace catalogue — the platform-operated storefront (Session 30.x).
 *
 * The marketplace is no longer peer-to-peer: only staff sell, so listings gain
 * the fields a retail catalogue needs (promotion price, shelf image, featured
 * flag, fulfilment kind) and categories become managed rows instead of a free
 * text slug keyed off a regex.
 *
 * Money rules stay exactly as before: DECIMAL(20,8), the platform fee is frozen
 * on the order at purchase time, and the buyer always pays the server-side
 * price — never a submitted one.
 */
class Migration_Marketplace_catalogue extends CI_Migration {
    public static function statements() {
        return array(
            "CREATE TABLE IF NOT EXISTS marketplace_categories (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              name VARCHAR(80) NOT NULL,
              slug VARCHAR(64) NOT NULL UNIQUE,
              status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|ARCHIVED',
              sort_order INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_mpcat_status_sort (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE marketplace_listings
              ADD COLUMN promo_price DECIMAL(20,8) NULL COMMENT 'promotional price; NULL means not on sale',
              ADD COLUMN image VARCHAR(255) NULL COMMENT 'uploaded shelf image (MediaService storage key)',
              ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'featured shelf placement',
              ADD COLUMN product_type VARCHAR(16) NOT NULL DEFAULT 'DIGITAL' COMMENT 'DIGITAL|PHYSICAL'"
        );
    }

    public static function tables() {
        return array('marketplace_categories');
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query("ALTER TABLE marketplace_listings
            DROP COLUMN promo_price, DROP COLUMN image,
            DROP COLUMN is_featured, DROP COLUMN product_type");
        foreach (self::tables() as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$table);
        }
    }
}
