<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 018 — remove the withdrawals feature from existing databases.
 *
 * The feature is gone from the code entirely (Session 30). This migration is
 * the UPGRADE half of that removal: it exists only for databases that were
 * created while the feature existed, i.e. any install that ran the old
 * `016_withdrawals` migration.
 *
 * Upgrade matrix (sequential CI3 migrations store one integer version):
 *
 *   - Fresh install        : runs 001–018. The two DROPs are no-ops, the
 *                            DELETEs match nothing. Tables never come back.
 *   - Old install at v17   : old layout was 001–015 + 016_withdrawals +
 *                            017_mass_orders, target 17. Upgrading runs only
 *                            this file: the feature's tables are dropped.
 *   - Old install at v≤15  : runs 016_mass_orders, 017_marketplace_catalogue
 *                            and this file — every path lands on the new shape.
 *
 * What is deliberately KEPT: wallet_transactions / ledger_entries rows of type
 * WITHDRAWAL, audit_logs rows, and notifications created while the feature was
 * live. Those are the historical record of money that already moved; the
 * double-entry ledger stays balanced and the audit trail stays intact. What
 * makes the feature impossible are the tables, permissions and settings, and
 * those are what this migration removes.
 *
 * Dependency analysis (why this is safe):
 *   withdrawal_events → FK INTO withdrawal_requests (child) — dropped first.
 *   withdrawal_requests → FKs INTO users + wallet_transactions — the CHILD
 *   side of both relationships, so dropping it never touches wallet, ledger
 *   or any other financial table. Nothing references these two tables.
 */
class Migration_Remove_withdrawals extends CI_Migration {

    /**
     * This migration creates no tables. The codebase's tables() contract is
     * "tables this migration creates" (SchemaTest), so it stays empty here.
     */
    public static function tables() {
        return array();
    }

    /** Dedicated tables dropped by this migration (historical feature). */
    public static function dropped_tables() {
        return array('withdrawal_events', 'withdrawal_requests');
    }

    /**
     * Statement-free on purpose: docs/database.sql is the CREATE-time schema,
     * and a DROP retrofit does not belong in a generated fresh-install dump.
     * The exporter tolerates migrations with no statements().
     */
    public static function statements() {
        return array();
    }

    /** Permission keys seeded while the feature existed. */
    private static function permission_keys() {
        return array('withdrawals.view', 'withdrawals.process', 'withdrawals.reveal');
    }

    /** Setting keys seeded while the feature existed. */
    private static function setting_keys() {
        return array(
            'withdrawal_min_amount', 'withdrawal_max_amount',
            'withdrawal_fee_fixed', 'withdrawal_fee_percent',
            'withdrawal_require_verified_identity',
        );
    }

    public function up() {
        $quoted_perms = "'".implode("','", self::permission_keys())."'";
        $quoted_settings = "'".implode("','", self::setting_keys())."'";

        // Child first, parent second. IF EXISTS keeps fresh installs harmless.
        foreach (self::dropped_tables() as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$table);
        }

        // RBAC rows: grants first, then the permission definitions.
        $this->db->query(
            "DELETE rp FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.perm_key IN (".$quoted_perms.")"
        );
        $this->db->query(
            "DELETE FROM permissions WHERE perm_key IN (".$quoted_perms.")"
        );

        // Settings rows seeded for the feature.
        $this->db->query(
            "DELETE FROM settings WHERE setting_key IN (".$quoted_settings.")"
        );
    }

    /**
     * There is no down(): the feature is being removed, not versioned. Rolling
     * back would resurrect code that no longer exists in the tree.
     */
    public function down() {}
}
