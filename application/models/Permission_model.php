<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Permission_model extends MY_Model {
    protected $table = 'permissions';

    /** All permission keys granted to a role name. SUPER_ADMIN bypasses this check. */
    /**
     * Permission keys granted to a role.
     *
     * Memoised per request (Session 18). The role/permission matrix cannot
     * change mid-request, but this three-table join was being re-run by every
     * single require_perm() call — an admin page issues four or five of them
     * before it renders a row, plus one more for the view's $permissions list.
     */
    private static $role_cache = array();

    public function keys_for_role($role_name){
        if (isset(self::$role_cache[$role_name])) {
            return self::$role_cache[$role_name];
        }
        $rows = $this->db->select('p.perm_key')
            ->from('permissions p')
            ->join('role_permissions rp', 'rp.permission_id = p.id')
            ->join('roles r', 'r.id = rp.role_id')
            ->where('r.name', $role_name)
            ->get()->result();
        $keys = array();
        foreach ($rows as $r) $keys[] = $r->perm_key;
        return self::$role_cache[$role_name] = $keys;
    }

    /** Drop the memo — for tests and for code that edits the role matrix. */
    public static function flush_cache(){
        self::$role_cache = array();
    }
    public function role_has($role_name, $perm_key){
        if ($role_name === 'SUPER_ADMIN') return TRUE;
        return in_array($perm_key, $this->keys_for_role($role_name), TRUE);
    }
}
