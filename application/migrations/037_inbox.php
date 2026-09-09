<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 037 — the dashboard inbox.
 *
 * The panel already sends mail (MailService → email_queue), but incoming mail
 * — a customer answering the support address, a vendor writing to the admin's
 * cPanel mailbox — landed in a mailbox no screen in the panel could see. The
 * operator asked for the opposite: mail addressed to the configured SMTP
 * account should arrive on the admin dashboard, and mail addressed to a
 * registered customer should arrive in that customer's dashboard.
 *
 * `inbox_messages` is the stored half of that. A scheduled worker
 * (CronWorkers::inbox_poll, via InboxService) pulls new messages from the
 * mailbox, parses them, and writes one row per message:
 *
 *   - mail addressed to the admin inbox address (settings.inbox_admin_email,
 *     falling back to the SMTP account username) → the staff inbox
 *     (owner_type = 'ADMIN', owner_id NULL — every staff member with
 *     settings.manage sees the same rows);
 *   - mail addressed to a registered customer's email → that customer's
 *     inbox (owner_type = 'USER', owner_id = users.id);
 *   - everything else → the staff inbox as the catch-all, so nothing that
 *     reached the mailbox ever vanishes from the panel's view.
 *
 * Deduplication is a UNIQUE key, not a lock: a poll that crashes between
 * fetching a message and deleting it re-fetches the same mail on the next
 * tick, and the dedupe_key (Message-Id, or a hash of the identifying headers
 * when the sender omitted one) makes the second insert a no-op instead of a
 * duplicate.
 *
 * The body is stored as text and (when the sender provided one) raw HTML.
 * The views render the text half; the HTML half is reference data only and
 * is never executed, so a hostile sender cannot store script in the inbox.
 */
class Migration_Inbox extends CI_Migration {

    public static function statements() {
        return array(
            "CREATE TABLE IF NOT EXISTS inbox_messages (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              owner_type VARCHAR(8) NOT NULL COMMENT 'ADMIN = the staff inbox (owner_id NULL, shared by all staff); USER = one customers inbox',
              owner_id BIGINT UNSIGNED NULL COMMENT 'users.id when owner_type is USER',
              to_email VARCHAR(255) NOT NULL COMMENT 'the address the mail was addressed to (lowercased) — the routing key',
              from_email VARCHAR(255) NULL,
              from_name VARCHAR(190) NULL,
              subject VARCHAR(255) NOT NULL DEFAULT '',
              body_text MEDIUMTEXT NOT NULL COMMENT 'plain-text body; when the sender sent only HTML, the stripped tags of it',
              body_html MEDIUMTEXT NULL COMMENT 'raw HTML part when the sender provided one — reference data, rendered escaped',
              message_id VARCHAR(255) NULL COMMENT 'RFC 822 Message-Id, sender-provided',
              dedupe_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'sha256 of the Message-Id, or of to+from+subject+date when the sender omitted one — a re-fetched message can never be stored twice',
              received_at DATETIME NULL COMMENT 'the Date header normalized to UTC; NULL when the header is missing or unparseable',
              is_read TINYINT(1) NOT NULL DEFAULT 0,
              read_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_inbox_owner (owner_type, owner_id, is_read, id),
              INDEX idx_inbox_to (to_email, id),
              CONSTRAINT fk_inbox_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /** Tables this migration creates, for the schema linter and down(). */
    public static function tables() {
        return array('inbox_messages');
    }

    public function up() {
        foreach (self::statements() as $sql) {
            $this->db->query($sql);
        }
    }

    public function down() {
        $this->db->query('DROP TABLE IF EXISTS inbox_messages');
    }
}
