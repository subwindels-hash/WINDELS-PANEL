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

                // A foreign key wrapped onto two lines —
                //   CONSTRAINT fk_x FOREIGN KEY (col)
                //     REFERENCES parent(id) ON DELETE SET NULL
                // — must be treated as ONE logical line. Splitting first made
                // the continuation parse as a column literally named
                // `REFERENCES`, which deploy-verify.php then reported as a
                // "missing column" on a perfectly healthy database
                // (managed_pages.REFERENCES). Join before splitting so the FK
                // is recorded as a foreign key instead.
                $body = preg_replace('/\R\s*(REFERENCES\b)/i', ' $1', $body);
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
                    $parsed = self::parse_column_line($line);
                    if ($parsed !== null) {
                        $col = $parsed['name'];
                        $table['columns'][$col] = $parsed['def'];
                        if (preg_match('/\bPRIMARY KEY\b/', strtoupper($line))) $table['pk'] = array($col);
                        // MySQL names an inline UNIQUE after the column itself
                        // (`name VARCHAR(64) NOT NULL UNIQUE` → index `name`).
                        if (preg_match('/\bUNIQUE\b/', strtoupper($line))) $table['unique'][$col] = array($col);
                    }
                }
                $tables[$name] = $table;
            }
        }

        /* ---- ALTER TABLE ... ADD CONSTRAINT (foreign keys) ---------------- */
        // Runs over the quote-aware statement scanner (a plain /.+?;/ regex
        // would cut inside a COMMENT string containing a semicolon).
        foreach (self::alter_statements($sql) as $a) {
            list($name, $body) = $a;
            if (!isset($tables[$name])) continue;
            if (preg_match('/ADD CONSTRAINT\s+`?(\w+)`?\s+FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)(.*)$/is', $body, $fk)) {
                $clause = strtoupper($fk[5]);
                $tables[$name]['fks'][$fk[1]] = array(
                    'cols'      => self::col_list($fk[2]),
                    'ref_table' => $fk[3],
                    'ref_cols'  => self::col_list($fk[4]),
                    'on_delete' => preg_match('/ON DELETE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $clause, $r) ? strtoupper($r[1]) : null,
                    'on_update' => preg_match('/ON UPDATE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT)/i', $clause, $r) ? strtoupper($r[1]) : null,
                );
            }
        }

        /* ---- ALTER TABLE ... ADD / MODIFY / DROP / CHANGE COLUMN ---------- */
        // The generated schema applies every later change as ALTER TABLE
        // statements (25+ of them across the migrations). Without folding
        // these in, the manifest only knew the original CREATE TABLE shape
        // and every verifier downstream (verify_database.php,
        // deploy-verify.php) reported each added column as missing — 19
        // phantom failures against a perfectly healthy schema.
        foreach (self::alter_statements($sql) as $a) {
            $name = $a[0];
            if (!isset($tables[$name])) continue;

                foreach (self::alter_clauses($a[1]) as $clause) {
                    $clause = trim($clause);
                    // Table-level clauses (ADD CONSTRAINT is the one that
                    // matters: a naive "ADD <word>" match would parse it as a
                    // column literally named CONSTRAINT).
                    if ($clause === '' || preg_match('/^(?:ADD\s+)?(?:CONSTRAINT|INDEX|KEY|UNIQUE|PRIMARY)\b/i', $clause)) continue;

                    if (preg_match('/^DROP\s+COLUMN\s+`?([a-zA-Z0-9_]+)`?$/i', $clause, $c)) {
                        unset($tables[$name]['columns'][$c[1]]);
                        continue;
                    }
                    if (preg_match('/^(?:CHANGE|CHANGE\s+COLUMN)\s+`?([a-zA-Z0-9_]+)`?\s+`?([a-zA-Z0-9_]+)`?\s+.+$/is', $clause, $c)) {
                        $old = $c[1];
                        $line = preg_replace('/^CHANGE\s+COLUMN\s+`?[a-zA-Z0-9_]+`?\s+/i', 'CHANGE ', $clause);
                        $line = preg_replace('/^CHANGE\s+`?[a-zA-Z0-9_]+`?\s+/i', '', $line);
                        if (isset($tables[$name]['columns'][$old])) {
                            $tables[$name]['columns'][$c[2]] = $tables[$name]['columns'][$old];
                            unset($tables[$name]['columns'][$old]);
                        }
                        $parsed = self::parse_column_line($line);
                        if ($parsed !== null) $tables[$name]['columns'][$parsed['name']] = $parsed['def'];
                        continue;
                    }
                    if (preg_match('/^(?:ADD|ADD\s+COLUMN|MODIFY|MODIFY\s+COLUMN)\s+`?([a-zA-Z0-9_]+)`?\s+.+$/is', $clause, $c)) {
                        $line = preg_replace('/^(ADD|MODIFY)(\s+COLUMN)?\s+/i', '', $clause);
                        $parsed = self::parse_column_line($line);
                        if ($parsed === null) continue;
                        if (stripos($clause, 'MODIFY') !== false && isset($tables[$name]['columns'][$parsed['name']])) {
                            // Type/nullable/default change; keep AUTO_INCREMENT
                            // and PK flags from the original definition.
                            $old = $tables[$name]['columns'][$parsed['name']];
                            foreach (array('auto') as $keep) {
                                if (isset($old[$keep])) $parsed['def'][$keep] = $old[$keep];
                            }
                        }
                        $tables[$name]['columns'][$parsed['name']] = $parsed['def'];
                    }
                }
            }

        /* ---- seed tables ---------------------------------------------------- */
        $seeds = array();
        if (preg_match_all('/INSERT\s+INTO\s+`?(\w+)`?/i', $sql, $m)) {
            foreach (array_unique($m[1]) as $t) $seeds[] = $t;
        }

        return array('tables' => $tables, 'seeds' => $seeds, 'error' => null);
    }

    /**
     * Extract every ALTER TABLE statement from a full SQL dump as
     * array(array($table_name, $statement_body), ...).
     *
     * A statement ends at the next semicolon, but COMMENT strings in the
     * generated schema regularly contain semicolons ("...for this customer
     * on this coupon; unique with (coupon_id,user_id)") — a regex like
     * /.+?;/ cut the statement inside that quote and silently dropped the
     * ADD COLUMN clauses after it. This scanner walks the text tracking
     * quote state (', ", ` with backslash and '' escapes) and parenthesis
     * depth, and only terminates on a semicolon at depth 0 outside quotes.
     *
     * @return array
     */
    private static function alter_statements($sql) {
        $out = array();
        $len = strlen($sql);
        $pos = 0;
        while (($start = stripos($sql, 'ALTER TABLE', $pos)) !== false) {
            $depth = 0;
            $quote = null;
            $end = null;
            for ($i = $start; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($quote !== null) {
                    if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) { $i++; }
                    elseif ($ch === $quote) {
                        if ($quote === "'" && $i + 1 < $len && $sql[$i + 1] === "'") { $i++; }
                        else $quote = null;
                    }
                    continue;
                }
                if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; continue; }
                if ($ch === '(') { $depth++; continue; }
                if ($ch === ')') { $depth = max(0, $depth - 1); continue; }
                if ($ch === ';' && $depth === 0) { $end = $i; break; }
            }
            if ($end === null) break;
            if (preg_match('/^ALTER TABLE\s+`?(\w+)`?\s*(.+);$/is', substr($sql, $start, $end - $start + 1), $a)) {
                $out[] = array($a[1], $a[2]);
            }
            $pos = $end + 1;
        }
        return $out;
    }

    /**
     * Split an ALTER TABLE body into its top-level comma-separated clauses.
     *
     * A clause like
     *   ADD COLUMN rate decimal(20,8) NULL DEFAULT NULL COMMENT 'rate, updated',
     * carries commas inside DECIMAL(...) and inside the COMMENT string, so a
     * naive explode(',') corrupts both. This scanner tracks parenthesis
     * depth and single/double-quote state (with backslash and '' escapes)
     * and only breaks on commas at depth 0 outside quotes.
     *
     * @return array
     */
    private static function alter_clauses($body) {
        $out = array();
        $cur = '';
        $depth = 0;
        $quote = null; // active quote char, or null
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($quote !== null) {
                $cur .= $ch;
                if ($ch === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $cur .= $body[++$i];
                } elseif ($ch === $quote) {
                    if ($quote === "'" && $i + 1 < $len && $body[$i + 1] === "'") {
                        $cur .= $body[++$i]; // '' escape inside single quotes
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
                $cur .= $ch;
                continue;
            }
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth = max(0, $depth - 1);
            elseif ($ch === ',' && $depth === 0) {
                $out[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $out[] = $cur;
        return $out;
    }

    /**
     * Parse one column definition line ("name TYPE [NOT] NULL [DEFAULT x] ...")
     * into name + coldef. Returns null when the line is not a column
     * definition (table-level keys and constraints are handled by the caller).
     *
     * @return array|null array('name'=>string,'def'=>array)
     */
    private static function parse_column_line($line) {
        $line = trim(rtrim(trim($line), ','));
        if (!preg_match('/^`?([a-zA-Z0-9_]+)`?\s+([a-zA-Z]+(?:\s*\([^)]*\))?(?:\s+unsigned)?)(.*)$/is', $line, $c)) {
            return null;
        }
        $col  = $c[1];
        $type = strtolower(preg_replace('/\s+/', ' ', trim($c[2])));
        if (preg_match('/^([a-zA-Z]+\s*(?:\([^)]*\))?)(.*)$/is', trim(substr($line, strlen($col))), $tt)) {
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
            'nullable' => strpos($rest, 'NOT NULL') === false,
            'default'  => null,
            'has_default' => false,
        );
        if (preg_match("/DEFAULT\s+('[^']*'|\"[^\"]*\"|CURRENT_TIMESTAMP|NULL|[-0-9.b()+e]+|b'[01]')/i", $rest, $d)) {
            $coldef['default'] = $d[1];
            $coldef['has_default'] = true;
        }
        if (strpos($rest, 'AUTO_INCREMENT') !== false) $coldef['auto'] = true;
        return array('name' => $col, 'def' => $coldef);
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
