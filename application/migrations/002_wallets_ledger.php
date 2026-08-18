<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 002 — Wallets, double-entry ledger, idempotency (§24/25/56/64)
 * wallets, wallet_transactions, ledger_entries, idempotency_keys
 *
 * Money is DECIMAL(20,8) everywhere. LedgerService is the only writer.
 * There is deliberately NO users.balance column.
 */
class Migration_Wallets_ledger extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS wallets (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL UNIQUE,
              balance DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              currency CHAR(3) NOT NULL DEFAULT 'NGN',
              total_deposited DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              total_spent DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT chk_wallets_balance CHECK (balance >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS wallet_transactions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              wallet_id BIGINT UNSIGNED NOT NULL,
              type VARCHAR(32) NOT NULL COMMENT 'wallet_tx_type',
              direction VARCHAR(8) NOT NULL COMMENT 'CREDIT|DEBIT',
              amount DECIMAL(20,8) NOT NULL,
              balance_before DECIMAL(20,8) NOT NULL,
              balance_after DECIMAL(20,8) NOT NULL,
              currency CHAR(3) NOT NULL DEFAULT 'NGN',
              reference_type VARCHAR(64) NULL COMMENT 'Order|PaymentTransaction|...',
              reference_id VARCHAR(64) NULL,
              note VARCHAR(255) NULL,
              actor_id BIGINT UNSIGNED NULL COMMENT 'admin who forced a manual entry',
              idempotency_key VARCHAR(128) NULL UNIQUE,
              metadata JSON NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_wt_wallet_created (wallet_id, created_at),
              INDEX idx_wt_type_created (type, created_at),
              INDEX idx_wt_ref (reference_type, reference_id),
              CONSTRAINT fk_wt_wallet FOREIGN KEY (wallet_id) REFERENCES wallets(id),
              CONSTRAINT fk_wt_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ledger_entries (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              wallet_transaction_id BIGINT UNSIGNED NOT NULL,
              account VARCHAR(128) NOT NULL COMMENT 'wallet:{id}|revenue|liability|provider_cost|commission',
              direction VARCHAR(8) NOT NULL,
              amount DECIMAL(20,8) NOT NULL,
              currency CHAR(3) NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_le_account_created (account, created_at),
              INDEX idx_le_wt (wallet_transaction_id),
              CONSTRAINT fk_le_wt FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS idempotency_keys (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              idem_key VARCHAR(128) NOT NULL UNIQUE,
              scope VARCHAR(64) NOT NULL COMMENT 'order:create|payment:webhook|...',
              request_hash CHAR(64) NULL,
              response JSON NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'IN_PROGRESS',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              expires_at DATETIME NOT NULL,
              INDEX idx_idem_scope_exp (scope, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('wallets','wallet_transactions','ledger_entries','idempotency_keys');
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::tables()) as $t) { $this->db->query('DROP TABLE IF EXISTS '.$t); }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
