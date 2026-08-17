<?php
/**
 * FakeDb — an in-memory stand-in for CI3's database driver, built from the real
 * migration DDL so seeders can be executed (and their SQL surface verified)
 * without a MySQL server.
 *
 * It supports exactly the query-builder surface the seeders use:
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

    public function where($key, $value = null)
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) $this->pending_where[$k] = $v;
        } else {
            $this->pending_where[$key] = $value;
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

    public function get($table)
    {
        $this->assertTable($table, 'get');
        $where = $this->takeWhere();
        $this->queries[] = array('op' => 'select', 'table' => $table, 'where' => $where);
        $matched = array();
        foreach ($this->rows[$table] as $row) {
            if ($this->matches($row, $where)) $matched[] = (object)$row;
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
        foreach ($data as $column => $_) {
            if (!isset($this->schema[$table]['columns'][$column])) {
                throw new RuntimeException("FakeDb: unknown column {$table}.{$column} in update");
            }
        }
        $this->queries[] = array('op' => 'update', 'table' => $table, 'where' => $where, 'data' => $data);
        if ($table === 'wallets' && array_key_exists('balance', $data)) {
            $this->raw_updates[] = 'wallets.balance';
        }
        foreach ($this->rows[$table] as $i => $row) {
            if ($this->matches($row, $where)) {
                $this->rows[$table][$i] = array_merge($row, $data);
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

    public function select($fields, $escape = null) { return $this; }

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

    private function takeWhere()
    {
        $w = $this->pending_where;
        $this->pending_where = array();
        return $w;
    }

    public function count_all_results($table)
    {
        $this->assertTable($table, 'count_all_results');
        $where = $this->takeWhere();
        $this->pending_order = array(); $this->pending_limit = null;
        $n = 0;
        foreach ($this->rows[$table] as $row) if ($this->matches($row, $where)) $n++;
        return $n;
    }

    private function matches(array $row, array $where)
    {
        foreach ($where as $k => $v) {
            if (!array_key_exists($k, $row)) return false;
            if (is_array($v) && isset($v['__in'])) {
                if (!in_array((string)$row[$k], $v['__in'], true)) return false;
                continue;
            }
            if ((string)$row[$k] !== (string)$v) return false;
        }
        return true;
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
