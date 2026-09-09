<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 010 — Universal transaction record + VTU
 *
 * service_transactions, provider_transactions, vtu_networks, vtu_products,
 * vtu_transactions
 *
 * §18/§19 require one transaction lifecycle shared by every service domain.
 * Rather than give each domain its own money columns, `service_transactions` is
 * the universal record: it carries service_domain/service_type and *references*
 * the wallet transaction that moved the money. LedgerService remains the only
 * writer of wallet tables, and wallet_transactions.reference_type is free text
 * ('ServiceTransaction'), so no money-table schema change was needed.
 *
 * amount is what the customer paid; provider_cost is frozen at request time so
 * margin stays auditable (§15, matching orders.provider_charge in 005).
 *
 * vtu_transactions holds only VTU-specific detail (msisdn, meter, token...) and
 * points back at its service_transaction — the same split used by orders.
 */
class Migration_Service_transactions_vtu extends CI_Migration {

    public static function statements() {
        return array(

            // ---------------------------------------------------------------
            // Universal transaction record (§19)
            // ---------------------------------------------------------------
            "CREATE TABLE IF NOT EXISTS service_transactions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              service_domain VARCHAR(24) NOT NULL COMMENT 'VTU|SMM|NUMBER|OTP|IDENTITY|GIFTCARD|EDUCATION|MARKETPLACE',
              service_type VARCHAR(32) NOT NULL COMMENT 'AIRTIME|DATA|CABLE|ELECTRICITY|EXAM_PIN|...',
              service_id BIGINT UNSIGNED NULL COMMENT 'domain-local product id, if any',
              provider_id BIGINT UNSIGNED NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|PROCESSING|SUCCESSFUL|FAILED|CANCELLED|REFUNDED',
              amount DECIMAL(20,8) NOT NULL COMMENT 'what the customer paid',
              provider_cost DECIMAL(20,8) NULL COMMENT 'frozen at request time (§15)',
              currency CHAR(3) NOT NULL DEFAULT 'NGN',
              wallet_transaction_id BIGINT UNSIGNED NULL COMMENT 'the debit; NULL until charged',
              refunded_amount DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              provider_reference VARCHAR(128) NULL,
              idempotency_key VARCHAR(128) NULL UNIQUE,
              source VARCHAR(16) NOT NULL DEFAULT 'WEB' COMMENT 'WEB|API|ADMIN|CRON',
              failure_reason VARCHAR(255) NULL,
              metadata JSON NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              completed_at DATETIME NULL,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_stx_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_stx_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
              CONSTRAINT fk_stx_wtx FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL,
              INDEX idx_stx_user_created (user_id, created_at),
              INDEX idx_stx_domain_status (service_domain, status),
              INDEX idx_stx_status_created (status, created_at),
              INDEX idx_stx_provider_ref (provider_id, provider_reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS service_transaction_status_history (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              service_transaction_id BIGINT UNSIGNED NOT NULL,
              from_status VARCHAR(16) NULL,
              to_status VARCHAR(16) NOT NULL,
              source VARCHAR(16) NOT NULL DEFAULT 'SYSTEM' COMMENT 'SYSTEM|ADMIN|PROVIDER|CUSTOMER|CRON',
              reason VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_stxh_tx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
              INDEX idx_stxh_tx (service_transaction_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Provider call log, shared by every domain (§14).
            "CREATE TABLE IF NOT EXISTS provider_transactions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              provider_id BIGINT UNSIGNED NOT NULL,
              service_transaction_id BIGINT UNSIGNED NULL,
              action VARCHAR(32) NOT NULL COMMENT 'PURCHASE|VERIFY|STATUS|BALANCE',
              provider_reference VARCHAR(128) NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
              cost DECIMAL(20,8) NULL,
              latency_ms INT NULL,
              error TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_ptx_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
              CONSTRAINT fk_ptx_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE SET NULL,
              INDEX idx_ptx_provider_created (provider_id, created_at),
              INDEX idx_ptx_ref (provider_reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // ---------------------------------------------------------------
            // VTU (§9)
            // ---------------------------------------------------------------
            "CREATE TABLE IF NOT EXISTS vtu_networks (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              code VARCHAR(32) NOT NULL UNIQUE COMMENT 'MTN|GLO|AIRTEL|9MOBILE',
              name VARCHAR(64) NOT NULL,
              service_type VARCHAR(32) NOT NULL DEFAULT 'AIRTIME' COMMENT 'AIRTIME|DATA|CABLE|ELECTRICITY|EXAM_PIN',
              msisdn_prefixes VARCHAR(255) NULL COMMENT 'comma-separated, for client-side detection',
              logo_url VARCHAR(512) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_vnet_type_active (service_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * One products table for every VTU type rather than a table per
             * bundle shape (§9: 'must support multiple data product types
             * rather than hard-coding one bundle structure').
             *
             * Airtime is variable-amount: price/face_value are NULL and the
             * customer supplies the amount between min_amount and max_amount.
             * Data/cable/exam products are fixed-price rows.
             */
            "CREATE TABLE IF NOT EXISTS vtu_products (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              network_id BIGINT UNSIGNED NOT NULL,
              provider_id BIGINT UNSIGNED NULL,
              service_type VARCHAR(32) NOT NULL COMMENT 'AIRTIME|DATA|CABLE|ELECTRICITY|EXAM_PIN',
              code VARCHAR(64) NOT NULL COMMENT 'our stable code',
              provider_code VARCHAR(64) NULL COMMENT 'what the provider calls it',
              name VARCHAR(128) NOT NULL,
              description VARCHAR(255) NULL,
              product_type VARCHAR(32) NULL COMMENT 'SME|GIFTING|CORPORATE|... for data',
              validity VARCHAR(32) NULL COMMENT '30 days, 7 days, ...',
              face_value DECIMAL(20,8) NULL COMMENT 'NULL for variable-amount products',
              price DECIMAL(20,8) NULL COMMENT 'customer price; NULL when variable',
              provider_cost DECIMAL(20,8) NULL,
              discount_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000 COMMENT 'for variable-amount airtime',
              min_amount DECIMAL(20,8) NULL,
              max_amount DECIMAL(20,8) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_vprod_network FOREIGN KEY (network_id) REFERENCES vtu_networks(id) ON DELETE CASCADE,
              CONSTRAINT fk_vprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
              UNIQUE KEY uq_vprod_code (network_id, service_type, code),
              INDEX idx_vprod_type_active (service_type, is_active, sorting)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * VTU-specific detail only. Money lives on service_transactions;
             * this table never duplicates it.
             */
            "CREATE TABLE IF NOT EXISTS vtu_transactions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
              network_id BIGINT UNSIGNED NULL,
              product_id BIGINT UNSIGNED NULL,
              service_type VARCHAR(32) NOT NULL,
              recipient VARCHAR(64) NOT NULL COMMENT 'msisdn, smartcard or meter number',
              recipient_name VARCHAR(128) NULL COMMENT 'resolved by verification, where supported',
              variation_code VARCHAR(64) NULL,
              face_value DECIMAL(20,8) NULL,
              token VARCHAR(128) NULL COMMENT 'electricity token / exam PIN',
              units VARCHAR(64) NULL COMMENT 'electricity units',
              extra JSON NULL COMMENT 'meter_type, exam serial, ...',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_vtx_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
              CONSTRAINT fk_vtx_network FOREIGN KEY (network_id) REFERENCES vtu_networks(id) ON DELETE SET NULL,
              CONSTRAINT fk_vtx_product FOREIGN KEY (product_id) REFERENCES vtu_products(id) ON DELETE SET NULL,
              INDEX idx_vtx_recipient (recipient),
              INDEX idx_vtx_type (service_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array(
            'service_transactions', 'service_transaction_status_history',
            'provider_transactions', 'vtu_networks', 'vtu_products', 'vtu_transactions',
        );
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
