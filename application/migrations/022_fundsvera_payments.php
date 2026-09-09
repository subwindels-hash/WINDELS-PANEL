<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 022 — Fundsvera payment collection.
 *
 * Fundsvera is a Nigerian collections provider: it issues virtual bank accounts
 * and time-limited "secured checkout" transfer accounts, then calls a webhook
 * when money lands. Its documented API is collections-only — there is no
 * disbursement endpoint — so this migration adds nothing about paying money
 * out. See docs/payments-fundsvera.md.
 *
 * Three additions:
 *
 *  1. `payment_transactions` gains the reference/timestamp columns the brief
 *     asks for. `public_id` already existed as our identifier, but Fundsvera
 *     requires a `request_id` of at least 20 characters that is unique per
 *     business, so `internal_reference` is its own column with its own UNIQUE
 *     constraint rather than overloading the ULID.
 *
 *  2. `fundsvera_virtual_accounts` — the persistent per-customer account. Their
 *     API returns the existing account rather than creating a duplicate, so
 *     this table mirrors that: one row per user.
 *
 *  3. `fundsvera_checkouts` — one row per secured-checkout attempt, holding the
 *     30-minute account details and the amount we expect. The webhook is
 *     matched against this, which is what makes "verify the amount" possible
 *     rather than trusting whatever the callback claims.
 */
class Migration_Fundsvera_payments extends CI_Migration {

    public static function tables() {
        return array('fundsvera_virtual_accounts', 'fundsvera_checkouts');
    }

    public static function statements() {
        return array(
            // --- 1. payment_transactions: references and lifecycle stamps ---
            "ALTER TABLE payment_transactions
              ADD COLUMN internal_reference VARCHAR(64) NULL COMMENT 'our reference sent to the provider as request_id'",
            "ALTER TABLE payment_transactions
              ADD COLUMN provider VARCHAR(32) NULL COMMENT 'gateway code that owns this transaction'",
            "ALTER TABLE payment_transactions
              ADD COLUMN payment_method VARCHAR(32) NULL COMMENT 'bank_transfer|virtual_account|manual|...'",
            "ALTER TABLE payment_transactions
              ADD COLUMN initiated_at DATETIME NULL",
            "ALTER TABLE payment_transactions
              ADD COLUMN paid_at DATETIME NULL",
            "ALTER TABLE payment_transactions
              ADD COLUMN failed_at DATETIME NULL",

            // --- 2. persistent virtual accounts -----------------------------
            "CREATE TABLE IF NOT EXISTS fundsvera_virtual_accounts (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL UNIQUE,
              account_number VARCHAR(32) NOT NULL,
              account_name VARCHAR(160) NOT NULL,
              bank_name VARCHAR(120) NOT NULL,
              bank_code VARCHAR(16) NOT NULL,
              account_status VARCHAR(24) NOT NULL DEFAULT 'Active',
              customer_email VARCHAR(255) NOT NULL,
              customer_phone VARCHAR(32) NULL,
              raw_response MEDIUMTEXT NULL COMMENT 'provider payload, for support',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_fva_account (account_number, bank_code),
              CONSTRAINT fk_fva_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- 3. secured-checkout attempts -------------------------------
            "CREATE TABLE IF NOT EXISTS fundsvera_checkouts (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              payment_transaction_id BIGINT UNSIGNED NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              request_id VARCHAR(64) NOT NULL UNIQUE COMMENT 'sent to Fundsvera; >= 20 chars',
              trx_ref VARCHAR(128) NULL COMMENT 'provider reference returned at initiation',
              expected_amount DECIMAL(20,8) NOT NULL COMMENT 'what the webhook must match',
              currency CHAR(3) NOT NULL DEFAULT 'NGN',
              account_number VARCHAR(32) NULL,
              account_name VARCHAR(160) NULL,
              bank_name VARCHAR(120) NULL,
              checkout_url VARCHAR(512) NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|PAID|EXPIRED|FAILED',
              amount_paid DECIMAL(20,8) NULL,
              settlement_amount DECIMAL(20,8) NULL,
              provider_fee DECIMAL(20,8) NULL,
              expires_at DATETIME NULL,
              paid_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_fvc_status (status, created_at),
              INDEX idx_fvc_user (user_id, created_at),
              INDEX idx_fvc_trx (trx_ref),
              CONSTRAINT fk_fvc_tx FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE,
              CONSTRAINT fk_fvc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public function up() {
        foreach (self::statements() as $sql) {
            if (preg_match('/^ALTER TABLE payment_transactions\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('payment_transactions', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }

        // Backfill so existing rows satisfy the UNIQUE index, then add it.
        $this->backfill_references();
        if (!$this->index_exists('payment_transactions', 'uq_pt_internal_reference')) {
            $this->db->query('ALTER TABLE payment_transactions
                ADD UNIQUE KEY uq_pt_internal_reference (internal_reference)');
        }
    }

    public function down() {
        $this->db->query('DROP TABLE IF EXISTS fundsvera_checkouts');
        $this->db->query('DROP TABLE IF EXISTS fundsvera_virtual_accounts');
        foreach (array('internal_reference', 'provider', 'payment_method',
                       'initiated_at', 'paid_at', 'failed_at') as $col) {
            if ($this->column_exists('payment_transactions', $col)) {
                $this->db->query('ALTER TABLE payment_transactions DROP COLUMN `'.$col.'`');
            }
        }
    }

    /**
     * Give historical transactions an internal reference.
     *
     * Derived from the existing public_id so it is stable and traceable back to
     * the row it names, rather than a fresh random value that means nothing.
     */
    private function backfill_references() {
        $rows = $this->db->select('id, public_id, created_at')
                         ->where('internal_reference IS NULL', null, false)
                         ->get('payment_transactions')->result();
        foreach ($rows as $row) {
            $this->db->where('id', $row->id)->update('payment_transactions', array(
                'internal_reference' => 'MVS-'.strtoupper((string)$row->public_id),
                'initiated_at'       => $row->created_at,
            ));
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

    private function index_exists($table, $index) {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                array($table, $index)
            )->row();
            return $row && (int)$row->n > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
