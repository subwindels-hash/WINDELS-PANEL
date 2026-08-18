<?php
/**
 * FakeDb — an in-memory stand-in for CI3's database driver, built from the real
 * migration DDL so seeders and services can be executed (and their SQL surface
 * verified) without a MySQL server.
 *
 * Session 19 widened this from "enough for the seeders" to "enough to run the
 * real services end to end", which is what the integration tests need:
 * comparison operators in where(), affected_rows() for compare-and-set claims,
 * insert_batch(), a query() that understands the SELECT ... FOR UPDATE the
 * ledger uses, and from()/join() so model reads resolve.
 *
 * Joins are modelled as a flat merge of the joined row's columns, prefixed with
 * their alias. That is enough for "does the service name come back" without
 * pretending to be a SQL engine — anything relying on join semantics beyond
 * column projection should be tested against a real database.
 *
 * It supports the query-builder surface the seeders use:
 *   ->where(array|string, value?)->get($table)->row()
 *   ->insert($table, $data) / ->insert_id()
 *   ->where(...)->update($table, $data)
 *   ->table_exists($table) / ->list_tables()
 *   ->trans_start() / ->trans_complete() / ->trans_status()
 *
 * Unknown tables, unknown columns, NOT NULL violations and UNIQUE violations all
 * throw — which is the point: it turns "the seed ran" into "the seed is schema-correct".
 */
class FakeDb
{
    /** @var array<string, array{columns: array<string, array>, unique: array<int, array<int,string>>}> */
    public $schema = array();
    /** @var array<string, array<int, array>> */
    public $rows = array();
    public $queries = array();
    public $raw_updates = array();

    private $pending_where = array();
    private $pending_or_where = array();
    private $pending_groups = array();
    private $current_group = array('and' => array(), 'or' => array());
    private $group_depth = 0;
    private $pending_like = array();
    private $pending_from = null;
    private $pending_joins = array();
    private $pending_select = array();
    private $pending_select_all = false;
    private $pending_aliases = array();
    private $pending_aggregates = array();
    private $pending_group = array();
    private $affected = 0;
    private $pending_order = array();
    private $pending_limit = null;
    private $auto_increment = array();
    private $last_insert_id = 0;
    private $trans_depth = 0;
    private $trans_ok = true;

    public function __construct(array $statements)
    {
        foreach ($statements as $sql) {
            $this->applyDdl($sql);
        }
    }

    /* ----------------------------- schema ----------------------------- */

    private function applyDdl($sql)
    {
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)\s*\((.*)\)\s*ENGINE=/is', $sql, $m)) {
            $table = $m[1];
            $this->schema[$table] = array('columns' => array(), 'unique' => array());
            $this->rows[$table] = array();
            $this->auto_increment[$table] = 1;

            foreach ($this->splitDefinitions($m[2]) as $definition) {
                $definition = trim($definition);
                if ($definition === '') continue;
                $upper = strtoupper($definition);

                if (preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY|INDEX|FULLTEXT|CONSTRAINT|FOREIGN KEY)/', $upper)) {
                    if (preg_match('/^(?:UNIQUE KEY \w+|PRIMARY KEY)\s*\(([^)]*)\)/i', $definition, $u)) {
                        $cols = array_map(function ($c) { return trim($c, " `"); }, explode(',', $u[1]));
                        $this->schema[$table]['unique'][] = $cols;
                    }
                    continue;
                }
                if (!preg_match('/^(\w+)\s+([A-Z]+(?:\([^)]*\))?(?:\s+UNSIGNED)?)/i', $definition, $c)) continue;

                $name = $c[1];
                $this->schema[$table]['columns'][$name] = array(
                    'type'     => strtoupper($c[2]),
                    'nullable' => stripos($definition, 'NOT NULL') === false,
                    'default'  => (stripos($definition, 'DEFAULT') !== false || stripos($definition, 'AUTO_INCREMENT') !== false),
                    'auto'     => stripos($definition, 'AUTO_INCREMENT') !== false,
                );
                if (preg_match('/\bUNIQUE\b/i', $definition) || preg_match('/PRIMARY KEY/i', $definition)) {
                    $this->schema[$table]['unique'][] = array($name);
                }
            }
        }
    }

    private function splitDefinitions($body)
    {
        $parts = array();
        $depth = 0;
        $buffer = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $parts[] = $buffer; $buffer = ''; continue; }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') $parts[] = $buffer;
        return $parts;
    }

    /* -------------------------- query builder -------------------------- */

    public function where($key, $value = null, $escape = null)
    {
        if ($this->group_depth > 0) {
            if (is_array($key)) {
                foreach ($key as $k => $v) $this->current_group['and'][$k] = $v;
            } else {
                $this->current_group['and'][$key] = $value;
            }
            return $this;
        }
        if (is_array($key)) {
            foreach ($key as $k => $v) $this->pending_where[$k] = $v;
        } else {
            $this->pending_where[$key] = $value;
        }
        return $this;
    }

    public function or_where($key, $value = null, $escape = null)
    {
        // Inside group_start()/group_end() the alternatives belong to that
        // group alone, and the group as a whole is AND-ed with everything
        // outside it. Outside a group they are the old flat alternative set.
        if ($this->group_depth > 0) {
            $this->current_group['or'][$key] = $value;
        } else {
            $this->pending_or_where[$key] = $value;
        }
        return $this;
    }

    /**
     * like()/or_like() — the substring search the admin queues use.
     *
     * The codebase only ever writes them as one OR group between
     * group_start()/group_end(), so they are modelled as a single group that
     * is AND-ed with the rest of the predicate. That is exactly the semantics
     * of `WHERE ... AND (a LIKE x OR b LIKE x)`.
     */
    public function like($field, $match = '', $side = 'both', $escape = null)
    {
        $this->pending_like[] = array('col' => (string)$field, 'value' => (string)$match, 'side' => $side);
        return $this;
    }

    public function or_like($field, $match = '', $side = 'both', $escape = null)
    {
        return $this->like($field, $match, $side, $escape);
    }

    public function group_by($field)
    {
        foreach ((array)$field as $one) {
            foreach (explode(',', (string)$one) as $col) {
                $col = trim($col);
                if ($col !== '') $this->pending_group[] = $col;
            }
        }
        return $this;
    }

    /**
     * where_in('col', array(...)) — matched by the sentinel below in matches().
     */
    public function where_in($key, array $values)
    {
        $this->pending_where[$key] = array('__in' => array_map('strval', $values));
        return $this;
    }

    public function get($table = null)
    {
        $table = $table ?: $this->pending_from;
        $joins = $this->pending_joins;
        $select = $this->pending_select;
        $select_all = $this->pending_select_all;
        $aliases = $this->pending_aliases;
        $aggregates = $this->pending_aggregates;
        $group = $this->pending_group;
        $this->pending_from = null; $this->pending_joins = array();
        $this->pending_select = array(); $this->pending_aggregates = array();
        $this->pending_group = array(); $this->pending_select_all = false;
        $this->pending_aliases = array();

        $this->assertTable($table, 'get');
        $where = $this->takeWhere();
        $or    = $this->takeOrWhere();
        $like  = $this->takeLike();
        $groups = $this->takeGroups();
        $this->queries[] = array('op' => 'select', 'table' => $table, 'where' => $where);
        $matched = array();
        foreach ($this->rows[$table] as $row) {
            // A real SELECT * returns every column, nulls included. Returning
            // only the keys that were inserted would let production code that
            // reads a never-written nullable column pass here and warn in
            // production, so fill the shape out first.
            //
            // Joins are applied *before* the predicates, as SQL does. Matching
            // the base row first would make a WHERE on a joined column — a
            // perfectly ordinary query, e.g. "brands with a priced product" —
            // silently match nothing, which is the kind of harness artefact
            // that sends you looking for a bug in the model.
            // A join can fan one base row out into several, as SQL does.
            foreach ($this->applyJoins($this->hydrate($table, $row), $joins) as $full) {
                $full = $this->applyAliases($full, $aliases);
                if (!$this->matches($full, $where, $or, $like, $groups)) continue;
                // Projection is deferred when there are aggregates: SUM(amount)
                // needs `amount` on the row, and the projected shape only carries
                // the alias. Stripping first would sum a column that is no longer
                // there and quietly report zero revenue.
                if ($select && !$select_all && !$aggregates) {
                    $projected = array();
                    foreach ($select as $column) {
                        if (array_key_exists($column, $full)) $projected[$column] = $full[$column];
                    }
                    $full = $projected;
                }
                $matched[] = (object)$full;
            }
        }

        if ($group) {
            $buckets = array();
            foreach ($matched as $rowObj) {
                $key = array();
                foreach ($group as $col) {
                    $bare = strpos($col, '.') !== false ? substr($col, strrpos($col, '.') + 1) : $col;
                    $key[] = isset($rowObj->$bare) ? (string)$rowObj->$bare : '';
                }
                $key = implode("\0", $key);
                if (!isset($buckets[$key])) { $buckets[$key] = array('row' => $rowObj, 'rows' => array()); }
                $buckets[$key]['rows'][] = $rowObj;
            }
            $matched = array();
            foreach ($buckets as $bucket) {
                $out = $bucket['row'];
                foreach ($aggregates as $alias => $spec) {
                    $out->$alias = $this->aggregate($spec, $bucket['rows']);
                }
                // Now that the aggregates are computed, apply the projection
                // that was deferred above.
                $matched[] = ($select && !$select_all)
                    ? (object)$this->project((array)$out, $select) : $out;
            }
        } elseif ($aggregates) {
            // An aggregate with no GROUP BY collapses to exactly one row, and
            // does so even when nothing matched — SELECT COUNT(*) over an
            // empty table returns 0, not no rows. Returning an empty set here
            // would make every caller's ?? fallback hide a real query bug.
            $out = new stdClass();
            foreach ($aggregates as $alias => $spec) {
                $out->$alias = $this->aggregate($spec, $matched);
            }
            $matched = array($out);
        }

        $order = $this->pending_order; $this->pending_order = array();
        $limit = $this->pending_limit; $this->pending_limit = null;
        if ($order) {
            usort($matched, function($a, $b) use ($order) {
                foreach ($order as $spec) {
                    list($col, $dir) = $spec;
                    $av = isset($a->$col) ? $a->$col : null;
                    $bv = isset($b->$col) ? $b->$col : null;
                    $cmp = (is_numeric($av) && is_numeric($bv))
                        ? ($av <=> $bv) : strcmp((string)$av, (string)$bv);
                    if ($cmp !== 0) return $dir === 'DESC' ? -$cmp : $cmp;
                }
                return 0;
            });
        }
        if ($limit) $matched = array_slice($matched, $limit[1], $limit[0]);

        return new FakeDbResult($matched);
    }

    public function insert($table, array $data)
    {
        $this->assertTable($table, 'insert');
        $this->takeWhere();
        $this->takeGroups();

        foreach ($data as $column => $_) {
            if (!isset($this->schema[$table]['columns'][$column])) {
                throw new RuntimeException("FakeDb: unknown column {$table}.{$column}");
            }
        }
        foreach ($this->schema[$table]['columns'] as $column => $meta) {
            if ($meta['nullable'] || $meta['default'] || $meta['auto']) continue;
            if (!array_key_exists($column, $data) || $data[$column] === null) {
                throw new RuntimeException("FakeDb: {$table}.{$column} is NOT NULL but no value was supplied");
            }
        }

        $row = $data;
        if (isset($this->schema[$table]['columns']['id']) && !isset($row['id'])) {
            $row['id'] = $this->auto_increment[$table]++;
        }
        foreach ($this->schema[$table]['unique'] as $cols) {
            $probe = array();
            foreach ($cols as $c) {
                if (!array_key_exists($c, $row) || $row[$c] === null) { $probe = null; break; }
                $probe[$c] = $row[$c];
            }
            if ($probe === null) continue;
            foreach ($this->rows[$table] as $existing) {
                if ($this->matches($existing, $probe)) {
                    throw new RuntimeException("FakeDb: duplicate entry for UNIQUE(".implode(',', $cols).") on {$table}");
                }
            }
        }

        $this->rows[$table][] = $row;
        $this->last_insert_id = isset($row['id']) ? (int)$row['id'] : 0;
        $this->queries[] = array('op' => 'insert', 'table' => $table, 'data' => $data);
        return true;
    }

    public function update($table, array $data)
    {
        $this->assertTable($table, 'update');
        $where = $this->takeWhere();
        $groups = $this->takeGroups();
        foreach ($data as $column => $_) {
            if (!isset($this->schema[$table]['columns'][$column])) {
                throw new RuntimeException("FakeDb: unknown column {$table}.{$column} in update");
            }
        }
        $this->queries[] = array('op' => 'update', 'table' => $table, 'where' => $where, 'data' => $data);
        if ($table === 'wallets' && array_key_exists('balance', $data)) {
            $this->raw_updates[] = 'wallets.balance';
        }
        $this->affected = 0;
        foreach ($this->rows[$table] as $i => $row) {
            if ($this->matches($row, $where, array(), array(), $groups)) {
                $this->rows[$table][$i] = array_merge($row, $data);
                $this->affected++;
            }
        }
        return true;
    }

    /* ---- ordering / limiting: recorded, then applied in get() ---- */

    public function order_by($key, $dir = 'ASC') {
        $this->pending_order[] = array((string)$key, strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC');
        return $this;
    }

    public function limit($limit, $offset = 0) {
        $this->pending_limit = array((int)$limit, (int)$offset);
        return $this;
    }

    /**
     * Records the projection so get() returns only the named columns. A no-op
     * select() would hand every test a full row and hide production code that
     * reads a column its own query never asked for.
     */
    public function select($fields, $escape = null)
    {
        $list = array();
        // Split on top-level commas only. A naive explode(',') cuts
        // COALESCE(SUM(x),0) in half, and the halves then look like two
        // unrecognised plain columns — which is silent, and turns a revenue
        // figure into an undefined property rather than an error.
        foreach ($this->splitSelectFields((string)$fields) as $field) {
            $field = trim(preg_replace('/\s+/', ' ', $field));
            if ($field === '') continue;
            // "*" or "orders.*" selects the whole base row; the joined columns
            // applyJoins() merged in stay visible too, which is what the real
            // "t.*, other.col AS alias" queries return.
            if ($field === '*' || preg_match('/^[\w`]+\.\*$/', $field)) {
                $this->pending_select_all = true;
                continue;
            }
            // COUNT(*) AS alias.
            if (preg_match('/^COUNT\(\s*\*?\s*\)\s+AS\s+(\S+)$/i', $field, $m)) {
                $alias = trim($m[1], '`');
                $this->pending_aggregates[$alias] = array('kind' => 'count');
                $list[] = $alias;
                continue;
            }
            // SUM(col) AS alias, optionally wrapped in COALESCE(...,0), and
            // the conditional form SUM(CASE WHEN <col> <op> <value> THEN <x>
            // ELSE 0 END). Money reporting is built out of exactly these, so a
            // double that could only count would push every revenue figure
            // into "trust the reviewer" territory — which is how a revenue
            // query that silently omits a whole domain survives code review.
            // Anything richer still belongs in a test against a real database.
            if (preg_match('/^(?:COALESCE\(\s*)?SUM\((.+?)\)(?:\s*,\s*0\s*\))?\s+AS\s+(\S+)$/i', $field, $m)) {
                $alias = trim($m[2], '`');
                $spec  = $this->parseSumExpression(trim($m[1]));
                if ($spec !== null) {
                    $this->pending_aggregates[$alias] = $spec;
                    $list[] = $alias;
                    continue;
                }
            }
            // "table.col AS alias" — remember where the alias comes from so
            // get() can materialise it. A real SELECT returns provider_name;
            // this double only has providers.name until it is renamed.
            if (preg_match('/^(\S+)\s+AS\s+(\S+)$/i', $field, $m)) {
                $source = trim($m[1], '`');
                $alias  = trim($m[2], '`');
                $this->pending_aliases[$alias] = $source;
                $list[] = $alias;
                continue;
            }
            $parts = explode('.', $field);
            $list[] = trim(end($parts), '`');
        }
        if ($list) {
            $this->pending_select = array_merge($this->pending_select, $list);
        }
        return $this;
    }

    /* ---- from/join: enough for column projection, not a SQL engine ---- */

    public function from($table)
    {
        // "orders o" / "wallet_transactions wt" — keep the table, drop the alias.
        $this->pending_from = preg_split('~\s+~', trim($table))[0];
        return $this;
    }

    public function join($table, $condition, $type = '')
    {
        $parts = preg_split('~\s+~', trim($table));
        $this->pending_joins[] = array(
            'table' => $parts[0],
            'alias' => isset($parts[1]) ? $parts[1] : $parts[0],
            'on'    => $condition,
            'type'  => strtolower($type),
        );
        return $this;
    }

    /**
     * Apply every join to one base row, returning **all** resulting rows.
     *
     * The ON clause is parsed for the simple `a.col = b.col` form the codebase
     * uses; the joined row's columns are merged in without clobbering the base
     * row, plus alias-prefixed copies so `services.name AS service_name` style
     * projections can be read as either.
     *
     * A join is one-to-many in general, so this fans out rather than picking
     * a winner. It used to keep only the first match, which is right for the
     * common `orders → users` shape and silently wrong for a many-to-many:
     * the RBAC matrix (`permissions → role_permissions → roles`) returned the
     * permissions of whichever role happened to own the first join row and an
     * empty set for every other role. That looks exactly like a broken model,
     * so the harness has to model the fan-out to stay trustworthy.
     */
    private function applyJoins(array $row, array $joins)
    {
        $rows = array($row);

        foreach ($joins as $j) {
            if (!isset($this->rows[$j['table']])) continue;
            if (!preg_match('~([\w.]+)\s*=\s*([\w.]+)~', $j['on'], $m)) continue;

            list($left, $right) = array($m[1], $m[2]);
            $lcol = substr($left, strrpos($left, '.') + 1);
            $rcol = substr($right, strrpos($right, '.') + 1);
            $lq   = strpos($left, $j['alias'].'.') === 0 || strpos($left, $j['table'].'.') === 0;

            // Whichever side names the joined table supplies its key column.
            $joined_col = $lq ? $lcol : $rcol;
            $base_col   = $lq ? $rcol : $lcol;

            $next = array();
            foreach ($rows as $current) {
                if (!array_key_exists($base_col, $current)) { $next[] = $current; continue; }

                $matches = array();
                foreach ($this->rows[$j['table']] as $cand) {
                    if (!array_key_exists($joined_col, $cand)) continue;
                    if ((string)$cand[$joined_col] !== (string)$current[$base_col]) continue;
                    $merged = $current;
                    foreach ($cand as $k => $v) {
                        $merged[$j['alias'].'.'.$k] = $v;
                        if (!array_key_exists($k, $merged)) $merged[$k] = $v;
                    }
                    $matches[] = $merged;
                }

                if ($matches) {
                    foreach ($matches as $mrow) $next[] = $mrow;
                } else {
                    // No match: a LEFT join keeps the base row, and an INNER
                    // join drops it. Everything in this app that relies on the
                    // distinction spells out 'left'.
                    if (strtolower((string)($j['type'] ?? '')) !== 'inner') $next[] = $current;
                }
            }
            $rows = $next;
        }

        return $rows;
    }

    /**
     * Materialise "source AS alias" projections onto the row, so a view that
     * reads $row->provider_name sees what the real query would return.
     */
    private function applyAliases(array $row, array $aliases)
    {
        foreach ($aliases as $alias => $source) {
            if (array_key_exists($source, $row)) {
                $row[$alias] = $row[$source];
                continue;
            }
            $bare = strpos($source, '.') !== false
                ? substr($source, strrpos($source, '.') + 1) : $source;
            $row[$alias] = array_key_exists($bare, $row) ? $row[$bare] : null;
        }
        return $row;
    }

    public function affected_rows() { return $this->affected; }

    public function insert_batch($table, array $rows)
    {
        foreach ($rows as $r) $this->insert($table, $r);
        $this->affected = count($rows);
        return count($rows);
    }

    /**
     * Raw query support, limited to the one shape the application uses:
     * LedgerService's `SELECT * FROM wallets WHERE id=? FOR UPDATE`.
     * Anything else throws rather than silently returning an empty set.
     */
    public function query($sql, $binds = array())
    {
        $this->queries[] = array('op' => 'raw', 'sql' => $sql, 'binds' => $binds);
        if (preg_match('~^\s*SELECT\s+\*\s+FROM\s+(\w+)\s+WHERE\s+(\w+)\s*=\s*\?~i', $sql, $m)) {
            $table = $m[1]; $col = $m[2];
            $this->assertTable($table, 'query');
            $needle = is_array($binds) ? reset($binds) : $binds;
            $out = array();
            foreach ($this->rows[$table] as $row) {
                if (array_key_exists($col, $row) && (string)$row[$col] === (string)$needle) {
                    $out[] = (object)$row;
                }
            }
            return new FakeDbResult($out);
        }
        throw new RuntimeException('FakeDb: unsupported raw query: '.trim($sql));
    }

    /**
     * Real grouping, not a no-op.
     *
     * These used to be ignored, which made `WHERE a = 1 AND (b IS NULL OR
     * b <= now)` behave as `a = 1 OR b IS NULL OR b <= now` — any or_where
     * could rescue a row that had already failed the AND. Announcement_model
     * ::visible() is exactly that shape, so a *hidden* announcement came back
     * as visible and the harness disagreed with production in the one
     * direction that matters.
     *
     * Each group becomes its own clause, AND-ed with everything outside it.
     */
    public function group_start()
    {
        if ($this->group_depth === 0) $this->current_group = array('and' => array(), 'or' => array());
        $this->group_depth++;
        return $this;
    }

    public function group_end()
    {
        if ($this->group_depth === 0) return $this;
        $this->group_depth--;
        if ($this->group_depth === 0 && ($this->current_group['and'] || $this->current_group['or'])) {
            $this->pending_groups[] = $this->current_group;
            $this->current_group = array('and' => array(), 'or' => array());
        }
        return $this;
    }
    public function reset_query() {
        $this->pending_where = array(); $this->pending_or_where = array();
        $this->pending_groups = array(); $this->group_depth = 0;
        $this->current_group = array('and' => array(), 'or' => array());
        $this->pending_like = array();
        $this->pending_order = array(); $this->pending_limit = null;
        $this->pending_from = null; $this->pending_joins = array();
        $this->pending_select = array(); $this->pending_aggregates = array();
        $this->pending_group = array(); $this->pending_select_all = false;
        $this->pending_aliases = array();
        return $this;
    }
    public function delete($table)
    {
        $this->assertTable($table, 'delete');
        $where = $this->takeWhere();
        $groups = $this->takeGroups();
        $kept = array(); $this->affected = 0;
        foreach ($this->rows[$table] as $row) {
            if ($this->matches($row, $where, array(), array(), $groups)) { $this->affected++; continue; }
            $kept[] = $row;
        }
        $this->rows[$table] = $kept;
        return true;
    }

    public function insert_id() { return $this->last_insert_id; }

    public function table_exists($table) { return isset($this->schema[$table]); }

    public function list_tables() { return array_keys($this->schema); }

    public function trans_start() { $this->trans_depth++; }
    public function trans_complete() { $this->trans_depth--; }
    public function trans_rollback() { $this->trans_ok = false; }
    public function trans_status() { return $this->trans_ok; }

    public function count($table) { return isset($this->rows[$table]) ? count($this->rows[$table]) : 0; }

    public function all($table) { return isset($this->rows[$table]) ? $this->rows[$table] : array(); }

    /* ------------------------------ util ------------------------------ */

    /** Every column the table declares, missing ones as null. */
    private function hydrate($table, array $row)
    {
        $full = array();
        foreach ($this->schema[$table]['columns'] as $column => $_) {
            $full[$column] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        // Keep anything extra (e.g. alias-prefixed join columns).
        return array_merge($full, $row);
    }

    private function takeWhere()
    {
        $w = $this->pending_where;
        $this->pending_where = array();
        return $w;
    }

    private function takeOrWhere()
    {
        $w = $this->pending_or_where;
        $this->pending_or_where = array();
        return $w;
    }

    /** Consume the pending parenthesised groups for one query. */
    private function takeGroups()
    {
        $g = $this->pending_groups;
        $this->pending_groups = array();
        $this->current_group  = array('and' => array(), 'or' => array());
        $this->group_depth    = 0;
        return $g;
    }

    private function takeLike()
    {
        $l = $this->pending_like;
        $this->pending_like = array();
        return $l;
    }

    public function count_all_results($table = null)
    {
        $table = $table ?: $this->pending_from;
        $this->pending_from = null; $this->pending_joins = array();
        $this->pending_select = array(); $this->pending_aggregates = array();
        $this->pending_group = array(); $this->pending_select_all = false;
        $this->pending_aliases = array();
        $this->assertTable($table, 'count_all_results');
        $where = $this->takeWhere();
        $this->pending_order = array(); $this->pending_limit = null;
        $or = $this->takeOrWhere();
        $like = $this->takeLike();
        $groups = $this->takeGroups();
        $n = 0;
        foreach ($this->rows[$table] as $row) if ($this->matches($row, $where, $or, $like, $groups)) $n++;
        return $n;
    }

    private function matches(array $row, array $where, array $or_where = array(), array $like = array(),
                             array $groups = array())
    {
        // The LIKE group is AND-ed with everything else, matching how the
        // codebase writes it: ... AND (a LIKE t OR b LIKE t).
        if ($like && !$this->matchesLike($row, $like)) return false;

        // Each parenthesised group is its own clause, AND-ed with the rest.
        foreach ($groups as $group) {
            $ok = true;
            foreach ($group['and'] as $k => $v) {
                if (!$this->matchesOne($row, $k, $v)) { $ok = false; break; }
            }
            if (!$ok) {
                foreach ($group['or'] as $k => $v) {
                    if ($this->matchesOne($row, $k, $v)) { $ok = true; break; }
                }
            }
            if (!$ok) return false;
        }

        foreach ($where as $k => $v) {
            if (!$this->matchesOne($row, $k, $v)) {
                // An or_where group can still rescue the row.
                foreach ($or_where as $ok => $ov) {
                    if ($this->matchesOne($row, $ok, $ov)) return true;
                }
                return false;
            }
        }
        if ($where === array() && $or_where !== array()) {
            foreach ($or_where as $ok => $ov) {
                if ($this->matchesOne($row, $ok, $ov)) return true;
            }
            return false;
        }
        return true;
    }

    /** True when any column in the LIKE group contains the term. */
    private function matchesLike(array $row, array $like)
    {
        foreach ($like as $spec) {
            $col = $spec['col'];
            if (strpos($col, '.') !== false) $col = substr($col, strrpos($col, '.') + 1);
            if (!array_key_exists($col, $row) || $row[$col] === null) continue;

            $hay = (string)$row[$col];
            $needle = $spec['value'];
            if ($needle === '') return true;
            if ($spec['side'] === 'before') {
                if (substr($hay, -strlen($needle)) === $needle) return true;
            } elseif ($spec['side'] === 'after') {
                if (strncmp($hay, $needle, strlen($needle)) === 0) return true;
            } elseif (stripos($hay, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * One predicate. CI3 puts the operator in the key ("created_at <=") and
     * bare SQL fragments in the key too ("provider_order_id IS NOT NULL").
     */
    private function matchesOne(array $row, $key, $value)
    {
        $key = trim($key);

        // Bare SQL fragments used with escaping disabled. The column may be
        // qualified ("giftcard_products.price IS NOT NULL"), which it has to
        // be as soon as the query joins — an unqualified name would be
        // ambiguous in real SQL, so refusing to parse it here would push the
        // application into writing queries MySQL rejects.
        if (preg_match('~^([\w.]+)\s+IS\s+NOT\s+NULL$~i', $key, $m)) {
            $col = $this->bareColumn($m[1]);
            return array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '';
        }
        if (preg_match('~^([\w.]+)\s+IS\s+NULL$~i', $key, $m)) {
            $col = $this->bareColumn($m[1]);
            return !array_key_exists($col, $row) || $row[$col] === null;
        }
        // Anything else with parentheses/OR is a raw fragment this double
        // cannot evaluate; treat it as satisfied rather than silently
        // dropping every row.
        if (strpbrk($key, '()') !== false) return true;

        $op = '=';
        if (preg_match('~^(.+?)\s*(<=|>=|!=|<>|<|>)$~', $key, $m)) {
            $key = trim($m[1]);
            $op  = $m[2];
        }
        // Qualified column ("orders.status") — compare on the bare name.
        if (strpos($key, '.') !== false) {
            $key = substr($key, strrpos($key, '.') + 1);
        }
        if (!array_key_exists($key, $row)) return false;

        if (is_array($value) && isset($value['__in'])) {
            return in_array((string)$row[$key], $value['__in'], true);
        }

        $a = $row[$key];
        $b = $value;
        if ($op === '=')  return (string)$a === (string)$b;
        if ($op === '!=' || $op === '<>') return (string)$a !== (string)$b;

        $cmp = (is_numeric($a) && is_numeric($b)) ? ($a <=> $b) : strcmp((string)$a, (string)$b);
        if ($op === '<')  return $cmp < 0;
        if ($op === '<=') return $cmp <= 0;
        if ($op === '>')  return $cmp > 0;
        if ($op === '>=') return $cmp >= 0;
        return true;
    }

    /** Keep only the named columns, as a real projection would. */
    private function project(array $row, array $select)
    {
        $out = array();
        foreach ($select as $column) {
            if (array_key_exists($column, $row)) $out[$column] = $row[$column];
        }
        return $out;
    }

    /** Split a select list on commas that are not inside parentheses or quotes. */
    private function splitSelectFields($fields)
    {
        $out = array();
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($fields);
        for ($i = 0; $i < $len; $i++) {
            $ch = $fields[$i];
            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote) $quote = null;
                continue;
            }
            if ($ch === "'" || $ch === '"') { $quote = $ch; $buf .= $ch; continue; }
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }

    /**
     * The inside of a SUM(), reduced to something evaluable per row.
     *
     * Two shapes, both of which the reporting code actually writes:
     *   SUM(charge)
     *   SUM(CASE WHEN status = 'COMPLETED' THEN charge ELSE 0 END)
     *
     * Returns NULL for anything else, so an unsupported expression falls
     * through to being treated as a plain column rather than quietly summing
     * to zero — a zero here would look exactly like "no revenue".
     */
    private function parseSumExpression($expr)
    {
        if (preg_match('/^CASE\s+WHEN\s+(.+?)\s+THEN\s+(\S+)\s+ELSE\s+(\S+)\s+END$/is', $expr, $m)) {
            $cond = $this->parseSumCondition(trim($m[1]));
            if ($cond === null) return null;
            return array(
                'kind' => 'sum_case',
                'cond' => $cond,
                'then' => trim($m[2], "'` "),
                'else' => trim($m[3], "'` "),
            );
        }
        if (preg_match('/^[\w.`]+$/', $expr)) {
            return array('kind' => 'sum', 'column' => $this->bareColumn(trim($expr, '`')));
        }
        return null;
    }

    /**
     * `status = 'X'`, `status IN (...)`, `col >= 'v'` or `col IS [NOT] NULL`
     * inside a CASE.
     *
     * The NULL forms matter more than they look: "how many of these sales
     * recorded a vendor cost?" is what stops a margin computed from three rows
     * out of four hundred being presented as though it covered all of them.
     */
    private function parseSumCondition($cond)
    {
        if (preg_match('/^([\w.`]+)\s+IS\s+NOT\s+NULL$/i', $cond, $m)) {
            return array('column' => $this->bareColumn(trim($m[1], '`')), 'op' => 'NOT NULL');
        }
        if (preg_match('/^([\w.`]+)\s+IS\s+NULL$/i', $cond, $m)) {
            return array('column' => $this->bareColumn(trim($m[1], '`')), 'op' => 'NULL');
        }
        if (preg_match('/^([\w.`]+)\s+IN\s*\((.+)\)$/is', $cond, $m)) {
            $values = array();
            foreach (explode(',', $m[2]) as $v) $values[] = trim($v, " '\"`");
            return array('column' => $this->bareColumn(trim($m[1], '`')),
                         'op' => 'IN', 'values' => $values);
        }
        if (preg_match('/^([\w.`]+)\s*(=|!=|<>|<=|>=|<|>)\s*(.+)$/s', $cond, $m)) {
            return array('column' => $this->bareColumn(trim($m[1], '`')),
                         'op' => $m[2], 'value' => trim($m[3], " '\"`"));
        }
        return null;
    }

    /** One aggregate over a bucket of already-matched rows. */
    private function aggregate(array $spec, array $rows)
    {
        if ($spec['kind'] === 'count') return count($rows);

        $total = '0';
        foreach ($rows as $rowObj) {
            $row = (array)$rowObj;
            if ($spec['kind'] === 'sum') {
                $v = $row[$spec['column']] ?? 0;
                $total = bcadd($total, is_numeric($v) ? (string)$v : '0', 8);
                continue;
            }
            // sum_case
            $c   = $spec['cond'];
            $lhs = $row[$c['column']] ?? null;
            $hit = false;
            if ($c['op'] === 'NOT NULL') {
                $hit = $lhs !== null && $lhs !== '';
            } elseif ($c['op'] === 'NULL') {
                $hit = $lhs === null || $lhs === '';
            } elseif ($c['op'] === 'IN') {
                $hit = in_array((string)$lhs, $c['values'], true);
            } else {
                $cmp = (is_numeric($lhs) && is_numeric($c['value']))
                    ? ($lhs <=> $c['value']) : strcmp((string)$lhs, (string)$c['value']);
                switch ($c['op']) {
                    case '=':  $hit = $cmp === 0; break;
                    case '!=':
                    case '<>': $hit = $cmp !== 0; break;
                    case '<':  $hit = $cmp < 0;   break;
                    case '<=': $hit = $cmp <= 0;  break;
                    case '>':  $hit = $cmp > 0;   break;
                    case '>=': $hit = $cmp >= 0;  break;
                }
            }
            $branch = $hit ? $spec['then'] : $spec['else'];
            // The branch is either a literal (1, 0) or a column name.
            $v = is_numeric($branch) ? $branch
                : (isset($row[$branch]) && is_numeric($row[$branch]) ? $row[$branch] : 0);
            $total = bcadd($total, (string)$v, 8);
        }
        // Integer-valued sums read back as integers, as MySQL returns them for
        // COUNT-shaped CASE sums; money keeps its 8dp string form.
        return (strpos($total, '.') !== false && rtrim(substr($total, strpos($total, '.') + 1), '0') === '')
            ? (string)(int)$total : $total;
    }

    /** "giftcard_products.price" → "price"; joins merge columns flat. */
    private function bareColumn($name)
    {
        return strpos($name, '.') !== false
            ? substr($name, strrpos($name, '.') + 1) : $name;
    }

    private function assertTable($table, $op)
    {
        if (!isset($this->schema[$table])) {
            throw new RuntimeException("FakeDb: {$op} on unknown table '{$table}' — is it missing from the migrations?");
        }
    }
}

class FakeDbResult
{
    private $rows;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function row() { return count($this->rows) ? $this->rows[0] : null; }
    public function result() { return $this->rows; }
    public function num_rows() { return count($this->rows); }
}

/** Minimal CI loader stub: the seeders only use load->library(). */
class FakeLoader
{
    private $ci;
    public function __construct($ci) { $this->ci = $ci; }
    public function library($name, $params = null, $object_name = null)
    {
        $file = dirname(dirname(__DIR__)).'/application/libraries/'.$name.'.php';
        if (file_exists($file)) require_once $file;
        $prop = strtolower($object_name ?: $name);
        if (class_exists($name) && !isset($this->ci->$prop)) {
            $this->ci->$prop = new $name();
        }
        return $this;
    }
    public function model($name) { return $this; }
    public function database() { return $this; }
    public function dbforge() { return $this; }
}

/** Minimal CI super-object exposing ->db and ->load. */
#[AllowDynamicProperties]
class FakeCI
{
    public $db;
    public $load;
    public function __construct(FakeDb $db)
    {
        $this->db = $db;
        $this->load = new FakeLoader($this);
    }
}
