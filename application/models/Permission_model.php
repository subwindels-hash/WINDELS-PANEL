<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Permission_model extends MY_Model {
    protected $table = 'permissions';

    /** All permission keys granted to a role name. SUPER_ADMIN bypasses this check. */
    public function keys_for_role($role_name){
        $rows = $this->db->select('p.perm_key')
            ->from('permissions p')
            ->join('role_permissions rp', 'rp.permission_id = p.id')
            ->join('roles r', 'r.id = rp.role_id')
            ->where('r.name', $role_name)
            ->get()->result();
        $keys = array();
        foreach ($rows as $r) $keys[] = $r->perm_key;
        return $keys;
    }
    public function role_has($role_name, $perm_key){
        if ($role_name === 'SUPER_ADMIN') return TRUE;
        return in_array($perm_key, $this->keys_for_role($role_name), TRUE);
    }
}
