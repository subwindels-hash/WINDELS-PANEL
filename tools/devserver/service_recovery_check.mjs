/**
 * Stuck service purchases end-to-end check (VTU / numbers / identity / gift cards).
 *
 * A real purchase is made over HTTP — real charge, real ledger, real wallet —
 * and then rewound into the one state no settlement worker can act on: in
 * flight, with no vendor reference to poll. That is what a vendor does when it
 * accepts a purchase and answers with nothing usable, and what happens when the
 * process dies between the charge and the response. Before this module such a
 * row stayed PROCESSING for ever with the customer's money in it, on every
 * domain except gift cards.
 *
 * The recovery sweep then runs as cron really runs it (`php index.php cron
 * service_recovery` through the PHP CLI), and every consequence is checked
 * against the database and the rendered admin page.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/service_recovery_check.mjs --admin-password '…'
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
function cron(job) {
  try {
    return execFileSync('node', ['tools/devserver/php_run.mjs', 'index.php', 'cron', job],
      { cwd: ROOT, encoding: 'utf8', timeout: 180000 });
  } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
}
const money = (v) => Number(v || 0);

/* -------------------------------- sign in -------------------------------- */

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login', { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);
if (!/\/dashboard/.test(clogin.url)) process.exit(2);

const admin = new Client(BASE);
await admin.get('/admin/login');
const alogin = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
check('admin signed in', /\/admin/.test(alogin.url) && !/login/.test(alogin.url), alogin.url);

/* ------------------------- a real airtime purchase ------------------------ */

const user = withDb((db) => db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get());
const balance = () => withDb((db) =>
  money(db.prepare(`SELECT balance FROM wallets WHERE user_id = ?`).get(user.id).balance));
const network = withDb((db) => db.prepare(
  `SELECT code FROM vtu_networks WHERE service_type = 'AIRTIME' AND is_active = 1 LIMIT 1`).get());

async function buyAirtime(amount) {
  const before = balance();
  const page = await cust.get('/dashboard/vtu/airtime');
  await cust.postForm('/dashboard/vtu/buy/airtime', {
    network: network.code, msisdn: '08031234567', amount: String(amount),
    form_token: 'e2e-' + Date.now() + '-' + Math.random(),
  }, { fromHtml: page.text });
  const tx = withDb((db) => db.prepare(
    `SELECT * FROM service_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1`).get(user.id));
  return { tx, before };
}

console.log('\n── A purchase the vendor left unpollable');
const startBalance = balance();
const bought = await buyAirtime(500);
check('the purchase was created and charged',
  bought.tx && money(bought.tx.amount) > 0 && balance() < startBalance,
  bought.tx ? `${bought.tx.public_id} ${bought.tx.status} ${bought.tx.amount}` : 'no transaction');
if (!bought.tx) process.exit(1);

// Rewind it into the state this module exists for: accepted, in flight, and
// nothing to poll — exactly what a vendor that answers without a reference
// leaves behind, and what no settlement worker can see.
withDb((db) => db.prepare(
  `UPDATE service_transactions
      SET status = 'PROCESSING', provider_reference = NULL, completed_at = NULL,
          created_at = datetime('now', '-6 hours')
    WHERE id = ?`).run(bought.tx.id));
const chargedBalance = balance();

const summary = cron('service_recovery');
check('the sweep runs as a cron job', /service_recovery|closed|refunded/i.test(summary),
  summary.trim().slice(0, 200));

const settled = withDb((db) => db.prepare(
  `SELECT * FROM service_transactions WHERE id = ?`).get(bought.tx.id));
check('the purchase is closed instead of waiting for ever', settled.status === 'FAILED',
  `status=${settled.status}`);
check('with a reason a human can act on', /check on|settle/i.test(settled.failure_reason || ''),
  String(settled.failure_reason));
check('the charge is returned in full',
  Math.abs(money(settled.refunded_amount) - money(settled.amount)) < 0.00001,
  `refunded=${settled.refunded_amount} of ${settled.amount}`);
check('and the wallet actually received it',
  Math.abs(balance() - (chargedBalance + money(settled.amount))) < 0.00001,
  `${chargedBalance} -> ${balance()}`);

const note = withDb((db) => db.prepare(
  `SELECT COUNT(*) n FROM notifications WHERE user_id = ? AND type = 'purchase.refunded' AND body LIKE ?`)
  .get(user.id, `%${bought.tx.public_id}%`).n);
check('the customer is told why their balance changed', note === 1, `notifications=${note}`);

const history = withDb((db) => db.prepare(
  `SELECT to_status, source FROM service_transaction_status_history
    WHERE service_transaction_id = ? ORDER BY id DESC LIMIT 1`).get(bought.tx.id));
check('the status trail records who closed it',
  history && history.to_status === 'FAILED' && history.source === 'SYSTEM',
  JSON.stringify(history));

/* ------------------------- running it again is safe ----------------------- */

console.log('\n── Running the sweep again changes nothing');
const afterFirst = balance();
cron('service_recovery');
cron('service_recovery');
check('the money is not returned twice', Math.abs(balance() - afterFirst) < 0.00001,
  `${afterFirst} -> ${balance()}`);
check('and the customer is not told twice',
  withDb((db) => db.prepare(
    `SELECT COUNT(*) n FROM notifications WHERE user_id = ? AND type = 'purchase.refunded' AND body LIKE ?`)
    .get(user.id, `%${bought.tx.public_id}%`).n) === 1);

/* --------------------- a fresh purchase is left alone --------------------- */

console.log('\n── A purchase still inside its window');
const fresh = await buyAirtime(400);
withDb((db) => db.prepare(
  `UPDATE service_transactions SET status = 'PROCESSING', provider_reference = NULL WHERE id = ?`)
  .run(fresh.tx.id));
cron('service_recovery');
const stillOpen = withDb((db) => db.prepare(
  `SELECT status FROM service_transactions WHERE id = ?`).get(fresh.tx.id));
check('a purchase minutes old is the poller’s job, not a write-off',
  stillOpen.status === 'PROCESSING', `status=${stillOpen.status}`);

/* ---------------------------- the admin surface --------------------------- */

console.log('\n── What staff see afterwards');
const detail = await admin.get('/admin/vtu/' + bought.tx.public_id);
check('the admin purchase page loads', detail.status === 200);
check('it shows the failure reason', detail.text.includes('check on') || /FAILED/.test(detail.text));
check('and that the money went back', /refund/i.test(detail.text));

const queue = await admin.get('/admin/vtu?status=FAILED');
check('the failed purchase is findable in the queue',
  queue.status === 200 && queue.text.includes(bought.tx.public_id));

/* -------------------------------- cleanup -------------------------------- */

withDb((db) => {
  for (const id of [bought.tx.id, fresh.tx.id]) {
    db.prepare(`DELETE FROM service_transaction_status_history WHERE service_transaction_id = ?`).run(id);
    db.prepare(`DELETE FROM vtu_transactions WHERE service_transaction_id = ?`).run(id);
    db.prepare(`DELETE FROM service_transactions WHERE id = ?`).run(id);
  }
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
