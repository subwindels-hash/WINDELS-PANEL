<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 033 — three operator requests against the live panel.
 *
 *  1. `users.pin_cipher` — an AES-256-GCM copy of the transaction PIN.
 *
 *     The PIN began life as a one-way hash with a deliberate "no reveal"
 *     contract: staff could clear it, never read it. The operator has since
 *     asked for the opposite — support needs to answer "what is my PIN?" on
 *     the phone, and clearing + re-issuing every time is slower than the
 *     question deserves. So the PIN now also travels in an envelope the panel
 *     can reopen: encrypted at rest with ENCRYPTION_KEY (the same key that
 *     already guards provider API keys and MFA secrets), never plaintext in
 *     the database, and every reveal is written to the audit log.
 *
 *     Two honest limits ship with it:
 *       - PINs set *before* this migration have no envelope; they stay
 *         hash-only and the admin file says so instead of guessing.
 *       - A database dump plus the ENCRYPTION_KEY is enough to read every
 *         customer's PIN. That was already true of provider API keys; it is
 *         the price of the feature and the reason the reveal stays behind
 *         `users.edit` and an audit entry.
 *
 *  2. `contact_messages` — the visitor contact form gets a real inbox.
 *
 *     Until now an anonymous visitor's message went to the support mailbox as
 *     an email_queue row and that was the last the panel saw of it: the
 *     "Customer messages" screen could list subjects but not show the body,
 *     and the only way to reply was from staff's own mail client. This table
 *     is the dashboard half of the conversation — the message as a row, the
 *     reply stored against it, and a reply-to address that is a column
 *     instead of a regex over an email body.
 *
 *  3. Seed reply templates, so the reply box opens with something to send.
 */
class Migration_Pin_history_contact_inbox extends CI_Migration {

    public static function statements() {
        return array(

            // --- 1. the encrypted PIN copy --------------------------------
            "ALTER TABLE users
              ADD COLUMN pin_cipher VARCHAR(255) NULL COMMENT 'AES-256-GCM copy of the PIN (EncryptionService); lets staff reveal it. NULL for PINs set before this column existed'",

            // --- 2. the contact inbox ------------------------------------
            "CREATE TABLE IF NOT EXISTS contact_messages (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              name VARCHAR(100) NOT NULL,
              email VARCHAR(255) NOT NULL,
              subject VARCHAR(150) NOT NULL,
              department VARCHAR(32) NOT NULL DEFAULT 'other',
              message MEDIUMTEXT NOT NULL,
              ip VARCHAR(64) NULL,
              email_queue_id BIGINT UNSIGNED NULL COMMENT 'the copy queued to the support mailbox',
              status VARCHAR(16) NOT NULL DEFAULT 'NEW' COMMENT 'NEW|REPLIED',
              reply_subject VARCHAR(255) NULL,
              reply_body MEDIUMTEXT NULL COMMENT 'what staff last sent, kept so the dashboard holds both halves',
              replied_at DATETIME NULL,
              replied_by_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_cmsg_status_created (status, created_at),
              INDEX idx_cmsg_email (email, created_at),
              CONSTRAINT fk_cmsg_replier FOREIGN KEY (replied_by_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /** Tables this migration creates, for the schema linter and down(). */
    public static function tables() {
        return array('contact_messages');
    }

    public function up() {
        foreach (self::statements() as $sql) {
            // Partially applied migrations must re-run cleanly.
            if (preg_match('/^ALTER TABLE users\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('users', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }

        $this->seed_reply_templates();
    }

    public function down() {
        $this->db->query('DROP TABLE IF EXISTS contact_messages');
        foreach (array('pin_cipher') as $col) {
            if ($this->column_exists('users', $col)) {
                $this->db->query('ALTER TABLE users DROP COLUMN `'.$col.'`');
            }
        }
        foreach (array_keys(self::reply_templates()) as $key) {
            $this->db->where('template_key', $key)->delete('email_templates');
        }
    }

    /**
     * The starter templates for the reply box. Fresh installs get them from
     * Core_seeder; existing installs get them here, because a deployment is
     * not going to re-run the seeder over live data.
     */
    private function seed_reply_templates() {
        foreach (self::reply_templates() as $key => $t) {
            $exists = $this->db->where('template_key', $key)->get('email_templates')->row();
            if ($exists) continue;
            $this->db->insert('email_templates', array(
                'template_key' => $key,
                'subject'      => $t[0],
                'body_html'    => $t[1],
                'body_text'    => trim(strip_tags(str_replace('</p>', "\n", $t[1]))),
                'variables'    => json_encode($t[2]),
                'is_active'    => 1,
            ));
        }
    }

    /** @return array key => array(subject, body_html, variables) */
    public static function reply_templates() {
        return array(
            'contact.reply_general' => array(
                'Re: {{subject}}',
                '<p>Hi {{name}},</p>'
                .'<p>Thanks for contacting {{site_name}} — here is where that stands.</p>'
                .'<p>{{reply}}</p>'
                .'<p>If anything above is unclear, just answer this email.</p>',
                array('name', 'subject', 'site_name', 'reply'),
            ),
            'contact.reply_order' => array(
                'Re: {{subject}} — your order',
                '<p>Hi {{name}},</p>'
                .'<p>Thanks for the details about your order.</p>'
                .'<p>{{reply}}</p>'
                .'<p>You can follow the order from Dashboard → Orders; include the order ID '
                .'(the short code starting with a hash) if you write back.</p>',
                array('name', 'subject', 'site_name', 'reply'),
            ),
            'contact.reply_billing' => array(
                'Re: {{subject}} — your payment',
                '<p>Hi {{name}},</p>'
                .'<p>Thanks for reaching out about your payment.</p>'
                .'<p>{{reply}}</p>'
                .'<p>Payments are credited to your wallet balance as soon as they are verified — '
                .'you can watch the balance from Dashboard → Wallet.</p>',
                array('name', 'subject', 'site_name', 'reply'),
            ),
        );
    }

    /** Partially applied migrations must re-run cleanly. */
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
}
