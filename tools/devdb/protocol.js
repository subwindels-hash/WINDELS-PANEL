'use strict';
/**
 * Minimal MySQL client/server wire-protocol codec (protocol version 10).
 *
 * DEV TOOLING ONLY — see tools/devdb/README.md. This exists so the panel can be
 * booted and exercised end-to-end on a machine with no MySQL server available.
 * It speaks enough of the protocol for the mysqli driver CodeIgniter uses:
 * handshake, auth (accept-all), COM_QUERY, COM_PING, COM_INIT_DB, COM_QUIT,
 * COM_FIELD_LIST and text-protocol result sets. It is never used in production
 * and is not reachable from the application code.
 */

// ---------------------------------------------------------------------------
// Capability flags
// ---------------------------------------------------------------------------
const CLIENT_LONG_PASSWORD = 0x00000001;
const CLIENT_FOUND_ROWS = 0x00000002;
const CLIENT_LONG_FLAG = 0x00000004;
const CLIENT_CONNECT_WITH_DB = 0x00000008;
const CLIENT_LOCAL_FILES = 0x00000080;
const CLIENT_PROTOCOL_41 = 0x00000200;
const CLIENT_TRANSACTIONS = 0x00002000;
const CLIENT_SECURE_CONNECTION = 0x00008000;
const CLIENT_MULTI_STATEMENTS = 0x00010000;
const CLIENT_MULTI_RESULTS = 0x00020000;
const CLIENT_PLUGIN_AUTH = 0x00080000;
const CLIENT_CONNECT_ATTRS = 0x00100000;
const CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;

const SERVER_STATUS_AUTOCOMMIT = 0x0002;

// Column types (text protocol — everything is transmitted as a string).
const TYPE_VAR_STRING = 0xfd;
const TYPE_LONGLONG = 0x08;
const TYPE_NEWDECIMAL = 0xf6;
const TYPE_DOUBLE = 0x05;
const TYPE_BLOB = 0xfc;

const NOT_NULL_FLAG = 0x0001;
const PRI_KEY_FLAG = 0x0002;
const UNSIGNED_FLAG = 0x0020;
const BINARY_FLAG = 0x0080;

const SERVER_CAPABILITIES =
  CLIENT_LONG_PASSWORD |
  CLIENT_FOUND_ROWS |
  CLIENT_LONG_FLAG |
  CLIENT_CONNECT_WITH_DB |
  CLIENT_PROTOCOL_41 |
  CLIENT_TRANSACTIONS |
  CLIENT_SECURE_CONNECTION |
  CLIENT_MULTI_STATEMENTS |
  CLIENT_MULTI_RESULTS |
  CLIENT_PLUGIN_AUTH |
  CLIENT_CONNECT_ATTRS |
  CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA;

// ---------------------------------------------------------------------------
// Writer
// ---------------------------------------------------------------------------
class Writer {
  constructor() {
    this.chunks = [];
  }
  int1(v) {
    const b = Buffer.alloc(1);
    b.writeUInt8(v & 0xff, 0);
    this.chunks.push(b);
    return this;
  }
  int2(v) {
    const b = Buffer.alloc(2);
    b.writeUInt16LE(v & 0xffff, 0);
    this.chunks.push(b);
    return this;
  }
  int3(v) {
    const b = Buffer.alloc(3);
    b.writeUIntLE(v & 0xffffff, 0, 3);
    this.chunks.push(b);
    return this;
  }
  int4(v) {
    const b = Buffer.alloc(4);
    b.writeUInt32LE(v >>> 0, 0);
    this.chunks.push(b);
    return this;
  }
  int6(v) {
    const b = Buffer.alloc(6);
    b.writeUIntLE(Number(v), 0, 6);
    this.chunks.push(b);
    return this;
  }
  int8(v) {
    const b = Buffer.alloc(8);
    b.writeBigUInt64LE(BigInt(v));
    this.chunks.push(b);
    return this;
  }
  lenencInt(v) {
    const n = Number(v);
    if (n < 0xfb) return this.int1(n);
    if (n < 0x10000) return this.int1(0xfc).int2(n);
    if (n < 0x1000000) return this.int1(0xfd).int3(n);
    return this.int1(0xfe).int8(n);
  }
  bytes(buf) {
    this.chunks.push(Buffer.isBuffer(buf) ? buf : Buffer.from(buf));
    return this;
  }
  str(s) {
    return this.bytes(Buffer.from(String(s), 'utf8'));
  }
  nulStr(s) {
    return this.str(s).int1(0);
  }
  lenencStr(s) {
    const b = Buffer.isBuffer(s) ? s : Buffer.from(String(s), 'utf8');
    return this.lenencInt(b.length).bytes(b);
  }
  fill(n, byte = 0) {
    return this.bytes(Buffer.alloc(n, byte));
  }
  build() {
    return Buffer.concat(this.chunks);
  }
}

// ---------------------------------------------------------------------------
// Reader
// ---------------------------------------------------------------------------
class Reader {
  constructor(buf) {
    this.buf = buf;
    this.pos = 0;
  }
  get remaining() {
    return this.buf.length - this.pos;
  }
  int1() {
    return this.buf.readUInt8(this.pos++);
  }
  int2() {
    const v = this.buf.readUInt16LE(this.pos);
    this.pos += 2;
    return v;
  }
  int3() {
    const v = this.buf.readUIntLE(this.pos, 3);
    this.pos += 3;
    return v;
  }
  int4() {
    const v = this.buf.readUInt32LE(this.pos);
    this.pos += 4;
    return v;
  }
  skip(n) {
    this.pos += n;
    return this;
  }
  bytes(n) {
    const b = this.buf.subarray(this.pos, this.pos + n);
    this.pos += n;
    return b;
  }
  nulStr() {
    const end = this.buf.indexOf(0, this.pos);
    const stop = end === -1 ? this.buf.length : end;
    const s = this.buf.toString('utf8', this.pos, stop);
    this.pos = stop + 1;
    return s;
  }
  lenencInt() {
    const first = this.int1();
    if (first < 0xfb) return first;
    if (first === 0xfc) return this.int2();
    if (first === 0xfd) return this.int3();
    if (first === 0xfe) {
      const v = this.buf.readBigUInt64LE(this.pos);
      this.pos += 8;
      return Number(v);
    }
    return 0;
  }
  restStr() {
    const s = this.buf.toString('utf8', this.pos);
    this.pos = this.buf.length;
    return s;
  }
}

// ---------------------------------------------------------------------------
// Packet builders
// ---------------------------------------------------------------------------

/** Wrap a payload in the 4-byte packet header. */
function packet(payload, seq) {
  const head = Buffer.alloc(4);
  head.writeUIntLE(payload.length, 0, 3);
  head.writeUInt8(seq & 0xff, 3);
  return Buffer.concat([head, payload]);
}

function handshakePayload(connectionId, salt) {
  const w = new Writer();
  w.int1(10);
  w.nulStr('8.0.36-marvysocials-devdb');
  w.int4(connectionId);
  w.bytes(salt.subarray(0, 8));
  w.int1(0);
  w.int2(SERVER_CAPABILITIES & 0xffff);
  w.int1(0x2d); // utf8mb4_general_ci
  w.int2(SERVER_STATUS_AUTOCOMMIT);
  w.int2((SERVER_CAPABILITIES >> 16) & 0xffff);
  w.int1(21); // auth plugin data length
  w.fill(10);
  w.bytes(salt.subarray(8, 20));
  w.int1(0);
  w.nulStr('mysql_native_password');
  return w.build();
}

const SERVER_MORE_RESULTS_EXISTS = 0x0008;
const SERVER_STATUS_IN_TRANS = 0x0001;

/**
 * Build the status flags word.
 *
 * SERVER_STATUS_IN_TRANS is not decorative: PDO's mysql driver tracks whether
 * a transaction is open purely from this flag, and PDO::commit() throws
 * "There is no active transaction" if the server never sets it.
 */
function statusFlags({ inTransaction = false, moreResults = false, autocommit = true } = {}) {
  return (
    (autocommit ? SERVER_STATUS_AUTOCOMMIT : 0) |
    (inTransaction ? SERVER_STATUS_IN_TRANS : 0) |
    (moreResults ? SERVER_MORE_RESULTS_EXISTS : 0)
  );
}

function okPayload(affectedRows = 0, lastInsertId = 0, warnings = 0, info = '', status = {}) {
  const w = new Writer();
  w.int1(0x00);
  w.lenencInt(affectedRows);
  w.lenencInt(lastInsertId);
  w.int2(statusFlags(status));
  w.int2(warnings);
  if (info) w.str(info);
  return w.build();
}

function errPayload(code, message, sqlState = 'HY000') {
  const w = new Writer();
  w.int1(0xff);
  w.int2(code);
  w.str('#');
  w.str(sqlState.padEnd(5, '0').slice(0, 5));
  w.str(message);
  return w.build();
}

function eofPayload(warnings = 0, status = {}) {
  const w = new Writer();
  w.int1(0xfe);
  w.int2(warnings);
  w.int2(statusFlags(status));
  return w.build();
}

function columnDefPayload(col, schema) {
  const w = new Writer();
  w.lenencStr('def');
  w.lenencStr(schema || '');
  w.lenencStr(col.table || '');
  w.lenencStr(col.orgTable || col.table || '');
  w.lenencStr(col.name);
  w.lenencStr(col.orgName || col.name);
  w.lenencInt(0x0c);
  w.int2(col.charset === 'binary' ? 63 : 45); // utf8mb4_general_ci
  w.int4(col.length || 1024);
  w.int1(col.type == null ? TYPE_VAR_STRING : col.type);
  w.int2(col.flags || 0);
  w.int1(col.decimals || 0);
  w.int2(0);
  return w.build();
}

/** Encode one text-protocol result row. */
function rowPayload(values) {
  const w = new Writer();
  for (const v of values) {
    if (v === null || v === undefined) {
      w.int1(0xfb);
    } else if (Buffer.isBuffer(v)) {
      w.lenencStr(v);
    } else {
      w.lenencStr(String(v));
    }
  }
  return w.build();
}

/** Parse the client's HandshakeResponse41. */
function parseHandshakeResponse(payload) {
  const r = new Reader(payload);
  const clientFlags = r.int4();
  r.int4(); // max packet size
  const charset = r.int1();
  r.skip(23);
  const username = r.nulStr();

  let authResponse = Buffer.alloc(0);
  if (clientFlags & CLIENT_PLUGIN_AUTH_LENENC_CLIENT_DATA) {
    authResponse = r.bytes(r.lenencInt());
  } else if (clientFlags & CLIENT_SECURE_CONNECTION) {
    authResponse = r.bytes(r.int1());
  } else {
    authResponse = Buffer.from(r.nulStr());
  }

  let database = null;
  if (clientFlags & CLIENT_CONNECT_WITH_DB && r.remaining > 0) database = r.nulStr();

  return { clientFlags, charset, username, authResponse, database };
}

module.exports = {
  Writer,
  Reader,
  packet,
  handshakePayload,
  okPayload,
  errPayload,
  eofPayload,
  columnDefPayload,
  rowPayload,
  parseHandshakeResponse,
  TYPE_VAR_STRING,
  TYPE_LONGLONG,
  TYPE_NEWDECIMAL,
  TYPE_DOUBLE,
  TYPE_BLOB,
  NOT_NULL_FLAG,
  PRI_KEY_FLAG,
  UNSIGNED_FLAG,
  BINARY_FLAG,
  SERVER_CAPABILITIES,
  SERVER_MORE_RESULTS_EXISTS,
  SERVER_STATUS_IN_TRANS,
  statusFlags,
};
