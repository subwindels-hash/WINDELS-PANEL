<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 021 — administrator-managed page content.
 *
 * The legal and marketing pages (Terms, Privacy, Refund policy, Acceptable
 * use, About) shipped as PHP views, so changing a sentence meant editing
 * source and redeploying. That is exactly the thing an operator should never
 * have to do: policy text changes for legal reasons, on legal timescales, by
 * people who do not deploy code.
 *
 * This table is an *override* layer, not a replacement. A row here takes over
 * the page body; with no row, the bundled view still renders. That way an
 * install that has never touched the admin screens keeps working, and clearing
 * an override restores the shipped text rather than blanking the page.
 */
class Migration_Managed_pages extends CI_Migration {

    public static function tables() {
        return array('managed_pages');
    }

    public static function statements() {
        return array(
            "CREATE TABLE IF NOT EXISTS managed_pages (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              page_key VARCHAR(64) NOT NULL UNIQUE COMMENT 'terms|privacy|refund-policy|acceptable-use|about',
              title VARCHAR(160) NOT NULL,
              body_html MEDIUMTEXT NOT NULL COMMENT 'sanitised on write by ContentService',
              meta_description VARCHAR(320) NULL,
              is_published TINYINT(1) NOT NULL DEFAULT 1,
              updated_by_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_managed_pages_published (is_published),
              CONSTRAINT fk_managed_pages_author FOREIGN KEY (updated_by_id)
                REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public function up() {
        foreach (self::statements() as $sql) { $this->db->query($sql); }
    }

    public function down() {
        $this->db->query('DROP TABLE IF EXISTS managed_pages');
    }
}
