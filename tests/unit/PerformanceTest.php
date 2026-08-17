<?php
use PHPUnit\Framework\TestCase;

/**
 * Session 18 — performance.
 *
 * Performance regressions are invisible in a functional test: the page still
 * renders, it just costs 40 queries instead of 4. So these tests count round
 * trips rather than checking output, using a db double that records every
 * query it is asked for.
 *
 * The index tests read the migration DDL directly, because composite index
 * column order is the kind of thing that is silently wrong for years.
 */
class PerformanceTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
        if (!defined('BASEPATH')) define('BASEPATH', self::$root.'/system/');
        if (!class_exists('CI_Model')) eval('#[AllowDynamicProperties] class CI_Model { public $db; }');
        if (!function_exists('get_instance')) eval('function get_instance(){ return $GLOBALS["__fake_ci"]; }');
        if (!function_exists('log_message')) eval('function log_message($l,$m){}');
        require_once self::$root.'/application/core/MY_Model.php';
        require_once self::$root.'/application/models/Setting_model.php';
        require_once self::$root.'/application/models/Permission_model.php';
    }

    protected function setUp(): void
    {
        Setting_model::flush_cache();
        Permission_model::flush_cache();
    }

    /* ========================== repeated reads =========================== */

    public function testRepeatedSettingReadsHitTheDatabaseOnce()
    {
        $db = new PerfCountingDb(array(
            'settings' => array(
                (object)array('setting_key'=>'site_name',      'setting_value'=>'{"value":"WINDELS"}', 'category'=>'general', 'is_public'=>1),
                (object)array('setting_key'=>'mail_transport', 'setting_value'=>'{"value":"log"}',     'category'=>'mail',    'is_public'=>0),
            ),
        ));
        $m = $this->model('Setting_model', $db);

        // Sending one email reads several settings; placing an order reads more.
        $this->assertSame('WINDELS', $m->get('site_name'));
        $this->assertSame('log', $m->get('mail_transport'));
        $this->assertSame('WINDELS', $m->get('site_name'));
        $m->get('missing_key', 'fallback');

        $this->assertSame(1, $db->count('settings'),
            'settings must be read once per request, not once per key');
    }

    public function testSettingFallsBackToTheDefaultForUnknownKeys()
    {
        $db = new PerfCountingDb(array('settings' => array()));
        $m = $this->model('Setting_model', $db);

        // The memo must not turn "absent" into null and lose the default.
        $this->assertSame('fallback', $m->get('nope', 'fallback'));
        $this->assertNull($m->get('nope'));
    }

    public function testAWrittenSettingIsVisibleImmediately()
    {
        $db = new PerfCountingDb(array(
            'settings' => array(
                (object)array('setting_key'=>'site_name', 'setting_value'=>'{"value":"OLD"}', 'category'=>'general', 'is_public'=>1),
            ),
        ));
        $m = $this->model('Setting_model', $db);

        $this->assertSame('OLD', $m->get('site_name'));
        $m->set('site_name', 'NEW');
        // A stale cache after a write would be a correctness bug, not just a
        // performance one.
        $this->assertSame('NEW', $m->get('site_name'));
    }

    public function testPermissionLookupsForOneRoleQueryOnce()
    {
        $db = new PerfCountingDb(array(
            'permissions' => array((object)array('perm_key'=>'orders.view'), (object)array('perm_key'=>'orders.edit')),
        ));
        $m = $this->model('Permission_model', $db);

        // An admin page calls require_perm() several times before rendering,
        // then asks for the whole list again for the view.
        $this->assertTrue($m->role_has('ADMIN', 'orders.view'));
        $this->assertTrue($m->role_has('ADMIN', 'orders.edit'));
        $this->assertFalse($m->role_has('ADMIN', 'payments.refund'));
        $m->keys_for_role('ADMIN');

        $this->assertSame(1, $db->count('permissions'),
            'the role/permission join must not re-run for every permission check');
    }

    public function testDifferentRolesAreCachedSeparately()
    {
        $db = new PerfCountingDb(array(
            'permissions' => array((object)array('perm_key'=>'orders.view')),
        ));
        $m = $this->model('Permission_model', $db);

        $m->keys_for_role('ADMIN');
        $m->keys_for_role('STAFF');
        // Two distinct roles genuinely need two queries; caching by role must
        // not collapse them into one shared answer.
        $this->assertSame(2, $db->count('permissions'));
    }

    public function testSuperAdminShortCircuitsWithoutQuerying()
    {
        $db = new PerfCountingDb(array('permissions' => array()));
        $m = $this->model('Permission_model', $db);

        $this->assertTrue($m->role_has('SUPER_ADMIN', 'anything.at.all'));
        $this->assertSame(0, $db->count('permissions'));
    }

    /* ============================== N+1 ================================== */

    public function testBulkOrderLookupIsASingleQuery()
    {
        require_once self::$root.'/application/models/Order_model.php';
        $rows = array();
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = (object)array('public_id'=>"ORD{$i}", 'user_id'=>7, 'status'=>'COMPLETED');
        }
        $db = new PerfCountingDb(array('orders' => $rows));
        $m = $this->model('Order_model', $db);

        $ids = array();
        for ($i = 1; $i <= 100; $i++) $ids[] = "ORD{$i}";
        $found = $m->find_public_many_for_user($ids, 7);

        $this->assertCount(100, $found);
        $this->assertSame(1, $db->count('orders'),
            'the bulk status endpoint must not issue one query per order id');
        $this->assertArrayHasKey('ORD42', $found, 'results must be keyed by public id');
    }

    public function testBulkLookupStillScopesToTheOwner()
    {
        require_once self::$root.'/application/models/Order_model.php';
        $db = new PerfCountingDb(array('orders' => array(
            (object)array('public_id'=>'MINE',   'user_id'=>7, 'status'=>'COMPLETED'),
            (object)array('public_id'=>'THEIRS', 'user_id'=>9, 'status'=>'COMPLETED'),
        )));
        $m = $this->model('Order_model', $db);

        $found = $m->find_public_many_for_user(array('MINE', 'THEIRS'), 7);
        // Batching must not become an IDOR.
        $this->assertArrayHasKey('MINE', $found);
        $this->assertArrayNotHasKey('THEIRS', $found);
    }

    public function testBulkLookupHandlesEmptyAndDuplicateInput()
    {
        require_once self::$root.'/application/models/Order_model.php';
        $db = new PerfCountingDb(array('orders' => array(
            (object)array('public_id'=>'A', 'user_id'=>7, 'status'=>'PENDING'),
        )));
        $m = $this->model('Order_model', $db);

        // An empty list must short-circuit: WHERE IN () is a SQL error.
        $this->assertSame(array(), $m->find_public_many_for_user(array(), 7));
        $this->assertSame(0, $db->count('orders'));

        $m->find_public_many_for_user(array('A', 'A', 'A', ''), 7);
        $this->assertSame(array('A'), $db->last_where_in,
            'duplicates and blanks must be collapsed before the query');
    }

    public function testTheBulkEndpointUsesTheBatchedLookup()
    {
        $src = file_get_contents(self::$root.'/application/controllers/Api_v1.php');
        $method = substr($src, strpos($src, 'function orders_status'));
        $method = substr($method, 0, strpos($method, "\n    }"));

        $this->assertStringContainsString('find_public_many_for_user', $method);
        $this->assertStringNotContainsString('find_public_for_user(', $method,
            'the per-id lookup inside the loop is the N+1 this replaced');
    }

    public function testNoControllerQueriesInsideALoop()
    {
        $offenders = array();
        foreach ($this->controller_files() as $file) {
            $src = file_get_contents($file);
            foreach ($this->loop_bodies($src) as $body) {
                if (preg_match('~\$this->[A-Z]\w*_model->(find|get|for_)\w*\(~', $body)
                    || preg_match('~\$this->db->(get|count_all_results)\(~', $body)) {
                    $offenders[] = str_replace(self::$root.'/', '', $file);
                }
            }
        }
        // Migrate.php legitimately runs DDL statements in a loop.
        $offenders = array_values(array_filter(array_unique($offenders), function ($f) {
            return strpos($f, 'Migrate.php') === false;
        }));
        $this->assertSame(array(), $offenders,
            'query inside a loop (N+1): '.implode(', ', $offenders));
    }

    /* ============================= indexes =============================== */

    /**
     * Composite indexes must lead with equality predicates, then the range
     * column. `(next_run_at, status)` for `WHERE status = ? AND next_run_at <= ?`
     * lets MySQL use only the first column: it scans every future-dated row and
     * filters status afterwards.
     */
    public function testDueQueriesLeadWithTheEqualityColumn()
    {
        $indexes = $this->indexes();

        $cases = array(
            array('dripfeed_orders', 'idx_df_next_run',        array('status', 'next_run_at')),
            array('subscriptions',   'idx_sub_next_exec',      array('status', 'next_execution_at')),
            array('refills',         'idx_ref_status_checked', array('status', 'last_checked_at')),
            array('email_queue',     'idx_eq_status_sched',    array('status', 'scheduled_at')),
        );
        foreach ($cases as $c) {
            list($table, $name, $expected) = $c;
            $this->assertArrayHasKey($name, $indexes[$table], "{$table}.{$name} missing");
            $this->assertSame($expected, $indexes[$table][$name],
                "{$name} column order defeats the index for its scheduler query");
        }
    }

    public function testEveryCronSchedulerQueryHasASupportingIndex()
    {
        $indexes = $this->indexes();
        // Each worker opens with one of these; an unindexed scan here runs on
        // every tick, forever.
        $required = array(
            'dripfeed_orders'      => 'status',
            'subscriptions'        => 'status',
            'email_queue'          => 'status',
            'refills'              => 'status',
            'payment_transactions' => 'status',
        );
        foreach ($required as $table => $first_col) {
            $leads = array();
            foreach ($indexes[$table] as $cols) $leads[] = $cols[0];
            $this->assertContains($first_col, $leads,
                "{$table} has no index leading with {$first_col}");
        }
    }

    public function testForeignKeyColumnsUsedForLookupAreIndexed()
    {
        $indexes = $this->indexes();
        foreach (array(
            'orders'               => 'user_id',
            'notifications'        => 'user_id',
            'payment_transactions' => 'user_id',
            'tickets'              => 'user_id',
            'dripfeed_runs'        => 'dripfeed_order_id',
            'login_attempts'       => 'ip',
        ) as $table => $col) {
            $leads = array();
            foreach ($indexes[$table] as $cols) $leads[] = $cols[0];
            $this->assertContains($col, $leads, "{$table}.{$col} is queried but not indexed");
        }
    }

    /* ============================ payload size ============================ */

    public function testServicePickersDoNotSelectTheDescriptionBlob()
    {
        require_once self::$root.'/application/models/Service_model.php';
        $cols = preg_replace('~\s+~', ' ', Service_model::PICKER_COLUMNS);

        // description is TEXT and backs the FULLTEXT index; no picker renders it.
        $this->assertStringNotContainsString('description', $cols);
        foreach (array('id', 'public_id', 'name', 'rate', 'min_quantity', 'max_quantity') as $needed) {
            $this->assertMatchesRegularExpression('~\b'.$needed.'\b~', $cols,
                "picker views read {$needed}");
        }
    }

    public function testPickerColumnsAllExistInTheSchema()
    {
        require_once self::$root.'/application/models/Service_model.php';
        $ddl = $this->table_ddl('services');
        preg_match_all('~^\s+(\w+)\s+(?:BIGINT|INT|VARCHAR|CHAR|TEXT|DECIMAL|TINYINT|DATETIME|JSON|ENUM)~mi',
            $ddl, $m);
        $schema = $m[1];

        foreach (explode(',', preg_replace('~\s+~', ' ', Service_model::PICKER_COLUMNS)) as $col) {
            $col = trim($col);
            $this->assertContains($col, $schema,
                "picker selects '{$col}', which does not exist on services");
        }
    }

    public function testThePickerPagesUseTheProjectedQuery()
    {
        foreach (array('Orders', 'Dripfeed', 'Subscriptions') as $controller) {
            $src = file_get_contents(self::$root."/application/controllers/dashboard/{$controller}.php");
            $this->assertStringContainsString('active_for_picker()', $src,
                "dashboard/{$controller} should not SELECT * the whole catalogue");
        }
    }

    /* ====================== provider calls off render ===================== */

    public function testNoWebPageBlocksOnAProviderHttpCall()
    {
        // §18: provider calls must be off the page render. Admin actions are
        // explicit button presses and are allowed; ordinary page loads are not.
        $offenders = array();
        foreach ($this->controller_files() as $file) {
            if (strpos($file, '/admin/') !== false) continue;
            $src = file_get_contents($file);
            if (preg_match('~securehttpclient|->adapter\(|sync_services\(|test_connection\(~i', $src)) {
                $offenders[] = str_replace(self::$root.'/', '', $file);
            }
        }
        $this->assertSame(array(), $offenders,
            'these controllers make a provider HTTP call during a page render: '
            .implode(', ', $offenders));
    }

    public function testOrderSubmissionFailureDoesNotStrandTheCustomer()
    {
        // Placing an order does call the provider synchronously — the customer
        // needs the outcome. What matters is that a slow/failed provider does
        // not lose their money.
        $src = file_get_contents(self::$root.'/application/libraries/OrderService.php');
        $this->assertStringContainsString('SUBMIT_FAILED', $src);
        $this->assertStringContainsString('refund_charge', $src);
    }

    /* ============================ pagination ============================= */

    public function testListEndpointsAreBounded()
    {
        $unbounded = array();
        foreach ($this->controller_files() as $file) {
            $src = file_get_contents($file);
            foreach ($this->methods($src) as $name => $body) {
                if (!preg_match('~->(for_user|admin_search)\(~', $body)) continue;
                // Bounded by a named constant, a $limit variable, or a literal
                // count passed straight to the model.
                if (preg_match('~PER_PAGE|\blimit\b~i', $body)) continue;
                if (preg_match('~->(?:for_user|admin_search)\([^)]*,\s*\d+~', $body)) continue;
                // Single-row accessors (Wallet_model::for_user) are not lists.
                if (preg_match('~\$(?:wallet|method|tx)\s*=\s*\$this->\w+_model->for_user\(~', $body)) continue;
                $unbounded[] = basename($file).'::'.$name.'()';
            }
        }
        $this->assertSame(array(), $unbounded,
            'unpaginated list query: '.implode(', ', $unbounded));
    }

    /* ------------------------------ helpers ------------------------------ */

    /**
     * A model wired to a counting db.
     *
     * Real CI models reach $this->db through CI_Model's magic __get; the stub
     * CI_Model here has none, so the property is set directly.
     */
    private function model($class, $db)
    {
        $ci = new stdClass();
        $ci->db = $db;
        $GLOBALS['__fake_ci'] = $ci;
        $m = new $class();
        $m->db = $db;
        return $m;
    }

    /** table => [index name => [columns]] from the migration DDL. */
    private function indexes()
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = array();
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            $src = file_get_contents($file);
            foreach (preg_split('~CREATE TABLE IF NOT EXISTS ~', $src) as $chunk) {
                if (!preg_match('~^(\w+) \((.*?)\) ENGINE~s', $chunk, $m)) continue;
                $table = $m[1];
                if (!isset($cache[$table])) $cache[$table] = array();
                if (preg_match_all('~(?:INDEX|UNIQUE KEY|KEY)\s+(\w+)\s*\(([^)]*)\)~', $m[2], $ix, PREG_SET_ORDER)) {
                    foreach ($ix as $i) {
                        $cache[$table][$i[1]] = array_map('trim', explode(',', $i[2]));
                    }
                }
                // Inline `col ... UNIQUE` counts as an index on that column.
                if (preg_match_all('~^\s+(\w+)[^,\n]*\bUNIQUE\b~mi', $m[2], $u)) {
                    foreach ($u[1] as $col) $cache[$table]['uq_inline_'.$col] = array($col);
                }
            }
        }
        return $cache;
    }

    private function table_ddl($table)
    {
        foreach (glob(self::$root.'/application/migrations/*.php') as $file) {
            $src = file_get_contents($file);
            if (preg_match('~CREATE TABLE IF NOT EXISTS '.$table.' \((.*?)\) ENGINE~s', $src, $m)) {
                return $m[1];
            }
        }
        $this->fail("no DDL found for {$table}");
    }

    private function controller_files()
    {
        $out = array();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$root.'/application/controllers'));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    private function methods($src)
    {
        $out = array();
        if (!preg_match_all('~function\s+(\w+)\s*\([^)]*\)\s*\{~', $src, $m, PREG_OFFSET_CAPTURE)) return $out;
        foreach ($m[0] as $i => $match) {
            $start = $match[1] + strlen($match[0]);
            $depth = 1; $j = $start;
            while ($j < strlen($src) && $depth > 0) {
                if ($src[$j] === '{') $depth++;
                elseif ($src[$j] === '}') $depth--;
                $j++;
            }
            $out[$m[1][$i][0]] = substr($src, $start, $j - $start);
        }
        return $out;
    }

    private function loop_bodies($src)
    {
        $bodies = array();
        if (!preg_match_all('~\b(?:foreach|for|while)\s*\([^{]*\)\s*\{~', $src, $m, PREG_OFFSET_CAPTURE)) {
            return $bodies;
        }
        foreach ($m[0] as $match) {
            $start = $match[1] + strlen($match[0]);
            $depth = 1; $j = $start;
            while ($j < strlen($src) && $depth > 0) {
                if ($src[$j] === '{') $depth++;
                elseif ($src[$j] === '}') $depth--;
                $j++;
            }
            $bodies[] = substr($src, $start, $j - $start);
        }
        return $bodies;
    }
}

/* ------------------------------- doubles --------------------------------- */

/** Query-builder double that counts reads per table. */
class PerfCountingDb {
    public $last_where_in = array();
    private $tables, $counts = array();
    private $w = array(), $in = array(), $from = null;

    public function __construct(array $tables) { $this->tables = $tables; }

    public function count($table) { return $this->counts[$table] ?? 0; }

    public function select($s, $esc = null) { return $this; }
    public function from($t) { $this->from = preg_split('~\s+~', trim($t))[0]; return $this; }
    public function join($t, $on, $type = '') { return $this; }
    public function order_by($c, $d = 'ASC') { return $this; }
    public function limit($n, $o = 0) { return $this; }
    public function group_by($c) { return $this; }

    public function where($k, $v = null, $esc = null) {
        if (is_array($k)) { foreach ($k as $kk => $vv) $this->w[$kk] = $vv; }
        else $this->w[$k] = $v;
        return $this;
    }
    public function where_in($k, $v) {
        $this->in[$k] = $v;
        $this->last_where_in = $v;
        return $this;
    }

    public function get($t = null) {
        $table = $t ?: $this->from;
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;

        $rows = $this->tables[$table] ?? array();
        $w = $this->w; $in = $this->in;
        $this->w = array(); $this->in = array(); $this->from = null;

        $out = array();
        foreach ($rows as $r) {
            $ok = true;
            foreach ($w as $col => $val) {
                // Predicates on a joined alias (e.g. "r.name") cannot be
                // evaluated here; joins are not modelled, so skip them.
                if (strpos($col, '.') !== false) continue;
                if (!property_exists($r, $col) || (string)$r->$col !== (string)$val) { $ok = false; break; }
            }
            foreach ($in as $col => $vals) {
                if (!property_exists($r, $col) || !in_array($r->$col, $vals, false)) { $ok = false; break; }
            }
            if ($ok) $out[] = $r;
        }
        return new PerfResult($out);
    }

    public function insert($t, $d) {
        $this->tables[$t][] = (object)$d;
        return true;
    }
    public function update($t, $d) {
        $w = $this->w; $this->w = array();
        foreach ($this->tables[$t] ?? array() as $r) {
            $ok = true;
            foreach ($w as $col => $val) {
                if (!property_exists($r, $col) || (string)$r->$col !== (string)$val) { $ok = false; break; }
            }
            if ($ok) foreach ($d as $k => $v) $r->$k = $v;
        }
        return true;
    }
    public function count_all_results($t) {
        $this->counts[$t] = ($this->counts[$t] ?? 0) + 1;
        $this->w = array();
        return count($this->tables[$t] ?? array());
    }
    public function insert_id() { return 1; }
    public function affected_rows() { return 1; }
}

class PerfResult {
    private $rows;
    public function __construct(array $r) { $this->rows = $r; }
    public function result() { return $this->rows; }
    public function row() { return $this->rows ? $this->rows[0] : null; }
}
