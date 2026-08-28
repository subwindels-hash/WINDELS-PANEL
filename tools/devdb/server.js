'use strict';
/**
 * MarvySocials dev database — a MySQL-wire-protocol server backed by SQLite.
 *
 * DEV TOOLING ONLY. See tools/devdb/README.md.
 *
 * Why this exists: the panel targets MySQL 8 / MariaDB in production, and the
 * application code, migrations and mysqli driver are written for it. In an
 * environment where no MySQL server can be installed, this lets the
 * *unmodified* application connect over TCP as if MySQL were present, so the
 * full stack (migrations, seeds, auth, orders, payments, admin) can be
 * exercised end to end. Production always uses real MySQL.
 *
 *   node tools/devdb/server.js --port 3399 --db storage/devdb/marvy.sqlite
 */

const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { DatabaseSync } = require('node:sqlite');

const proto = require('./protocol');
const { translate, NOOP } = require('./translate');

// ---------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------
function parseArgs(argv) {
  const out = { port: 3399, host: '127.0.0.1', db: 'storage/devdb/marvy.sqlite', verbose: false, fresh: false };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--port') out.port = parseInt(argv[++i], 10);
    else if (a === '--host') out.host = argv[++i];
    else if (a === '--db') out.db = argv[++i];
    else if (a === '--verbose') out.verbose = true;
    else if (a === '--fresh') out.fresh = true;
  }
  return out;
}

const args = parseArgs(process.argv);
const dbPath = path.resolve(args.db);
fs.mkdirSync(path.dirname(dbPath), { recursive: true });
if (args.fresh && fs.existsSync(dbPath)) fs.unlinkSync(dbPath);

const db = new DatabaseSync(dbPath);
db.exec('PRAGMA journal_mode = WAL');
db.exec('PRAGMA foreign_keys = ON');
db.exec('PRAGMA busy_timeout = 8000');

// MySQL functions SQLite lacks, registered as UDFs.
db.function('CONCAT_WS', { varargs: true }, (sep, ...rest) =>
  rest.filter((v) => v !== null && v !== undefined).join(sep)
);
db.function('IF', (cond, a, b) => (cond ? a : b));
db.function('MD5', (s) => (s === null ? null : crypto.createHash('md5').update(String(s)).digest('hex')));
db.function('SHA1', (s) => (s === null ? null : crypto.createHash('sha1').update(String(s)).digest('hex')));
db.function('UUID', () => crypto.randomUUID());
db.function('FIND_IN_SET', (needle, hay) => {
  if (hay === null || needle === null) return null;
  const idx = String(hay).split(',').indexOf(String(needle));
  return idx + 1;
});
db.function('LAST_INSERT_ID', () => 0);
// FIELD(needle, a, b, c) → 1-based position of needle, 0 when absent. Used for
// "ORDER BY FIELD(status, 'OPEN', 'PENDING', ...)" custom sort orders.
db.function('FIELD', { varargs: true }, (needle, ...list) => {
  if (needle === null || needle === undefined) return 0;
  const idx = list.findIndex((v) => String(v) === String(needle));
  return idx + 1;
});
db.function('GREATEST', { varargs: true }, (...v) => {
  const vals = v.filter((x) => x !== null && x !== undefined);
  return vals.length ? vals.reduce((a, b) => (a > b ? a : b)) : null;
});
db.function('LEAST', { varargs: true }, (...v) => {
  const vals = v.filter((x) => x !== null && x !== undefined);
  return vals.length ? vals.reduce((a, b) => (a < b ? a : b)) : null;
});
// SUBSTRING_INDEX(str, delim, count) — MySQL's "everything before the Nth
// delimiter". Migration 028 uses it to recover a rate-limit scope from an
// identifier like 'assistant:1.2.3.4'.
db.function('SUBSTRING_INDEX', (str, delim, count) => {
  if (str === null || str === undefined) return null;
  const parts = String(str).split(String(delim));
  const n = Number(count);
  return n >= 0 ? parts.slice(0, n).join(String(delim))
                : parts.slice(parts.length + n).join(String(delim));
});
db.function('TIMESTAMPDIFF_SECOND', (a, b) =>
  Math.round((Date.parse(b + 'Z') - Date.parse(a + 'Z')) / 1000)
);

const registry = {
  tables: {},
  autoIncrement: new Set(),
  uniqueColumns: {},
  deferredForeignKeys: [],
};

const SCHEMA_NAME = process.env.DEVDB_SCHEMA || 'marvysocials';

const log = (...a) => {
  if (args.verbose) console.error('[devdb]', ...a);
};

// ---------------------------------------------------------------------------
// SQLite introspection helpers used to answer MySQL admin queries
// ---------------------------------------------------------------------------
function listTables() {
  return db
    .prepare("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    .all()
    .map((r) => r.name);
}

function tableColumns(table) {
  try {
    return db.prepare(`PRAGMA table_info("${table.replace(/"/g, '')}")`).all();
  } catch {
    return [];
  }
}

function tableIndexes(table) {
  try {
    return db.prepare(`PRAGMA index_list("${table.replace(/"/g, '')}")`).all();
  } catch {
    return [];
  }
}

function tableForeignKeys(table) {
  try {
    return db.prepare(`PRAGMA foreign_key_list("${table.replace(/"/g, '')}")`).all();
  } catch {
    return [];
  }
}

/** Best-effort MySQL type string for a SQLite column, for information_schema. */
function mysqlTypeOf(table, column) {
  const meta = registry.tables[table];
  if (meta) {
    const c = meta.columns.find((x) => x.name === column.name);
    if (c) return c.mysqlType.toLowerCase();
  }
  const t = String(column.type || '').toUpperCase();
  if (t === 'INTEGER') return 'bigint(20)';
  if (t === 'REAL') return 'double';
  if (t === 'BLOB') return 'blob';
  return 'varchar(255)';
}

// ---------------------------------------------------------------------------
// MySQL admin-query emulation
// ---------------------------------------------------------------------------

/** @returns {null|{columns:Array,rows:Array}} */
function handleAdminQuery(sql, session) {
  const s = sql.trim().replace(/;+$/, '');
  const upper = s.toUpperCase();

  // -- SHOW TABLES [FROM db] [LIKE ...] ------------------------------------
  let m = /^SHOW\s+(?:FULL\s+)?TABLES(?:\s+FROM\s+[`"]?[\w$]+[`"]?)?(?:\s+LIKE\s+'([^']*)')?/i.exec(s);
  if (m) {
    let names = listTables();
    if (m[1]) {
      const rx = new RegExp('^' + m[1].replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/%/g, '.*').replace(/_/g, '.') + '$', 'i');
      names = names.filter((n) => rx.test(n));
    }
    return {
      columns: [{ name: `Tables_in_${session.database || SCHEMA_NAME}`, table: 'TABLES' }],
      rows: names.map((n) => [n]),
    };
  }

  // -- SHOW COLUMNS / DESCRIBE ---------------------------------------------
  m = /^(?:SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM|DESC(?:RIBE)?)\s+[`"]?([\w$]+)[`"]?/i.exec(s);
  if (m) {
    const table = m[1];
    const cols = tableColumns(table);
    const idx = tableIndexes(table);
    const uniqueCols = new Set();
    for (const i of idx) {
      if (!i.unique) continue;
      const info = db.prepare(`PRAGMA index_info("${i.name}")`).all();
      if (info.length === 1) uniqueCols.add(info[0].name);
    }
    return {
      columns: [
        { name: 'Field', table: 'COLUMNS' },
        { name: 'Type', table: 'COLUMNS' },
        { name: 'Null', table: 'COLUMNS' },
        { name: 'Key', table: 'COLUMNS' },
        { name: 'Default', table: 'COLUMNS' },
        { name: 'Extra', table: 'COLUMNS' },
      ],
      rows: cols.map((c) => [
        c.name,
        mysqlTypeOf(table, c),
        c.notnull ? 'NO' : 'YES',
        c.pk ? 'PRI' : uniqueCols.has(c.name) ? 'UNI' : '',
        c.dflt_value === null ? null : String(c.dflt_value).replace(/^'|'$/g, ''),
        c.pk && registry.autoIncrement.has(`${table}.${c.name}`) ? 'auto_increment' : '',
      ]),
    };
  }

  // -- SHOW INDEX ----------------------------------------------------------
  m = /^SHOW\s+(?:INDEX|INDEXES|KEYS)\s+FROM\s+[`"]?([\w$]+)[`"]?/i.exec(s);
  if (m) {
    const table = m[1];
    const rows = [];
    for (const i of tableIndexes(table)) {
      const info = db.prepare(`PRAGMA index_info("${i.name}")`).all();
      info.forEach((col, n) => {
        rows.push([table, i.unique ? '0' : '1', i.name, String(n + 1), col.name, 'A', '0', null, null, 'YES', 'BTREE', '', '']);
      });
    }
    return {
      columns: [
        'Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Collation',
        'Cardinality', 'Sub_part', 'Packed', 'Null', 'Index_type', 'Comment', 'Index_comment',
      ].map((name) => ({ name, table: 'STATISTICS' })),
      rows,
    };
  }

  // -- SHOW STATUS / VARIABLES ---------------------------------------------
  m = /^SHOW\s+(?:GLOBAL\s+|SESSION\s+)?(STATUS|VARIABLES)(?:\s+LIKE\s+'([^']*)')?/i.exec(s);
  if (m) {
    const which = m[1].toUpperCase();
    const like = (m[2] || '').toLowerCase().replace(/%/g, '');
    const data =
      which === 'STATUS'
        ? { ssl_cipher: '', Threads_connected: '1', Uptime: '1000' }
        : {
            version: '8.0.36-marvysocials-devdb',
            version_comment: 'MarvySocials dev database (SQLite-backed)',
            sql_mode: 'STRICT_ALL_TABLES',
            character_set_client: 'utf8mb4',
            character_set_connection: 'utf8mb4',
            character_set_results: 'utf8mb4',
            collation_connection: 'utf8mb4_unicode_ci',
            max_allowed_packet: '67108864',
            time_zone: 'SYSTEM',
            autocommit: 'ON',
            lower_case_table_names: '0',
          };
    const rows = Object.entries(data)
      .filter(([k]) => !like || k.toLowerCase().includes(like))
      .map(([k, v]) => [k, v]);
    return {
      columns: [
        { name: 'Variable_name', table: 'VARIABLES' },
        { name: 'Value', table: 'VARIABLES' },
      ],
      rows,
    };
  }

  // -- SHOW DATABASES ------------------------------------------------------
  if (/^SHOW\s+DATABASES/i.test(s)) {
    return { columns: [{ name: 'Database', table: 'SCHEMATA' }], rows: [[SCHEMA_NAME]] };
  }

  // -- SHOW CREATE TABLE ---------------------------------------------------
  m = /^SHOW\s+CREATE\s+TABLE\s+[`"]?([\w$]+)[`"]?/i.exec(s);
  if (m) {
    const row = db.prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?").get(m[1]);
    return {
      columns: [
        { name: 'Table', table: 'TABLES' },
        { name: 'Create Table', table: 'TABLES' },
      ],
      rows: row ? [[m[1], row.sql]] : [],
    };
  }

  // -- information_schema.TABLES -------------------------------------------
  if (/FROM\s+information_schema\.TABLES/i.test(s)) {
    const names = listTables();
    const wantsCount = /COUNT\s*\(/i.test(s);
    if (wantsCount) {
      return { columns: [{ name: 'c', table: 'TABLES' }], rows: [[String(names.length)]] };
    }
    const cols = /SELECT\s+([\s\S]*?)\s+FROM/i.exec(s)[1];
    const wanted = cols
      .split(',')
      .map((c) => c.trim().replace(/^.*\./, '').replace(/`/g, '').split(/\s+as\s+/i)[0].toUpperCase());
    const rows = names.map((n) =>
      wanted.map((w) => {
        if (w === 'TABLE_NAME' || w === '*') return n;
        if (w === 'TABLE_SCHEMA') return SCHEMA_NAME;
        if (w === 'TABLE_ROWS') {
          try {
            return String(db.prepare(`SELECT COUNT(*) c FROM "${n}"`).get().c);
          } catch {
            return '0';
          }
        }
        if (w === 'ENGINE') return 'InnoDB';
        if (w === 'TABLE_COLLATION') return 'utf8mb4_unicode_ci';
        return null;
      })
    );
    return { columns: wanted.map((w) => ({ name: w === '*' ? 'TABLE_NAME' : w, table: 'TABLES' })), rows };
  }

  // -- information_schema.COLUMNS ------------------------------------------
  if (/FROM\s+information_schema\.COLUMNS/i.test(s)) {
    const tm = /TABLE_NAME\s*=\s*'([^']*)'/i.exec(s);
    const targets = tm ? [tm[1]] : listTables();
    const cols = /SELECT\s+([\s\S]*?)\s+FROM/i.exec(s)[1];
    const wanted = cols
      .split(',')
      .map((c) => c.trim().replace(/^.*\./, '').replace(/`/g, '').split(/\s+as\s+/i)[0].toUpperCase());
    const rows = [];
    for (const t of targets) {
      for (const c of tableColumns(t)) {
        rows.push(
          wanted.map((w) => {
            if (w === 'COLUMN_NAME') return c.name;
            if (w === 'COLUMN_TYPE' || w === 'DATA_TYPE') return mysqlTypeOf(t, c);
            if (w === 'TABLE_NAME') return t;
            if (w === 'TABLE_SCHEMA') return SCHEMA_NAME;
            if (w === 'IS_NULLABLE') return c.notnull ? 'NO' : 'YES';
            if (w === 'COLUMN_DEFAULT') return c.dflt_value;
            if (w === 'COLUMN_KEY') return c.pk ? 'PRI' : '';
            return null;
          })
        );
      }
    }
    return { columns: wanted.map((w) => ({ name: w, table: 'COLUMNS' })), rows };
  }

  // -- information_schema.STATISTICS ---------------------------------------
  if (/FROM\s+information_schema\.STATISTICS/i.test(s)) {
    const tm = /TABLE_NAME\s*=\s*'([^']*)'/i.exec(s);
    const targets = tm ? [tm[1]] : listTables();
    const rows = [];
    for (const t of targets) for (const i of tableIndexes(t)) rows.push([i.name]);
    return { columns: [{ name: 'INDEX_NAME', table: 'STATISTICS' }], rows };
  }

  // -- information_schema.TABLE_CONSTRAINTS --------------------------------
  if (/FROM\s+information_schema\.(TABLE_CONSTRAINTS|KEY_COLUMN_USAGE|REFERENTIAL_CONSTRAINTS)/i.test(s)) {
    const tm = /TABLE_NAME\s*=\s*'([^']*)'/i.exec(s);
    const targets = tm ? [tm[1]] : listTables();
    const rows = [];
    for (const t of targets) {
      const fks = tableForeignKeys(t);
      fks.forEach((fk, n) => rows.push([`fk_${t}_${n + 1}`, t, 'FOREIGN KEY', fk.from, fk.table, fk.to]));
      // FKs recorded from ALTER TABLE (SQLite cannot add them post-hoc).
      for (const d of registry.deferredForeignKeys.filter((x) => x.table === t)) {
        rows.push([`fk_${t}_${d.columns.replace(/\W+/g, '')}`, t, 'FOREIGN KEY', d.columns, d.refTable, d.refColumns]);
      }
    }
    const cols = /SELECT\s+([\s\S]*?)\s+FROM/i.exec(s)[1];
    const wanted = cols
      .split(',')
      .map((c) => c.trim().replace(/^.*\./, '').replace(/`/g, '').split(/\s+as\s+/i)[0].toUpperCase());
    const pick = (row, w) => {
      if (w === 'CONSTRAINT_NAME') return row[0];
      if (w === 'TABLE_NAME') return row[1];
      if (w === 'CONSTRAINT_TYPE') return row[2];
      if (w === 'COLUMN_NAME') return row[3];
      if (w === 'REFERENCED_TABLE_NAME') return row[4];
      if (w === 'REFERENCED_COLUMN_NAME') return row[5];
      return null;
    };
    return {
      columns: wanted.map((w) => ({ name: w, table: 'TABLE_CONSTRAINTS' })),
      rows: rows.map((r) => wanted.map((w) => pick(r, w))),
    };
  }

  // -- SELECT DATABASE()/VERSION()/CONNECTION_ID() -------------------------
  if (/^SELECT\s+DATABASE\s*\(\s*\)/i.test(s)) {
    return { columns: [{ name: 'DATABASE()', table: '' }], rows: [[session.database || SCHEMA_NAME]] };
  }
  if (/^SELECT\s+(?:@@)?VERSION(?:\s*\(\s*\))?/i.test(s) && !/FROM/i.test(s)) {
    return { columns: [{ name: 'VERSION()', table: '' }], rows: [['8.0.36-marvysocials-devdb']] };
  }
  if (/^SELECT\s+CONNECTION_ID\s*\(\s*\)/i.test(s)) {
    return { columns: [{ name: 'CONNECTION_ID()', table: '' }], rows: [[String(session.connectionId)]] };
  }
  if (/^SELECT\s+@@/i.test(s)) {
    const names = s.replace(/^SELECT\s+/i, '').split(',').map((x) => x.trim());
    return {
      columns: names.map((n) => ({ name: n, table: '' })),
      rows: [names.map((n) => (/sql_mode/i.test(n) ? 'STRICT_ALL_TABLES' : /version/i.test(n) ? '8.0.36' : '1'))],
    };
  }

  void upper;
  return null;
}

// ---------------------------------------------------------------------------
// Query execution
// ---------------------------------------------------------------------------
function isSelectLike(sql) {
  return /^\s*(SELECT|WITH|PRAGMA|EXPLAIN|VALUES|SHOW)/i.test(sql);
}

/** Track single-column unique indexes so ON DUPLICATE KEY can find a target. */
function refreshUniqueRegistry() {
  for (const t of listTables()) {
    const cols = [];
    for (const i of tableIndexes(t)) {
      if (!i.unique) continue;
      const info = db.prepare(`PRAGMA index_info("${i.name}")`).all();
      if (info.length === 1) cols.push(info[0].name);
    }
    registry.uniqueColumns[t] = cols;
  }
}

/**
 * The MySQL column type advertised for a value.
 *
 * A non-integer JS number MUST NOT be advertised as LONGLONG. SQLite returns
 * SUM() over a DECIMAL-as-TEXT column as a float, and this function used to
 * label every number LONGLONG — so the client cast the perfectly good text
 * "16215.60500004" to an integer and handed the application 16215. Every money
 * aggregate read through the dev database silently lost its decimals: revenue
 * cards, refund totals, wallet sums. Real MySQL returns those as DECIMAL, so
 * the bug existed only here — which is worse, because it makes correct code
 * look wrong (and could make wrong code look right).
 */
function columnTypeFor(value) {
  if (typeof value === 'bigint') return proto.TYPE_LONGLONG;
  if (typeof value === 'number') {
    return Number.isInteger(value) ? proto.TYPE_LONGLONG : proto.TYPE_NEWDECIMAL;
  }
  if (Buffer.isBuffer(value)) return proto.TYPE_BLOB;
  return proto.TYPE_VAR_STRING;
}

/**
 * Execute one COM_QUERY payload.
 *
 * MySQL allows several statements in a single query packet (CodeIgniter's
 * mysqli init command does exactly that), and each one produces its own
 * result. Returns an array of results so the caller can frame them as a
 * multi-resultset response.
 *
 * @returns {Array<{type:'ok',affectedRows:number,insertId:number}|{type:'resultset',columns:Array,rows:Array}>}
 */
function execute(sqlText, session) {
  const admin = handleAdminQuery(sqlText, session);
  if (admin) return [{ type: 'resultset', ...admin }];

  const results = [];
  for (const chunk of splitStatements(sqlText)) {
    const adminChunk = handleAdminQuery(chunk, session);
    if (adminChunk) {
      results.push({ type: 'resultset', ...adminChunk });
      continue;
    }

    const statements = translate(chunk, registry);
    let last = { type: 'ok', affectedRows: 0, insertId: 0 };

    for (const stmt of statements) {
      const sql = stmt.trim();
      if (!sql || sql === NOOP) continue;
      log('SQL>', sql.slice(0, 400));

      if (isSelectLike(sql)) {
        const prepared = db.prepare(sql);
        const rows = prepared.all();
        const names = prepared.columns
          ? prepared.columns().map((c) => c.name ?? c.column ?? '?')
          : rows.length
          ? Object.keys(rows[0])
          : [];
        const columns = names.map((name) => {
          const sample = rows.find((r) => r[name] !== null && r[name] !== undefined);
          return {
            name,
            table: '',
            type: sample ? columnTypeFor(sample[name]) : proto.TYPE_VAR_STRING,
          };
        });
        // A zero-column result set is malformed on the wire; answer OK instead.
        last = columns.length
          ? {
              type: 'resultset',
              columns,
              rows: rows.map((r) => names.map((n) => (r[n] === null || r[n] === undefined ? null : r[n]))),
            }
          : { type: 'ok', affectedRows: 0, insertId: 0 };
        continue;
      }

      // Transaction bookkeeping: SQLite has one connection here, so the
      // session flag mirrors the file's real state.
      if (/^\s*BEGIN\b/i.test(sql)) {
        if (session.inTransaction) continue; // nested BEGIN — MySQL ignores it
        db.exec('BEGIN');
        session.inTransaction = true;
        last = { type: 'ok', affectedRows: 0, insertId: 0 };
        continue;
      }
      if (/^\s*(COMMIT|ROLLBACK)\b/i.test(sql)) {
        if (session.inTransaction) {
          db.exec(/ROLLBACK/i.test(sql) ? 'ROLLBACK' : 'COMMIT');
          session.inTransaction = false;
        }
        last = { type: 'ok', affectedRows: 0, insertId: 0 };
        continue;
      }

      const info = db.prepare(sql).run();
      if (/^\s*(CREATE|ALTER|DROP)/i.test(sql)) refreshUniqueRegistry();
      last = {
        type: 'ok',
        affectedRows: Number(info.changes ?? 0),
        insertId: Number(info.lastInsertRowid ?? 0),
      };
    }

    results.push(last);
  }

  return results.length ? results : [{ type: 'ok', affectedRows: 0, insertId: 0 }];
}

/** Split a query payload into individual statements, respecting quoting. */
function splitStatements(sql) {
  const out = [];
  let cur = '';
  let i = 0;
  while (i < sql.length) {
    const ch = sql[i];
    if (ch === "'" || ch === '"' || ch === '`') {
      const q = ch;
      cur += ch;
      i++;
      while (i < sql.length) {
        if (sql[i] === '\\' && q !== '`') {
          cur += sql[i] + (sql[i + 1] || '');
          i += 2;
          continue;
        }
        cur += sql[i];
        if (sql[i] === q) {
          i++;
          break;
        }
        i++;
      }
      continue;
    }
    if (ch === ';') {
      if (cur.trim()) out.push(cur.trim());
      cur = '';
      i++;
      continue;
    }
    cur += ch;
    i++;
  }
  if (cur.trim()) out.push(cur.trim());
  return out;
}

// ---------------------------------------------------------------------------
// Connection handling
// ---------------------------------------------------------------------------
let connectionSeq = 0;

function createConnection(socket) {
  const session = { connectionId: ++connectionSeq, database: null, authenticated: false, inTransaction: false };
  let buffer = Buffer.alloc(0);
  let seq = 0;

  // Responses are accumulated and flushed as a single write. PHP's mysqlnd
  // (notably under the WASM socket emulation used for local testing) does not
  // tolerate a result set arriving as many small TCP segments, so every
  // command answers with one contiguous buffer.
  let outbox = [];
  const send = (payload) => {
    outbox.push(proto.packet(payload, seq++));
  };
  const flush = () => {
    if (!outbox.length) return;
    const buf = Buffer.concat(outbox);
    outbox = [];
    socket.write(buf);
  };
  const sendOk = (a = 0, i = 0) =>
    send(proto.okPayload(a, i, 0, '', { inTransaction: session.inTransaction }));
  const sendErr = (code, msg, state) => send(proto.errPayload(code, msg, state));

  const sendResultSet = (columns, rows, more = false) => {
    const w = new proto.Writer();
    w.lenencInt(columns.length);
    send(w.build());
    for (const c of columns) send(proto.columnDefPayload(c, session.database || SCHEMA_NAME));
    send(proto.eofPayload());
    for (const r of rows) send(proto.rowPayload(r));
    send(proto.eofPayload(0, { moreResults: more, inTransaction: session.inTransaction }));
  };

  /** Frame every result of a (possibly multi-statement) query. */
  const sendResults = (results) => {
    results.forEach((result, idx) => {
      const more = idx < results.length - 1;
      if (result.type === 'resultset') sendResultSet(result.columns, result.rows, more);
      else
        send(
          proto.okPayload(result.affectedRows, result.insertId, 0, '', {
            moreResults: more,
            inTransaction: session.inTransaction,
          })
        );
    });
  };

  // Greet the client.
  const salt = crypto.randomBytes(20);
  send(proto.handshakePayload(session.connectionId, salt));
  flush();

  socket.on('data', (chunk) => {
    buffer = Buffer.concat([buffer, chunk]);

    while (buffer.length >= 4) {
      const len = buffer.readUIntLE(0, 3);
      if (buffer.length < 4 + len) break;
      const packetSeq = buffer.readUInt8(3);
      const payload = buffer.subarray(4, 4 + len);
      buffer = buffer.subarray(4 + len);
      seq = packetSeq + 1;

      try {
        if (!session.authenticated) {
          // Any credentials are accepted: this server is bound to loopback and
          // exists purely so the app can be exercised locally.
          const hs = proto.parseHandshakeResponse(payload);
          session.database = hs.database || SCHEMA_NAME;
          session.authenticated = true;
          log('auth', hs.username, '→', session.database);
          sendOk();
          flush();
          continue;
        }

        const command = payload.readUInt8(0);
        const body = payload.subarray(1);

        switch (command) {
          case 0x01: // COM_QUIT
            flush();
            socket.end();
            return;

          case 0x02: // COM_INIT_DB
            session.database = body.toString('utf8');
            sendOk();
            break;

          case 0x03: { // COM_QUERY
            const sqlText = body.toString('utf8');
            log('QUERY', sqlText.slice(0, 300));
            // CI3 sends multi-statement init commands separated by ';'
            sendResults(execute(sqlText, session));
            break;
          }

          case 0x0e: // COM_PING
            sendOk();
            break;

          case 0x04: { // COM_FIELD_LIST
            const table = body.toString('utf8').split('\0')[0];
            for (const c of tableColumns(table)) {
              send(proto.columnDefPayload({ name: c.name, table }, session.database || SCHEMA_NAME));
            }
            send(proto.eofPayload());
            break;
          }

          case 0x0d: // COM_DEBUG
          case 0x07: // COM_REFRESH
            sendOk();
            break;

          default:
            sendErr(1047, `Unsupported command 0x${command.toString(16)} in dev database`);
        }
      } catch (err) {
        const msg = String(err && err.message ? err.message : err);
        log('ERROR', msg);
        outbox = outbox.filter(() => false);
        seq = packetSeq + 1;
        // Map SQLite errors onto the MySQL error numbers the app checks for.
        let code = 1064;
        let state = '42000';
        if (/UNIQUE constraint failed/i.test(msg)) {
          code = 1062;
          state = '23000';
        } else if (/FOREIGN KEY constraint failed/i.test(msg)) {
          code = 1452;
          state = '23000';
        } else if (/NOT NULL constraint failed/i.test(msg)) {
          code = 1048;
          state = '23000';
        } else if (/no such table/i.test(msg)) {
          code = 1146;
          state = '42S02';
        } else if (/no such column/i.test(msg)) {
          code = 1054;
          state = '42S22';
        } else if (/already exists/i.test(msg)) {
          code = 1050;
          state = '42S01';
        } else if (/database is locked/i.test(msg)) {
          code = 1205;
          state = 'HY000';
        }
        sendErr(code, msg, state);
      }
      flush();
    }
  });

  socket.on('error', (e) => log('socket error', e.message));

  // A client that vanishes mid-transaction must not leave the SQLite file
  // locked for the next connection.
  socket.on('close', () => {
    if (session.inTransaction) {
      try {
        db.exec('ROLLBACK');
      } catch {
        /* already resolved */
      }
      session.inTransaction = false;
    }
  });
}

const server = net.createServer(createConnection);
server.listen(args.port, args.host, () => {
  refreshUniqueRegistry();
  console.log(`[devdb] MySQL-protocol dev server on ${args.host}:${args.port} → ${dbPath}`);
});

process.on('SIGTERM', () => {
  server.close();
  db.close();
  process.exit(0);
});
