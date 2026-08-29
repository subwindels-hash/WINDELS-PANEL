/**
 * Private support attachments, end to end (module 17).
 *
 * What this proves that no unit test can: the bytes a customer uploads to a
 * support ticket are not reachable by URL. Before this module they were —
 * `assets/uploads/<32 hex>.png`, served by the web server, no session, no
 * ownership check. Unguessable is not authorised: a forwarded email, a referer
 * header, a shared screen or a departed staff member's browser history handed
 * the file over for ever, and support attachments are where customers put bank
 * statements and identity documents.
 *
 * The stages below sign in as four different parties against the *same*
 * attachment and assert who gets bytes:
 *
 *   owner   → 200 and the exact bytes
 *   stranger (signed in) → 404, never 403 (a 403 confirms it exists)
 *   signed out → the login page, never the file
 *   staff   → 200 (that is the queue's job)
 *
 * and the same again for an attachment on an internal staff note, which the
 * customer must never see even though it hangs off their own ticket.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/attachment_check.mjs --admin-password '…'
 */
import path from 'node:path';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ADMIN_PASSWORD = arg('--admin-password', process.env.DEMO_PASSWORD || null);
if (!ADMIN_PASSWORD) {
  console.error('Pass --admin-password or set DEMO_PASSWORD in .env (the seeder prints the demo password once).');
  process.exit(2);
}
const CUSTOMER_PASSWORD = arg('--customer-password', ADMIN_PASSWORD);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}
function withDb(fn) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync(path.resolve(ROOT, DB_PATH));
  try { return fn(db); } finally { db.close(); }
}

const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64');

/**
 * Fetch a URL as binary with one client's cookies. `Client.raw()` decodes the
 * body as text, which mangles PNG bytes — a byte-for-byte assertion has to see
 * the real buffer.
 */
async function fetchBytes(client, pathname) {
  const res = await fetch(client.base + pathname, {
    headers: { cookie: client.cookieHeader() },
    redirect: 'manual',
  });
  return { status: res.status, headers: res.headers, body: Buffer.from(await res.arrayBuffer()) };
}

async function postMultipart(client, url, fields, files, fromHtml) {
  const boundary = '----marvy' + Date.now() + Math.random().toString(16).slice(2);
  const token = client.csrfFrom(fromHtml);
  const parts = [];
  const push = (s) => parts.push(Buffer.from(s, 'utf8'));
  const all = Object.assign({}, fields);
  if (token) all[token.name] = token.value;
  for (const [k, v] of Object.entries(all)) {
    push(`--${boundary}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`);
  }
  for (const f of files) {
    push(`--${boundary}\r\nContent-Disposition: form-data; name="${f.field}"; filename="${f.name}"\r\n`
       + `Content-Type: ${f.type}\r\n\r\n`);
    parts.push(f.data);
    push('\r\n');
  }
  push(`--${boundary}--\r\n`);
  return client.raw(url, {
    method: 'POST',
    headers: { 'content-type': `multipart/form-data; boundary=${boundary}` },
    body: Buffer.concat(parts),
  });
}

/* ===================== 1 · the owner uploads evidence ==================== */

console.log('\n── A customer attaches a document to a ticket');

const owner = new Client(BASE);
await owner.get('/login');
const ownerLogin = await owner.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('the ticket owner signs in', /\/dashboard/.test(ownerLogin.url), ownerLogin.url);
if (!/\/dashboard/.test(ownerLogin.url)) {
  console.error('\n  cannot establish the owner session — aborting rather than reporting false passes\n');
  process.exit(2);
}

const ticketsPage = await owner.get('/dashboard/tickets');
const subject = 'Private attachment check ' + Date.now();
await postMultipart(owner, '/dashboard/tickets/create',
  { subject, message: 'My bank statement is attached.', category: 'PAYMENT' },
  [{ field: 'attachments[]', name: 'bank-statement.png', type: 'image/png', data: PNG }],
  ticketsPage.text);

const ticket = withDb((db) => db.prepare(
  `SELECT * FROM tickets WHERE subject = ? ORDER BY id DESC LIMIT 1`).get(subject));
check('the ticket was created', !!ticket, subject);
if (!ticket) process.exit(2);

const row = withDb((db) => db.prepare(
  `SELECT a.* FROM ticket_attachments a
     JOIN ticket_messages m ON m.id = a.ticket_message_id
    WHERE m.ticket_id = ? ORDER BY a.id DESC LIMIT 1`).get(ticket.id));
check('the document is recorded against the message', !!row);

const media = withDb((db) => db.prepare(
  `SELECT * FROM media WHERE purpose = 'ticket' ORDER BY id DESC LIMIT 1`).get());
check('and a media row exists for it', !!media);

/* ================== 2 · where the bytes actually live =================== */

console.log('\n── The file is not in the document root at all');

check('the stored key says private storage',
  !!media && String(media.storage_key).startsWith('storage:ticket_attachments/'),
  media ? media.storage_key : 'no media row');

const fileName = media ? String(media.storage_key).split('/').pop() : '';
check('the bytes are on disk outside assets/',
  !!fileName && fs.existsSync(path.join(ROOT, 'storage/ticket_attachments', fileName)),
  fileName);
check('and nothing was left in the public upload directory',
  !!fileName && !fs.existsSync(path.join(ROOT, 'assets/uploads', fileName)));

// The old, direct URL shape must be a dead end even if someone remembers it.
const legacy = await owner.raw('/assets/uploads/' + fileName, { redirect: 'manual' });
check('the old public URL shape returns nothing', legacy.status === 404,
  `status=${legacy.status}`);

const url = new URL(String(media.url)).pathname;
check('the recorded link is the authorising route',
  /^\/support\/attachment\/[A-Z0-9]+$/.test(url), url);

const thread = await owner.get('/dashboard/tickets/' + ticket.public_id);
check('and that is what the thread renders', thread.text.includes(url), url);
check('the thread never renders a raw upload path',
  !/assets\/uploads\/[0-9a-f]{32}/.test(thread.text));

/* ======================= 3 · who gets the bytes ========================= */

console.log('\n── Four parties ask for the same file');

const asOwner = await fetchBytes(owner, url);
check('the owner gets 200', asOwner.status === 200, `status=${asOwner.status}`);
check('and the bytes are byte-for-byte the file they uploaded',
  asOwner.body.equals(PNG), `${asOwner.body.length} vs ${PNG.length}`);
check('served as a download, never inline',
  /attachment;/.test(asOwner.headers.get('content-disposition') || ''),
  asOwner.headers.get('content-disposition'));
check('with nosniff, so an HTML-ish payload cannot run on our origin',
  (asOwner.headers.get('x-content-type-options') || '') === 'nosniff');
check('and no-store, so a shared proxy cannot hand it to the next customer',
  /no-store/.test(asOwner.headers.get('cache-control') || ''),
  asOwner.headers.get('cache-control'));

const anon = new Client(BASE);
const asAnon = await anon.raw(url, { redirect: 'manual' });
check('a signed-out stranger with the link gets no bytes',
  asAnon.status !== 200 && !asAnon.text.includes('PNG'),
  `status=${asAnon.status}`);

const stranger = new Client(BASE);
const regPage = await stranger.get('/register');
const reg = await stranger.postForm('/register', {
  username: 'att' + String(Date.now()).slice(-8),
  email: `att${Date.now()}@x.test`, password: 'Str0ng!probe1',
  password_confirm: 'Str0ng!probe1', confirm_password: 'Str0ng!probe1', terms: '1',
}, { fromHtml: regPage.text });
check('a second customer can register', /\/dashboard/.test(reg.url), reg.url);
if (!/\/dashboard/.test(reg.url)) {
  console.error('\n  the stranger session could not be established — aborting\n');
  process.exit(2);
}

const asStranger = await stranger.raw(url, { redirect: 'manual' });
check('a signed-in stranger with the link gets 404', asStranger.status === 404,
  `status=${asStranger.status}`);
check('and 404, not 403 — the endpoint never confirms the file exists',
  asStranger.status !== 403);

const admin = new Client(BASE);
await admin.get('/admin/login');
await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
const asStaff = await admin.raw(url, { redirect: 'manual' });
check('support staff get 200 — reading the evidence is the queue’s job',
  asStaff.status === 200, `status=${asStaff.status}`);

/* ==================== 4 · internal notes stay internal =================== */

console.log('\n── An attachment on an internal note is staff-only');

const adminTicket = await admin.get('/admin/tickets/' + ticket.public_id);
await postMultipart(admin, `/admin/tickets/${ticket.public_id}/reply`,
  // `internal` is the checkbox name on the staff reply form.
  { message: 'Flagging this account for review.', internal: '1' },
  [{ field: 'attachments[]', name: 'internal-note.png', type: 'image/png', data: PNG }],
  adminTicket.text);

const noteMedia = withDb((db) => db.prepare(
  `SELECT m.* FROM media m ORDER BY m.id DESC LIMIT 1`).get());
const noteRow = withDb((db) => db.prepare(
  `SELECT a.file_url, msg.is_internal_note FROM ticket_attachments a
     JOIN ticket_messages msg ON msg.id = a.ticket_message_id
    WHERE msg.ticket_id = ? ORDER BY a.id DESC LIMIT 1`).get(ticket.id));
check('the note attachment was stored on an internal message',
  !!noteRow && Number(noteRow.is_internal_note) === 1, JSON.stringify(noteRow));

if (noteRow && Number(noteRow.is_internal_note) === 1) {
  const noteUrl = new URL(String(noteRow.file_url)).pathname;
  const staffSees = await admin.raw(noteUrl, { redirect: 'manual' });
  check('staff can read it', staffSees.status === 200, `status=${staffSees.status}`);
  const ownerSees = await owner.raw(noteUrl, { redirect: 'manual' });
  check('the customer whose ticket it is cannot', ownerSees.status === 404,
    `status=${ownerSees.status}`);
  const ownerThread = await owner.get('/dashboard/tickets/' + ticket.public_id);
  check('and the link is not even rendered to them', !ownerThread.text.includes(noteUrl));
}

/* ============================== summary ================================= */

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailed:');
  for (const f of failed) console.log(`  ✗ ${f.label}${f.detail ? ` — ${f.detail}` : ''}`);
  process.exit(1);
}
