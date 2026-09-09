<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 030 — make "one coupon per customer" true under concurrency.
 *
 * Module 14 taught `usage_limit_per_user` to be enforced, and enforced it with
 * a `COUNT(*)` over `coupon_redemptions` taken just before the row was
 * written. That is a check-then-act race: two checkouts by the same customer,
 * a few milliseconds apart, both count zero redemptions, both decide they are
 * within the limit, and both write one. A "one per customer" launch code is
 * then worth two — and the panel shows nothing wrong, because after the fact
 * the data is perfectly consistent with two legitimate redemptions.
 *
 * A few milliseconds is not a theoretical window. It is exactly what a
 * double-clicked Pay button, a retried request on a flaky mobile connection,
 * or two browser tabs produce, and it is trivially weaponisable by anyone who
 * reads a discount code off social media.
 *
 * A counter cannot close that; only the database can. This adds
 * `redemption_slot` — which redemption *number* this is for this customer on
 * this coupon — and a UNIQUE index over `(coupon_id, user_id,
 * redemption_slot)`. Two concurrent checkouts both compute slot 1, both try to
 * insert it, and the second one is refused by the index rather than by a
 * count. `Coupon_model::reserve_redemption()` takes the slot BEFORE the money
 * moves and releases it if the checkout does not complete, so the loser of the
 * race is told "You have already used this coupon" instead of being charged
 * and reconciled later.
 *
 * Backfill: existing rows are numbered per (coupon, user) in id order, so the
 * historical data satisfies the new index and the next redemption continues
 * the sequence.
 */
class Migration_Coupon_redemption_slots extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE coupon_redemptions
               ADD COLUMN redemption_slot INT UNSIGNED NOT NULL DEFAULT 1
               COMMENT 'Which redemption number this is for this customer on this coupon; unique with (coupon_id,user_id)'",

            // Number the rows that already exist, per customer per coupon, in
            // the order they were taken. A correlated count of earlier ids,
            // with no window function (MySQL 5.7) and no UPDATE alias (SQLite,
            // which the dev harness speaks); the derived table is what lets
            // MySQL read the table it is updating.
            "UPDATE coupon_redemptions
                SET redemption_slot = 1 + (
                  SELECT COUNT(*) FROM (SELECT id, coupon_id, user_id FROM coupon_redemptions) e
                   WHERE e.coupon_id = coupon_redemptions.coupon_id
                     AND e.user_id = coupon_redemptions.user_id
                     AND e.id < coupon_redemptions.id
                )",

            "CREATE UNIQUE INDEX uq_couponredeem_slot
               ON coupon_redemptions (coupon_id, user_id, redemption_slot)",
        );
    }

    /** Creates no tables; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            if (preg_match('/^ALTER TABLE (\w+)\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists($m[1], $m[2])) {
                continue;
            }
            if (preg_match('/^CREATE UNIQUE INDEX (\w+)/i', trim($sql), $m) && $this->index_exists($m[1])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    /**
     * Reversible, unlike 029: dropping the index only removes a guarantee, it
     * does not expose anything. The column is left in place because rolling
     * the index back and then forward again must not lose the numbering.
     */
    public function down() {
        if ($this->index_exists('uq_couponredeem_slot')) {
            $this->db->query('DROP INDEX uq_couponredeem_slot ON coupon_redemptions');
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

    private function index_exists($name) {
        try {
            $rows = $this->db->query("SHOW INDEX FROM coupon_redemptions")->result();
            foreach ($rows as $row) {
                if (isset($row->Key_name) && $row->Key_name === $name) return true;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }
}
