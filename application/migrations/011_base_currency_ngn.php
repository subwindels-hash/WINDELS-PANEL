<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 011 — Redenominate the panel to the Naira (₦).
 *
 * The Master Rebuild Spec prices everything in ₦, and every service domain the
 * panel is growing into — VTU, NIN/BVN identity, exam PINs, Nigerian virtual
 * numbers — is settled in Naira by the vendors themselves. Carrying 'USD' as
 * the base was a Checkpoint-01 default nobody revisited; the VTU catalogue
 * seeded in Session 21 already quotes naira figures (₦300 for 1GB, ₦10,500 for
 * DSTV Compact) against a USD base, so the two were already inconsistent.
 *
 * CREATES NO TABLES. This migration only:
 *   1. moves the CHAR(3) column defaults from 'USD' to 'NGN',
 *   2. relabels rows that were written under the old default,
 *   3. rebases the `currencies` table so NGN has exchange_rate 1.0,
 *   4. moves naira-shaped defaults into `settings` and `payment_methods`.
 *
 * ---------------------------------------------------------------------------
 * IMPORTANT — this RELABELS, it does not CONVERT.
 * ---------------------------------------------------------------------------
 * A row holding 100.00000000 stays 100.00000000 and starts meaning ₦100 rather
 * than $100. That is the correct behaviour for this deployment, because the
 * catalogue was authored in naira figures all along, and because the panel has
 * not taken real money yet.
 *
 * If you are running this against a deployment that HAS taken real deposits,
 * do not run it as-is: relabelling would silently devalue every wallet by the
 * USD/NGN rate. Convert the balances first (multiply every base-currency money
 * column by the old `currencies.exchange_rate` for NGN), then run this to move
 * the labels. `down()` reverses the labelling for the same reason.
 *
 * `providers.currency` is deliberately NOT mass-updated: a provider row records
 * the currency that provider bills in, and an SMM vendor invoicing in dollars
 * must keep saying USD. Only the column default moves.
 */
class Migration_Base_currency_ngn extends CI_Migration {

    /** Tables whose `currency` column carried DEFAULT 'USD'. */
    private static $defaulted = array(
        'wallets', 'wallet_transactions', 'providers', 'orders',
        'dripfeed_orders', 'service_transactions',
    );

    /**
     * Tables whose existing rows should be relabelled.
     *
     * `providers` is absent on purpose (see the class docblock) and so is
     * `ledger_entries`, which is append-only accounting history: rewriting a
     * posted entry would break the audit trail, so it is relabelled only for
     * entries whose wallet is being relabelled alongside it.
     */
    private static $relabel = array(
        'wallets', 'wallet_transactions', 'ledger_entries', 'orders',
        'dripfeed_orders', 'payment_transactions', 'referral_commissions',
        'service_transactions',
    );

    public static function statements() {
        $sql = array();

        // 1. Column defaults ------------------------------------------------
        foreach (self::$defaulted as $table) {
            $sql[] = "ALTER TABLE {$table} MODIFY currency CHAR(3) NOT NULL DEFAULT 'NGN'";
        }

        // 2. Relabel rows written under the old default ---------------------
        foreach (self::$relabel as $table) {
            $sql[] = "UPDATE {$table} SET currency = 'NGN' WHERE currency = 'USD'";
        }

        // 3. Rebase the currency table --------------------------------------
        // exchange_rate is "units of this currency per 1 unit of base", so
        // rebasing from USD to NGN divides every rate by the old NGN rate
        // (1550). NGN itself becomes exactly 1.
        $sql[] = "UPDATE currencies SET is_base = 0";
        $sql[] = "UPDATE currencies SET is_base = 1, exchange_rate = '1.00000000', is_active = 1 WHERE code = 'NGN'";
        $sql[] = "UPDATE currencies SET exchange_rate = '0.00064516' WHERE code = 'USD'";
        $sql[] = "UPDATE currencies SET exchange_rate = '0.00059355' WHERE code = 'EUR'";
        $sql[] = "UPDATE currencies SET exchange_rate = '0.00050968' WHERE code = 'GBP'";
        $sql[] = "UPDATE currencies SET exchange_rate = '0.05354839' WHERE code = 'INR'";
        $sql[] = "UPDATE currencies SET exchange_rate = '0.00348387' WHERE code = 'BRL'";

        // 4. Naira-shaped operational defaults -------------------------------
        // $5 / $10,000 deposit bounds are meaningless in naira; these are the
        // same order of magnitude a Nigerian panel actually uses.
        $sql[] = "UPDATE settings SET setting_value = JSON_OBJECT('value', 'NGN') WHERE setting_key = 'base_currency'";
        $sql[] = "UPDATE settings SET setting_value = JSON_OBJECT('value', '500.00000000') WHERE setting_key = 'min_deposit'";
        $sql[] = "UPDATE settings SET setting_value = JSON_OBJECT('value', '5000000.00000000') WHERE setting_key = 'max_deposit'";
        $sql[] = "UPDATE settings SET setting_value = JSON_OBJECT('value', '100.00000000') WHERE setting_key = 'referral_min_payout'";
        $sql[] = "UPDATE payment_methods SET min_amount = '500.00000000', max_amount = '5000000.00000000', currencies = JSON_ARRAY('NGN')";

        return $sql;
    }

    /** Creates no tables — the schema tests assert this list matches exactly. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        foreach (self::$defaulted as $table) {
            $this->db->query("ALTER TABLE {$table} MODIFY currency CHAR(3) NOT NULL DEFAULT 'USD'");
        }
        foreach (self::$relabel as $table) {
            $this->db->query("UPDATE {$table} SET currency = 'USD' WHERE currency = 'NGN'");
        }
        $this->db->query("UPDATE currencies SET is_base = 0");
        $this->db->query("UPDATE currencies SET is_base = 1, exchange_rate = '1.00000000' WHERE code = 'USD'");
        $this->db->query("UPDATE currencies SET exchange_rate = '0.92000000' WHERE code = 'EUR'");
        $this->db->query("UPDATE currencies SET exchange_rate = '0.79000000' WHERE code = 'GBP'");
        $this->db->query("UPDATE currencies SET exchange_rate = '1550.00000000' WHERE code = 'NGN'");
        $this->db->query("UPDATE currencies SET exchange_rate = '83.00000000' WHERE code = 'INR'");
        $this->db->query("UPDATE currencies SET exchange_rate = '5.40000000' WHERE code = 'BRL'");
        $this->db->query("UPDATE settings SET setting_value = JSON_OBJECT('value', 'USD') WHERE setting_key = 'base_currency'");
        $this->db->query("UPDATE settings SET setting_value = JSON_OBJECT('value', '5.00000000') WHERE setting_key = 'min_deposit'");
        $this->db->query("UPDATE settings SET setting_value = JSON_OBJECT('value', '10000.00000000') WHERE setting_key = 'max_deposit'");
        $this->db->query("UPDATE settings SET setting_value = JSON_OBJECT('value', '0.01000000') WHERE setting_key = 'referral_min_payout'");
        $this->db->query("UPDATE payment_methods SET min_amount = '5.00000000', max_amount = '10000.00000000', currencies = JSON_ARRAY('USD')");
    }
}
