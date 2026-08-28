<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 027 — backfill the wallet lifetime counters.
 *
 * `wallets.total_deposited` and `wallets.total_spent` have existed since
 * migration 002 and **nothing has ever written them**. Only the demo seeder
 * set `total_deposited`, once, at seed time. Meanwhile three admin screens
 * report them as fact:
 *
 *   - Customers list → "Spent" column
 *   - Customer detail → "Total spent"
 *   - Wallets → the platform-wide deposited/spent summary
 *
 * So every customer on every install has always been shown as having spent
 * ₦0.00, no matter how much they bought, and the platform summary said the
 * same. An operator deciding who to give a discount to, or reconciling float
 * against income, was reading a column that was never maintained.
 *
 * LedgerService now maintains both counters inside the same locked
 * transaction that moves the balance. This migration makes the existing rows
 * true, by recomputing them from `wallet_transactions` — the movements are the
 * source of truth; the counters are a cache of them.
 *
 * Definitions (the same ones LedgerService keeps up to date):
 *   total_deposited = sum of CREDIT movements of type DEPOSIT
 *   total_spent     = sum of every DEBIT movement, minus REFUND credits
 *
 * Re-runnable by design: it recomputes rather than increments, so running it
 * twice is a no-op and it doubles as the repair for any future drift.
 */
class Migration_Wallet_counter_backfill extends CI_Migration {

    public static function statements() {
        return array(
            "UPDATE wallets SET
               total_deposited = COALESCE((
                 SELECT SUM(wt.amount) FROM wallet_transactions wt
                  WHERE wt.wallet_id = wallets.id
                    AND wt.direction = 'CREDIT' AND wt.type = 'DEPOSIT'), 0),
               total_spent = GREATEST(0, COALESCE((
                 SELECT SUM(wt.amount) FROM wallet_transactions wt
                  WHERE wt.wallet_id = wallets.id AND wt.direction = 'DEBIT'), 0)
                 - COALESCE((
                 SELECT SUM(wt.amount) FROM wallet_transactions wt
                  WHERE wt.wallet_id = wallets.id
                    AND wt.direction = 'CREDIT' AND wt.type = 'REFUND'), 0))",
        );
    }

    /** Creates no tables; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * Nothing to undo: the columns already existed and their previous values
     * were wrong by construction (always zero). Rolling back to "wrong" is not
     * a service to anyone, so this is deliberately a no-op.
     */
    public function down() {
    }
}
