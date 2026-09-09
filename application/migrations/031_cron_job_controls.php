<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 031 — let an operator pause a background job, safely.
 *
 * Module 16 gave the panel a cron screen that reports. It could not act: when
 * a provider goes down and every poll comes back an error, or a gateway starts
 * answering nonsense and the reconciliation sweep is about to write off live
 * deposits, the only way to stop a job was to SSH in and edit the crontab —
 * which most cPanel operators cannot do at 2am, and which then has to be
 * remembered and undone.
 *
 * So: a pause switch, in the database, honoured by the runner.
 *
 * The dangerous half of that feature is not pausing. It is **forgetting**.
 * A crontab line commented out during an incident stays commented out for
 * weeks, and nothing in the panel ever mentions it again; meanwhile earnings
 * never mature, deposits are never reconciled and refunds never land. Every
 * row here therefore carries `resume_at`, and the runner resumes the job by
 * itself when that time passes. A pause is a decision with an expiry, not a
 * state you can leave behind.
 *
 * `reason` is NOT NULL for the same reason: the next person to look at this
 * screen — possibly weeks later, possibly not the person who paused it — has
 * to be able to tell whether the pause still applies.
 */
class Migration_Cron_job_controls extends CI_Migration {

    public static function statements() {
        return array(
            "CREATE TABLE IF NOT EXISTS cron_job_controls (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              job VARCHAR(64) NOT NULL UNIQUE COMMENT 'cron job name, as passed to php index.php cron <job>',
              is_paused TINYINT(1) NOT NULL DEFAULT 0,
              reason VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'why it was paused — required when pausing',
              paused_by_id BIGINT UNSIGNED NULL,
              paused_at DATETIME NULL,
              resume_at DATETIME NULL COMMENT 'the pause expires here; the runner resumes the job itself',
              resumed_by_id BIGINT UNSIGNED NULL,
              resumed_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_cronctl_paused (is_paused, resume_at),
              CONSTRAINT fk_cronctl_pauser FOREIGN KEY (paused_by_id) REFERENCES users(id) ON DELETE SET NULL,
              CONSTRAINT fk_cronctl_resumer FOREIGN KEY (resumed_by_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('cron_job_controls');
    }

    public function up() {
        foreach (self::statements() as $sql) {
            $this->db->query($sql);
        }
    }

    /**
     * Dropping the table resumes everything, which is the safe direction: a
     * rollback must never leave a job paused with no screen able to show it.
     */
    public function down() {
        $this->db->query('DROP TABLE IF EXISTS cron_job_controls');
    }
}
