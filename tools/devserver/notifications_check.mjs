/**
 * Notifications and email end-to-end check.
 *
 * Drives the real events and proves the customer actually hears about them:
 *
 *   - approving a deposit credits the wallet AND lands in the inbox and the
 *     mail queue;
 *   - a staff reply notifies the customer, an internal note does not;
 *   - the cron worker delivers the queue and marks it SENT;
 *   - a failed message is visible to staff and can be retried;
 *   - turning the email off in the customer's preferences stops the email but
 *     keeps the inbox entry.
 *
 * Before this module all of that was silent: the tables and templates existed
 * and nothing ever wrote to them.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/notifications_check.mjs --admin-password <pw>
 */
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);
const customerPassword = arg('--password', adminPassword);

if (!adminPassword) {
  console.error('Usage: node tools/devserver/notifications_check.mjs --admin-password <pw>');
  process.exit(2);
}

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
function runCron(job) {
  return execFileSync('node', ['tools/devserver/php_run.mjs', 'index.php', 'cron', job],
    { cwd: ROOT, encoding: 'utf8', timeout: 120000 });
}
const userId = () => withDb((db) => db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get().id);
const inbox = (type) => withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND type = ?`).get(userId(), type).n);
const queued = (template) => withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM email_queue WHERE template_key = ?`).get(template).n);

const admin = new Client(BASE);
await admin.get('/admin/login');
const login = await admin.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login', { identifier: 'demo@marvy.local', password: customerPassword });
check('customer signed in', /\/dashboard/.test(clogin.url));

// ---------------------------------------------------------------------------
console.log('\n── Notifications · a credited deposit reaches the customer');
const beforeInbox = inbox('payment.credited');
const beforeMail = queued('payment.credited');

let addFunds = await cust.get('/dashboard/add-funds');
await cust.postForm('/dashboard/wallet/deposit', {
  payment_method: 'manual', amount: '2500', idempotency_key: 'notif-' + Date.now(),
}, { fromHtml: addFunds.text });

const pending = withDb((db) => db.prepare(
  `SELECT public_id FROM payment_transactions WHERE user_id = ? AND status = 'PENDING'
   ORDER BY id DESC LIMIT 1`).get(userId()));
check('a pending deposit exists to approve', !!pending, JSON.stringify(pending));

let detail = await admin.get('/admin/payments/' + pending.public_id);
const approved = await admin.postForm(`/admin/payments/${pending.public_id}/approve`, {}, { fromHtml: detail.text });
check('the deposit is approved', approved.status === 200 && !/error/i.test(
  (approved.text.match(/alert alert-(\w+)/) || [])[1] || ''));

check('the credit lands in the customer inbox', inbox('payment.credited') === beforeInbox + 1,
  `${beforeInbox} -> ${inbox('payment.credited')}`);
check('the matching email is queued', queued('payment.credited') === beforeMail + 1,
  `${beforeMail} -> ${queued('payment.credited')}`);

const bell = await cust.get('/dashboard');
check('the dashboard bell shows an unread notification',
  /ws-unread/.test(bell.text) || /1 new/.test(bell.text));

const inboxPage = await cust.get('/dashboard/notifications');
check('the notification is readable on the notifications page',
  /added to your wallet/i.test(inboxPage.text), inboxPage.text.length + ' bytes');

// ---------------------------------------------------------------------------
console.log('\n── Notifications · support replies, but internal notes stay internal');
let tickets = await cust.get('/dashboard/tickets');
await cust.postForm('/dashboard/tickets/create', {
  subject: 'Notification check ' + Date.now(), message: 'Please look into this.', priority: 'NORMAL',
}, { fromHtml: tickets.text });
const ticket = withDb((db) => db.prepare(
  `SELECT public_id FROM tickets WHERE user_id = ? ORDER BY id DESC LIMIT 1`).get(userId()));
check('a ticket exists', !!ticket, JSON.stringify(ticket));

const beforeTicketInbox = inbox('ticket.replied');
let adminTicket = await admin.get('/admin/tickets/' + ticket.public_id);
await admin.postForm(`/admin/tickets/${ticket.public_id}/reply`, {
  message: 'Internal: checking with the provider.', internal: '1',
}, { fromHtml: adminTicket.text });
check('an internal note notifies nobody', inbox('ticket.replied') === beforeTicketInbox,
  `${beforeTicketInbox} -> ${inbox('ticket.replied')}`);

adminTicket = await admin.get('/admin/tickets/' + ticket.public_id);
await admin.postForm(`/admin/tickets/${ticket.public_id}/reply`, {
  message: 'We have refunded the order, thank you for waiting.',
}, { fromHtml: adminTicket.text });
check('a real reply notifies the customer', inbox('ticket.replied') === beforeTicketInbox + 1,
  `${beforeTicketInbox} -> ${inbox('ticket.replied')}`);
check('the reply email is queued', queued('ticket.replied') >= 1);

// ---------------------------------------------------------------------------
console.log('\n── Mail queue · the worker delivers it');
// Switch the transport to `log` through the admin settings screen — the same
// path an operator uses — so delivery succeeds on a box with no mail server.
let settingsPage = await admin.get('/admin/settings');
const savedTransport = await admin.postForm('/admin/settings/save',
  { mail_transport: 'log' }, { fromHtml: settingsPage.text });
check('the mail transport is selectable in Settings',
  /setting updated|Nothing changed/i.test(savedTransport.text),
  (savedTransport.text.match(/alert alert-\w+"[^>]*>([^<]*)/) || [])[1]);

const queuePage0 = await admin.get('/admin/mail-queue');
check('the mail queue screen reports the transport it will use', /<strong class="mono">log<\/strong>/.test(queuePage0.text));

runCron('email_queue');

// Messages whose backoff has not elapsed are deliberately still waiting; the
// worker's job is to clear everything that is DUE.
const stillQueued = withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM email_queue WHERE status = 'QUEUED' AND scheduled_at <= datetime('now')`).get().n);
const sent = withDb((db) => db.prepare(`SELECT COUNT(*) AS n FROM email_queue WHERE status = 'SENT'`).get().n);
check('everything due is delivered', stillQueued === 0, `${stillQueued} still due`);
const backingOff = withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM email_queue WHERE status = 'QUEUED' AND scheduled_at > datetime('now')`).get().n);
console.log(`     (${backingOff} message(s) waiting out a retry backoff — expected)`);
check('messages are marked SENT', sent >= 2, `${sent} sent`);

// ---------------------------------------------------------------------------
console.log('\n── Mail queue · staff can see and retry a failure');
withDb((db) => db.prepare(
  `INSERT INTO email_queue (to_email, subject, body_html, template_key, status, attempts, last_error,
                            scheduled_at, created_at)
   VALUES ('bounce@example.com', 'Failed message', '<p>x</p>', 'order.completed', 'FAILED', 5,
           'SMTP: 550 mailbox unavailable', datetime('now'), datetime('now'))`).run());

let queuePage = await admin.get('/admin/mail-queue?status=FAILED');
check('the failed message is visible to staff',
  queuePage.status === 200 && /bounce@example.com/.test(queuePage.text) && /550 mailbox unavailable/.test(queuePage.text));
check('the screen names the transport in use', /transport/i.test(queuePage.text));

const failedId = withDb((db) => db.prepare(
  `SELECT id FROM email_queue WHERE to_email = 'bounce@example.com' ORDER BY id DESC LIMIT 1`).get().id);
const retried = await admin.postForm(`/admin/mail-queue/${failedId}/retry`, {}, { fromHtml: queuePage.text });
check('retry re-queues it', /re-queued/i.test(retried.text),
  (retried.text.match(/alert alert-\w+"[^>]*>([^<]*)/) || [])[1]);
const afterRetry = withDb((db) => db.prepare(`SELECT status, attempts FROM email_queue WHERE id = ?`).get(failedId));
check('its attempt counter is reset so the backoff starts again',
  afterRetry.status === 'QUEUED' && Number(afterRetry.attempts) === 0, JSON.stringify(afterRetry));

// ---------------------------------------------------------------------------
console.log('\n── Notifications · the customer\'s preferences are honoured');
let profile = await cust.get('/dashboard/profile');
await cust.postForm('/dashboard/profile', {
  action: 'notifications',
  'notify_rendered[payment__credited]': '1',
  'notify[payment__credited][in_app]': '1',   // email deliberately off
}, { fromHtml: profile.text });

const inboxBefore = inbox('payment.credited');
const mailBefore = queued('payment.credited');
addFunds = await cust.get('/dashboard/add-funds');
await cust.postForm('/dashboard/wallet/deposit', {
  payment_method: 'manual', amount: '1500', idempotency_key: 'notif2-' + Date.now(),
}, { fromHtml: addFunds.text });
const second = withDb((db) => db.prepare(
  `SELECT public_id FROM payment_transactions WHERE user_id = ? AND status = 'PENDING'
   ORDER BY id DESC LIMIT 1`).get(userId()));
detail = await admin.get('/admin/payments/' + second.public_id);
await admin.postForm(`/admin/payments/${second.public_id}/approve`, {}, { fromHtml: detail.text });

check('the inbox entry is still written', inbox('payment.credited') === inboxBefore + 1,
  `${inboxBefore} -> ${inbox('payment.credited')}`);
check('no email is queued once the customer turns it off',
  queued('payment.credited') === mailBefore, `${mailBefore} -> ${queued('payment.credited')}`);

// Put the preference back.
profile = await cust.get('/dashboard/profile');
const restore = { action: 'notifications' };
for (const type of ['order__completed', 'order__partial', 'order__canceled', 'order__refunded',
                    'payment__credited', 'ticket__replied']) {
  restore[`notify_rendered[${type}]`] = '1';
  restore[`notify[${type}][in_app]`] = '1';
  restore[`notify[${type}][email]`] = '1';
}
await cust.postForm('/dashboard/profile', restore, { fromHtml: profile.text });

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
