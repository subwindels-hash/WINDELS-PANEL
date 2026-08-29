<?php
/**
 * verify_database.php — audit the codebase against database/marvysocials.sql.
 *
 * Guarantees the flood-fill question of every deployment — "does the shipped
 * database actually match the code?" — has a mechanical answer:
 *
 *   · every table the code touches exists in the SQL         (missing tables)
 *   · every column bound to a known table exists on it       (missing columns)
 *   · string literals assigned to ENUM/SET columns belong     (bad enum values)
 *   · every foreign key points at a real table + real columns (broken FKs)
 *   · every index/unique/primary key references real columns  (broken indexes)
 *   · every model's `protected $table` exists                 (orphan models)
 *   · every schema table is either referenced or seeded       (stale tables, info)
 *
 * Static analysis only — no MySQL, no composer, PHP 7.4+:
 *
 *   php tools/verify_database.php            # full report
 *   php tools/verify_database.php --quiet    # failures only
 *   echo $?                                  # 0 = clean, 1 = mismatches
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('BASEPATH')) define('BASEPATH', dirname(__DIR__) . '/system/');  // standalone CLI
$ROOT = dirname(__DIR__);
$SQL  = $ROOT . '/database/marvysocials.sql';
$QUIET = in_array('--quiet', $argv, true);

require_once $ROOT . '/application/libraries/SchemaManifest.php';

/* ===========================================================================
 * 1. Manifest
 * ========================================================================= */
$manifest = SchemaManifest::from_file($SQL);
if ($manifest['error']) { fwrite(STDERR, "FAIL  {$manifest['error']}\n"); exit(1); }
$TABLES = $manifest['tables'];
$SEEDED = $manifest['seeds'];

$errors = array();   // array(section, where, message)
$infos  = array();
function err($s, $w, $m)  { global $errors; $errors[] = array($s, $w, $m); }
function info($s, $w, $m) { global $infos;  $infos[]  = array($s, $w, $m); }

/* ===========================================================================
 * 2. Schema internal consistency (broken FKs / indexes / PK)
 * ========================================================================= */
foreach ($TABLES as $tname => $t) {
    foreach ($t['pk'] as $c) {
        if (!isset($t['columns'][$c])) err('index', $tname, "PRIMARY KEY references missing column {$c}");
    }
    foreach ($t['unique'] as $in => $cols) foreach ($cols as $c) {
        if (!isset($t['columns'][$c])) err('index', $tname, "UNIQUE KEY {$in} references missing column {$c}");
    }
    foreach ($t['indexes'] as $in => $cols) foreach ($cols as $c) {
        if (!isset($t['columns'][$c])) err('index', $tname, "INDEX {$in} references missing column {$c}");
    }
    foreach ($t['fks'] as $fkname => $fk) {
        if (!isset($TABLES[$fk['ref_table']])) {
            err('fk', $tname, "FK {$fkname} references missing table {$fk['ref_table']}");
            continue;
        }
        foreach ($fk['cols'] as $c) {
            if (!isset($t['columns'][$c])) err('fk', $tname, "FK {$fkname} uses missing local column {$c}");
        }
        foreach ($fk['ref_cols'] as $c) {
            if (!isset($TABLES[$fk['ref_table']]['columns'][$c]))
                err('fk', $tname, "FK {$fkname} references missing column {$fk['ref_table']}.{$c}");
        }
    }
}

/* ===========================================================================
 * 3. Scan the application code
 * ========================================================================= */
$php_files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/application'));
$files = array($ROOT . '/index.php', $ROOT . '/deploy-verify.php');
foreach ($php_files as $f) {
    if ($f->isFile() && substr($f->getFilename(), -4) === '.php'
        && strpos($f->getPathname(), DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR) === false) {
        $files[] = $f->getPathname();
    }
}

$TABLE_CALL  = '/(?:\$this->ci->db|\$this->db|\$db)->(?:get|get_where|from|join|insert|insert_batch|update|update_batch|replace|delete|table|truncate|empty_table|count_all|count_all_results)\(\s*[\'"]([a-z_][a-z0-9_]*(?:\s+[a-z]+)?)[\'"]/i';
$MODEL_TABLE = '/protected\s+\$table\s*=\s*[\'"]([a-z_][a-z0-9_]*)[\'"]/';
$COL_CALL    = '/(?:\$this->ci->db|\$this->db|\$db)->(?:set|where|or_where|where_in|or_where_in|where_not_in|or_where_not_in|like|or_like|not_like|order_by|group_by|having|or_having|select|select_max|select_min|select_avg|select_sum)\(\s*[\'"]([^\'"]+)[\'"]/i';
$RAW_SQL     = '/(?:\$this->ci->db|\$this->db|\$db)->query\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s';

$referenced_tables = array();
$bound_columns = array();   // table => col => where(file:line)
$enum_checks   = array();   // table => col => array(values)

function normalize_key($raw) {
    $c = trim($raw, " \t`'\"");
    // strip trailing operators: col >= / col IS NULL / col LIKE ...
    $c = preg_replace('/\s+(>=|<=|<>|!=|>|<|=|LIKE|NOT LIKE|IN|NOT IN|BETWEEN|IS NULL|IS NOT NULL|IS)\s*$/i', '', $c);
    if (strpos($c, ' ') !== false) {                       // "col alias" / two words → not a bare column
        $parts = preg_split('/\s+/', $c);
        if (count($parts) === 2 && strtoupper($parts[0]) === 'CAST') return null;
        return null;
    }
    if ($c === '' || strpos($c, '(') !== false || strpos($c, ')') !== false || strpos($c, '*') !== false
        || strpos($c, '$') !== false || strpos($c, '.') === 0) return null;
    if (is_numeric($c)) return null;
    return $c;
}

foreach ($files as $file) {
    $code = @file_get_contents($file);
    if ($code === false) continue;
    $rel  = str_replace($ROOT . DIRECTORY_SEPARATOR, '', $file);

    // this file's own model table (one class per file in this codebase)
    $model_table = null;
    if (preg_match($MODEL_TABLE, $code, $mm)) $model_table = $mm[1];

    // ---- table references ---------------------------------------------------
    if (preg_match_all($TABLE_CALL, $code, $tm, PREG_SET_ORDER)) {
        foreach ($tm as $t) {
            $first = strtolower(strtok($t[1], ' '));
            $referenced_tables[$first][$rel] = true;
        }
    }
    if ($model_table) $referenced_tables[$model_table][$rel] = true;

    // $this->table / $table usages inside a model resolve to its declaration
    if ($model_table && strpos($code, '->get($this->table') === false
        && strpos($code, '$this->table') === false) {
        // model with no query usage of its own table — nothing to bind
    }

    // ---- raw SQL: table tokens ------------------------------------------------
    if (preg_match_all($RAW_SQL, $code, $qm, PREG_SET_ORDER)) {
        foreach ($qm as $q) {
            if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z_][a-z0-9_]*)`?/i', $q[2], $rt)) {
                foreach ($rt[1] as $t) $referenced_tables[strtolower($t)][$rel . ' (raw SQL)'] = true;
            }
        }
    }

    // ---- statement windows for column binding ---------------------------------
    foreach (explode(';', $code) as $chunk) {
        if (strpos($chunk, '$this->db') === false && strpos($chunk, '->db->') === false
            && strpos($chunk, 'query(') === false && strpos($chunk, 'insert_once(') === false
            && strpos($chunk, 'update_once(') === false) continue;
        if (strpos($chunk, '->query(') !== false) continue;  // raw strings: tables already handled

        // alias map for THIS statement: from/join with aliases
        $aliases = array();
        if (preg_match_all('/->(?:from|join)\(\s*[\'"]([a-z_][a-z0-9_]*)\s+([a-z][a-z0-9_]*)[\'"]/i', $chunk, $am, PREG_SET_ORDER))
            foreach ($am as $a) $aliases[strtolower($a[2])] = strtolower($a[1]);

        // target tables: literal, or $this->table in a model file
        $targets = array();
        if (preg_match_all('/->(?:insert|insert_batch|update|update_batch|replace|get|get_where|delete|count_all_results|count_all)\(\s*[\'"]([a-z_][a-z0-9_]*)[\'"]/i', $chunk, $xm))
            foreach ($xm[1] as $x) $targets[strtolower($x)] = true;
        if ($model_table && preg_match('/->\w+\(\s*\$this->table\s*[,)]/', $chunk))
            $targets[$model_table] = true;
        if (preg_match('/(?:insert_once|update_once)\(\s*[\'"]([a-z_][a-z0-9_]*)[\'"]/', $chunk, $sm))
            $targets[strtolower($sm[1])] = true;

        foreach (array_keys($targets) as $__t) {
            if ($__t !== '' && $__t !== '*' && strpos($__t, '$') === false)
                $referenced_tables[$__t][$rel] = true;
        }
        if (count($targets) !== 1) continue;
        $T = key($targets);

        // builder key calls bound to T
        if (preg_match_all($COL_CALL, $chunk, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $key = normalize_key(strtok($c[1], ',') === $c[1] ? $c[1] : $c[1]);
                if ($c[1] === null) continue;
                // select('a, b AS c') → split; where('k','v') stays one token
                $cands = array($c[1]);
                if (strpos($c[1], ',') !== false && stripos($chunk, 'select(') !== false) {
                    // split on commas that are not inside parentheses —
                    // COALESCE(SUM(balance),0) must not spawn a fake column
                    $cands = array(); $buf = ''; $depth = 0;
                    foreach (str_split($c[1]) as $chr) {
                        if ($chr === '(') $depth++;
                        elseif ($chr === ')') $depth = max(0, $depth - 1);
                        if ($chr === ',' && $depth === 0) { $cands[] = $buf; $buf = ''; continue; }
                        $buf .= $chr;
                    }
                    $cands[] = $buf;
                }
                foreach ($cands as $cand) {
                    $cand = preg_replace('/\s+AS\s+\w+\s*$/i', '', trim($cand));
                    $key  = normalize_key($cand);
                    if ($key === null) continue;
                    if (strpos($key, '.') !== false) {
                        list($qual, $col) = explode('.', $key, 2);
                        $q = strtolower($qual);
                        $target = ($q === $T) ? $T : (isset($aliases[$q]) ? $aliases[$q] : (isset($TABLES[$q]) ? $q : null));
                        if ($target === null) continue;          // unresolvable qualifier: skip silently
                        $bound_columns[$target][$col][$rel] = true;
                        continue;
                    }
                    $bound_columns[$T][strtolower($key) === $key ? $key : $key][$rel] = true;

                    // enum membership for literal assignments on this column
                    if (isset($TABLES[$T]['columns'][$key])) {
                        $etype = $TABLES[$T]['columns'][$key]['type'];
                        $evals = SchemaManifest::enum_values($etype);
                        if ($evals !== null) {
                            if (preg_match('/(?:set|where|or_where|like)\(\s*[\'"]' . preg_quote($c[1], '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/i', $chunk, $vm))
                                $enum_checks[$T][$key][] = array($vm[1], $rel, $evals);
                            if (preg_match('/where_in\(\s*[\'"][^\'"]+[\'"]\s*,\s*array\(([^)]*)\)/is', $chunk, $vm2)) {
                                preg_match_all('/[\'"]([^\'"]+)[\'"]/', $vm2[1], $vv);
                                foreach ($vv[1] as $one) $enum_checks[$T][$key][] = array($one, $rel, $evals);
                            }
                        }
                    }
                }
            }
        }
        // insert/update/replace with a data array: ->insert('t', array( 'k'=> ...
        if (preg_match_all('/->(?:insert|update|replace|insert_batch|update_batch)\(\s*(?:[\'"]([a-z_][a-z0-9_]*)[\'"]|\$this->table)\s*,\s*array\s*\(/is', $chunk, $dm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($dm as $d) {
                $dt = (isset($d[1]) && is_array($d[1]) && $d[1][0] !== '') ? strtolower($d[1][0]) : $model_table;
                if ($dt === null) continue;
                $start = $d[0][1] + strlen($d[0][0]);
                $depth = 1; $i = $start; $len = strlen($chunk);
                $keys = array(); $key_start = $start;
                while ($i < $len && $depth > 0) {
                    $ch = $chunk[$i];
                    if ($ch === '(' || $ch === '[') {
                        if ($depth === 1) $key_start = $i + 1; // crude; keys below are captured separately
                        $depth++;
                    } elseif ($ch === ')' || $ch === ']') $depth--;
                    elseif ($depth === 1 && ($ch === '\'' || $ch === '"')) {
                        if (preg_match('/\G([\'"])([a-z_][a-z0-9_]*)\1\s*=>/i', $chunk, $km, 0, $i)) {
                            $keys[] = $km[2];
                            $i += strlen($km[0]) - 1;
                        }
                    }
                    $i++;
                }
                foreach ($keys as $k) $bound_columns[$dt][$k][$rel] = true;
            }
        }
    }
}

/* ======================================================================== */
/* 4. Resolve findings                                                       */
/* ======================================================================== */

// 4a. missing tables
// information_schema is the MySQL system catalog — migrations introspect it
// legitimately; it is never an application table.
$SYSTEM_CATALOGS = array('information_schema' => true, 'mysql' => true, 'performance_schema' => true);
foreach (array_keys($referenced_tables) as $t) {
    if (isset($SYSTEM_CATALOGS[$t])) continue;
    if (!isset($TABLES[$t])) {
        // A table the code probes with table_exists() before use is OPTIONAL
        // by design (e.g. 'payouts' in AdminStats — a legacy table the stats
        // page reads when an older schema still has it). Every referencing
        // file carrying the guard means absence is handled: report as info,
        // not as a deployment failure.
        $unguarded = array();
        foreach (array_keys($referenced_tables[$t]) as $rel) {
            $base = preg_replace('/ \(raw SQL\)$/', '', $rel);
            $code = @file_get_contents($ROOT . DIRECTORY_SEPARATOR . $base);
            if ($code === false || !preg_match('/table_exists\(\s*[\'"]' . preg_quote($t, '/') . '[\'"]\s*\)/i', $code)) {
                $unguarded[] = $rel;
            }
        }
        if ($unguarded === array()) {
            info('table', implode(', ', array_keys($referenced_tables[$t])),
                "optional table '{$t}' (guarded by table_exists in every referencing file) is not in the schema");
        } else {
            err('table', implode(', ', $unguarded),
                "code references table '{$t}' — not present in database/marvysocials.sql");
        }
    }
}

// 4b. missing columns
foreach ($bound_columns as $t => $cols) {
    if (!isset($TABLES[$t])) continue;                          // already a missing-table error
    foreach ($cols as $c => $where) {
        if (!isset($TABLES[$t]['columns'][$c])) {
            err('column', implode(', ', array_keys($where)),
                "column {$t}.{$c} used by code but missing from the schema");
        }
    }
}

// 4c. enum membership
foreach ($enum_checks as $t => $cols) foreach ($cols as $c => $cases) foreach ($cases as $case) {
    if (!in_array($case[0], $case[2], true)) {
        err('type', $case[1], "'{$case[0]}' is not a value of {$t}.{$c} "
            . $TABLES[$t]['columns'][$c]['type']);
    }
}

// 4d. unreferenced tables (informational — they may be seed-only / upcoming)
$RESERVED_TABLES = array(
    'user_sessions'   => "session rows when SESS_DRIVER=database (CI writes it internally)",
    'idempotency_keys'=> 'reserved: idempotency registry for future queued webhooks',
);
foreach ($TABLES as $tname => $t) {
    if (!isset($referenced_tables[$tname]) && !in_array($tname, $SEEDED, true)) {
        $note = isset($RESERVED_TABLES[$tname]) ? $RESERVED_TABLES[$tname]
            : 'never referenced by code and carries no seed rows';
        info('table', 'schema', "table '{$tname}': {$note}");
    }
}

/* ===========================================================================
 * 5. Report
 * ========================================================================= */
$sec_order = array('table','column','type','fk','index');
usort($errors, function ($a, $b) use ($sec_order) {
    return array_search($a[0], $sec_order) <=> array_search($b[0], $sec_order);
});

$counts = array('table'=>0,'column'=>0,'type'=>0,'fk'=>0,'index'=>0);
foreach ($errors as $e) $counts[$e[0]]++;

if (!$QUIET || $errors) {
    echo "MarvySocials — database ↔ code audit\n";
    echo "schema: database/marvysocials.sql — " . count($TABLES) . " tables\n";
    echo "code:   " . count($files) . " PHP files scanned\n";
    echo str_repeat('-', 68) . "\n";
}
foreach (array('table'=>'MISSING TABLES','column'=>'MISSING COLUMNS','type'=>'ENUM/SET MISMATCHES',
              'fk'=>'BROKEN FOREIGN KEYS','index'=>'BROKEN INDEXES') as $sec => $title) {
    $rows = array_filter($errors, function ($e) use ($sec) { return $e[0] === $sec; });
    if (!$rows) { if (!$QUIET) echo "[ok]   {$title}: none\n"; continue; }
    echo "[FAIL] {$title}: " . count($rows) . "\n";
    foreach ($rows as $r) echo "        {$r[2]}\n           ({$r[1]})\n";
}
if (!$QUIET) {
    echo str_repeat('-', 68) . "\n";
    echo "referenced tables: " . count($referenced_tables) . " / " . count($TABLES) . "\n";
    if ($infos) {
        echo "info (unreferenced & unseeded tables):\n";
        foreach ($infos as $i) echo "        {$i[2]}\n";
    }
}
if ($errors) {
    echo "RESULT: FAIL — " . count($errors) . " schema/code mismatch(es).\n";
    exit(1);
}
echo "RESULT: PASS — zero missing tables, zero missing columns, FKs and indexes intact.\n";
exit(0);
