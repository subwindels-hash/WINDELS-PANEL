<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 012 — Virtual numbers + OTP (§10, §11; rebuild-spec phase D).
 *
 * number_countries, number_services, number_products, virtual_numbers,
 * otp_messages.
 *
 * The audit singled this domain out because it is the first one whose
 * lifecycle the order engine does not already model: a virtual number is a
 * **reservation**. The customer does not buy a thing that is delivered, they
 * rent a phone number for a few minutes and the purchase is only worth
 * anything if a code arrives before it expires. Two columns carry that idea:
 *
 *   virtual_numbers.expires_at  — server-side deadline, set from the vendor's
 *                                 own `expires` field, never guessed locally.
 *   virtual_numbers.status      — where the reservation is, independently of
 *                                 where the money is.
 *
 * Money still lives on service_transactions (domain NUMBER), exactly as VTU
 * does: this table never duplicates an amount. The two states are related but
 * not the same — a RESERVED number sits against a PROCESSING transaction, and
 * an EXPIRED one against a FAILED (refunded) transaction. Keeping them apart
 * is what lets the expiry sweep run without re-implementing the refund rules.
 *
 * otp_messages is append-only. A number can receive more than one SMS (a
 * re-send, or a second code), so the codes are rows rather than a column on
 * the reservation, and the UNIQUE on the vendor's message id makes a repeated
 * poll idempotent instead of duplicating the customer's code.
 */
class Migration_Virtual_numbers_otp extends CI_Migration {

    public static function statements() {
        return array(

            // ---------------------------------------------------------------
            // Catalogue (§10) — the same reference-table shape as vtu_networks
            // ---------------------------------------------------------------
            "CREATE TABLE IF NOT EXISTS number_countries (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              code VARCHAR(32) NOT NULL UNIQUE COMMENT 'our stable code: NG, GB, US',
              name VARCHAR(64) NOT NULL,
              dial_prefix VARCHAR(8) NULL COMMENT '+234, for display only',
              flag_emoji VARCHAR(16) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_ncty_active (is_active, sorting)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * What the number is *for* — the site the OTP will come from.
             * Separate from the country because the same service is sold in
             * many countries at different prices, which is exactly the
             * (country, service) grid number_products holds.
             */
            "CREATE TABLE IF NOT EXISTS number_services (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              code VARCHAR(48) NOT NULL UNIQUE COMMENT 'WHATSAPP|TELEGRAM|...',
              name VARCHAR(64) NOT NULL,
              logo_url VARCHAR(512) NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_nsvc_active (is_active, sorting)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * One buyable (country, service) pair from one vendor.
             *
             * provider_country/provider_operator/provider_product are the
             * vendor's own spelling ('england', 'any', 'facebook'); our codes
             * stay stable when a vendor renames something or a second vendor
             * is added.
             *
             * price is what the customer pays and is ours to set. provider_cost
             * is what the vendor charges, converted into the panel's base
             * currency at sync time — 5sim quotes in roubles, so storing the
             * raw vendor figure next to a naira price would silently compare
             * two different units.
             *
             * ttl_minutes is how long the vendor holds the number. It is
             * advisory: the authoritative deadline is whatever the vendor
             * returns on the reservation itself.
             */
            "CREATE TABLE IF NOT EXISTS number_products (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              country_id BIGINT UNSIGNED NOT NULL,
              service_id BIGINT UNSIGNED NOT NULL,
              provider_id BIGINT UNSIGNED NULL,
              code VARCHAR(96) NOT NULL COMMENT 'our stable code, NG-WHATSAPP',
              provider_country VARCHAR(48) NULL,
              provider_operator VARCHAR(48) NULL COMMENT 'any, unless pinned',
              provider_product VARCHAR(48) NULL,
              price DECIMAL(20,8) NULL COMMENT 'customer price in base currency',
              provider_cost DECIMAL(20,8) NULL COMMENT 'vendor cost, converted to base currency',
              stock INT NULL COMMENT 'last known vendor availability, advisory',
              ttl_minutes INT NOT NULL DEFAULT 15 COMMENT 'vendor hold time; the reservation carries the real deadline',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_nprod_country FOREIGN KEY (country_id) REFERENCES number_countries(id) ON DELETE CASCADE,
              CONSTRAINT fk_nprod_service FOREIGN KEY (service_id) REFERENCES number_services(id) ON DELETE CASCADE,
              CONSTRAINT fk_nprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
              UNIQUE KEY uq_nprod_code (country_id, service_id, code),
              INDEX idx_nprod_active (is_active, sorting),
              INDEX idx_nprod_provider (provider_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // ---------------------------------------------------------------
            // The reservation (§10)
            // ---------------------------------------------------------------
            /*
             * Number-specific detail only; the money is on service_transactions.
             *
             * status is the *reservation's* state and is deliberately its own
             * vocabulary rather than a copy of the transaction's:
             *   RESERVED  — held by the vendor, waiting for an SMS
             *   RECEIVED  — at least one code has arrived (the purchase worked)
             *   COMPLETED — we told the vendor we are done with it
             *   CANCELLED — released before any code arrived (refunded)
             *   EXPIRED   — the deadline passed with no code (refunded)
             *   BANNED    — reported to the vendor as unusable (refunded)
             */
            "CREATE TABLE IF NOT EXISTS virtual_numbers (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
              country_id BIGINT UNSIGNED NULL,
              service_id BIGINT UNSIGNED NULL,
              product_id BIGINT UNSIGNED NULL,
              msisdn VARCHAR(32) NOT NULL COMMENT 'the rented number, E.164',
              operator VARCHAR(48) NULL,
              provider_order_id VARCHAR(64) NULL COMMENT 'vendor order id; also the transaction provider_reference',
              status VARCHAR(16) NOT NULL DEFAULT 'RESERVED' COMMENT 'RESERVED|RECEIVED|COMPLETED|CANCELLED|EXPIRED|BANNED',
              sms_count INT NOT NULL DEFAULT 0,
              last_code VARCHAR(32) NULL COMMENT 'most recent extracted code, for the list view',
              reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              expires_at DATETIME NULL COMMENT 'vendor deadline; the expiry sweep reads this',
              released_at DATETIME NULL,
              extra JSON NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_vnum_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
              CONSTRAINT fk_vnum_country FOREIGN KEY (country_id) REFERENCES number_countries(id) ON DELETE SET NULL,
              CONSTRAINT fk_vnum_service FOREIGN KEY (service_id) REFERENCES number_services(id) ON DELETE SET NULL,
              CONSTRAINT fk_vnum_product FOREIGN KEY (product_id) REFERENCES number_products(id) ON DELETE SET NULL,
              INDEX idx_vnum_status_expires (status, expires_at),
              INDEX idx_vnum_msisdn (msisdn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * Every SMS delivered to a rented number, append-only.
             *
             * uq_otp_msg makes polling idempotent: the vendor returns the
             * whole inbox on every check, so without it a number polled six
             * times would show the customer six copies of one code.
             */
            "CREATE TABLE IF NOT EXISTS otp_messages (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              virtual_number_id BIGINT UNSIGNED NOT NULL,
              provider_message_id VARCHAR(64) NULL,
              sender VARCHAR(64) NULL,
              body TEXT NULL,
              code VARCHAR(32) NULL COMMENT 'extracted OTP, when the vendor or we can find one',
              received_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              CONSTRAINT fk_otp_number FOREIGN KEY (virtual_number_id) REFERENCES virtual_numbers(id) ON DELETE CASCADE,
              UNIQUE KEY uq_otp_msg (virtual_number_id, provider_message_id),
              INDEX idx_otp_number_created (virtual_number_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array(
            'number_countries', 'number_services', 'number_products',
            'virtual_numbers', 'otp_messages',
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
