<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 032 — let a marketplace order be refunded in part.
 *
 * Escrow shipped all-or-nothing, and module 11 recorded that as a deliberate
 * default: for a single-seller platform, "the sale stands or it does not" is
 * the right shape and the safest one to build first.
 *
 * It stops being right the moment a real dispute arrives. A five-licence
 * bundle where two keys are dead, a physical order that arrived damaged but
 * usable, an agreed goodwill discount after a late delivery — every one of
 * those has an obvious answer that the panel could not express. Staff had two
 * options: refund the whole order (giving away the four licences that worked),
 * or refund nothing and settle it by hand with a wallet adjustment, which
 * leaves the order reporting as fully paid for ever and quietly overstates
 * revenue by the amount that was actually returned.
 *
 * That second workaround is the reason this is a schema change rather than a
 * screen: the money moved, and nothing on the order said so. `refunded_amount`
 * and `refunded_quantity` make a part-refunded order tell the truth to the
 * buyer, to staff, and to every revenue figure that reads these tables.
 *
 * `PARTIALLY_REFUNDED` is a new status because the alternative — leaving the
 * order DELIVERED with a non-zero refund — hides the event on every list
 * screen in the panel.
 */
class Migration_Marketplace_partial_refunds extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE marketplace_orders
               ADD COLUMN refunded_amount DECIMAL(20,8) NOT NULL DEFAULT 0
               COMMENT 'Total returned to the buyer so far; never exceeds gross_amount'",

            "ALTER TABLE marketplace_orders
               ADD COLUMN refunded_quantity INT UNSIGNED NOT NULL DEFAULT 0
               COMMENT 'Units returned to stock so far; a goodwill discount returns none'",

            // Existing fully refunded orders are made honest: they returned
            // everything, and nothing in the old code recorded that.
            "UPDATE marketplace_orders
                SET refunded_amount = gross_amount, refunded_quantity = quantity
              WHERE status = 'REFUNDED' AND refunded_amount = 0",
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
            $this->db->query($sql);
        }
    }

    /**
     * Not reversible. Dropping these columns would erase the record of money
     * that left the platform — the ledger would still show the refund and the
     * order would claim it was paid in full.
     */
    public function down() {
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
