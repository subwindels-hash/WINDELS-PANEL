<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 024 — Currency management (Admin → Settings → Currency).
 *
 * ## What this does and does NOT do
 *
 * The `currencies` table (migration 009) already carries `code`, `symbol`,
 * `name`, `decimal_precision`, `exchange_rate`, `is_base`, `is_active` — that
 * shape was seeded from day one but never surfaced in the admin panel or read
 * by anything beyond `Currency_model::active()`/`base()`, which nothing
 * called either. This migration is purely additive:
 *
 *   1. Rate provenance columns, so a manually-entered exchange rate shows who
 *      set it, when, from what source, and since when it has been effective —
 *      exactly what an operator needs to trust (or distrust) a number that
 *      directly prices the platform's foreign-currency *display*.
 *   2. Nothing here changes `is_base`, `exchange_rate` or any existing row's
 *      values. NGN remains the accounting/base currency exactly as migration
 *      011 left it — every wallet, order, payment, earning and payout keeps
 *      meaning exactly what it always meant. This migration only adds the
 *      metadata columns; a separate, explicit admin action (never this
 *      migration) is what would change a rate going forward.
 *
 * ## Scope: display conversion, not settlement currency
 *
 * This lays the groundwork for showing prices in USD/EUR/GBP (browsing,
 * quoting) without touching how anything is charged or settled — every
 * financial table that already recorded a currency (wallets, orders, service
 * transactions, payments, earnings, payouts, gift card orders) keeps doing so
 * unchanged. Accepting a *different settlement currency* at checkout is a
 * separate, much larger change (rewiring OrderService, TransactionEngine,
 * MarketplaceService and GiftcardService) and is explicitly out of scope
 * here so as not to touch core money-movement code in one pass.
 */
class Migration_Currency_management extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE currencies
              ADD COLUMN rate_source VARCHAR(32) NULL COMMENT 'MANUAL today; a provider key (e.g. OPEN_EXCHANGE_RATES) once automatic rates exist',
              ADD COLUMN rate_updated_by BIGINT UNSIGNED NULL COMMENT 'admin who last set exchange_rate',
              ADD COLUMN rate_updated_at DATETIME NULL COMMENT 'when exchange_rate was last changed',
              ADD COLUMN rate_effective_at DATETIME NULL COMMENT 'when this rate is considered to take effect'",

            "ALTER TABLE currencies
              ADD CONSTRAINT fk_currencies_rate_updated_by FOREIGN KEY (rate_updated_by)
              REFERENCES users(id) ON DELETE SET NULL",
        );
    }

    /** Creates no new tables — only extends the existing `currencies` table. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            // Re-running a partially applied migration must not fail on
            // "duplicate column".
            if (preg_match('/^ALTER TABLE currencies\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('currencies', $m[1])) {
                continue;
            }
            if (preg_match('/^ALTER TABLE currencies\s+ADD CONSTRAINT (\w+)/i', trim($sql), $m)
                && $this->constraint_exists('currencies', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }

        // Backfill provenance for the seeded rows so the admin screen never
        // shows an unexplained rate: they were set by the platform itself,
        // at seed time, from the same figures migration 011 rebased onto NGN.
        $this->db->where('rate_updated_at IS NULL', null, false)
            ->update('currencies', array(
                'rate_source'       => 'SEED',
                'rate_updated_at'   => gmdate('Y-m-d H:i:s'),
                'rate_effective_at' => gmdate('Y-m-d H:i:s'),
            ));
    }

    public function down() {
        $this->db->query('ALTER TABLE currencies DROP FOREIGN KEY fk_currencies_rate_updated_by');
        foreach (array('rate_source', 'rate_updated_by', 'rate_updated_at', 'rate_effective_at') as $col) {
            if ($this->column_exists('currencies', $col)) {
                $this->db->query('ALTER TABLE currencies DROP COLUMN `'.$col.'`');
            }
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
