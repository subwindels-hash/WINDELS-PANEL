<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 001 — Identity & Access
 * users, price_groups, roles, permissions, role_permissions,
 * user_sessions, refresh_tokens, mfa_methods, login_attempts
 *
 * Conventions (Checkpoint 01 / Artifact 2):
 *  - InnoDB, utf8mb4_unicode_ci, DATETIME stored in UTC
 *  - internal BIGINT id + public_id CHAR(26) ULID exposed in URLs/APIs
 *  - no license/purchase-code tables (§81)
 */
class Migration_Identity extends CI_Migration {

    public static function statements() {
        return array(

            "CREATE TABLE IF NOT EXISTS price_groups (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(64) NOT NULL UNIQUE,
              description VARCHAR(255) NULL,
              is_default TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS users (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              public_id CHAR(26) NOT NULL UNIQUE,
              username VARCHAR(64) NOT NULL UNIQUE,
              email VARCHAR(255) NOT NULL UNIQUE,
              password_hash VARCHAR(255) NOT NULL,
              first_name VARCHAR(100) NULL,
              last_name VARCHAR(100) NULL,
              phone VARCHAR(32) NULL,
              avatar_url VARCHAR(512) NULL,
              status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
              role VARCHAR(32) NOT NULL DEFAULT 'CUSTOMER',
              price_group_id BIGINT UNSIGNED NULL,
              referral_code VARCHAR(32) NULL UNIQUE,
              referred_by_id BIGINT UNSIGNED NULL,
              timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
              locale VARCHAR(8) NOT NULL DEFAULT 'en',
              email_verified_at DATETIME NULL,
              last_login_at DATETIME NULL,
              last_login_ip VARCHAR(45) NULL,
              mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
              mfa_secret VARCHAR(255) NULL COMMENT 'encrypted at rest',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_users_status_created (status, created_at),
              INDEX idx_users_role (role),
              INDEX idx_users_price_group (price_group_id),
              INDEX idx_users_referred_by (referred_by_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "ALTER TABLE users
              ADD CONSTRAINT fk_users_price_group FOREIGN KEY (price_group_id) REFERENCES price_groups(id) ON DELETE SET NULL",

            "ALTER TABLE users
              ADD CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by_id) REFERENCES users(id) ON DELETE SET NULL",

            "CREATE TABLE IF NOT EXISTS roles (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(64) NOT NULL UNIQUE,
              description VARCHAR(255) NULL,
              is_system TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS permissions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              perm_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'e.g. orders.view',
              description VARCHAR(255) NULL,
              category VARCHAR(64) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS role_permissions (
              role_id BIGINT UNSIGNED NOT NULL,
              permission_id BIGINT UNSIGNED NOT NULL,
              PRIMARY KEY (role_id, permission_id),
              INDEX idx_rp_perm (permission_id),
              CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
              CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS user_sessions (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL,
              token_hash CHAR(64) NOT NULL,
              ip VARCHAR(45) NULL,
              user_agent VARCHAR(512) NULL,
              expires_at DATETIME NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_sess_user_exp (user_id, expires_at),
              INDEX idx_sess_token (token_hash),
              CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS refresh_tokens (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL,
              token_hash CHAR(64) NOT NULL UNIQUE,
              expires_at DATETIME NOT NULL,
              revoked_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_rt_user (user_id),
              CONSTRAINT fk_rt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS mfa_methods (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              user_id BIGINT UNSIGNED NOT NULL,
              type VARCHAR(16) NOT NULL DEFAULT 'TOTP',
              secret VARCHAR(255) NOT NULL COMMENT 'encrypted at rest',
              recovery_codes JSON NULL COMMENT 'hashed recovery codes',
              verified TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_mfa_user_type (user_id, type),
              CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS login_attempts (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              email VARCHAR(255) NULL,
              ip VARCHAR(45) NOT NULL,
              success TINYINT(1) NOT NULL,
              reason VARCHAR(255) NULL,
              user_agent VARCHAR(512) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_la_ip_created (ip, created_at),
              INDEX idx_la_email_created (email, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    public static function tables() {
        return array('price_groups','users','roles','permissions','role_permissions','user_sessions','refresh_tokens','mfa_methods','login_attempts');
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
