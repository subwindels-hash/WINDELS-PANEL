<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 026 — coupon discovery.
 *
 * Migration 025 shipped `coupons` with everything needed to *redeem* a code
 * but no way for a customer to *discover* one without being told the code
 * out-of-band — the cart page only ever had a manual code-entry box. This
 * adds a single additive `is_public` flag an admin sets when creating or
 * editing a coupon; `/cart` lists every currently-valid coupon with that
 * flag set (still subject to the exact same active/date-window/usage-limit
 * rules `Coupon_model::find_valid()` already enforces at apply time — this
 * is a listing, not a second source of truth about validity).
 *
 * Purely additive: existing coupons default to NOT publicly listed (0), so
 * every coupon created before this migration keeps behaving exactly as it
 * did — invite/code-only — unless an admin explicitly opts it into the
 * public list.
 */
class Migration_Coupon_discovery extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE coupons
              ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0
              COMMENT 'Shown in the cart page discovery list when 1; still requires is_active + date window + usage limit to actually apply'",
        );
    }

    public function up() {
        foreach (self::statements() as $sql) {
            if (preg_match('/^ALTER TABLE (\w+)\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists($m[1], $m[2])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    public function down() {
        if ($this->column_exists('coupons', 'is_public')) {
            $this->db->query('ALTER TABLE coupons DROP COLUMN `is_public`');
        }
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
}
