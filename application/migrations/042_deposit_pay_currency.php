<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 042 — deposits are charged in the DEFAULT (display) currency.
 *
 * ## The gap this closes
 *
 * The panel has two currency concepts and they had never met at the payment
 * gateway:
 *
 *   - the BASE currency (`currencies.is_base`) is the accounting currency —
 *     every wallet, order, refund and payout is denominated in it;
 *   - the DEFAULT DISPLAY currency (`default_display_currency`) is what a
 *     customer actually sees and thinks in.
 *
 * Add Funds ignored the second one entirely: `Wallet::deposit()` hardcoded
 * `'currency' => marvy_base_currency()`, so a panel keeping its books in USD
 * while serving Nigerian customers sent Paystack a USD charge. The customer
 * saw ₦ everywhere and then got asked to pay in dollars — and no rate was
 * ever shown, so there was no way to tell what ₦1,328 was going to be worth.
 *
 * From here the split is explicit on the row itself:
 *
 *   - `amount` / `fee` / `bonus` / `credited_amount` / `currency` are the
 *     CHARGE: what the customer pays, in the currency the gateway is handed.
 *     That is the default display currency (NGN), not the base currency.
 *   - `base_amount` / `credited_base_amount` / `base_currency` are the
 *     SETTLEMENT: what the wallet is credited with, in the accounting
 *     currency. `PaymentService::confirm()` credits this figure, so the
 *     ledger keeps speaking base currency exactly as it always has.
 *   - `fx_rate` is the rate PINNED when the deposit was opened: units of the
 *     charge currency per 1 unit of base. It is the number shown to the
 *     customer on Add Funds ("$1 = ₦1,328.00"), and because it is stored on
 *     the deposit rather than re-read at confirmation time, a rate change
 *     between "Continue" and the webhook cannot silently re-price a payment
 *     the customer already authorised. They pay at the rate they were shown.
 *
 * On a panel where base == display (the shipped NGN default) every new column
 * simply mirrors the old one and nothing about the existing behaviour moves.
 *
 * The backfill is therefore exact rather than a guess: every historical
 * deposit was charged in the base currency by construction, so its charge
 * currency IS its settlement currency and the pinned rate is 1.
 */
class Migration_Deposit_pay_currency extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE payment_transactions
              ADD COLUMN base_currency CHAR(3) NULL COMMENT 'accounting currency the wallet is credited in',
              ADD COLUMN base_amount DECIMAL(20,8) NULL COMMENT 'amount in base currency at fx_rate',
              ADD COLUMN credited_base_amount DECIMAL(20,8) NULL COMMENT 'what the wallet is credited, in base currency',
              ADD COLUMN fx_rate DECIMAL(20,8) NULL COMMENT 'units of the charge currency per 1 unit of base, pinned at initiation'",
        );
    }

    /** Creates no tables; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    public function up() {
        if (!$this->table_exists('payment_transactions')) return;

        foreach (self::statements() as $sql) {
            // Partially applied migrations must re-run cleanly.
            $wanted = array();
            if (preg_match_all('/ADD COLUMN (\w+)/i', $sql, $m)) $wanted = $m[1];
            $missing = array();
            foreach ($wanted as $col) {
                if (!$this->column_exists('payment_transactions', $col)) $missing[] = $col;
            }
            if (!$missing) continue;
            if (count($missing) === count($wanted)) {
                $this->db->query($sql);
                continue;
            }
            // Some columns already exist: add the rest one at a time.
            foreach ($missing as $col) {
                $this->db->query('ALTER TABLE payment_transactions ADD COLUMN '.$this->column_ddl($col));
            }
        }

        // Every deposit taken before this migration was charged in the base
        // currency, so the charge row and the settlement row are the same row.
        // One deployment window is different: PHP files may have been uploaded
        // before this SQL, and those new deposits carry their correct base leg
        // in metadata.settlement. Prefer that JSON when present so importing
        // the migration later does not relabel an NGN/default-currency payment
        // as a USD/base-currency one.
        $this->db->query(
            "UPDATE payment_transactions
                SET base_currency        = COALESCE(
                    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.settlement.base_currency')), 'null'), ''),
                    base_currency, currency),
                    base_amount          = COALESCE(
                    CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.settlement.base_amount')), 'null'), '') AS DECIMAL(20,8)),
                    base_amount, amount),
                    credited_base_amount = COALESCE(
                    CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.settlement.credited_base_amount')), 'null'), '') AS DECIMAL(20,8)),
                    credited_base_amount, credited_amount, amount),
                    fx_rate              = COALESCE(
                    CAST(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.settlement.fx_rate')), 'null'), '') AS DECIMAL(20,8)),
                    fx_rate, 1.00000000)"
        );
    }

    public function down() {
        foreach (array('base_currency', 'base_amount', 'credited_base_amount', 'fx_rate') as $col) {
            if ($this->column_exists('payment_transactions', $col)) {
                $this->db->query('ALTER TABLE payment_transactions DROP COLUMN `'.$col.'`');
            }
        }
    }

    private function column_ddl($col) {
        $ddl = array(
            'base_currency'        => "`base_currency` CHAR(3) NULL COMMENT 'accounting currency the wallet is credited in'",
            'base_amount'          => "`base_amount` DECIMAL(20,8) NULL COMMENT 'amount in base currency at fx_rate'",
            'credited_base_amount' => "`credited_base_amount` DECIMAL(20,8) NULL COMMENT 'what the wallet is credited, in base currency'",
            'fx_rate'              => "`fx_rate` DECIMAL(20,8) NULL COMMENT 'units of the charge currency per 1 unit of base, pinned at initiation'",
        );
        return $ddl[$col];
    }

    /* CI_Migration has no column_exists; portable across MySQL and SQLite. */
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
