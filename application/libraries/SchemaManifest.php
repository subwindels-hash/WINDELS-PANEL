<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SchemaManifest — parse database/marvysocials.sql into a machine-readable
 * manifest every verifier can share.
 *
 * The SQL file is machine-generated (tools/build_production_sql.php), so its
 * format is stable:
 *   CREATE TABLE IF NOT EXISTS name (
 *     col TYPE [NOT] NULL [DEFAULT x] [AUTO_INCREMENT] [PRIMARY KEY] [UNIQUE],
 *     PRIMARY KEY (cols) / UNIQUE KEY n (cols) / INDEX n (cols) / KEY n (cols),
 *   ) ENGINE=InnoDB ...;
 *   ALTER TABLE name ADD CONSTRAINT fk_x FOREIGN KEY (cols) REFERENCES t(cols) ...;
 *   INSERT INTO `t` (`a`, `b`) VALUES (...), (...);
 *
 * Returns, per table: columns (type/nullable/default), primary key, unique
 * keys, secondary indexes, foreign keys, engine/charset, and the list of
 * tables that carry seed rows. Pure string parsing — no database required.
 */
class SchemaManifest {

    /**
     * @return array{tables:array, seeds:array, error:?string}
     */
    public static function from_file($path) {
        if (!is_file($path)) {
            return array('tables' => array(), 'seeds' => array(),
                'error' => 'schema file not found: ' . $path);
        }
        $sql = file_get_contents($path);
        if ($sql === false || strlen($sql) < 100) {
            return array('tables' => array(), 'seeds' => array(), 'error' => 'schema file unreadable: ' . $path);
        }
        return self::from_sql($sql);
    }

    public static function from_sql($sql) {
        $tables = array();

        /* ---- CREATE TABLE blocks ----------------------------------------- */
        if (preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*ENGINE\s*=\s*(\w+)([^;]*);/si',
            $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $t) {
                $name = $t[1];
                $body = $t[2];
                $table = array(
                    'engine'  => strtolower($t[3]),
                    'columns' => array(),
                    'pk'      => array(),
                    'unique'  => array(),
                    'indexes' => array(),
                    'fks'     => array(),
                );

                foreach (preg_split('/\R/', $body) as $line) {
                    $line = trim(rtrim($line, ','));
                    if ($line === '') continue;

                    // table-level keys
                    if (preg_match('/^PRIMARY KEY\s*\(([^)]+)\)/i', $line, $k)) {
                        $table['pk'] = self::col_list($k[1]);
                        continue;
                    }
                    if (preg_match('/^UNIQUE(?:\s+KEY|\s+INDEX)?\s+(\w+)\s*\(([^)]+)\)/i', $line, $k)) {
                        $table['unique'][$k[1]] = self::col_list($k[2]);
                        continue;
                    }
                    if (preg_match('/^(?:KEY|INDEX)\s+(\w+)\s*\(([^)]+)\)/i', $line, $k)) {
                        $table['indexes'][$k[1]] = self::col_list($k[2]);
                        continue;
                    }
                    if (preg_match('/^FULLTEXT(?:\s+KEY|\s+INDEX)?\s+(\w+)\s*\(([^)]+)\)/i', $line, $k)) {
                        $table['indexes'][$k[1]] = self::col_list($k[2]);
                        continue;
                    }

                    // Inline FKs: CONSTRAINT name FOREIGN KEY (...) REFERENCES t(...)
                    if (preg_match('/^(?:CONSTRAINT\s+`?(\w+)`?\s+)?FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)(.*)$/i', $line, $k)) {
                        $fkname = $k[1] !== '' ? $k[1] : ('fk_' . $name . '_' . implode('_', self::col_list($k[2])));
                        $clause = strtoupper($k[5]);
                        $table['fks'][$fkname] = array(
                            'cols'      => self::col_list($k[2]),
                            'ref_table' => $k[3],
                            'ref_cols'  => self::col_list($k[4]),
                            'on_delete' => preg_match('/ON DELETE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $clause, $r) ? strtoupper($r[1]) : null,
                            'on_update' => preg_match('/ON UPDATE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $clause, $r) ? strtoupper($r[1]) : null,
                        );
                        continue;
                    }

                    // CHECK constraints (and leftover CONSTRAINT lines) are not
                    // columns. Treating "CONSTRAINT" as a column is what made
                    // deploy-verify.php report dozens of "missing columns".
                    if (preg_match('/^CONSTRAINT\b/i', $line) || preg_match('/^CHECK\s*\(/i', $line)) {
                        continue;
                    }

                    // column definition
                    if (preg_match('/^`?([a-zA-Z0-9_]+)`?\s+([a-zA-Z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?)(.*)$/i', $line, $c)) {
                        $col  = $c[1];
                        $type = strtolower(preg_replace('/\s+/', ' ', trim($c[3 - 1])));
                        // fix: type may include size, e.g. varchar(64) / decimal(20,8) / enum(...)
                        if (preg_match('/^([a-zA-Z]+\s*(?:\([^)]*\))?)(.*)$/is', trim(substr($line, strlen($c[1]))), $tt)) {
                            $type = strtolower(preg_replace('/\s+/', ' ', trim($tt[1])));
                            $rest = strtoupper(trim($tt[2]));
                        } else {
                            $rest = strtoupper($c[3]);
                        }
                        if (strpos($rest, 'UNSIGNED') !== false && strpos($type, 'unsigned') === false
                            && preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $type)) {
                            $type .= ' unsigned';
                        }
                        $coldef = array(
                            'type'     => $type,
                            'nullable' => !(strpos($rest, 'NOT NULL') !== false),
                            'default'  => null,
                            'has_default' => false,
                        );
                        if (preg_match('/DEFAULT\s+(\'[^\']*\'|"[^"]*"|CURRENT_TIMESTAMP|NULL|[-0-9.b()+e]+|b\'[01]\')/i', $rest, $d)) {
                            $coldef['default'] = $d[1];
                            $coldef['has_default'] = true;
                        }
                        if (strpos($rest, 'AUTO_INCREMENT') !== false) $coldef['auto'] = true;
                        if (strpos($rest, 'PRIMARY KEY') !== false) $table['pk'] = array($col);
                        // MySQL names an inline UNIQUE after the column itself
                        // (`name VARCHAR(64) NOT NULL UNIQUE` → index `name`).
                        if (preg_match('/\bUNIQUE\b/', $rest)) $table['unique'][$col] = array($col);
                        $table['columns'][$col] = $coldef;
                    }
                }
                $tables[$name] = $table;
            }
        }

        /* ---- ALTER TABLE ... ADD CONSTRAINT (foreign keys) ---------------- */
        if (preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+ADD CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)([^;]*);/si',
            $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $fk) {
                if (!isset($tables[$fk[1]])) continue;
                $clause = strtoupper($fk[6]);
                $tables[$fk[1]]['fks'][$fk[2]] = array(
                    'cols'      => self::col_list($fk[3]),
                    'ref_table' => $fk[4],
                    'ref_cols'  => self::col_list($fk[5]),
                    'on_delete' => preg_match('/ON DELETE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $clause, $r) ? strtoupper($r[1]) : null,
                    'on_update' => preg_match('/ON UPDATE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $clause, $r) ? strtoupper($r[1]) : null,
                );
            }
        }

        /* ---- seed tables ---------------------------------------------------- */
        $seeds = array();
        if (preg_match_all('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $m)) {
            foreach (array_unique($m[1]) as $t) $seeds[] = $t;
        }

        return array('tables' => $tables, 'seeds' => $seeds, 'error' => null);
    }

    /** "a, b, c" (optionally backticked) → array('a','b','c'); strips length hints like (10). */
    private static function col_list($raw) {
        $out = array();
        foreach (explode(',', $raw) as $c) {
            $c = trim($c);
            $c = preg_replace('/\(\d+\)$/', '', $c);
            $c = trim($c, " \t`");
            if ($c !== '') $out[] = $c;
        }
        return $out;
    }

    /** Base type without size: "decimal(20,8)" → "decimal", "int unsigned" → "int unsigned". */
    public static function base_type($type) {
        $type = strtolower($type);
        $type = preg_replace('/\s*\(.*\)/', '', $type);
        return trim(preg_replace('/\s+/', ' ', $type));
    }

    /**
     * True when two column types are the same family across MySQL and MariaDB.
     *
     * MariaDB implements the JSON type as LONGTEXT (information_schema
     * COLUMN_TYPE is `longtext`, often with a CHECK json_valid() constraint).
     * Treating that as a mismatch made every cPanel/MariaDB install fail
     * deploy-verify.php with dozens of "type mismatch" rows even though the
     * schema was correct. TIMESTAMP and DATETIME are likewise aliases.
     */
    public static function types_compatible($want, $have) {
        $want = self::base_type($want);
        $have = self::base_type($have);
        if ($want === 'timestamp') $want = 'datetime';
        if ($have === 'timestamp') $have = 'datetime';
        if ($want === $have) return true;
        $json = array('json' => true, 'longtext' => true);
        if (isset($json[$want]) && isset($json[$have])) return true;
        return false;
    }

    /** enum('A','B') → array('A','B'); NULL for non-enums. */
    public static function enum_values($type) {
        if (!preg_match('/^(enum|set)\s*\((.*)\)/is', $type, $m)) return null;
        preg_match_all('/\'((?:[^\']|\'\')*)\'/', $m[2], $vals);
        return array_map(function ($v) { return str_replace("''", "'", $v); }, $vals[1]);
    }
}
