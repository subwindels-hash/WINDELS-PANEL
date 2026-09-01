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
     * Surfaced rather than hidden: a permission with no check behind it is a
     * promise the code does not keep, and an operator ticking it deserves to
     * know it currently does nothing. This is how the missing admin modules
     * were found in the first place.
     *
     * ## Why this is not a substring search any more
     *
     * It used to concatenate every PHP file under controllers/, libraries/ and
     * core/ and ask whether the string `'vtu.refund'` appeared **anywhere** in
     * it. Every permission key also appears in the navigation tree, in tab
     * definitions, in `$this->auth->can()` calls that only decide whether to
     * draw a button, and in queue maps — so a permission that merely *hides a
     * link* was reported as enforced. That is the exact false reassurance this
     * screen exists to prevent: the operator is told the tick means something,
     * ticks it, and the endpoint behind the hidden button is still open to
     * anyone who types the URL.
     *
     * A key now counts as enforced only when it reaches a **gate**:
     *
     *   - as a literal argument to `require_perm()` / `require_any_perm()`,
     *     either directly or handed to a shared `guard()` helper — including
     *     the helper's *default* parameter value, which is what a call with
     *     no permission argument gates on (`guard_post()` on the provider
     *     test/sync actions checks its `$perm = 'providers.sync'` default), or
     *   - as a value in a declared map a gate reads dynamically — the
     *     `admin/Operations` queue table and `ContentService::permission()`
     *     both do this, so their arrays are recognised.
     *
     * `can()` and `has_perm()` are deliberately NOT gates: they answer
     * "should I draw this?", never "may you do this?".
     */
    public function unenforced() {
        $src = $this->source();
        $enforced = $this->enforced_keys($src);
        $out = array();
        foreach ($this->ci->db->get('permissions')->result() as $p) {
            if (!in_array($p->perm_key, $enforced, true)) $out[] = $p->perm_key;
        }
        return $out;
    }

    /** Every permission key the code actually gates something with. */
    public function enforced_keys($src = null) {
        $src = $src === null ? $this->source() : $src;
        $keys = array();

        // 1. Literals handed to a gate, directly or through a guard helper.
        //    The argument list is captured with one level of nesting allowed so
        //    `require_perm(ContentService::permission($d))` does not truncate.
        $gate = '/(?:require_perm|require_any_perm)\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/';
        if (preg_match_all($gate, $src, $calls)) {
            foreach ($calls[1] as $args) {
                if (preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $args, $found)) {
                    foreach ($found[1] as $key) $keys[] = $key;
                }
            }
        }

        // 2. Helpers that forward their argument to a gate. Half the admin
        //    controllers share one `guard($public_id, $perm)` that ends in
        //    `require_perm($perm)`, so the literal lives at the call site.
        //    Detected by shape — a helper whose body gates a *variable* — so a
        //    new controller following the same pattern is covered without
        //    naming it here. The gated parameter's default counts as well:
        //    Providers::test/sync/sync_balance call bare `guard_post()`, whose
        //    `$perm = 'providers.sync'` default is the key being checked.
        foreach ($this->forwarding_helpers($src) as $helper => $default) {
            $call = '/\$this->'.preg_quote($helper, '/').'\s*\(([^;]{0,200}?)\)/';
            if (preg_match_all($call, $src, $calls)) {
                $bare = false;
                foreach ($calls[1] as $args) {
                    if (preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $args, $found)) {
                        foreach ($found[1] as $key) $keys[] = $key;
                    } elseif ($default !== null && trim($args) === '') {
                        // No permission argument — the parameter default is
                        // the key the gate checks.
                        $bare = true;
                    }
                }
                if ($bare) $keys[] = $default;
            }
        }

        // 3. Keys reached through a declared map a gate reads dynamically.
        foreach ($this->dynamic_gate_maps($src) as $key) $keys[] = $key;

        return array_values(array_unique($keys));
    }

    /**
     * Helpers that gate on a permission handed to them, mapped to the default
     * value of the gated parameter (null when it has none).
     *
     * `private function guard($public_id, $perm) { ...; $this->require_perm($perm); }`
     * is the shape: the check is real, the key is at the call site. A helper
     * that merely *mentions* a permission does not qualify — the body has to
     * pass a variable to require_perm(). A permission default on that variable
     * (`guard_post($perm = 'providers.sync')`) is itself a gate: a call that
     * passes nothing checks the default, so it is recorded as such.
     */
    private function forwarding_helpers($src) {
        $out = array();
        $re = '/function\s+(\w+)\s*\(([^)]*)\)\s*\{[\s\S]{0,800}?require_perm\(\s*\$(\w+)/';
        if (preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $one) {
                $name = $one[1];
                if ($name === 'require_perm') continue;
                $out[$name] = $this->default_perm($one[2], $one[3]);
            }
        }
        return $out;
    }

    /** The gated parameter's default value, when it is a permission key. */
    private function default_perm($params, $gated) {
        if (preg_match('/\$'.preg_quote($gated, '/').'\s*=\s*\'([a-z_]+(?:\.[a-z_*]+)+)\'/', $params, $d)) {
            return $d[1];
        }
        return null;
    }

    /**
     * Permission keys reached through a table rather than a literal.
     *
     * Two shapes exist in this codebase and both end in `require_perm()`:
     *
     *   private static $queues = array('refills' => array('Refills', 'orders.refill'), ...);
     *   ... require_perm(self::$queues[$queue][1]);
     *
     *   public static function permission($d) { $m = array('blog' => 'blog.manage', ...); }
     *   ... require_perm(ContentService::permission($domain));
     *
     * They are matched only when the same file also contains a `require_perm()`
     * that reads them, so an ordinary lookup table cannot launder a key into
     * looking enforced.
     */
    private function dynamic_gate_maps($src) {
        $keys = array();
        // self::$map[...][n] passed to require_perm
        if (preg_match_all('/require_perm\(\s*self::\$(\w+)\[/', $src, $m)) {
            foreach (array_unique($m[1]) as $prop) {
                if (preg_match('/\$'.$prop.'\s*=\s*array\(([\s\S]{0,2000}?)\);/', $src, $arr)
                    && preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $arr[1], $found)) {
                    foreach ($found[1] as $key) $keys[] = $key;
                }
            }
        }
        // require_perm(Something::method(...)) → that method's own literals
        if (preg_match_all('/require_perm\(\s*(\w+)::(\w+)\(/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $call) {
                if (preg_match('/function\s+'.$call[2].'\s*\([^)]*\)\s*\{([\s\S]{0,800}?)\n\s*\}/', $src, $fn)
                    && preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $fn[1], $found)) {
                    foreach ($found[1] as $key) $keys[] = $key;
                }
            }
        }
        return $keys;
    }

    /** Every PHP file a gate could live in, as one string. */
    private function source() {
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
        return $src;
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
