<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 028 — give rate-limit counters their own scope.
 *
 * `login_attempts` is the panel's only throttling store, and every feature
 * that needs a limit writes to it: sign-in, admin sign-in, MFA, registration,
 * password reset and the on-site assistant. Each namespaces its *identifier*
 * (`assistant:1.2.3.4`, `pwreset:someone@example.com`) so the per-account
 * counters stay separate.
 *
 * The per-IP counter had no such separation. It counted every row for the
 * address, whoever wrote it, so unrelated features shared one budget:
 *
 *   sixteen answered questions to the on-site assistant put the visitor's IP
 *   over the login lockout (5 x 3), and the login page then told them
 *   "Too many failed attempts. Try again in 15 minutes."
 *
 * Nothing had failed. A visitor who used the help widget locked themselves —
 * and everyone behind the same office or mobile NAT — out of signing in.
 *
 * This adds the column the counters need to stay apart, and backfills it from
 * the identifier prefix already in the data, so historical rows are classified
 * exactly as the code would classify them today.
 */
class Migration_Rate_limit_scope extends CI_Migration {

    public static function statements() {
        return array(
            "ALTER TABLE login_attempts
               ADD COLUMN scope VARCHAR(32) NOT NULL DEFAULT 'login'
               COMMENT 'Which limiter wrote this row: login|admin_login|mfa|register|pwreset|assistant'",

            // Existing rows carry their scope in the identifier: 'assistant:1.2.3.4'.
            "UPDATE login_attempts
                SET scope = SUBSTRING_INDEX(email, ':', 1)
              WHERE email LIKE '%:%'",

            "CREATE INDEX idx_la_scope_ip_created ON login_attempts (scope, ip, created_at)",
            "CREATE INDEX idx_la_scope_email_created ON login_attempts (scope, email, created_at)",
        );
    }

    /** Creates no tables; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            if (preg_match('/^ALTER TABLE (\w+)\s+ADD COLUMN (\w+)/i', trim($sql), $m)
                && $this->column_exists($m[1], $m[2])) {
                continue;
            }
            if (preg_match('/^CREATE INDEX (\w+)/i', trim($sql), $m) && $this->index_exists($m[1])) {
                continue;
            }
            $this->db->query($sql);
        }
    }

    public function down() {
        if ($this->column_exists('login_attempts', 'scope')) {
            $this->db->query('ALTER TABLE login_attempts DROP COLUMN `scope`');
        }
    }

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

    private function index_exists($name) {
        try {
            $rows = $this->db->query("SHOW INDEX FROM login_attempts")->result();
            foreach ($rows as $row) {
                if (isset($row->Key_name) && $row->Key_name === $name) return true;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }
}
