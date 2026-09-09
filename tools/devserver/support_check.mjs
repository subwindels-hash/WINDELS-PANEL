/**
 * Support end-to-end check: the assistant, the rate limiter, and tickets.
 *
 * Two things this proves that no unit test can:
 *
 *  1. **Using the help widget does not lock you out of signing in.** Every
 *     throttled feature writes to `login_attempts`, and the per-IP counter
 *     used to count them all together — so sixteen *answered* assistant
 *     questions tripped the login lockout (5 x 3) and the login page said
 *     "Too many failed attempts. Try again in 15 minutes." Nothing had failed.
 *     Behind an office or mobile NAT, one chatty visitor locked out everyone.
 *
 *  2. **A customer can attach a screenshot to a support ticket** — the table,
 *     the service parameter and the media purpose all shipped, and no
 *     controller ever passed a file, so the feature did not exist in the
 *     product.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/support_check.mjs --admin-password '…'
 */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ADMIN_PASSWORD = arg('--admin-password', 'Demo!cabcd50b');
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
const meta = (html, name) =>
  (new RegExp(`meta name="${name}" content="([^"]+)"`).exec(html) || [])[1];

/* ===================== 1 · the assistant and the limiter ================== */

console.log('\n── The assistant answers, and is capped on its own budget');

// A clean slate for this address: other checks sign in and out from here too.
withDb((db) => db.prepare(`DELETE FROM login_attempts`).run());

const visitor = new Client(BASE);
const assistantPage = await visitor.get('/assistant');
check('the assistant page loads', assistantPage.status === 200);
const token = meta(assistantPage.text, 'csrf-token');

async function ask(message) {
  return visitor.raw('/assistant/chat', {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify({ message }),
  });
}

const first = await ask('what services do you sell?');
let body = {};
try { body = JSON.parse(first.text); } catch { /* reported below */ }
check('it answers a question', first.status === 200 && body.success === true,
  first.text.slice(0, 120));
check('the answer is real text, not a stub',
  (body.data?.reply || '').length > 40, (body.data?.reply || '').slice(0, 80));

// Eighteen questions: past the old login threshold (15), inside the
// assistant's own cap (20).
for (let i = 0; i < 17; i++) await ask('and what about pricing?');
const scopes = withDb((db) => db.prepare(
  `SELECT scope, COUNT(*) n FROM login_attempts GROUP BY scope`).all());
check('the questions are recorded under the assistant’s own scope',
  scopes.some((r) => r.scope === 'assistant' && r.n >= 18), JSON.stringify(scopes));

console.log('\n── …and that must not touch anyone’s ability to sign in');
const afterChat = new Client(BASE);
const lp = await afterChat.get('/login');
const login = await afterChat.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD }, { fromHtml: lp.text });
check('a customer can still sign in after 18 assistant questions',
  /\/dashboard/.test(login.url), login.url);

console.log('\n── The assistant’s own cap still bites');
let capped = null;
for (let i = 0; i < 6; i++) capped = await ask('one more question');
check('the 20-per-hour cap returns 429', capped.status === 429, `status=${capped.status}`);
check('with an explanation rather than a blank error',
  /assistant/i.test(capped.text) && /minute/i.test(capped.text), capped.text.slice(0, 120));

// And the reverse direction: a locked-out login must not silence the assistant
// for the whole network.
withDb((db) => db.prepare(`DELETE FROM login_attempts`).run());
const bruteforcer = new Client(BASE);
for (let i = 0; i < 6; i++) {
  const p = await bruteforcer.get('/login');
  await bruteforcer.postForm('/login',
    { identifier: 'demo@marvy.local', password: 'wrong-password-' + i }, { fromHtml: p.text });
}
const stillAnswers = await ask('are you still there?');
check('failed sign-ins do not silence the assistant', stillAnswers.status === 200,
  `status=${stillAnswers.status}`);
const lockedOut = new Client(BASE);
const lp2 = await lockedOut.get('/login');
const denied = await lockedOut.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD }, { fromHtml: lp2.text });
check('but the sign-in lockout itself still works',
  /login/.test(denied.url) && /too many/i.test(denied.text), denied.url);

// Leave the table as we found it, or every later check inherits our lockout.
withDb((db) => db.prepare(`DELETE FROM login_attempts`).run());

/* ========================= 2 · ticket attachments ======================== */

console.log('\n── A customer can attach a screenshot to a ticket');

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);

// A real 1x1 PNG, so the pipeline's getimagesize() check is exercised.
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64');

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

const ticketsPage = await cust.get('/dashboard/tickets');
check('the new-ticket form accepts files',
  /enctype="multipart\/form-data"/.test(ticketsPage.text) && /name="attachments\[\]"/.test(ticketsPage.text));

const subject = 'Attachment check ' + Date.now();
await postMultipart(cust, '/dashboard/tickets/create',
  { subject, message: 'Here is what I see on my screen.', category: 'ORDER' },
  [{ field: 'attachments[]', name: 'screenshot.png', type: 'image/png', data: PNG }],
  ticketsPage.text);

const ticket = withDb((db) => db.prepare(
  `SELECT * FROM tickets WHERE subject = ? ORDER BY id DESC LIMIT 1`).get(subject));
check('the ticket was created', !!ticket, subject);

const attachment = ticket ? withDb((db) => db.prepare(
  `SELECT a.* FROM ticket_attachments a
     JOIN ticket_messages m ON m.id = a.ticket_message_id
    WHERE m.ticket_id = ?`).get(ticket.id)) : null;
check('the screenshot is stored against the message', !!attachment,
  attachment ? attachment.file_name : 'no attachment row');
check('with its real type and a non-zero size',
  attachment && attachment.mime_type === 'image/png' && Number(attachment.size) > 0,
  JSON.stringify(attachment));

const detail = ticket ? await cust.get('/dashboard/tickets/' + ticket.public_id) : { text: '' };
check('and the customer can see it in the thread',
  attachment && detail.text.includes(attachment.file_name));

console.log('\n── A .php "screenshot" is refused');
const shell = Buffer.from('<?php echo "pwned"; ?>', 'utf8');
const before = withDb((db) => db.prepare(`SELECT COUNT(*) n FROM media`).get().n);
const page2 = await cust.get('/dashboard/tickets/' + (ticket ? ticket.public_id : ''));
const reply = await postMultipart(cust, `/dashboard/tickets/${ticket.public_id}/reply`,
  { message: 'And here is another file.' },
  [{ field: 'attachments[]', name: 'shell.php', type: 'image/png', data: shell }],
  page2.text);
const afterUpload = withDb((db) => db.prepare(`SELECT COUNT(*) n FROM media`).get().n);
check('nothing was stored for the disguised script', afterUpload === before,
  `media rows ${before} -> ${afterUpload}`);
// postMultipart does not follow the redirect, so read the page the customer
// actually lands on — that is where the flash message is rendered.
const afterReply = await cust.get('/dashboard/tickets/' + ticket.public_id);
check('the reply itself still went through', /Reply sent/i.test(afterReply.text));
check('and the customer is told why the file was dropped, not silently losing it',
  /not accepted|Allowed:/i.test(afterReply.text),
  (/(alert alert-warning[^>]*>)([\s\S]{0,120})/.exec(afterReply.text) || [, '', 'no warning'])[2].trim());

console.log('\n── Staff can attach a file back');
const admin = new Client(BASE);
await admin.get('/admin/login');
await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
const adminTicket = await admin.get('/admin/tickets/' + ticket.public_id);
check('the staff reply form accepts files',
  /enctype="multipart\/form-data"/.test(adminTicket.text));
await postMultipart(admin, `/admin/tickets/${ticket.public_id}/reply`,
  { message: 'Here is the receipt.' },
  [{ field: 'attachments[]', name: 'receipt.png', type: 'image/png', data: PNG }],
  adminTicket.text);
const staffAttachment = withDb((db) => db.prepare(
  `SELECT a.file_name FROM ticket_attachments a
     JOIN ticket_messages m ON m.id = a.ticket_message_id
    WHERE m.ticket_id = ? AND m.is_staff = 1`).get(ticket.id));
check('the staff file is stored against the staff message', !!staffAttachment,
  JSON.stringify(staffAttachment));
const customerView = await cust.get('/dashboard/tickets/' + ticket.public_id);
check('and the customer can see it', staffAttachment && customerView.text.includes(staffAttachment.file_name));

/* ================= 3 · unanswerable questions become tickets ============= */

console.log('\n── A question outside the knowledge base becomes a ticket');

// A fresh customer each run: the 24-hour dedupe window means a reused
// account might already carry an open assistant ticket from a previous run.
const uname = 'escalate' + String(Date.now()).slice(-8);
const esc = new Client(BASE);
const regPage = await esc.get('/register');
const escReg = await esc.postForm('/register', {
  username: uname, email: uname + '@example.test',
  password: 'Escalate!1a', password_confirm: 'Escalate!1a',
  confirm_password: 'Escalate!1a', terms: '1',
}, { fromHtml: regPage.text });
check('a fresh customer can register', /\/dashboard/.test(escReg.url), escReg.url);

const escAssistant = await esc.get('/assistant');
const escToken = meta(escAssistant.text, 'csrf-token');
async function askEsc(message) {
  const r = await esc.raw('/assistant/chat', {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': escToken },
    body: JSON.stringify({ message }),
  });
  let b = {};
  try { b = JSON.parse(r.text); } catch { /* reported below */ }
  return { r, b };
}

const Q_OUT = 'What is the recommended vitamin D dosage for golden retrievers?';
const escFirst = await askEsc(Q_OUT);
check('the chat answers (and escalates) an unanswerable question',
  escFirst.r.status === 200 && escFirst.b.success === true, escFirst.r.text.slice(0, 120));
check('a ticket was created for the customer',
  escFirst.b.data?.ticket && escFirst.b.data?.ticket_action === 'created',
  JSON.stringify({ ticket: escFirst.b.data?.ticket, action: escFirst.b.data?.ticket_action }));
check('the reply names the ticket and links to it',
  typeof escFirst.b.data?.reply === 'string' && (escFirst.b.data?.reply || '').includes(escFirst.b.data?.ticket || '∅')
    && (escFirst.b.data?.links || []).some((l) => l.href.endsWith('/dashboard/tickets/' + escFirst.b.data?.ticket)));

const escUser = withDb((db) => db.prepare(`SELECT id FROM users WHERE username = ?`).get(uname));
const escTicket = escUser ? withDb((db) => db.prepare(
  `SELECT t.*, m.message FROM tickets t
     JOIN ticket_messages m ON m.ticket_id = t.id AND m.is_staff = 0
    WHERE t.user_id = ? ORDER BY t.id DESC LIMIT 1`).get(escUser.id)) : null;
check('the ticket is stored, tagged as assistant-originated',
  escTicket && escTicket.source === 'assistant' && escTicket.status === 'OPEN',
  JSON.stringify(escTicket ? { source: escTicket.source, status: escTicket.status } : null));
check('the customer\'s question is the ticket body',
  !!escTicket && (escTicket.message || '').includes('golden retrievers')
    && (escTicket.message || '').includes('site assistant'));
check('the subject says where it came from',
  !!escTicket && String(escTicket.subject).startsWith('Site assistant:'), escTicket?.subject);

const repeat = await askEsc('How do I tune the carburetor on a 1998 Yamaha?');
check('a second unanswerable question joins the open ticket, no duplicate',
  repeat.b.data?.ticket === escFirst.b.data?.ticket && repeat.b.data?.ticket_action === 'existing',
  JSON.stringify({ ticket: repeat.b.data?.ticket, action: repeat.b.data?.ticket_action }));
const escCount = escUser ? withDb((db) => db.prepare(
  `SELECT COUNT(*) n FROM tickets WHERE user_id = ?`).get(escUser.id).n) : 0;
check('exactly one assistant ticket exists for the customer', escCount === 1, `count=${escCount}`);

const answered = await askEsc('How does pricing work?');
check('an answerable question does not open a ticket',
  answered.b.data?.ticket === null && answered.b.data?.ticket_action === null,
  JSON.stringify({ ticket: answered.b.data?.ticket, action: answered.b.data?.ticket_action }));

// A visitor has no account to hang a ticket on: the contact form is the
// hand-off, and nothing is created.
withDb((db) => db.prepare(`DELETE FROM login_attempts WHERE scope = 'assistant'`).run());
const anon2 = new Client(BASE);
const anonPage = await anon2.get('/assistant');
const anonAsk = await anon2.raw('/assistant/chat', {
  method: 'POST',
  headers: { 'content-type': 'application/json', 'X-CSRF-TOKEN': meta(anonPage.text, 'csrf-token') },
  body: JSON.stringify({ message: Q_OUT }),
});
let anonBody = {};
try { anonBody = JSON.parse(anonAsk.text); } catch { /* reported below */ }
check('a visitor\'s unanswerable question opens no ticket',
  anonAsk.status === 200 && anonBody.data?.ticket === null, anonAsk.text.slice(0, 120));
check('and the visitor is handed to the contact form',
  /contact form/.test(anonBody.data?.reply || '')
    && (anonBody.data?.links || []).some((l) => l.href.endsWith('/contact')));

/* -------------------------------- cleanup -------------------------------- */

// The uploads are real files in the media library. Leaving them behind grows
// the deployment tree with test rubbish (the package builder notices).
const uploaded = ticket ? withDb((db) => db.prepare(
  `SELECT a.file_url FROM ticket_attachments a
     JOIN ticket_messages m ON m.id = a.ticket_message_id
    WHERE m.ticket_id = ?`).all(ticket.id)) : [];
for (const row of uploaded) {
  const name = String(row.file_url).split('/').pop();
  const file = path.join(ROOT, 'assets/uploads', name);
  try { fs.unlinkSync(file); } catch { /* already gone */ }
  withDb((db) => db.prepare(`DELETE FROM media WHERE url LIKE ?`).run('%'+name));
}

withDb((db) => {
  if (!ticket) return;
  db.prepare(`DELETE FROM ticket_attachments WHERE ticket_message_id IN
              (SELECT id FROM ticket_messages WHERE ticket_id = ?)`).run(ticket.id);
  db.prepare(`DELETE FROM ticket_messages WHERE ticket_id = ?`).run(ticket.id);
  db.prepare(`DELETE FROM tickets WHERE id = ?`).run(ticket.id);
  db.prepare(`DELETE FROM login_attempts`).run();
});

// The escalation fixture is a whole user (registration creates a wallet);
// delete the chain explicitly so the next run starts from a clean slate.
withDb((db) => {
  if (!escUser) return;
  db.prepare(`DELETE FROM ticket_messages WHERE ticket_id IN
              (SELECT id FROM tickets WHERE user_id = ?)`).run(escUser.id);
  db.prepare(`DELETE FROM tickets WHERE user_id = ?`).run(escUser.id);
  db.prepare(`DELETE FROM wallets WHERE user_id = ?`).run(escUser.id);
  db.prepare(`DELETE FROM users WHERE id = ?`).run(escUser.id);
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
