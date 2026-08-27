<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 020 — six-digit user code, security PIN, Blockonomics payments.
 *
 * Three additions, all additive (no column is dropped or retyped, so this is
 * safe to run against a populated production database):
 *
 *  1. `users.user_code` — a unique six-digit account number a customer can
 *     quote to support and sign in with. The ULID `public_id` stays the
 *     canonical identifier everywhere internally; this is a human-facing
 *     handle, nothing more. Backfilled for existing rows before the UNIQUE
 *     index is added, so the constraint can never fail on legacy data.
 *
 *  2. `users.pin_hash` / `pin_set_at` / `pin_failed_attempts` /
 *     `pin_locked_until` — an optional four-digit transaction PIN, stored as a
 *     password_hash() digest exactly like the password. Administrators can
 *     clear it but can never read it. The lock columns exist because a
 *     four-digit secret is brute-forceable in 10,000 guesses and must be rate
 *     limited at rest, not just per request.
 *
 *  3. `blockonomics_addresses` — one row per generated BTC receive address,
 *     tying it to the payment transaction that is waiting on it. Blockonomics
 *     issues an address per payment and calls back as confirmations arrive;
 *     the unique key on `address` is what makes repeated callbacks idempotent.
 */
class Migration_User_code_pin_blockonomics extends CI_Migration {

    public static function statements() {
        return array(

            // --- 1. six-digit user code ---------------------------------
            "ALTER TABLE users
              ADD COLUMN user_code CHAR(6) NULL COMMENT 'human-facing six-digit account number'",

            // --- 2. transaction PIN -------------------------------------
            "ALTER TABLE users
              ADD COLUMN pin_hash VARCHAR(255) NULL COMMENT 'password_hash of the 4-digit PIN; never reversible',
              ADD COLUMN pin_set_at DATETIME NULL,
              ADD COLUMN pin_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
              ADD COLUMN pin_locked_until DATETIME NULL",

            // --- 3. Blockonomics receive addresses ----------------------
            "CREATE TABLE IF NOT EXISTS blockonomics_addresses (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              payment_transaction_id BIGINT UNSIGNED NOT NULL,
              user_id BIGINT UNSIGNED NOT NULL,
              crypto VARCHAR(8) NOT NULL DEFAULT 'BTC' COMMENT 'BTC|USDT',
              address VARCHAR(128) NOT NULL UNIQUE,
              expected_crypto_amount DECIMAL(20,8) NULL COMMENT 'quoted at initiation',
              received_crypto_amount DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              fiat_amount DECIMAL(20,8) NOT NULL,
              fiat_currency CHAR(3) NOT NULL,
              rate_used DECIMAL(20,8) NULL COMMENT 'fiat per 1 crypto unit at initiation',
              confirmations INT NOT NULL DEFAULT 0,
              required_confirmations INT NOT NULL DEFAULT 2,
              txid VARCHAR(128) NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'AWAITING' COMMENT 'AWAITING|PARTIAL|CONFIRMING|PAID|EXPIRED',
              expires_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_blk_status (status, created_at),
              INDEX idx_blk_user (user_id, created_at),
              CONSTRAINT fk_blk_tx FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE,
              CONSTRAINT fk_blk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /** Tables this migration creates, for the schema linter and down(). */
    public static function tables() {
        return array('blockonomics_addresses');
    }

    public function up() {
        foreach (self::statements() as $sql) {
            // Re-running a partially applied migration must not fail on
            // "duplicate column": each ALTER is skipped when its column is
            // already there.
            if (preg_match('/^ALTER TABLE users\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('users', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }

        $this->backfill_user_codes();

        // The UNIQUE index goes on *after* the backfill, so existing rows
        // cannot collide on NULL/duplicate values while it is being created.
        if (!$this->index_exists('users', 'uq_users_user_code')) {
            $this->db->query('ALTER TABLE users ADD UNIQUE KEY uq_users_user_code (user_code)');
        }
    }

    public function down() {
        $this->db->query('DROP TABLE IF EXISTS blockonomics_addresses');
        foreach (array('user_code', 'pin_hash', 'pin_set_at', 'pin_failed_attempts', 'pin_locked_until') as $col) {
            if ($this->column_exists('users', $col)) {
                $this->db->query('ALTER TABLE users DROP COLUMN `'.$col.'`');
            }
        }
    }

    /**
     * Give every existing account a six-digit code.
     *
     * Codes start at 100000 so they are always six digits (no leading-zero
     * ambiguity when someone reads one over the phone), and are allocated by
     * probing for a free value rather than from a counter, so a partially
     * backfilled table resumes correctly.
     */
    private function backfill_user_codes() {
        $rows = $this->db->select('id')->where('user_code IS NULL', null, false)->get('users')->result();
        if (!$rows) return;

        $taken = array();
        foreach ($this->db->select('user_code')->where('user_code IS NOT NULL', null, false)->get('users')->result() as $r) {
            $taken[(string)$r->user_code] = true;
        }

        foreach ($rows as $row) {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $code = (string) random_int(100000, 999999);
                if (isset($taken[$code])) continue;
                $taken[$code] = true;
                $this->db->where('id', $row->id)->update('users', array('user_code' => $code));
                break;
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
