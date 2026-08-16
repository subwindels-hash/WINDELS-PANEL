<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 008 — Support, Content, Referrals
 * tickets, ticket_messages, ticket_attachments,
 * referral_accounts, referrals, referral_commissions,
 * blog_categories, blog_posts, faqs, announcements, media
 */
class Migration_Support_content extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS tickets (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              user_id BIGINT UNSIGNED NOT NULL,
              subject VARCHAR(255) NOT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'OPEN',
              priority VARCHAR(16) NOT NULL DEFAULT 'MEDIUM',
              department VARCHAR(64) NULL,
              order_id BIGINT UNSIGNED NULL,
              assigned_to_id BIGINT UNSIGNED NULL,
              last_reply_at DATETIME NULL,
              closed_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_t_user_status (user_id, status),
              INDEX idx_t_status_prio_created (status, priority, created_at),
              INDEX idx_t_assigned (assigned_to_id, status),
              CONSTRAINT fk_t_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_t_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
              CONSTRAINT fk_t_assignee FOREIGN KEY (assigned_to_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ticket_messages (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              ticket_id BIGINT UNSIGNED NOT NULL,
              author_id BIGINT UNSIGNED NOT NULL,
              message TEXT NOT NULL,
              is_staff TINYINT(1) NOT NULL DEFAULT 0,
              is_internal_note TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_tm_ticket_created (ticket_id, created_at),
              CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
              CONSTRAINT fk_tm_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ticket_attachments (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              ticket_message_id BIGINT UNSIGNED NOT NULL,
              file_url VARCHAR(512) NOT NULL,
              file_name VARCHAR(255) NOT NULL,
              mime_type VARCHAR(128) NOT NULL,
              size INT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_ta_msg (ticket_message_id),
              CONSTRAINT fk_ta_msg FOREIGN KEY (ticket_message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS referral_accounts (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL UNIQUE,
              code VARCHAR(32) NOT NULL UNIQUE,
              commission_percent DECIMAL(10,4) NOT NULL DEFAULT 5.0000,
              total_referred INT NOT NULL DEFAULT 0,
              total_earned DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              total_paid DECIMAL(20,8) NOT NULL DEFAULT 0.00000000,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS referrals (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              referrer_id BIGINT UNSIGNED NOT NULL,
              referred_id BIGINT UNSIGNED NOT NULL UNIQUE,
              referral_account_id BIGINT UNSIGNED NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_ref_referrer (referrer_id),
              CONSTRAINT fk_rr_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_rr_referred FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
              CONSTRAINT fk_rr_account FOREIGN KEY (referral_account_id) REFERENCES referral_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS referral_commissions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              referral_id BIGINT UNSIGNED NOT NULL,
              order_id BIGINT UNSIGNED NULL,
              amount DECIMAL(20,8) NOT NULL,
              currency CHAR(3) NOT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
              wallet_transaction_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              paid_at DATETIME NULL,
              INDEX idx_rc_ref_status (referral_id, status),
              CONSTRAINT fk_rc_ref FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
              CONSTRAINT fk_rc_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
              CONSTRAINT fk_rc_wt FOREIGN KEY (wallet_transaction_id) REFERENCES wallet_transactions(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS blog_categories (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(128) NOT NULL,
              slug VARCHAR(128) NOT NULL UNIQUE,
              description VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS blog_posts (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              title VARCHAR(255) NOT NULL,
              slug VARCHAR(255) NOT NULL UNIQUE,
              excerpt TEXT NULL,
              content MEDIUMTEXT NOT NULL,
              featured_image VARCHAR(512) NULL,
              meta_title VARCHAR(255) NULL,
              meta_description TEXT NULL,
              status VARCHAR(16) NOT NULL DEFAULT 'DRAFT',
              author_id BIGINT UNSIGNED NULL,
              category_id BIGINT UNSIGNED NULL,
              views INT NOT NULL DEFAULT 0,
              published_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_bp_status_pub (status, published_at),
              FULLTEXT INDEX ft_bp_search (title, excerpt, content),
              CONSTRAINT fk_bp_cat FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
              CONSTRAINT fk_bp_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS faqs (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              question TEXT NOT NULL,
              answer TEXT NOT NULL,
              category VARCHAR(64) NULL,
              sorting INT NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_faq_active_sort (is_active, sorting)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS announcements (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              title VARCHAR(255) NOT NULL,
              content TEXT NOT NULL,
              severity VARCHAR(16) NOT NULL DEFAULT 'INFO',
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              audience VARCHAR(32) NULL COMMENT 'all|customers|staff',
              starts_at DATETIME NULL,
              ends_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_ann_active_window (is_active, starts_at, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS media (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              uploader_id BIGINT UNSIGNED NULL,
              url VARCHAR(512) NOT NULL,
              storage_key VARCHAR(512) NULL,
              file_name VARCHAR(255) NOT NULL,
              mime_type VARCHAR(128) NOT NULL,
              size INT NOT NULL,
              purpose VARCHAR(32) NULL COMMENT 'avatar|blog|ticket|service|branding',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_media_purpose_created (purpose, created_at),
              CONSTRAINT fk_media_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('tickets','ticket_messages','ticket_attachments','referral_accounts','referrals','referral_commissions','blog_categories','blog_posts','faqs','announcements','media');
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
