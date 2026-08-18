<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 016 — customer withdrawal requests.
 *
 * Wallet funds are reserved through LedgerService when the request is opened.
 * The payout destination is encrypted at rest and omitted from every queue;
 * only an explicit, audited reveal can retrieve it.
 */
class Migration_Withdrawals extends CI_Migration {

    public static function statements() {
        return array(
            "CREATE TABLE IF NOT EXISTS withdrawal_requests (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              wallet_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'wallet debit reserving the requested amount',
              refund_wallet_transaction_id BIGINT UNSIGNED NULL UNIQUE,
              amount DECIMAL(20,8) NOT NULL COMMENT 'gross amount reserved from the wallet',
              fee_amount DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              payout_amount DECIMAL(20,8) NOT NULL COMMENT 'net amount transferred to the customer',
              currency CHAR(3) NOT NULL DEFAULT 'NGN',
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|APPROVED|PAID|REJECTED|CANCELLED',
              destination_label VARCHAR(120) NOT NULL COMMENT 'safe masked bank and last four digits',
              destination_encrypted MEDIUMTEXT NOT NULL,
              idempotency_key VARCHAR(128) NOT NULL UNIQUE,
              payout_reference VARCHAR(128) NULL,
              admin_note VARCHAR(500) NULL,
              approved_at DATETIME NULL,
              approved_by BIGINT UNSIGNED NULL,
              paid_at DATETIME NULL,
              paid_by BIGINT UNSIGNED NULL,
              resolved_at DATETIME NULL,
              reveal_count INT UNSIGNED NOT NULL DEFAULT 0,
              last_revealed_at DATETIME NULL,
              last_revealed_by BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_withdrawal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
              CONSTRAINT fk_withdrawal_wallet_tx FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE RESTRICT,
              CONSTRAINT fk_withdrawal_refund_tx FOREIGN KEY (refund_wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL,
              CONSTRAINT fk_withdrawal_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
              CONSTRAINT fk_withdrawal_payer FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
              CONSTRAINT fk_withdrawal_revealer FOREIGN KEY (last_revealed_by) REFERENCES users(id) ON DELETE SET NULL,
              INDEX idx_withdrawal_user_created (user_id, created_at),
              INDEX idx_withdrawal_queue (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS withdrawal_events (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              withdrawal_id BIGINT UNSIGNED NOT NULL,
              actor_id BIGINT UNSIGNED NULL,
              event_type VARCHAR(32) NOT NULL,
              from_status VARCHAR(16) NULL,
              to_status VARCHAR(16) NULL,
              note VARCHAR(500) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_withdrawal_event_request FOREIGN KEY (withdrawal_id) REFERENCES withdrawal_requests(id) ON DELETE CASCADE,
              CONSTRAINT fk_withdrawal_event_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
              INDEX idx_withdrawal_event_created (withdrawal_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('withdrawal_requests', 'withdrawal_events');
    }

    public function up() {
        foreach (self::statements() as $sql) $this->db->query($sql);
    }

    public function down() {
        $this->db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse(self::tables()) as $table) {
            $this->db->query('DROP TABLE IF EXISTS '.$table);
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS=1');
    }
}
