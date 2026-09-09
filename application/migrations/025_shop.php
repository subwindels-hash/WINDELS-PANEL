<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 025 — Shop: cart, checkout, coupons, digital delivery, shipping,
 * gift-card storefront connection, and reviews.
 *
 * ## Reusing what already exists, not duplicating it
 *
 * The platform already has a staff-run, platform-as-sole-seller catalogue —
 * `marketplace_listings` / `marketplace_orders` (migrations 015/017/019/030)
 * — with escrow, digital delivery (encrypted), stock, promo pricing, images
 * and admin moderation already built and tested. That IS the shop's product
 * and order backbone; this migration does not create a second, parallel
 * "products" table. It only adds what genuinely does not exist yet:
 *
 *   - a real multi-item shopping cart (today a listing is bought one at a
 *     time, immediately, with no basket in between)
 *   - coupons/discounts a cart or order can apply
 *   - a secure digital-file delivery model (today's digital delivery is a
 *     block of encrypted *text* — a license key or instructions — which is
 *     exactly right for those, but there is no "download this file" path)
 *   - shipping details, carrier and tracking number for PHYSICAL listings
 *     (today `product_type = PHYSICAL` exists but nothing records an
 *     address, a shipping method or a tracking number)
 *   - product reviews
 *   - a formal link from a marketplace order to the gift-card system, so a
 *     GIFT_CARD-category purchase can hand the buyer a real, already-built
 *     giftcard_orders/giftcard_codes row instead of a second bespoke
 *     gift-card implementation living only in escrow-delivery text
 *
 * Every new table is additive; nothing here alters `marketplace_listings` or
 * `marketplace_orders` beyond one new nullable link column each, and no
 * existing row's meaning changes.
 */
class Migration_Shop extends CI_Migration {

    public static function statements() {
        return array(

            // --- Cart -------------------------------------------------------
            "CREATE TABLE IF NOT EXISTS shopping_carts (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'one open cart per account',
              currency CHAR(3) NOT NULL COMMENT 'base currency at the time items were added',
              coupon_code VARCHAR(32) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS cart_items (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              cart_id BIGINT UNSIGNED NOT NULL,
              listing_id BIGINT UNSIGNED NOT NULL,
              quantity INT UNSIGNED NOT NULL DEFAULT 1,
              quoted_unit_price DECIMAL(20,8) NOT NULL COMMENT 're-quoted from the listing at checkout, never trusted from this row',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_cart_listing (cart_id, listing_id),
              CONSTRAINT fk_cartitem_cart FOREIGN KEY (cart_id) REFERENCES shopping_carts(id) ON DELETE CASCADE,
              CONSTRAINT fk_cartitem_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Coupons / discounts -----------------------------------------
            "CREATE TABLE IF NOT EXISTS coupons (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              code VARCHAR(32) NOT NULL UNIQUE,
              description VARCHAR(255) NULL,
              discount_type VARCHAR(16) NOT NULL DEFAULT 'PERCENT' COMMENT 'PERCENT|FIXED',
              discount_value DECIMAL(20,8) NOT NULL,
              currency CHAR(3) NULL COMMENT 'required when discount_type = FIXED',
              min_order_amount DECIMAL(20,8) NULL,
              max_discount_amount DECIMAL(20,8) NULL COMMENT 'caps a PERCENT discount in absolute terms',
              usage_limit INT UNSIGNED NULL COMMENT 'NULL = unlimited total redemptions',
              usage_limit_per_user INT UNSIGNED NULL DEFAULT 1,
              times_used INT UNSIGNED NOT NULL DEFAULT 0,
              starts_at DATETIME NULL,
              ends_at DATETIME NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_by_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_coupon_active (is_active, starts_at, ends_at),
              CONSTRAINT fk_coupon_creator FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS coupon_redemptions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              coupon_id BIGINT UNSIGNED NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              marketplace_order_id BIGINT UNSIGNED NULL,
              discount_amount DECIMAL(20,8) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_couponredeem_coupon (coupon_id),
              INDEX idx_couponredeem_user (user_id),
              CONSTRAINT fk_couponredeem_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE CASCADE,
              CONSTRAINT fk_couponredeem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_couponredeem_order FOREIGN KEY (marketplace_order_id) REFERENCES marketplace_orders(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Digital files (downloadable products / assets) -------------
            // Separate from marketplace_orders.delivery_encrypted, which stays
            // exactly what it is (an encrypted text block — license keys,
            // instructions). This is for an actual FILE a customer downloads.
            "CREATE TABLE IF NOT EXISTS digital_products (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              listing_id BIGINT UNSIGNED NOT NULL UNIQUE,
              storage_key VARCHAR(255) NOT NULL COMMENT 'path under the private storage/ directory, never web-accessible directly',
              original_filename VARCHAR(255) NOT NULL,
              mime_type VARCHAR(128) NOT NULL,
              size_bytes BIGINT UNSIGNED NOT NULL,
              download_limit INT UNSIGNED NULL COMMENT 'NULL = unlimited downloads per order',
              link_ttl_hours INT UNSIGNED NOT NULL DEFAULT 168 COMMENT 'how long a generated download link stays valid',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_digitalproduct_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS digital_deliveries (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              marketplace_order_id BIGINT UNSIGNED NOT NULL,
              digital_product_id BIGINT UNSIGNED NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              download_count INT UNSIGNED NOT NULL DEFAULT 0,
              last_downloaded_at DATETIME NULL,
              last_download_ip VARCHAR(45) NULL,
              revoked TINYINT(1) NOT NULL DEFAULT 0,
              revoked_reason VARCHAR(255) NULL,
              revoked_by BIGINT UNSIGNED NULL,
              revoked_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_digitaldelivery_order (marketplace_order_id, digital_product_id),
              INDEX idx_digitaldelivery_user (user_id),
              CONSTRAINT fk_digitaldelivery_order FOREIGN KEY (marketplace_order_id) REFERENCES marketplace_orders(id) ON DELETE CASCADE,
              CONSTRAINT fk_digitaldelivery_product FOREIGN KEY (digital_product_id) REFERENCES digital_products(id) ON DELETE CASCADE,
              CONSTRAINT fk_digitaldelivery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_digitaldelivery_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Physical fulfilment -----------------------------------------
            "CREATE TABLE IF NOT EXISTS shipping_methods (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              name VARCHAR(120) NOT NULL,
              carrier VARCHAR(80) NULL,
              price DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              currency CHAR(3) NOT NULL,
              estimated_days_min INT UNSIGNED NULL,
              estimated_days_max INT UNSIGNED NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_shipmethod_active (is_active, sorting)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS shipping_addresses (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              full_name VARCHAR(160) NOT NULL,
              phone VARCHAR(32) NOT NULL,
              line1 VARCHAR(255) NOT NULL,
              line2 VARCHAR(255) NULL,
              city VARCHAR(120) NOT NULL,
              state VARCHAR(120) NULL,
              postal_code VARCHAR(32) NULL,
              country_code CHAR(2) NOT NULL,
              is_default TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_shipaddr_user (user_id),
              CONSTRAINT fk_shipaddr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS physical_products (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              listing_id BIGINT UNSIGNED NOT NULL UNIQUE,
              sku VARCHAR(64) NOT NULL UNIQUE,
              weight_grams INT UNSIGNED NULL,
              length_cm DECIMAL(8,2) NULL,
              width_cm DECIMAL(8,2) NULL,
              height_cm DECIMAL(8,2) NULL,
              requires_shipping TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_physicalproduct_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // One row per physical order's fulfilment state — kept separate
            // from marketplace_orders (which stays domain-agnostic) rather
            // than bolting shipping-only columns onto every order, digital
            // or not.
            "CREATE TABLE IF NOT EXISTS shop_order_shipments (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              marketplace_order_id BIGINT UNSIGNED NOT NULL UNIQUE,
              shipping_address_id BIGINT UNSIGNED NOT NULL,
              shipping_method_id BIGINT UNSIGNED NULL,
              shipping_cost DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING'
                COMMENT 'PENDING|PROCESSING|SHIPPED|DELIVERED|CANCELLED|RETURNED',
              carrier VARCHAR(80) NULL,
              tracking_number VARCHAR(120) NULL,
              tracking_url VARCHAR(500) NULL,
              shipped_at DATETIME NULL,
              delivered_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_shipment_order FOREIGN KEY (marketplace_order_id) REFERENCES marketplace_orders(id) ON DELETE CASCADE,
              CONSTRAINT fk_shipment_address FOREIGN KEY (shipping_address_id) REFERENCES shipping_addresses(id) ON DELETE RESTRICT,
              CONSTRAINT fk_shipment_method FOREIGN KEY (shipping_method_id) REFERENCES shipping_methods(id) ON DELETE SET NULL,
              INDEX idx_shipment_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Reviews -------------------------------------------------------
            "CREATE TABLE IF NOT EXISTS product_reviews (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              listing_id BIGINT UNSIGNED NOT NULL,
              marketplace_order_id BIGINT UNSIGNED NOT NULL COMMENT 'proof of a completed purchase',
              user_id BIGINT UNSIGNED NOT NULL,
              rating TINYINT UNSIGNED NOT NULL COMMENT '1-5',
              title VARCHAR(160) NULL,
              body TEXT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|APPROVED|REJECTED',
              moderated_by BIGINT UNSIGNED NULL,
              moderated_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_review_order (marketplace_order_id) COMMENT 'one review per completed purchase',
              INDEX idx_review_listing_status (listing_id, status),
              CONSTRAINT fk_review_listing FOREIGN KEY (listing_id) REFERENCES marketplace_listings(id) ON DELETE CASCADE,
              CONSTRAINT fk_review_order FOREIGN KEY (marketplace_order_id) REFERENCES marketplace_orders(id) ON DELETE CASCADE,
              CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_review_moderator FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- Gift-card storefront connection -----------------------------
            // Links a marketplace order to the ALREADY-BUILT gift card system
            // (giftcard_products/giftcard_orders/giftcard_codes, migration 014)
            // instead of creating a second, unrelated gift-card implementation.
            // A GIFT_CARD-category marketplace listing points at a real
            // giftcard_products row; buying it drives the existing
            // GiftcardService and this table just records which marketplace
            // order the resulting giftcard_orders row belongs to.
            "ALTER TABLE marketplace_listings
              ADD COLUMN giftcard_product_id BIGINT UNSIGNED NULL COMMENT 'set only for category=GIFT_CARD listings; points at the real gift card catalogue'",
            "ALTER TABLE marketplace_listings
              ADD CONSTRAINT fk_listing_giftcard_product FOREIGN KEY (giftcard_product_id)
              REFERENCES giftcard_products(id) ON DELETE SET NULL",

            "ALTER TABLE marketplace_orders
              ADD COLUMN giftcard_order_id BIGINT UNSIGNED NULL COMMENT 'set when this order fulfilled through the gift card system'",
            "ALTER TABLE marketplace_orders
              ADD CONSTRAINT fk_order_giftcard_order FOREIGN KEY (giftcard_order_id)
              REFERENCES giftcard_orders(id) ON DELETE SET NULL",

            // Currency, explicitly, on the two tables the earlier audit found
            // relying on an undocumented base-currency convention. Additive
            // and NOT NULL with the base currency as default so every existing
            // row is correctly labelled the moment the column exists — nothing
            // is reinterpreted, because the base currency is exactly what
            // these amounts already meant.
            "ALTER TABLE marketplace_listings
              ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'NGN' COMMENT 'currency of price/promo_price'",
            "ALTER TABLE marketplace_orders
              ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'NGN' COMMENT 'currency of unit_price/gross_amount'",
        );
    }

    public static function tables() {
        return array(
            'shopping_carts', 'cart_items',
            'coupons', 'coupon_redemptions',
            'digital_products', 'digital_deliveries',
            'shipping_methods', 'shipping_addresses', 'physical_products', 'shop_order_shipments',
            'product_reviews',
        );
    }

    public function up() {
        foreach (self::statements() as $sql) {
            if (preg_match('/^ALTER TABLE (\w+)\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists($m[1], $m[2])) {
                continue;
            }
            if (preg_match('/^ALTER TABLE (\w+)\s+ADD CONSTRAINT (\w+)/i', trim($sql), $m)
                && $this->constraint_exists($m[1], $m[2])) {
                continue;
            }
            $this->db->query($sql);
        }

        // Rebase currency to whatever the base currency actually is right
        // now, in case this install already redenominated (migration 011)
        // before this migration ran — the DEFAULT above only helps brand-new
        // rows, this backfills existing ones to the true current base.
        $base = function_exists('marvy_base_currency') ? marvy_base_currency() : 'NGN';
        $this->db->query("UPDATE marketplace_listings SET currency = ? WHERE currency = 'NGN'", array($base));
        $this->db->query("UPDATE marketplace_orders SET currency = ? WHERE currency = 'NGN'", array($base));
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::tables()) as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$table);
        }
        foreach (array(
            array('marketplace_orders', 'fk_order_giftcard_order'),
            array('marketplace_listings', 'fk_listing_giftcard_product'),
        ) as $fk) {
            if ($this->constraint_exists($fk[0], $fk[1])) {
                $this->db->query('ALTER TABLE '.$fk[0].' DROP FOREIGN KEY '.$fk[1]);
            }
        }
        foreach (array(
            array('marketplace_listings', 'giftcard_product_id'),
            array('marketplace_listings', 'currency'),
            array('marketplace_orders', 'giftcard_order_id'),
            array('marketplace_orders', 'currency'),
        ) as $col) {
            if ($this->column_exists($col[0], $col[1])) {
                $this->db->query('ALTER TABLE '.$col[0].' DROP COLUMN `'.$col[1].'`');
            }
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private function column_exists($table, $column) {
        try {
            foreach ($this->db->field_data($table) as $field) {
                if ($field->name === $column) return true;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }

    private function constraint_exists($table, $name) {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
                array($table, $name)
            )->row();
            return $row && (int)$row->n > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
