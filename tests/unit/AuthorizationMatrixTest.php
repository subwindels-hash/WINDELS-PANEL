<?php
use PHPUnit\Framework\TestCase;

/**
 * Authorisation invariants across every admin route.
 *
 * The panel's access control is per-endpoint: a constructor `require_perm()`
 * for the screen, another for each mutation, and POST-only guards so nothing
 * that changes state can be triggered by a link, an image tag or a prefetch.
 * That is the right design, and it is enforced by hand in twenty controllers —
 * which means the only thing standing between it and a hole is that whoever
 * adds the twenty-first remembers.
 *
 * These tests are that memory. They read `config/routes.php`, resolve every
 * admin route to the method behind it, and assert:
 *
 *   1. the method exists (a routed 404 is an operator staring at a broken
 *      screen, and it is how the refill/cancellation queues shipped dead);
 *   2. every entry point is behind a permission;
 *   3. anything that writes is POST-only, so it cannot be CSRF'd by a GET.
 *
 * They also pin the accuracy of `RbacService::unenforced()`, the screen that
 * tells an operator which ticks do nothing. It used to answer that question
 * with a substring search over the whole codebase, so a permission that merely
 * hid a navigation link counted as enforced — the precise false reassurance
 * that screen exists to prevent.
 */
class AuthorizationMatrixTest extends TestCase
{
    private static $root;
    private static $routes;
    private static $sources = array();

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        self::$routes = self::admin_routes();
    }

    /** Every `$route[...] = 'admin/...'` pair, as url => controller/method. */
    private static function admin_routes()
    {
        $src = file_get_contents(self::$root.'/application/config/routes.php');
        preg_match_all("/\\\$route\\['([^']+)'\\]\\s*=\\s*'([^']+)'/", $src, $m, PREG_SET_ORDER);
        $out = array();
        foreach ($m as $one) {
            if (strpos($one[2], 'admin/') !== 0) continue;
            $out[$one[1]] = $one[2];
        }
        return $out;
    }

    private static function source($controller)
    {
        $file = self::$root.'/application/controllers/admin/'
              . ucfirst($controller).'.php';
        if (!isset(self::$sources[$file])) {
            self::$sources[$file] = is_file($file) ? file_get_contents($file) : null;
        }
        return self::$sources[$file];
    }

    /** A method body, plus the bodies of every private helper it calls. */
    private static function effective_body($src, $method)
    {
        $body = self::body($src, $method, 'public');
        if ($body === null) return null;
        $expanded = $body;
        if (preg_match_all('/\$this->(\w+)\(/', $body, $calls)) {
            foreach (array_unique($calls[1]) as $helper) {
                foreach (array('private', 'protected', 'public') as $vis) {
                    $helper_body = self::body($src, $helper, $vis);
                    if ($helper_body !== null) { $expanded .= "\n".$helper_body; break; }
                }
            }
        }
        return $expanded;
    }

    private static function body($src, $method, $visibility)
    {
        if (!preg_match('/\n    '.$visibility.' function\s+'.preg_quote($method, '/').'\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $rest  = substr($src, $start);
        $end   = preg_match('/\n    (public|private|protected) function\s/', $rest, $x, PREG_OFFSET_CAPTURE)
            ? $x[0][1] : strlen($rest);
        return substr($rest, 0, $end);
    }

    private static function constructor($src)
    {
        return preg_match('/public function __construct\(\)[\s\S]*?\n    \}/', $src, $m) ? $m[0] : '';
    }

    /* ===================== every route reaches real code ================= */

    public function testEveryAdminRouteResolvesToAMethodThatExists()
    {
        $this->assertGreaterThan(150, count(self::$routes), 'the route table should be fully read');

        $missing = array();
        foreach (self::$routes as $url => $target) {
            $parts = explode('/', $target);
            $controller = $parts[1] ?? '';
            $method = $parts[2] ?? 'index';
            $src = self::source($controller);
            if ($src === null) { $missing[] = "$url -> no controller for $target"; continue; }
            if (self::body($src, $method, 'public') === null) {
                $missing[] = "$url -> {$target} (method missing)";
            }
        }
        $this->assertSame(array(), $missing,
            "a routed admin URL with nothing behind it is a dead screen, not a 404 someone notices");
    }

    /* ========================= nothing is ungated ======================== */

    public function testEveryAdminEntryPointIsBehindAPermission()
    {
        $ungated = array();
        foreach (self::$routes as $url => $target) {
            $parts = explode('/', $target);
            $src = self::source($parts[1] ?? '');
            if ($src === null) continue;
            $body = self::effective_body($src, $parts[2] ?? 'index');
            if ($body === null) continue;
            if (strpos($body, 'require_perm(') === false
                && strpos(self::constructor($src), 'require_perm(') === false) {
                $ungated[] = $url.' -> '.$target;
            }
        }
        $this->assertSame(array(), $ungated,
            'an admin endpoint with no permission check is open to every signed-in member of staff');
    }

    /* ==================== nothing that writes accepts GET ================ */

    public function testEveryAdminMutationIsPostOnly()
    {
        $writable = '/set_flashdata\(\'success\'|->insert\(|->update\(|->delete\(/';
        // Either the method refuses anything that is not a POST, or it is the
        // form-and-save pattern where the write lives inside an explicit
        // `if (method === POST)` branch and the GET path only renders.
        $guarded  = '/post_only\(\)|require_post\(\)|guard_post\(|method\(true\)\s*!==\s*\'POST\''
                  . '|method\(\)\s*!==\s*\'post\'|method\(true\)\s*===\s*\'POST\'/';

        $offenders = array();
        foreach (self::$routes as $url => $target) {
            $parts = explode('/', $target);
            $src = self::source($parts[1] ?? '');
            if ($src === null) continue;
            $body = self::effective_body($src, $parts[2] ?? 'index');
            if ($body === null) continue;
            if (preg_match($writable, $body) && !preg_match($guarded, $body)) {
                $offenders[] = $url.' -> '.$target;
            }
        }
        $this->assertSame(array(), $offenders,
            'a state change reachable by GET can be fired by an <img> tag on any page a signed-in admin visits');
    }

    /* ============ the screen that reports which ticks do nothing ========= */

    /**
     * The detector must not count a mention. A permission used only to hide a
     * navigation link is exactly the case an operator needs told about.
     */
    public function testTheUnenforcedDetectorIgnoresMentionsThatAreNotGates()
    {
        $service = self::$root.'/application/libraries/RbacService.php';
        $src = file_get_contents($service);

        $this->assertStringNotContainsString("strpos(\$src, \"'\".\$p->perm_key.\"'\")", $src,
            'the old substring test counted any mention as enforcement');
        $this->assertStringContainsString('require_any_perm', $src,
            'both gates are recognised');
        $this->assertStringContainsString('dynamic_gate_maps', $src,
            'keys reached through a declared map are still enforcement');

        // can()/has_perm() decide whether to draw a button. If they counted,
        // hiding a link would look like protecting an endpoint.
        $this->assertDoesNotMatchRegularExpression('/\$gate\s*=\s*\'[^\']*has_perm/', $src);
        $this->assertDoesNotMatchRegularExpression('/\$gate\s*=\s*\'[^\']*\|can\|/', $src);
    }

    /**
     * Every permission the seeder grants must gate something.
     *
     * Run against the real source with the real detector logic, so a
     * permission added to the catalogue without a check behind it fails here
     * rather than being discovered by a customer.
     */
    public function testEverySeededPermissionActuallyGatesSomething()
    {
        $seeder = file_get_contents(self::$root.'/application/seeds/Core_seeder.php');
        // permission_catalog() only — the seeder also carries email template
        // keys and setting names that look like permissions but are not.
        $this->assertSame(1, preg_match(
            '/function permission_catalog\(\)\s*\{\s*return array\(([\s\S]*?)\n        \);/',
            $seeder, $m), 'the permission catalogue must be readable from the seeder');
        $catalogue = $m[1];
        preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $catalogue, $found);
        $keys = array_values(array_unique($found[1]));
        $this->assertNotEmpty($keys, 'the permission catalogue should be readable from the seeder');

        $src = '';
        foreach (array('controllers', 'libraries', 'core') as $dir) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$root.'/application/'.$dir));
            foreach ($rii as $file) {
                if ($file->isDir() || substr($file->getFilename(), -4) !== '.php') continue;
                $src .= file_get_contents($file->getPathname());
            }
        }

        // The same rule RbacService::enforced_keys() applies, written out
        // here rather than called: a detector that grades its own homework
        // cannot fail, and this test exists to catch the detector too. It
        // mirrors each detection step — including helper *defaults*: a bare
        // `guard_post()` gates on the helper's `$perm = 'providers.sync'`
        // default, so the default counts as enforcement. (The previous shape
        // scanned `guard_post(` anywhere, which accidentally matched the
        // helper's own signature and hid the detector's blind spot.)
        $enforced = array();

        // 1. Literals handed to a gate, directly. One level of nesting so
        //    `require_perm(ContentService::permission($d))` stays readable.
        $gate = '/(?:require_perm|require_any_perm)\s*\(([^()]*(?:\([^()]*\)[^()]*)*)\)/';
        if (preg_match_all($gate, $src, $calls)) {
            foreach ($calls[1] as $args) {
                if (preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $args, $lit)) {
                    foreach ($lit[1] as $key) $enforced[$key] = true;
                }
            }
        }

        // 2. Helpers that forward their argument to a gate: the key lives at
        //    the call site, or in the gated parameter's default when a call
        //    passes nothing.
        $helpers = array();
        if (preg_match_all('/function\s+(\w+)\s*\(([^)]*)\)\s*\{[\s\S]{0,800}?require_perm\(\s*\$(\w+)/',
                           $src, $found, PREG_SET_ORDER)) {
            foreach ($found as $fn) {
                $helpers[$fn[1]] = preg_match('/\$'.preg_quote($fn[3], '/')
                    ."\s*=\s*'([a-z_]+(?:\.[a-z_*]+)+)'/", $fn[2], $d) ? $d[1] : null;
            }
        }
        foreach ($helpers as $helper => $default) {
            if (preg_match_all('/\$this->'.preg_quote($helper, '/').'\s*\(([^;]{0,200}?)\)/',
                               $src, $calls)) {
                foreach ($calls[1] as $args) {
                    if (preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $args, $lit)) {
                        foreach ($lit[1] as $key) $enforced[$key] = true;
                    } elseif ($default !== null && trim($args) === '') {
                        $enforced[$default] = true;
                    }
                }
            }
        }
        // Keys reached through a declared map read by a gate.
        if (preg_match_all('/require_perm\(\s*self::\$(\w+)\[/', $src, $props)) {
            foreach (array_unique($props[1]) as $prop) {
                if (preg_match('/\$'.$prop.'\s*=\s*array\(([\s\S]{0,2000}?)\);/', $src, $arr)
                    && preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $arr[1], $lit)) {
                    foreach ($lit[1] as $key) $enforced[$key] = true;
                }
            }
        }
        if (preg_match_all('/require_perm\(\s*(\w+)::(\w+)\(/', $src, $calls, PREG_SET_ORDER)) {
            foreach ($calls as $call) {
                if (preg_match('/function\s+'.$call[2].'\s*\([^)]*\)\s*\{([\s\S]{0,800}?)\n\s*\}/', $src, $fn)
                    && preg_match_all("/'([a-z_]+(?:\.[a-z_*]+)+)'/", $fn[1], $lit)) {
                    foreach ($lit[1] as $key) $enforced[$key] = true;
                }
            }
        }

        $dead = array_values(array_filter($keys, function ($k) use ($enforced) {
            return !isset($enforced[$k]);
        }));
        $this->assertSame(array(), $dead,
            'a granted permission that gates nothing is a promise the code does not keep');
    }

    /**
     * The exact false positive an operator reported: the Roles screen listed
     * `providers.sync` under "currently gate nothing" while it gates three
     * actions. Providers::test/sync/sync_balance call `guard_post()` with no
     * argument, and the helper's `$perm = 'providers.sync'` default is the key
     * its require_perm() checks — a bare call IS the gate. The detector (and
     * this mirror of its rule) must count the default, or the screen tells an
     * operator that unticking the box changes nothing while it revokes live
     * endpoints.
     */
    public function testProvidersSyncDefaultIsCountedAsEnforcement()
    {
        $src = self::source('Providers');

        $this->assertStringContainsString("guard_post(\$perm = 'providers.sync')", $src,
            'the parameter default is the gate for the bare guard_post() calls');
        $this->assertSame(3, substr_count($src, '$this->guard_post()'),
            'test/sync/sync_balance must keep their bare guard_post() calls — losing the '
            .'default while keeping the calls would leave three actions ungated');
    }
}
