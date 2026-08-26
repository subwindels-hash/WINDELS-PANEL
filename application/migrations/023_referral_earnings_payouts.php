<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 023 — referral codes, campaigns, the earnings ledger and payouts.
 *
 * ## Why earnings are a separate balance from the wallet
 *
 * The existing `wallets` balance is money the customer *paid in* to spend on
 * services. Migration 018 deliberately removed withdrawals from it: a balance
 * you can top up with a card and then cash out is money transmission, and the
 * panel is not licensed for that.
 *
 * Referral and campaign earnings are different in kind — they are money the
 * platform *owes the user*, never money the user deposited. Paying that out is
 * ordinary commission settlement, not money transmission. So earnings live in
 * their own ledger with their own balance, and `payout_requests` can only ever
 * draw on that ledger. Deposited funds remain non-withdrawable; the separation
 * is structural, not a policy check that could be forgotten.
 *
 * ## Tables
 *
 *   referral_codes      — named/vanity codes (JOHN8K24), many per user
 *   referral_campaigns  — marketing codes (FACEBOOK2026) with budget + rules
 *   referral_visits     — click attribution, pre-registration
 *   referral_signups    — the qualification state machine per referred user
 *   earnings            — the append-only earning ledger (the source of truth)
 *   payout_requests     — controlled withdrawal of AVAILABLE earnings only
 *
 * The legacy `referral_accounts`/`referrals`/`referral_commissions` tables from
 * migration 008 are left untouched and still drive AffiliateService's per-order
 * commission. This migration adds the code/campaign/qualification layer around
 * them rather than replacing a working system mid-flight.
 */
class Migration_Referral_earnings_payouts extends CI_Migration {

    public static function tables() {
        return array(
            'referral_codes', 'referral_campaigns', 'referral_visits',
            'referral_signups', 'earnings', 'payout_requests',
        );
    }

    public static function statements() {
        return array(

            // --- named / vanity referral codes ---------------------------
            "CREATE TABLE IF NOT EXISTS referral_codes (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NULL COMMENT 'NULL for a platform/campaign code',
              code VARCHAR(32) NOT NULL,
              label VARCHAR(120) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              total_visits INT UNSIGNED NOT NULL DEFAULT 0,
              total_signups INT UNSIGNED NOT NULL DEFAULT 0,
              total_qualified INT UNSIGNED NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_referral_code (code),
              INDEX idx_rc_user (user_id, is_active),
              CONSTRAINT fk_refcode_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- advertising / promotional campaigns ----------------------
            "CREATE TABLE IF NOT EXISTS referral_campaigns (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              name VARCHAR(160) NOT NULL,
              code VARCHAR(32) NOT NULL UNIQUE,
              source VARCHAR(64) NULL COMMENT 'facebook|instagram|tiktok|influencer|partner',
              campaign_type VARCHAR(32) NOT NULL DEFAULT 'ACQUISITION',
              reward_amount DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              reward_percent DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
              qualify_event VARCHAR(32) NOT NULL DEFAULT 'FIRST_ORDER'
                COMMENT 'REGISTERED|EMAIL_VERIFIED|FIRST_DEPOSIT|FIRST_ORDER',
              hold_hours INT UNSIGNED NOT NULL DEFAULT 72,
              max_rewards INT UNSIGNED NULL COMMENT 'NULL = unlimited',
              budget DECIMAL(20,8) NULL COMMENT 'NULL = uncapped',
              spent DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              cost DECIMAL(20,8) NULL COMMENT 'what the advert itself cost, for ROI',
              geo_allow VARCHAR(255) NULL COMMENT 'comma-separated ISO-2, NULL = anywhere',
              starts_at DATETIME NULL,
              ends_at DATETIME NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|PAUSED|ENDED',
              total_visits INT UNSIGNED NOT NULL DEFAULT 0,
              total_signups INT UNSIGNED NOT NULL DEFAULT 0,
              total_qualified INT UNSIGNED NOT NULL DEFAULT 0,
              created_by_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_camp_status (status, starts_at, ends_at),
              CONSTRAINT fk_camp_creator FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- click attribution ---------------------------------------
            "CREATE TABLE IF NOT EXISTS referral_visits (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              code VARCHAR(32) NOT NULL,
              referral_code_id BIGINT UNSIGNED NULL,
              campaign_id BIGINT UNSIGNED NULL,
              visitor_hash CHAR(64) NOT NULL COMMENT 'salted hash of IP+UA; never the raw IP',
              landing_path VARCHAR(255) NULL,
              converted_user_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_visit_code_created (code, created_at),
              INDEX idx_visit_campaign (campaign_id, created_at),
              INDEX idx_visit_hash (visitor_hash, created_at),
              CONSTRAINT fk_visit_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id) ON DELETE SET NULL,
              CONSTRAINT fk_visit_campaign FOREIGN KEY (campaign_id) REFERENCES referral_campaigns(id) ON DELETE SET NULL,
              CONSTRAINT fk_visit_user FOREIGN KEY (converted_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- the qualification state machine --------------------------
            "CREATE TABLE IF NOT EXISTS referral_signups (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              referrer_user_id BIGINT UNSIGNED NULL COMMENT 'NULL for a pure campaign signup',
              referred_user_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'one attribution per account, ever',
              referral_code VARCHAR(32) NOT NULL,
              referral_code_id BIGINT UNSIGNED NULL,
              campaign_id BIGINT UNSIGNED NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING'
                COMMENT 'PENDING|QUALIFIED|REWARDED|REJECTED|FRAUD_REVIEW',
              fraud_flags VARCHAR(255) NULL,
              signup_ip_hash CHAR(64) NULL,
              qualified_at DATETIME NULL,
              rewarded_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_signup_referrer (referrer_user_id, status),
              INDEX idx_signup_campaign (campaign_id, status),
              INDEX idx_signup_status (status, created_at),
              CONSTRAINT fk_signup_referrer FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE SET NULL,
              CONSTRAINT fk_signup_referred FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_signup_code FOREIGN KEY (referral_code_id) REFERENCES referral_codes(id) ON DELETE SET NULL,
              CONSTRAINT fk_signup_campaign FOREIGN KEY (campaign_id) REFERENCES referral_campaigns(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- the earnings ledger --------------------------------------
            // Append-only in practice: a reversal is a new negative-signed row
            // (status REVERSED on the original + a REVERSAL entry), never an
            // edit. The user's balance is always a SUM over this table, so
            // there is no cached number that can silently disagree with it.
            "CREATE TABLE IF NOT EXISTS earnings (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              source VARCHAR(32) NOT NULL COMMENT 'REFERRAL|CAMPAIGN|PARTNER|AFFILIATE|MANUAL|REVERSAL',
              referral_signup_id BIGINT UNSIGNED NULL,
              campaign_id BIGINT UNSIGNED NULL,
              amount DECIMAL(20,8) NOT NULL COMMENT 'negative for a reversal',
              currency CHAR(3) NOT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING'
                COMMENT 'PENDING|AVAILABLE|LOCKED|PAID|REVERSED',
              description VARCHAR(255) NULL,
              idempotency_key VARCHAR(160) NOT NULL UNIQUE
                COMMENT 'the duplicate-earning guard; one key per earning event',
              payout_request_id BIGINT UNSIGNED NULL,
              available_at DATETIME NULL COMMENT 'end of the holding period',
              paid_out_at DATETIME NULL,
              reversed_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_earn_user_status (user_id, status),
              INDEX idx_earn_release (status, available_at),
              INDEX idx_earn_campaign (campaign_id),
              CONSTRAINT fk_earn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_earn_signup FOREIGN KEY (referral_signup_id) REFERENCES referral_signups(id) ON DELETE SET NULL,
              CONSTRAINT fk_earn_campaign FOREIGN KEY (campaign_id) REFERENCES referral_campaigns(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // --- controlled payout of earnings ----------------------------
            "CREATE TABLE IF NOT EXISTS payout_requests (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              amount DECIMAL(20,8) NOT NULL,
              currency CHAR(3) NOT NULL,
              method VARCHAR(32) NOT NULL DEFAULT 'BANK_TRANSFER'
                COMMENT 'BANK_TRANSFER (manual) | WALLET_CREDIT (spend on the panel)',
              destination VARCHAR(255) NULL COMMENT 'bank account, masked in views',
              destination_name VARCHAR(160) NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'REQUESTED'
                COMMENT 'REQUESTED|APPROVED|REJECTED|PAID|CANCELLED',
              idempotency_key VARCHAR(160) NOT NULL UNIQUE,
              reviewed_by_id BIGINT UNSIGNED NULL,
              review_note VARCHAR(500) NULL,
              payout_reference VARCHAR(160) NULL COMMENT 'bank/provider reference, recorded on payment',
              requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              reviewed_at DATETIME NULL,
              paid_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_payout_status (status, requested_at),
              INDEX idx_payout_user (user_id, status),
              CONSTRAINT fk_payout_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_payout_reviewer FOREIGN KEY (reviewed_by_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE earnings
              ADD CONSTRAINT fk_earn_payout FOREIGN KEY (payout_request_id)
              REFERENCES payout_requests(id) ON DELETE SET NULL",
        );
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        // Child-first: earnings references payout_requests.
        $this->db->query('DROP TABLE IF EXISTS earnings');
        $this->db->query('DROP TABLE IF EXISTS payout_requests');
        $this->db->query('DROP TABLE IF EXISTS referral_signups');
        $this->db->query('DROP TABLE IF EXISTS referral_visits');
        $this->db->query('DROP TABLE IF EXISTS referral_campaigns');
        $this->db->query('DROP TABLE IF EXISTS referral_codes');
    }
}
