<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 041 — put the links back into the plain-text part of every email.
 *
 * The seeder built `body_text` with a bare `strip_tags()`, which keeps an
 * anchor's TEXT and throws away its href. So the plain-text alternative of the
 * password-reset message read:
 *
 *     Hi bob,
 *     Use the link below to set a new password. It expires in 60 minutes.
 *     Reset password
 *
 * "the link below" and then no link — the token, the only thing the message
 * exists to carry, was gone. Every recipient whose client renders the text
 * alternative (and every plain-text digest, screen reader and mail gateway
 * that prefers it) got a reset mail they could not act on.
 *
 * MailService::readable_text() now renders links as "text <url>". This rewrites
 * the already-stored rows the same way, but ONLY where the HTML has a link the
 * text is missing — an operator who has edited a template by hand keeps their
 * wording untouched.
 */
class Migration_Email_template_text_links extends CI_Migration {

    /** Creates no tables; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    /** Data-only migration: no DDL for the schema exporter to diff. */
    public static function statements() {
        return array();
    }

    public function up() {
        if (!$this->table_exists('email_templates')) return;

        if (!class_exists('MailService', false)) {
            require_once APPPATH.'libraries/MailService.php';
        }

        $rows = $this->db->select('id, body_html, body_text')->get('email_templates')->result();
        $fixed = 0;

        foreach ($rows as $row) {
            $html = (string)$row->body_html;

            // Only templates whose HTML actually carries a URL are candidates.
            if (!preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
                continue;
            }

            $text = (string)$row->body_text;

            // Already carries every URL — nothing lost, leave it alone.
            $missing = false;
            foreach ($m[1] as $href) {
                if ($href !== '' && strpos($text, $href) === false) { $missing = true; break; }
            }
            if (!$missing) continue;

            $this->db->where('id', (int)$row->id)->update('email_templates', array(
                'body_text' => MailService::readable_text($html),
            ));
            $fixed++;
        }

        if ($fixed > 0) {
            log_message('error', "migration 041: restored links in {$fixed} email template text body(ies)");
        }
    }

    /**
     * Irreversible by design: down() would have to re-delete the URLs, which
     * is the defect. The rewrite is idempotent, so re-running up() is safe.
     */
    public function down() {
        // Intentionally a no-op.
    }

    private function table_exists($table) {
        try {
            return (bool)$this->db->table_exists($table);
        } catch (Exception $e) {
            return false;
        }
    }
}
