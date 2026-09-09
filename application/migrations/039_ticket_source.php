<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 039 — where a support ticket came from.
 *
 * The on-site assistant (SiteOperatorEngine) is not an LLM: it answers from
 * the local knowledge base, and the unfinished-work ledger said plainly what
 * happens to everything outside that file — "anything outside that file
 * becomes a ticket." The escalation itself already existed in the contact
 * flow, but a ticket the assistant opened on the customer's behalf was
 * indistinguishable from one a customer typed by hand: staff could not tell
 * the automated handoffs apart, and the check that "the same question does
 * not open a second ticket within 24 hours" had no column to lean on.
 *
 * `source` records that: 'contact' (the form, the old and only path) or
 * 'assistant' (auto-escalated from the chat). Existing rows are all
 * 'contact' by default, which is what every one of them was.
 */
class Migration_Ticket_source extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE tickets
              ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'contact' COMMENT 'Who opened it: contact (the form) or assistant (auto-escalated unanswerable question)'",

            "CREATE INDEX idx_t_source_created ON tickets (source, created_at)",
        );
    }

    /** Tables this migration creates, for the schema linter and down(). */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            // Partially applied migrations must re-run cleanly.
            if (preg_match('/^ALTER TABLE tickets\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists('tickets', $m[1])) {
                continue;
            }
            if (preg_match('/^CREATE INDEX (\w+) ON/i', trim($sql), $m)
                && $this->index_exists('tickets', $m[1])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    public function down() {
        if ($this->index_exists('tickets', 'idx_t_source_created')) {
            $this->db->query('DROP INDEX idx_t_source_created ON tickets');
        }
        if ($this->column_exists('tickets', 'source')) {
            $this->db->query('ALTER TABLE tickets DROP COLUMN `source`');
        }
    }

    private function index_exists($table, $index) {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                array($table, $index)
            )->row();
            return $row && (int)$row->n > 0;
        } catch (Exception $e) {
            return false;
        }
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
