'use strict';
/**
 * MySQL → SQLite SQL translation for the dev database.
 *
 * DEV TOOLING ONLY (see tools/devdb/README.md). The panel targets MySQL 8 /
 * MariaDB in production; this translator lets the *unmodified* application and
 * its real migrations run against a SQLite file so the whole stack can be
 * exercised where no MySQL server exists.
 *
 * It is deliberately conservative: it rewrites the DDL/dialect constructs this
 * repository actually uses, and passes anything else through untouched.
 */

/**
 * Split SQL into tokens that are "inside a string/comment" vs. code, so
 * rewrites never corrupt string literals.
 */
/** Sentinel for a statement that must answer OK without touching SQLite. */
const NOOP = '-- devdb:noop';

function mapOutsideStrings(sql, fn) {
  let out = '';
  let i = 0;
  let code = '';
  const flush = () => {
    if (code) {
      out += fn(code);
      code = '';
    }
  };
  while (i < sql.length) {
    const ch = sql[i];
    if (ch === "'" || ch === '"' || ch === '`') {
      flush();
      const quote = ch;
      let lit = ch;
      i++;
      while (i < sql.length) {
        if (sql[i] === '\\' && quote !== '`') {
          lit += sql[i] + (sql[i + 1] || '');
          i += 2;
          continue;
        }
        if (sql[i] === quote) {
          if (sql[i + 1] === quote) {
            lit += quote + quote;
            i += 2;
            continue;
          }
          lit += quote;
          i++;
          break;
        }
        lit += sql[i++];
      }
      out += lit;
      continue;
    }
    if (ch === '-' && sql[i + 1] === '-') {
      flush();
      const nl = sql.indexOf('\n', i);
      const stop = nl === -1 ? sql.length : nl;
      out += sql.slice(i, stop);
      i = stop;
      continue;
    }
    if (ch === '/' && sql[i + 1] === '*') {
      flush();
      const end = sql.indexOf('*/', i + 2);
      const stop = end === -1 ? sql.length : end + 2;
      out += sql.slice(i, stop);
      i = stop;
      continue;
    }
    code += ch;
    i++;
  }
  flush();
  return out;
}

/** Strip backtick quoting (SQLite accepts it, but our own parsing is simpler without). */
function stripBackticks(sql) {
  return sql.replace(/`([^`]*)`/g, '"$1"');
}

/** Split a comma-separated list respecting nested parentheses. */
function splitTopLevel(s, sep = ',') {
  const parts = [];
  let depth = 0;
  let cur = '';
  let i = 0;
  while (i < s.length) {
    const ch = s[i];
    if (ch === "'" || ch === '"') {
      const q = ch;
      cur += ch;
      i++;
      while (i < s.length) {
        if (s[i] === '\\') {
          cur += s[i] + (s[i + 1] || '');
          i += 2;
          continue;
        }
        cur += s[i];
        if (s[i] === q) {
          i++;
          break;
        }
        i++;
      }
      continue;
    }
    if (ch === '(') depth++;
    if (ch === ')') depth--;
    if (ch === sep && depth === 0) {
      parts.push(cur);
      cur = '';
      i++;
      continue;
    }
    cur += ch;
    i++;
  }
  if (cur.trim()) parts.push(cur);
  return parts;
}

/** MySQL column type → SQLite storage class, preserving affinity where it matters. */
function translateType(type) {
  const t = type.trim();
  const upper = t.toUpperCase();
  if (/^(TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT)\b/.test(upper)) return 'INTEGER';
  if (/^(DECIMAL|NUMERIC)\b/.test(upper)) return 'TEXT'; // exact money strings, never floats
  if (/^(FLOAT|DOUBLE|REAL)\b/.test(upper)) return 'REAL';
  if (/^(DATETIME|TIMESTAMP|DATE|TIME|YEAR)\b/.test(upper)) return 'TEXT';
  if (/^(CHAR|VARCHAR|TEXT|TINYTEXT|MEDIUMTEXT|LONGTEXT|ENUM|SET|JSON)\b/.test(upper)) return 'TEXT';
  if (/^(BLOB|TINYBLOB|MEDIUMBLOB|LONGBLOB|BINARY|VARBINARY)\b/.test(upper)) return 'BLOB';
  return 'TEXT';
}

/**
 * Parse and translate CREATE TABLE. Returns { sql, extra } where extra holds
 * index statements that must run separately (SQLite has no inline INDEX).
 */
function translateCreateTable(sql, registry) {
  const m = /^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?("?[\w$]+"?)\s*\(([\s\S]*)\)\s*([^)]*)$/i.exec(sql);
  if (!m) return { sql, extra: [] };

  const ifNotExists = m[1] ? 'IF NOT EXISTS ' : '';
  const tableRaw = m[2].replace(/"/g, '');
  const body = m[3];
  const extra = [];
  const cols = [];
  const tableConstraints = [];
  const columnMeta = [];

  for (const rawPart of splitTopLevel(body)) {
    const part = rawPart.trim();
    if (!part) continue;
    const upper = part.toUpperCase();

    // --- table-level keys -------------------------------------------------
    let km = /^PRIMARY\s+KEY\s*\(([^)]*)\)/i.exec(part);
    if (km) {
      tableConstraints.push(`PRIMARY KEY (${km[1]})`);
      continue;
    }
    km = /^(UNIQUE)\s+(?:KEY|INDEX)?\s*("?[\w$]*"?)\s*\(([^)]*)\)/i.exec(part);
    if (km) {
      const idxName = (km[2] || '').replace(/"/g, '') || `uq_${tableRaw}_${cols.length}`;
      extra.push(`CREATE UNIQUE INDEX IF NOT EXISTS "${idxName}" ON "${tableRaw}" (${km[3]})`);
      continue;
    }
    km = /^(?:KEY|INDEX)\s+("?[\w$]+"?)\s*\(([^)]*)\)/i.exec(part);
    if (km) {
      const idxName = km[1].replace(/"/g, '');
      // FULLTEXT/prefix lengths like col(20) are not supported by SQLite.
      const colList = km[2].replace(/\(\s*\d+\s*\)/g, '');
      extra.push(`CREATE INDEX IF NOT EXISTS "${idxName}" ON "${tableRaw}" (${colList})`);
      continue;
    }
    if (/^FULLTEXT\b|^SPATIAL\b/i.test(part)) continue;

    km = /^CONSTRAINT\s+("?[\w$]+"?)\s+FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+("?[\w$]+"?)\s*\(([^)]*)\)([\s\S]*)$/i.exec(part);
    if (km) {
      tableConstraints.push(
        `FOREIGN KEY (${km[2]}) REFERENCES "${km[3].replace(/"/g, '')}" (${km[4]}) ${km[5].trim()}`.trim()
      );
      continue;
    }
    km = /^FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+("?[\w$]+"?)\s*\(([^)]*)\)([\s\S]*)$/i.exec(part);
    if (km) {
      tableConstraints.push(
        `FOREIGN KEY (${km[1]}) REFERENCES "${km[2].replace(/"/g, '')}" (${km[3]}) ${km[4].trim()}`.trim()
      );
      continue;
    }
    if (/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|CHECK)\b/i.test(upper) && !/^CHECK\s*\(/i.test(upper)) {
      continue;
    }

    // --- column definition ------------------------------------------------
    const cm = /^("?[\w$]+"?)\s+([\w]+(?:\s*\([^)]*\))?(?:\s+UNSIGNED)?(?:\s+ZEROFILL)?)([\s\S]*)$/i.exec(part);
    if (!cm) continue;

    const colName = cm[1].replace(/"/g, '');
    const mysqlType = cm[2];
    let rest = cm[3] || '';

    const sqliteType = translateType(mysqlType);
    const isAutoInc = /AUTO_INCREMENT/i.test(rest);
    const isUnsigned = /UNSIGNED/i.test(mysqlType);
    const notNull = /\bNOT\s+NULL\b/i.test(rest);
    const inlineUnique = /\bUNIQUE\b/i.test(rest);
    const inlinePk = /\bPRIMARY\s+KEY\b/i.test(rest);

    // Default value.
    let def = null;
    const dm = /\bDEFAULT\s+('(?:[^'\\]|\\.|'')*'|[\w.$+-]+(?:\([^)]*\))?)/i.exec(rest);
    if (dm) {
      let d = dm[1];
      if (/^CURRENT_TIMESTAMP(\(\))?$/i.test(d)) d = 'CURRENT_TIMESTAMP';
      def = d;
    }

    // Inline REFERENCES.
    const rm = /\bREFERENCES\s+("?[\w$]+"?)\s*\(([^)]*)\)((?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION|SET\s+DEFAULT))*)/i.exec(rest);

    let colSql = `"${colName}" ${sqliteType}`;
    if (isAutoInc || (inlinePk && sqliteType === 'INTEGER')) {
      // SQLite: INTEGER PRIMARY KEY is the rowid alias and auto-increments.
      colSql = `"${colName}" INTEGER PRIMARY KEY AUTOINCREMENT`;
      registry.autoIncrement.add(`${tableRaw}.${colName}`);
    } else {
      if (notNull) colSql += ' NOT NULL';
      if (def !== null) colSql += ` DEFAULT ${def}`;
      if (inlineUnique) colSql += ' UNIQUE';
      if (inlinePk) colSql += ' PRIMARY KEY';
      if (rm) {
        colSql += ` REFERENCES "${rm[1].replace(/"/g, '')}" (${rm[2]})${rm[3] || ''}`;
      }
    }

    cols.push(colSql);
    columnMeta.push({
      name: colName,
      mysqlType: mysqlType.trim(),
      sqliteType,
      notNull,
      unsigned: isUnsigned,
      autoIncrement: isAutoInc,
      primaryKey: inlinePk || isAutoInc,
      default: def,
    });
  }

  registry.tables[tableRaw] = { columns: columnMeta };

  // ON UPDATE CURRENT_TIMESTAMP has no SQLite equivalent — emit a trigger.
  const touchCols = [];
  const bodyParts = splitTopLevel(body);
  for (const p of bodyParts) {
    const cm = /^\s*("?[\w$]+"?)\s+[\w]+/.exec(p);
    if (cm && /ON\s+UPDATE\s+CURRENT_TIMESTAMP/i.test(p)) {
      touchCols.push(cm[1].replace(/"/g, ''));
    }
  }

  const pkFromTable = tableConstraints.some((c) => /^PRIMARY KEY/i.test(c));
  const hasRowidPk = cols.some((c) => /INTEGER PRIMARY KEY AUTOINCREMENT/i.test(c));
  const constraints = tableConstraints.filter((c) => !(hasRowidPk && /^PRIMARY KEY/i.test(c)));

  const out =
    `CREATE TABLE ${ifNotExists}"${tableRaw}" (\n  ` +
    cols.concat(constraints).join(',\n  ') +
    '\n)';

  for (const tc of touchCols) {
    const pk = columnMeta.find((c) => c.primaryKey);
    if (!pk) continue;
    extra.push(
      `CREATE TRIGGER IF NOT EXISTS "trg_${tableRaw}_${tc}" AFTER UPDATE ON "${tableRaw}" ` +
        `FOR EACH ROW WHEN NEW."${tc}" = OLD."${tc}" BEGIN ` +
        `UPDATE "${tableRaw}" SET "${tc}" = CURRENT_TIMESTAMP WHERE "${pk.name}" = NEW."${pk.name}"; END`
    );
  }

  void pkFromTable;
  return { sql: out, extra };
}

/**
 * ALTER TABLE — SQLite only supports a subset. We support the forms this
 * repository's migrations use and report the rest as no-ops.
 */
function translateAlterTable(sql, registry) {
  const m = /^\s*ALTER\s+TABLE\s+("?[\w$]+"?)\s+([\s\S]*)$/i.exec(sql);
  if (!m) return { statements: [sql] };
  const table = m[1].replace(/"/g, '');
  const actions = splitTopLevel(m[2]);
  const statements = [];

  for (const raw of actions) {
    const action = raw.trim();

    let am = /^ADD\s+(?:CONSTRAINT\s+("?[\w$]+"?)\s+)?FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+("?[\w$]+"?)\s*\(([^)]*)\)/i.exec(action);
    if (am) {
      // SQLite cannot add an FK to an existing table. Recording it keeps the
      // dev database honest about intent without failing the migration.
      registry.deferredForeignKeys.push({ table, columns: am[2], refTable: am[3].replace(/"/g, ''), refColumns: am[4] });
      continue;
    }

    am = /^ADD\s+(?:COLUMN\s+)?("?[\w$]+"?)\s+([\s\S]+)$/i.exec(action);
    if (am && !/^(INDEX|KEY|UNIQUE|PRIMARY|CONSTRAINT|FOREIGN)\b/i.test(am[1].replace(/"/g, ''))) {
      const colName = am[1].replace(/"/g, '');
      const spec = am[2];
      const tm = /^([\w]+(?:\s*\([^)]*\))?(?:\s+UNSIGNED)?)/i.exec(spec);
      const sqliteType = tm ? translateType(tm[1]) : 'TEXT';
      let colSql = `"${colName}" ${sqliteType}`;
      const dm = /\bDEFAULT\s+('(?:[^'\\]|\\.)*'|[\w.$+-]+)/i.exec(spec);
      // A NOT NULL column added to a populated table needs a default in SQLite.
      if (/\bNOT\s+NULL\b/i.test(spec) && dm) colSql += ' NOT NULL';
      if (dm) {
        let d = dm[1];
        if (/^CURRENT_TIMESTAMP$/i.test(d)) d = 'CURRENT_TIMESTAMP';
        colSql += ` DEFAULT ${d}`;
      }
      statements.push(`ALTER TABLE "${table}" ADD COLUMN ${colSql}`);
      continue;
    }

    am = /^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+("?[\w$]+"?)\s*\(([^)]*)\)/i.exec(action);
    if (!am) {
      am = /^ADD\s+CONSTRAINT\s+("?[\w$]+"?)\s+UNIQUE\s*\(([^)]*)\)/i.exec(action);
      if (am) am = [action, 'UNIQUE ', am[1], am[2]];
    }
    if (am) {
      statements.push(
        `CREATE ${am[1] ? 'UNIQUE ' : ''}INDEX IF NOT EXISTS "${am[2].replace(/"/g, '')}" ON "${table}" (${am[3]})`
      );
      continue;
    }

    am = /^DROP\s+(?:COLUMN\s+)?("?[\w$]+"?)$/i.exec(action);
    if (am && !/^(INDEX|KEY|PRIMARY|FOREIGN|CONSTRAINT)$/i.test(am[1].replace(/"/g, ''))) {
      statements.push(`ALTER TABLE "${table}" DROP COLUMN "${am[1].replace(/"/g, '')}"`);
      continue;
    }

    am = /^DROP\s+(?:INDEX|KEY)\s+("?[\w$]+"?)/i.exec(action);
    if (am) {
      statements.push(`DROP INDEX IF EXISTS "${am[1].replace(/"/g, '')}"`);
      continue;
    }

    am = /^DROP\s+FOREIGN\s+KEY\s+("?[\w$]+"?)/i.exec(action);
    if (am) continue; // no-op in SQLite

    am = /^MODIFY\s+(?:COLUMN\s+)?/i.exec(action);
    if (am) continue; // type changes are a no-op given SQLite's dynamic typing

    am = /^CHANGE\s+(?:COLUMN\s+)?("?[\w$]+"?)\s+("?[\w$]+"?)/i.exec(action);
    if (am) {
      statements.push(
        `ALTER TABLE "${table}" RENAME COLUMN "${am[1].replace(/"/g, '')}" TO "${am[2].replace(/"/g, '')}"`
      );
      continue;
    }

    am = /^RENAME\s+(?:TO\s+)?("?[\w$]+"?)/i.exec(action);
    if (am) {
      statements.push(`ALTER TABLE "${table}" RENAME TO "${am[1].replace(/"/g, '')}"`);
      continue;
    }
  }

  return { statements: statements.length ? statements : [NOOP] };
}

/** Rewrite MySQL expressions/functions that SQLite spells differently. */
function translateExpressions(sql) {
  return mapOutsideStrings(sql, (code) => {
    let s = code;

    // Table options MySQL requires and SQLite rejects.
    s = s.replace(/\bENGINE\s*=\s*\w+/gi, '');
    s = s.replace(/\bDEFAULT\s+CHARSET\s*=\s*[\w]+/gi, '');
    s = s.replace(/\bCHARACTER\s+SET\s+[\w]+/gi, '');
    s = s.replace(/\bCOLLATE\s*=\s*[\w]+/gi, '');
    s = s.replace(/\bCOLLATE\s+utf8mb4_[\w]+/gi, '');
    s = s.replace(/\bAUTO_INCREMENT\s*=\s*\d+/gi, '');
    s = s.replace(/\bROW_FORMAT\s*=\s*\w+/gi, '');
    s = s.replace(/\bCOMMENT\s+'(?:[^'\\]|\\.)*'/gi, '');

    // Functions.
    s = s.replace(/\bIFNULL\s*\(/gi, 'COALESCE(');
    s = s.replace(/\bNOW\s*\(\s*\)/gi, "STRFTIME('%Y-%m-%d %H:%M:%S','now')");
    s = s.replace(/\bUTC_TIMESTAMP\s*\(\s*\)/gi, "STRFTIME('%Y-%m-%d %H:%M:%S','now')");
    s = s.replace(/\bCURDATE\s*\(\s*\)/gi, "DATE('now')");
    s = s.replace(/\bCURRENT_DATE\s*\(\s*\)/gi, "DATE('now')");
    s = s.replace(/\bUNIX_TIMESTAMP\s*\(\s*\)/gi, "CAST(STRFTIME('%s','now') AS INTEGER)");
    s = s.replace(/\bUNIX_TIMESTAMP\s*\(([^()]*)\)/gi, "CAST(STRFTIME('%s',$1) AS INTEGER)");
    s = s.replace(/\bRAND\s*\(\s*\)/gi, 'RANDOM()');
    s = s.replace(/\bGROUP_CONCAT\s*\(([^()]*?)\s+SEPARATOR\s+('(?:[^'\\]|\\.)*')\s*\)/gi, 'GROUP_CONCAT($1, $2)');
    s = s.replace(/\bLOCATE\s*\(/gi, 'INSTR(');

    // DATE_SUB / DATE_ADD → SQLite datetime modifiers.
    s = s.replace(
      /\bDATE_SUB\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)S?\s*\)/gi,
      (_all, base, n, unit) => `DATETIME(${base.trim()}, '-${n} ${unit.toLowerCase()}')`
    );
    s = s.replace(
      /\bDATE_ADD\s*\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)S?\s*\)/gi,
      (_all, base, n, unit) => `DATETIME(${base.trim()}, '+${n} ${unit.toLowerCase()}')`
    );

    // DATE_FORMAT with the handful of patterns this codebase uses.
    s = s.replace(/\bDATE_FORMAT\s*\(\s*([^,]+?)\s*,\s*'([^']*)'\s*\)/gi, (_all, base, fmt) => {
      const sfmt = fmt
        .replace(/%Y/g, '%Y')
        .replace(/%m/g, '%m')
        .replace(/%d/g, '%d')
        .replace(/%H/g, '%H')
        .replace(/%i/g, '%M')
        .replace(/%s/g, '%S');
      return `STRFTIME('${sfmt}', ${base.trim()})`;
    });

    // Locking/behaviour clauses SQLite has no syntax for.
    s = s.replace(/\bFOR\s+UPDATE\b/gi, '');
    s = s.replace(/\bLOCK\s+IN\s+SHARE\s+MODE\b/gi, '');
    s = s.replace(/\bSTRAIGHT_JOIN\b/gi, 'JOIN');
    s = s.replace(/\bSQL_CALC_FOUND_ROWS\b/gi, '');
    s = s.replace(/\bHIGH_PRIORITY\b/gi, '');
    s = s.replace(/\bLOW_PRIORITY\b/gi, '');
    s = s.replace(/\bINSERT\s+IGNORE\b/gi, 'INSERT OR IGNORE');
    s = s.replace(/\bREPLACE\s+INTO\b/gi, 'INSERT OR REPLACE INTO');
    s = s.replace(/\bBINARY\s+(?=["'\w])/gi, '');

    return s;
  });
}

/** INSERT ... ON DUPLICATE KEY UPDATE → INSERT ... ON CONFLICT DO UPDATE. */
function translateUpsert(sql, registry) {
  const m = /^([\s\S]*?)\bON\s+DUPLICATE\s+KEY\s+UPDATE\b([\s\S]*)$/i.exec(sql);
  if (!m) return sql;
  const head = m[1];
  const assignments = m[2];

  const tm = /INSERT\s+(?:OR\s+\w+\s+)?INTO\s+"?([\w$]+)"?/i.exec(head);
  const table = tm ? tm[1] : null;

  // Determine the conflict target: prefer a unique index, else the primary key.
  let target = '';
  if (table && registry.uniqueColumns[table] && registry.uniqueColumns[table].length) {
    target = `(${registry.uniqueColumns[table].map((c) => `"${c}"`).join(', ')})`;
  }

  const rewritten = assignments.replace(/\bVALUES\s*\(\s*("?[\w$]+"?)\s*\)/gi, (_a, col) => `excluded.${col}`);
  return `${head} ON CONFLICT ${target} DO UPDATE SET ${rewritten.trim()}`;
}

/**
 * `DELETE alias FROM t alias JOIN other ON ... WHERE ...`
 * → `DELETE FROM t WHERE <pk> IN (SELECT alias.<pk> FROM ... )`.
 *
 * MySQL's multi-table delete has no SQLite equivalent; rewriting it as a
 * subquery preserves the semantics for the single-target form the migrations
 * use.
 */
function translateMultiTableDelete(sql) {
  const m = /^\s*DELETE\s+("?[\w$]+"?)\s+FROM\s+([\s\S]+)$/i.exec(sql);
  if (!m) return sql;

  const alias = m[1].replace(/"/g, '');
  const rest = m[2];

  // The target table is the first one named, with `alias` bound to it.
  const tm = /^\s*("?[\w$]+"?)(?:\s+(?:AS\s+)?("?[\w$]+"?))?/i.exec(rest);
  if (!tm) return sql;
  const table = tm[1].replace(/"/g, '');
  const bound = (tm[2] || tm[1]).replace(/"/g, '');
  if (bound !== alias) return sql;

  return `DELETE FROM "${table}" WHERE rowid IN (SELECT ${alias}.rowid FROM ${rest})`;
}

/**
 * Rewrite MySQL string literals into SQLite string literals.
 *
 * MySQL (and therefore every driver that escapes for it, including PDO's
 * quote()) uses C-style backslash escapes inside single-quoted strings:
 * `'{\"value\":true}'`. SQLite has no backslash escaping at all — a backslash
 * is just a backslash — so that literal round-trips as `{\"value\":true}` and
 * any JSON stored through the query builder comes back corrupt.
 *
 * This converts each literal to SQLite's own convention: interpret the
 * backslash escapes, then re-quote by doubling single quotes.
 */
function translateStringLiterals(sql) {
  let out = '';
  let i = 0;

  while (i < sql.length) {
    const ch = sql[i];

    // Line and block comments pass through untouched.
    if (ch === '-' && sql[i + 1] === '-') {
      const nl = sql.indexOf('\n', i);
      const stop = nl === -1 ? sql.length : nl;
      out += sql.slice(i, stop);
      i = stop;
      continue;
    }
    if (ch === '/' && sql[i + 1] === '*') {
      const end = sql.indexOf('*/', i + 2);
      const stop = end === -1 ? sql.length : end + 2;
      out += sql.slice(i, stop);
      i = stop;
      continue;
    }
    // Identifiers keep their quoting.
    if (ch === '"' || ch === '`') {
      const q = ch;
      let lit = ch;
      i++;
      while (i < sql.length) {
        lit += sql[i];
        if (sql[i] === q) {
          i++;
          break;
        }
        i++;
      }
      out += lit;
      continue;
    }

    if (ch === "'") {
      i++;
      let value = '';
      while (i < sql.length) {
        const c = sql[i];
        if (c === '\\' && i + 1 < sql.length) {
          const next = sql[i + 1];
          const simple = { n: '\n', t: '\t', r: '\r', 0: '\0', b: '\b', Z: '\x1a' };
          if (Object.prototype.hasOwnProperty.call(simple, next)) value += simple[next];
          else value += next; // \' \" \\ and anything else: the literal character
          i += 2;
          continue;
        }
        if (c === "'") {
          // '' inside a literal is an escaped quote in both dialects.
          if (sql[i + 1] === "'") {
            value += "'";
            i += 2;
            continue;
          }
          i++;
          break;
        }
        value += c;
        i++;
      }
      out += "'" + value.replace(/'/g, "''") + "'";
      continue;
    }

    out += ch;
    i++;
  }

  return out;
}

/**
 * Main entry point: translate one MySQL statement into zero or more SQLite
 * statements.
 */
function translate(sqlRaw, registry) {
  // Literals first: every later rewrite scans for quotes, so they must already
  // be in SQLite's own escaping convention.
  let sql = translateStringLiterals(stripBackticks(sqlRaw)).trim().replace(/;+\s*$/, '');
  if (!sql) return [];

  if (/^CREATE\s+TABLE\b/i.test(sql)) {
    sql = translateExpressions(sql);
    const { sql: created, extra } = translateCreateTable(sql, registry);
    return [created, ...extra];
  }

  if (/^ALTER\s+TABLE\b/i.test(sql)) {
    sql = translateExpressions(sql);
    return translateAlterTable(sql, registry).statements;
  }

  if (/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE\s+SAVEPOINT)\b/i.test(sql)) {
    return [sql.replace(/^START\s+TRANSACTION/i, 'BEGIN')];
  }

  if (/^CREATE\s+(UNIQUE\s+)?INDEX\b/i.test(sql)) {
    sql = translateExpressions(sql);
    sql = sql.replace(/\(\s*\d+\s*\)(?=\s*[,)])/g, '');
    if (!/IF\s+NOT\s+EXISTS/i.test(sql)) sql = sql.replace(/^CREATE\s+(UNIQUE\s+)?INDEX\s+/i, (a) => `${a}IF NOT EXISTS `);
    return [sql];
  }

  if (/^DROP\s+INDEX\b/i.test(sql)) {
    const m = /^DROP\s+INDEX\s+("?[\w$]+"?)(?:\s+ON\s+("?[\w$]+"?))?/i.exec(sql);
    if (m) return [`DROP INDEX IF EXISTS "${m[1].replace(/"/g, '')}"`];
    return [sql];
  }

  // Statements MySQL accepts that SQLite has no equivalent for. They must
  // answer with an OK packet, never an empty result set: mysqlnd runs
  // `SET SESSION sql_mode = ...` as a connection init command and mis-parses a
  // result set there.
  if (/^(SET|LOCK\s+TABLES|UNLOCK\s+TABLES|FLUSH|ANALYZE|OPTIMIZE|CHECK\s+TABLE|REPAIR|USE)\b/i.test(sql)) {
    return [NOOP];
  }

  sql = translateExpressions(sql);
  if (/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i.test(sql)) sql = translateUpsert(sql, registry);
  sql = translateMultiTableDelete(sql);

  return [sql];
}

module.exports = {
  NOOP,
  translate,
  translateStringLiterals,
  translateMultiTableDelete,
  translateExpressions,
  translateCreateTable,
  translateAlterTable,
  splitTopLevel,
  stripBackticks,
  mapOutsideStrings,
};
