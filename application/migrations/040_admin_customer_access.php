<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 040 — let operational administrators access customer accounts.
 *
 * Customer impersonation already had a separately permissioned, audited,
 * expiring implementation, but `users.impersonate` was granted only to the
 * SUPER_ADMIN bypass in a fresh install. As a result, an ordinary ADMIN could
 * see and edit a customer file but could not use the requested "login as
 * customer" support flow. The dashboard entry point added with this migration
 * uses that existing boundary; this data migration makes it available to the
 * ADMIN role on databases that were seeded before the default matrix changed.
 *
 * STAFF is deliberately untouched. A super admin can still delegate the
 * permission from Roles and permissions when first-line support requires it.
 */
class Migration_Admin_customer_access extends CI_Migration {

    public static function statements() {
        return array(
            "INSERT INTO role_permissions (role_id, permission_id)
             SELECT r.id, p.id
               FROM roles r
               JOIN permissions p ON p.perm_key = 'users.impersonate'
               LEFT JOIN role_permissions rp
                 ON rp.role_id = r.id AND rp.permission_id = p.id
              WHERE r.name = 'ADMIN' AND rp.role_id IS NULL",
        );
    }

    /** Adds no tables; this is an idempotent role-permission data migration. */
    public static function tables() {
        return array();
    }

    public function up() {
        foreach (self::statements() as $sql) {
            $this->db->query($sql);
        }
    }

    public function down() {
        $this->db->query(
            "DELETE rp
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name = 'ADMIN' AND p.perm_key = 'users.impersonate'"
        );
    }
}
