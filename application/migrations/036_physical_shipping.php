<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 036 — make physical fulfilment part of the marketplace escrow
 * contract.
 *
 * Migration 025 created the shipment table and the checkout collected a
 * shipping method, but the method's price never entered the transaction and a
 * shipment status could be changed independently of the marketplace order.
 * That left a physical order paid forever after delivery, or allowed an
 * operator to mark a shipment cancelled without returning the buyer's money.
 *
 * The product amount remains the order's unit price; this column records the
 * separately quoted shipping charge. `gross_amount` and the universal service
 * transaction include both values, so refunds and revenue reporting use the
 * amount the customer actually paid.
 */
class Migration_Physical_shipping extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE marketplace_orders
              ADD COLUMN shipping_cost DECIMAL(20,8) NOT NULL DEFAULT 0
              COMMENT 'Shipping charge included in gross_amount; base currency'",
        );
    }

    /** This migration creates no tables. */
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

    /** The column is financial history; do not silently erase it on rollback. */
    public function down() {
        // Intentionally irreversible. The ledger and service transaction still
        // contain the total paid, but removing this label would make old order
        // receipts and shipment records ambiguous.
    }

    private function column_exists($table, $column) {
        try {
            foreach ($this->db->field_data($table) as $field) {
                if ($field->name === $column) return true;
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }
}
