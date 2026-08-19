<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 019 — remove the marketplace vendor/seller feature from existing
 * databases. The platform is now the sole seller; there is no vendor concept.
 *
 * This migration is the UPGRADE half of the removal: it exists only for
 * databases created while the feature existed (any install that ran the old
 * marketplace migration with `marketplace_sellers`). Fresh installs apply the
 * edited `015_marketplace`, which never creates the vendor shape, so every
 * guard here no-ops cleanly for them.
 *
 * Upgrade matrix (sequential CI3 migrations store one integer version):
 *
 *   - Fresh install        : runs 001–019. No table/column exists to drop;
 *                            the DELETEs match nothing. Vendor shape never
 *                            comes back.
 *   - Old install at v15–18: has marketplace_sellers + seller columns.
 *                            Upgrading runs this file: the table, the seller
 *                            columns and the vendor permissions/settings are
 *                            removed. Order and listing histories survive.
 *
 * What is deliberately KEPT: marketplace_orders / marketplace_listings rows
 * written while vendors existed (drops are column- and table-level only),
 * historical wallet_transactions of type MARKETPLACE_PAYOUT, audit_logs rows,
 * and notifications — the historical record of money that already moved. The
 * ledger stays balanced and the audit trail stays intact; what makes a vendor
 * impossible is the table, the columns, the permission and the settings, and
 * those are what this migration removes.
 *
 * Dependency analysis (why this order is safe):
 *   1. marketplace_orders.payout_wallet_transaction_id → wallet_transactions
 *      (fk_mporder_payout); seller_id → users (fk_mporder_seller) — FKs are
 *      resolved dynamically and dropped before their columns, because MySQL
 *      refuses to drop a constrained column.
 *   2. marketplace_listings.seller_id → marketplace_sellers (fk_mplisting_seller)
 *      — same FK-then-column order; dropping it severs the last dependency on
 *      the vendors table.
 *   3. marketplace_sellers has no remaining inbound FKs → DROP TABLE.
 *   FK names are LOOKED UP in information_schema rather than assumed, so a
 *   database whose operators renamed constraints still upgrades cleanly.
 */
class Migration_Remove_marketplace_vendors extends CI_Migration {

    /** Tables this migration permanently removes (created by the OLD 015). */
    public static function dropped_tables() {
        return array('marketplace_sellers');
    }

    /** Nothing is created: statements() stays empty for the schema export. */
    public static function statements() {
        return array();
    }

    /** This migration creates nothing — the SchemaTest tables() contract. */
    public static function tables() {
        return array();
    }

    public function up() {
        // ── 1. marketplace_orders: vendor money columns ────────────────────
        foreach (array('payout_wallet_transaction_id', 'seller_id', 'fee_amount',
                       'seller_amount') as $column) {
            $this->drop_column_with_fk_guards('marketplace_orders', $column);
        }
        $this->drop_index_if_exists('marketplace_orders', 'idx_mporder_seller');

        // ── 2. marketplace_listings: vendor ownership column ───────────────
        $this->drop_column_with_fk_guards('marketplace_listings', 'seller_id');
        $this->drop_index_if_exists('marketplace_listings', 'idx_mplisting_seller');

        // ── 3. the vendor table itself ─────────────────────────────────────
        $this->db->query('DROP TABLE IF EXISTS marketplace_sellers');

        // ── 4. permissions & settings that described the vendor feature ────
        $this->db->query(
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.perm_key = 'marketplace.moderate_sellers'"
        );
        $this->db->query(
            "DELETE FROM permissions WHERE perm_key = 'marketplace.moderate_sellers'"
        );
        $this->db->query(
            "DELETE FROM settings WHERE setting_key = 'marketplace_fee_percent'"
        );
    }

    /**
     * Rollback is intentionally a no-op (same rationale as migration 018):
     * resurrecting a removed feature from a rollback would silently restore
     * vendor behavior without the application code that manages it. The
     * forward-only path keeps the runtime and schema in one consistent shape.
     */
    public function down() {
    }

    /* ------------------------------------------------------------------ */

    /** Drop $table.$column, first detaching any FK that constrains it. */
    private function drop_column_with_fk_guards($table, $column) {
        if (!$this->column_exists($table, $column)) return;

        // Detach foreign keys resolved from information_schema (works even
        // when a live install's operators renamed our default FK names).
        $fks = $this->db->query(
            "SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
            array($table, $column)
        )->result();
        foreach ($fks as $fk) {
            $this->db->query(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table,
                preg_replace('/[^A-Za-z0-9_]/', '', $fk->name)
            ));
        }
        $this->db->query(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
    }

    private function column_exists($table, $column) {
        $row = $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            array($table, $column)
        )->row();
        return $row && (int)$row->n > 0;
    }

    private function drop_index_if_exists($table, $index) {
        $row = $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            array($table, $index)
        )->row();
        if ($row && (int)$row->n > 0) {
            // The composite vendor indexes lose their first column to the
            // DROP COLUMN above; dropping by name here keeps the shape exact.
            $this->db->query(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
        }
    }
}
