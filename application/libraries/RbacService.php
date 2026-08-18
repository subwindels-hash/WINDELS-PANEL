<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * RbacService — reading and editing the role/permission matrix.
 *
 * This is the most dangerous screen in the panel, because it is the one that
 * can remove the ability to use the panel. The failure mode is not a bad row;
 * it is an operator un-ticking the wrong box and locking every administrator
 * out of the system that grants access, with no way back in except SQL.
 *
 * So the rules here are all about staying recoverable:
 *
 *   - **SUPER_ADMIN's grid is not editable.** `AuthService::can()` short-
 *     circuits to true for that role before it ever reads a row, so the grid
 *     is decorative — editing it would imply a restriction the code does not
 *     honour. It is rendered locked, and refused server-side.
 *   - **You cannot remove `staff.manage` from your own role.** That is the
 *     single click that ends all further clicks. SUPER_ADMIN is unaffected
 *     (it bypasses checks), which is exactly why one must always exist.
 *   - **CUSTOMER may hold nothing.** Every customer shares that role, so a
 *     stray tick there is a mass privilege grant, not a mistake affecting one
 *     person.
 *   - **Roles themselves are fixed.** All four are `is_system = 1`, the code
 *     branches on their names, and `users.role` is a string column rather
 *     than a foreign key. Inventing a fifth role would produce something no
 *     controller recognises, so this service edits the matrix, never the role
 *     list.
 *
 * The permission catalogue is owned by the seeder, not by this class: it is
 * derived from what `require_perm()` calls actually exist.
 */
class RbacService {

    /** Editing this role's grid is meaningless — it bypasses every check. */
    const UNRESTRICTED = 'SUPER_ADMIN';

    /** Shared by every customer; must stay empty of staff permissions. */
    const CUSTOMER = 'CUSTOMER';

    /** Losing this locks the holder out of the screen that grants it back. */
    const KEYSTONE = 'staff.manage';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Role_model', 'Permission_model', 'User_model'));
    }

    /* ------------------------------ reading ----------------------------- */

    /** Every role, with its headcount. */
    public function roles() {
        $roles = $this->ci->db->order_by('id', 'ASC')->get('roles')->result();
        foreach ($roles as $role) {
            $role->headcount = (int)$this->ci->db
                ->where('role', $role->name)->from('users')->count_all_results();
            $role->editable  = $this->is_editable($role->name);
        }
        return $roles;
    }

    /** The permission catalogue grouped by category, for the grid's rows. */
    public function catalogue() {
        $out = array();
        foreach ($this->ci->db->order_by('category', 'ASC')->order_by('perm_key', 'ASC')
                              ->get('permissions')->result() as $p) {
            $out[$p->category ?: 'other'][] = $p;
        }
        return $out;
    }

    /** role name => array of held permission keys. */
    public function matrix() {
        $out = array();
        foreach ($this->ci->db->get('roles')->result() as $role) {
            $out[$role->name] = $this->ci->Permission_model->keys_for_role($role->name);
        }
        return $out;
    }

    /**
     * Which permissions are granted but gate nothing yet.
     *
     * Surfaced rather than hidden: a permission with no `require_perm()` call
     * behind it is a promise the code does not keep, and an operator ticking
     * it deserves to know it currently does nothing. This is how the missing
     * admin modules were found in the first place.
     */
    public function unenforced() {
        $root = defined('APPPATH') ? APPPATH : (dirname(dirname(__FILE__)).'/');
        $src  = '';
        foreach (array('controllers', 'libraries', 'core') as $dir) {
            $path = $root.$dir;
            if (!is_dir($path)) continue;
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($rii as $file) {
                if ($file->isDir() || substr($file->getFilename(), -4) !== '.php') continue;
                $src .= file_get_contents($file->getPathname());
            }
        }
        $out = array();
        foreach ($this->ci->db->get('permissions')->result() as $p) {
            if (strpos($src, "'".$p->perm_key."'") === false) $out[] = $p->perm_key;
        }
        return $out;
    }

    /* ------------------------------ writing ----------------------------- */

    /**
     * Replace one role's permission set.
     *
     * Takes the whole set rather than a single toggle so the grid saves
     * atomically: a half-applied matrix is the state most likely to lock
     * someone out.
     */
    public function set_permissions($actor, $role_name, array $keys) {
        $role = $this->ci->Role_model->find_by_name($role_name);
        if (!$role) {
            return $this->err('NO_ROLE', 'Unknown role "'.$role_name.'".');
        }
        if ($role_name === self::UNRESTRICTED) {
            return $this->err('UNRESTRICTED',
                'SUPER_ADMIN bypasses every permission check, so its grid cannot be edited.');
        }
        if ($role_name === self::CUSTOMER && $keys) {
            return $this->err('CUSTOMER',
                'The customer role must hold no staff permissions — every customer shares it.');
        }

        // Resolve to ids, ignoring anything not in the catalogue.
        $ids   = array();
        $valid = array();
        foreach ($this->ci->db->get('permissions')->result() as $p) {
            if (in_array($p->perm_key, $keys, true)) {
                $ids[] = (int)$p->id;
                $valid[] = $p->perm_key;
            }
        }

        // The lockout guard. SUPER_ADMIN is exempt because it never reads the
        // matrix — which is precisely why one must always exist.
        if ($actor && $actor->role === $role_name && $actor->role !== self::UNRESTRICTED
            && !in_array(self::KEYSTONE, $valid, true)) {
            return $this->err('LOCKOUT',
                'You cannot remove "'.self::KEYSTONE.'" from your own role — you would not be able to grant it back.');
        }

        $before = $this->ci->Permission_model->keys_for_role($role_name);

        $this->ci->db->trans_start();
        $this->ci->db->where('role_id', (int)$role->id)->delete('role_permissions');
        if ($ids) {
            $rows = array();
            foreach ($ids as $pid) {
                $rows[] = array('role_id' => (int)$role->id, 'permission_id' => $pid);
            }
            $this->ci->db->insert_batch('role_permissions', $rows);
        }
        $this->ci->db->trans_complete();

        if ($this->ci->db->trans_status() === false) {
            return $this->err('DB', 'The permission change could not be saved.');
        }

        // The matrix is memoised per request; it just changed.
        Permission_model::flush_cache();

        sort($before);
        $after = $valid;
        sort($after);

        return array(
            'ok' => true, 'error' => null, 'code' => null,
            'before' => array('permissions' => $before),
            'after'  => array('permissions' => $after),
            'added'   => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        );
    }

    /** Roles whose grid may be edited at all. */
    public function is_editable($role_name) {
        return $role_name !== self::UNRESTRICTED && $role_name !== self::CUSTOMER;
    }

    private function err($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code,
                     'before' => null, 'after' => null);
    }
}
