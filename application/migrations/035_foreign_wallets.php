<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 035 — wallets that hold a currency other than the base.
 *
 * Every money column in this panel has always meant "base currency": wallets,
 * orders, service transactions, ledger entries. The `currencies` table and
 * the admin currencies screen made foreign currencies *displayable* — a
 * customer could browse a USD-converted catalogue while every charge stayed
 * in naira — but a wallet could never actually hold a dollar. The
 * unfinished-work ledger called the gap by name: "charging in a second
 * currency needs conversion at the ledger boundary and a refund-rate
 * policy."
 *
 * The wallet's own `currency` column (migration 002) finally means something:
 * it may be set to any enabled currency while the wallet is still empty and
 * has never transacted, and it is frozen the moment money moves — rewriting
 * the currency of a wallet with history would silently re-denominate every
 * balance and movement on it, the exact failure mode migration 011's header
 * warns about for the base currency itself.
 *
 * Two columns record the conversion where it happened — inside
 * LedgerService, the only writer to wallets / wallet_transactions /
 * ledger_entries, so every purchase domain (SMM orders, VTU, numbers,
 * identity, gift cards, the marketplace shop) gets foreign-wallet support
 * through the one boundary all of them already charge through:
 *
 *   - `fx_rate` — the rate pinned at the moment of the movement (units of
 *     the wallet's currency per 1 unit of base). NULL when no conversion
 *     happened: a base-currency wallet, or a wallet-currency movement such
 *     as a staff adjustment.
 *   - `base_amount` — what the movement was worth in the base currency at
 *     that pinned rate, so a transaction list can show both "$0.63" and the
 *     "₦980" it paid for, without re-deriving anything from today's rate.
 *
 * The refund-rate policy these columns enable: a refund converts at the rate
 * pinned on the original charge — never the day's rate — so a customer is
 * returned exactly what was taken and FX drift can never make a refund
 * create or destroy money.
 *
 * `ledger_entries` needs nothing: the conversion writes its own double entry
 * (the wallet side in the wallet's currency against an `fx:CODE` translation
 * account, the base side against the account the movement always used), so
 * each currency's books balance independently and the existing per-currency
 * `currency` column carries the meaning.
 */
class Migration_Foreign_wallets extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE wallet_transactions
              ADD COLUMN fx_rate DECIMAL(20,8) NULL COMMENT 'Pinned rate for a converted movement: units of the wallet currency per 1 unit of base. NULL when no conversion happened',
              ADD COLUMN base_amount DECIMAL(20,8) NULL COMMENT 'The base-currency value this movement represented at fx_rate. NULL when no conversion happened'",
        );
    }

    /** Tables this migration creates, for the schema linter and down(). */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            // Partially applied migrations must re-run cleanly.
            if (preg_match('/^ALTER TABLE wallet_transactions\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('wallet_transactions', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    public function down() {
        // The columns are pure bookkeeping on top of movements that already
        // exist; dropping them cannot corrupt anything, but the wallet
        // currency choice IS meaningful and is not un-done here — an operator
        // who needs to revert a foreign wallet must empty it first, exactly
        // like the forward rule.
        foreach (array('fx_rate', 'base_amount') as $col) {
            if ($this->column_exists('wallet_transactions', $col)) {
                $this->db->query('ALTER TABLE wallet_transactions DROP COLUMN `'.$col.'`');
            }
        }
    }

    /* CI_Migration has no column_exists; portable across MySQL and SQLite. */

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
