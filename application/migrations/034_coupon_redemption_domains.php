<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 034 — coupons stop being a shop-only feature.
 *
 * A coupon could only be redeemed against a marketplace checkout, because
 * `coupon_redemptions` carried exactly one link — `marketplace_order_id` —
 * and only ShopCheckoutService ever took one. The unfinished-work ledger
 * said it plainly: "An operator expecting a site-wide promo code will not
 * find one."
 *
 * This migration widens the redemption row so any purchase domain can name
 * what it discounted:
 *
 *   - `domain` — which checkout redeemed it: SHOP, SMM, VTU, NUMBER,
 *     IDENTITY or GIFTCARD. Existing rows are backfilled to SHOP, which is
 *     what every one of them was.
 *   - `reference` — the public_id of the order or service transaction the
 *     discount applied to. The marketplace_order_id foreign key stays
 *     untouched for shop rows; a second, string reference lets the other
 *     domains link without inventing five more FK columns against five more
 *     tables (a gift card order is not a marketplace order, and pretending
 *     otherwise in the schema would be worse than an indexed string).
 *
 * The race-safe slot machinery from migration 030 (UNIQUE (coupon_id,
 * user_id, redemption_slot)) is untouched: every domain reserves before it
 * charges and releases if the charge never lands, exactly like the shop
 * checkout already did.
 */
class Migration_Coupon_redemption_domains extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE coupon_redemptions
              ADD COLUMN domain VARCHAR(16) NULL COMMENT 'Which checkout redeemed it: SHOP|SMM|VTU|NUMBER|IDENTITY|GIFTCARD. Rows from before this column existed are all SHOP',
              ADD COLUMN reference VARCHAR(64) NULL COMMENT 'Public id of the discounted order / service transaction'",

            "UPDATE coupon_redemptions SET domain = 'SHOP' WHERE domain IS NULL",

            "CREATE INDEX idx_couponredeem_reference ON coupon_redemptions (domain, reference)",
        );
    }

    /** Tables this migration creates, for the schema linter and down(). */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            // Partially applied migrations must re-run cleanly.
            if (preg_match('/^ALTER TABLE coupon_redemptions\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('coupon_redemptions', $m[1])) {
                continue;
            }
            if (preg_match('/^CREATE INDEX (\w+) ON/i', trim($sql), $m)
                && $this->index_exists('coupon_redemptions', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    public function down() {
        if ($this->index_exists('coupon_redemptions', 'idx_couponredeem_reference')) {
            $this->db->query('DROP INDEX idx_couponredeem_reference ON coupon_redemptions');
        }
        foreach (array('domain', 'reference') as $col) {
            if ($this->column_exists('coupon_redemptions', $col)) {
                $this->db->query('ALTER TABLE coupon_redemptions DROP COLUMN `'.$col.'`');
            }
        }
    }

    private function index_exists($table, $index) {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                array($table, $index)
            )->row();
            return $row && (int)$row->n > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /** Partially applied migrations must re-run cleanly. */
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
}
