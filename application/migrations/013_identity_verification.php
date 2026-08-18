<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 013 — Identity verification, NIN/BVN (§22; rebuild-spec phase E).
 *
 * identity_products, identity_checks.
 *
 * The lifecycle here is the simplest of any domain in the panel — one request,
 * one answer, no settlement window — so almost nothing in this migration is
 * about the lifecycle. It is about the data, because this is the first domain
 * where the *payload* is more dangerous than the money.
 *
 * A NIN or BVN identifies a real person to a bank and to the government. The
 * response carries their name, date of birth, phone number and, if you ask for
 * it, their photograph. A leak here is not a chargeback, it is an identity
 * theft kit. §22 therefore drives the schema, and three decisions are worth
 * spelling out because each one deliberately gives up a convenience:
 *
 *  1. **The identifier itself is never stored — not even encrypted.**
 *     identifier_hash is an HMAC blind index (EncryptionService::blind_index),
 *     and identifier_last4 is for display. That is enough to answer every
 *     question support actually gets asked — "is this the NIN I checked?" is
 *     verified by hashing the customer's claim and comparing — while making
 *     "dump the NINs of every customer" impossible, because the column does
 *     not contain them. Encrypting the identifier instead would have kept a
 *     recoverable copy of the most sensitive field in the system in exchange
 *     for a feature nobody needs: re-running a lookup the customer can simply
 *     type again.
 *
 *  2. **The result is encrypted at rest and has an expiry date.**
 *     result_encrypted is AES-256-GCM over the whole vendor entity. The
 *     customer paid for it, so it must survive long enough to be read, but
 *     holding a stranger's date of birth forever is a liability with no
 *     upside. purged_at records the retention sweep having scrubbed it
 *     (CronWorkers::identity_purge, identity_retention_days). The row itself
 *     survives — the money, the audit trail and the blind index are not the
 *     sensitive part and accounting needs them.
 *
 *  3. **No cleartext PII column exists at all.** There is deliberately no
 *     `full_name` or `date_of_birth` beside the encrypted blob, however much
 *     easier it would make an admin list. A convenience column would defeat
 *     both the encryption and the retention sweep on day one, which is the
 *     usual way this control fails in practice.
 *
 * Reading a result is itself a privileged act: reveal_count and
 * last_revealed_at record that a staff member looked, in addition to the
 * audit_logs entry, so the access trail is visible from the row.
 *
 * Money stays on service_transactions (domain IDENTITY), as in every other
 * domain — this table never duplicates an amount.
 */
class Migration_Identity_verification extends CI_Migration {

    public static function statements() {
        return array(

            /*
             * What can be checked and what it costs (§22, §26).
             *
             * Same reference-table shape as number_products: our stable `code`
             * is what the app and the seeder use, `provider_code` is the
             * vendor's spelling, so a vendor renaming an endpoint or a second
             * vendor being added does not rewrite the catalogue.
             *
             * lookup_field is what the customer is asked to type. A NIN lookup
             * takes a NIN; a "find my NIN by phone" lookup takes a phone
             * number and must validate — and mask — completely differently.
             * Keeping it in the catalogue means the form and the validator
             * both read it from one place instead of hardcoding a list.
             */
            "CREATE TABLE IF NOT EXISTS identity_products (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              code VARCHAR(48) NOT NULL UNIQUE COMMENT 'our stable code: NIN_BASIC, BVN_BASIC',
              name VARCHAR(96) NOT NULL,
              id_type VARCHAR(16) NOT NULL COMMENT 'NIN|BVN',
              lookup_field VARCHAR(16) NOT NULL DEFAULT 'IDENTIFIER' COMMENT 'IDENTIFIER|PHONE — what the customer types',
              provider_id BIGINT UNSIGNED NULL,
              provider_code VARCHAR(64) NULL COMMENT 'vendor endpoint key, e.g. kyc/nin',
              description VARCHAR(255) NULL,
              price DECIMAL(20,8) NULL COMMENT 'customer price in base currency',
              provider_cost DECIMAL(20,8) NULL COMMENT 'what the vendor charges us',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_idprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
              INDEX idx_idprod_active (is_active, sorting),
              INDEX idx_idprod_type (id_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * One lookup (§22).
             *
             * status is the *check's* own answer, kept separate from the
             * transaction status for the same reason virtual_numbers.status
             * is: they answer different questions. NOT_FOUND is a completed
             * lookup that found nobody — the vendor answered, the panel
             * refunds (see IdentityService) — and that is not the same event
             * as FAILED, where we never got an answer at all. Collapsing them
             * would make "how many of our lookups are for identifiers that do
             * not exist?" unanswerable, and that number is the fraud signal.
             *
             * consent_at / consent_ip are not decoration. Running a government
             * identity lookup without the subject's consent is the illegal
             * version of this product, so the panel records that consent was
             * given, when, and from where, on the row that proves the lookup
             * happened.
             */
            "CREATE TABLE IF NOT EXISTS identity_checks (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
              product_id BIGINT UNSIGNED NULL,
              id_type VARCHAR(16) NOT NULL COMMENT 'NIN|BVN',
              lookup_field VARCHAR(16) NOT NULL DEFAULT 'IDENTIFIER',
              identifier_hash CHAR(64) NOT NULL COMMENT 'HMAC blind index — the raw NIN/BVN is never stored',
              identifier_last4 VARCHAR(8) NULL COMMENT 'masked tail, for display only',
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|VERIFIED|NOT_FOUND|FAILED',
              result_encrypted MEDIUMTEXT NULL COMMENT 'AES-256-GCM JSON of the vendor entity; photo never stored',
              provider_reference VARCHAR(64) NULL,
              consent_at DATETIME NULL COMMENT 'when the customer confirmed they have the subject consent',
              consent_ip VARCHAR(45) NULL,
              reveal_count INT NOT NULL DEFAULT 0 COMMENT 'how many times staff opened the result',
              last_revealed_at DATETIME NULL,
              last_revealed_by BIGINT UNSIGNED NULL,
              purged_at DATETIME NULL COMMENT 'retention sweep scrubbed result_encrypted',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_idchk_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
              CONSTRAINT fk_idchk_product FOREIGN KEY (product_id) REFERENCES identity_products(id) ON DELETE SET NULL,
              CONSTRAINT fk_idchk_revealer FOREIGN KEY (last_revealed_by) REFERENCES users(id) ON DELETE SET NULL,
              INDEX idx_idchk_hash (identifier_hash),
              INDEX idx_idchk_status_created (status, created_at),
              INDEX idx_idchk_purge (purged_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('identity_products', 'identity_checks');
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
