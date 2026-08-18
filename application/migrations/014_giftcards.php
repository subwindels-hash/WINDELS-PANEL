<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 014 — Gift cards (§23; rebuild-spec phase F).
 *
 * giftcard_brands, giftcard_products, giftcard_orders, giftcard_codes.
 *
 * The panel *sells* codes: a customer pays naira and receives a redeem code
 * for a brand. There is no peer-to-peer trading here — that is the marketplace
 * half of phase F, deliberately deferred (see docs/session-27-giftcards.md),
 * and the schema is shaped so adding it later means new tables rather than
 * re-cutting these.
 *
 * Three things make this domain different from every one that came before it,
 * and each one is a table decision rather than a comment:
 *
 *  1. **The thing sold is a bearer instrument.** A gift card code is money to
 *     whoever holds it. That makes it as sensitive as the identity payload in
 *     013 — encrypted at rest, read through one audited path — but with the
 *     opposite retention rule. An identity result is a liability we scrub on a
 *     timer; a gift card code *is the product the customer bought*, so nothing
 *     in this schema ever deletes it on a schedule. There is no purged_at on
 *     giftcard_codes for exactly that reason: a sweep that helpfully tidied
 *     away a customer's unspent ₦20,000 Amazon card would be indistinguishable
 *     from theft.
 *
 *  2. **Delivery is a second round trip.** Reloadly accepts an order and then
 *     hands over the card numbers on a separate call, which may not be ready
 *     the instant the order returns. So the order and the codes are separate
 *     rows with separate states: giftcard_orders.status tracks the vendor's
 *     order, and the codes arriving is what makes the purchase SUCCESSFUL.
 *     Collapsing them would mean either marking a purchase delivered before
 *     anything was delivered, or re-deriving "did we ever actually get the
 *     code?" from a JSON blob on every sweep.
 *
 *  3. **One order can be several cards.** quantity is a real vendor field, so
 *     giftcard_codes is a child table rather than a pair of columns. Each card
 *     is independently revealable and independently useful, and a customer who
 *     bought five ₦5,000 cards needs to be able to hand four of them out and
 *     keep track of which.
 *
 * Money, as everywhere else, lives on service_transactions (domain GIFTCARD).
 * These tables never duplicate an amount the engine already owns; face_value
 * is the *card's* denomination in the recipient's currency, which is a
 * different quantity from what the customer paid us in naira.
 */
class Migration_Giftcards extends CI_Migration {

    public static function statements() {
        return array(

            /*
             * A brand (§23, §26).
             *
             * Separate from products because one brand has many products — an
             * Amazon US $25 card and an Amazon UK £10 card are different
             * things to buy and the same thing to a customer browsing. The
             * redeem instructions live here rather than being repeated on
             * every denomination, since they are a property of the brand.
             *
             * provider_brand_id is the vendor's id, kept alongside our own
             * stable `code` for the same reason every other reference table in
             * this panel does it: a vendor renaming or renumbering something
             * must not rewrite our catalogue or break saved links.
             */
            "CREATE TABLE IF NOT EXISTS giftcard_brands (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              code VARCHAR(64) NOT NULL UNIQUE COMMENT 'our stable code: AMAZON, STEAM',
              name VARCHAR(128) NOT NULL,
              provider_brand_id VARCHAR(48) NULL COMMENT 'vendor brand id, advisory',
              logo_url VARCHAR(512) NULL,
              redeem_instructions TEXT NULL COMMENT 'how the customer spends the code',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_gcbrand_active (is_active, sorting)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * One buyable denomination (§23, §26).
             *
             * Two currencies sit on this row and conflating them is the money
             * bug this domain is prone to:
             *
             *   face_value + recipient_currency — what the *card* is worth to
             *     the person redeeming it ($25, £10). This is what the
             *     storefront must lead with, because it is what the customer
             *     is buying.
             *   provider_cost — what the *vendor* charges us, already in our
             *     base currency (Reloadly quotes senderCurrencyCode, which for
             *     a Nigerian account is NGN).
             *   price — what we charge, in base currency.
             *
             * price is NULLable and stays NULL until an operator sets it, the
             * same rule the VTU, number and identity catalogues follow: a sync
             * imports availability and cost, never a selling price. An FX rate
             * that moved 20% overnight must never be able to reprice the shop
             * into a loss, and Giftcard_product_model::active() hides unpriced
             * rows so an unset price cannot be bought for nothing.
             *
             * recipient_currency has deliberately no DEFAULT. Every other currency
             * column in the panel defaults to the base currency, because it
             * records money the panel itself holds; this one records what a
             * *card* is worth to the person redeeming it, which is genuinely
             * foreign and genuinely unknowable. Defaulting it to USD would mean
             * a vendor that omitted the field silently produced dollar cards,
             * and a €50 card sold as "$50". A row whose currency the vendor did
             * not state is not imported at all.
             *
             * denomination_type mirrors the vendor's FIXED/RANGE distinction.
             * Only FIXED is sellable in this phase — a RANGE product needs the
             * customer to name an amount, which is a different form and a
             * different price calculation — so RANGE rows import inactive and
             * the service refuses them explicitly rather than silently
             * charging a face value nobody chose.
             */
            "CREATE TABLE IF NOT EXISTS giftcard_products (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              brand_id BIGINT UNSIGNED NOT NULL,
              provider_id BIGINT UNSIGNED NULL,
              code VARCHAR(96) NOT NULL COMMENT 'our stable code: AMAZON-US-25',
              name VARCHAR(160) NOT NULL,
              country_code CHAR(2) NOT NULL DEFAULT 'US',
              provider_product_id VARCHAR(48) NULL COMMENT 'vendor productId',
              denomination_type VARCHAR(8) NOT NULL DEFAULT 'FIXED' COMMENT 'FIXED|RANGE',
              recipient_currency CHAR(3) NOT NULL COMMENT 'currency the card is denominated in — never defaulted, see below',
              face_value DECIMAL(20,8) NULL COMMENT 'card denomination in recipient_currency',
              min_face_value DECIMAL(20,8) NULL COMMENT 'RANGE products only',
              max_face_value DECIMAL(20,8) NULL COMMENT 'RANGE products only',
              price DECIMAL(20,8) NULL COMMENT 'customer price in base currency; NULL = not for sale',
              provider_cost DECIMAL(20,8) NULL COMMENT 'vendor cost in base currency',
              max_quantity INT NOT NULL DEFAULT 5 COMMENT 'cards per order',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              sorting INT NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_gcprod_brand FOREIGN KEY (brand_id) REFERENCES giftcard_brands(id) ON DELETE CASCADE,
              CONSTRAINT fk_gcprod_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL,
              UNIQUE KEY uq_gcprod_code (code),
              INDEX idx_gcprod_active (is_active, sorting),
              INDEX idx_gcprod_brand (brand_id, is_active),
              INDEX idx_gcprod_provider (provider_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * One purchase of one product (§23).
             *
             * status is the *order's* state at the vendor, kept separate from
             * the transaction status for the same reason virtual_numbers.status
             * is separate: they answer different questions.
             *
             *   PENDING    — row written, vendor not yet called
             *   PLACED     — vendor accepted the order, codes not in hand yet
             *   DELIVERED  — codes stored; this is what makes the money earned
             *   FAILED     — vendor rejected, or gave up before delivering
             *   CANCELLED  — abandoned by staff, refunded
             *
             * PLACED is the state that justifies this table existing. Between
             * "the vendor took the order" and "the customer has a code" the
             * panel owes the customer something it cannot yet show them, and
             * that gap must be a queryable state rather than an inference from
             * two nullable timestamps — the retry worker's entire job is that
             * one column.
             *
             * code_attempts and last_attempt_at bound the retry: a vendor that
             * accepted our money and never produced a code has to be given up
             * on eventually, and giving up refunds the customer even though we
             * were still charged. That is a real loss, so the count is on the
             * row where an operator can see it rather than buried in a log.
             */
            "CREATE TABLE IF NOT EXISTS giftcard_orders (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              service_transaction_id BIGINT UNSIGNED NOT NULL UNIQUE,
              product_id BIGINT UNSIGNED NULL,
              brand_id BIGINT UNSIGNED NULL,
              quantity INT NOT NULL DEFAULT 1,
              face_value DECIMAL(20,8) NULL COMMENT 'per-card denomination, frozen at purchase',
              recipient_currency CHAR(3) NOT NULL COMMENT 'copied from the product at purchase',
              recipient_email VARCHAR(190) NULL COMMENT 'vendor delivery copy; the panel is the source of truth',
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING|PLACED|DELIVERED|FAILED|CANCELLED',
              provider_order_id VARCHAR(64) NULL COMMENT 'vendor transactionId, what code retrieval is keyed on',
              placed_at DATETIME NULL,
              delivered_at DATETIME NULL,
              code_attempts INT NOT NULL DEFAULT 0 COMMENT 'code-retrieval tries; bounds the retry worker',
              last_attempt_at DATETIME NULL,
              failure_reason VARCHAR(255) NULL,
              reveal_count INT NOT NULL DEFAULT 0 COMMENT 'how many times a code was opened',
              last_revealed_at DATETIME NULL,
              last_revealed_by BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_gcord_stx FOREIGN KEY (service_transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE,
              CONSTRAINT fk_gcord_product FOREIGN KEY (product_id) REFERENCES giftcard_products(id) ON DELETE SET NULL,
              CONSTRAINT fk_gcord_brand FOREIGN KEY (brand_id) REFERENCES giftcard_brands(id) ON DELETE SET NULL,
              CONSTRAINT fk_gcord_revealer FOREIGN KEY (last_revealed_by) REFERENCES users(id) ON DELETE SET NULL,
              INDEX idx_gcord_status (status, last_attempt_at),
              INDEX idx_gcord_placed (status, placed_at),
              INDEX idx_gcord_provider_ref (provider_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /*
             * One card (§23).
             *
             * The sensitive table. card_number_encrypted and pin_encrypted are
             * AES-256-GCM (EncryptionService), and there is deliberately no
             * plaintext alongside them, not even a masked tail: unlike a NIN,
             * a partial card number identifies nothing useful to support, so
             * the convenience column would be pure downside.
             *
             * What *is* stored in the clear is the shape of the thing —
             * card_last4 for "which of my five cards is this", the redemption
             * URL, and whether it has been opened. Redemption URLs are not
             * secret on their own (the vendor gates them behind the code) and
             * a customer who lost the tab needs to get back to it.
             *
             * No purge column and no retention sweep, on purpose. See the
             * class docblock: this is the product, not a liability.
             */
            "CREATE TABLE IF NOT EXISTS giftcard_codes (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              giftcard_order_id BIGINT UNSIGNED NOT NULL,
              card_index INT NOT NULL DEFAULT 1 COMMENT '1..quantity, stable ordering for the customer',
              card_number_encrypted TEXT NOT NULL COMMENT 'AES-256-GCM; never rendered without an audited reveal',
              pin_encrypted TEXT NULL COMMENT 'AES-256-GCM; many brands have no PIN',
              card_last4 VARCHAR(8) NULL COMMENT 'display only, so a customer can tell two cards apart',
              redemption_url VARCHAR(512) NULL,
              expires_on DATE NULL COMMENT 'vendor expiry, where one is given',
              revealed_at DATETIME NULL COMMENT 'first time this specific card was opened',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_gccode_order FOREIGN KEY (giftcard_order_id) REFERENCES giftcard_orders(id) ON DELETE CASCADE,
              UNIQUE KEY uq_gccode_slot (giftcard_order_id, card_index),
              INDEX idx_gccode_order (giftcard_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('giftcard_brands', 'giftcard_products', 'giftcard_orders', 'giftcard_codes');
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
