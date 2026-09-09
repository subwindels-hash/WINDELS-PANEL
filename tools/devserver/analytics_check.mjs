/**
 * Analytics accuracy end-to-end check.
 *
 * The admin analytics and dashboard screens are read-only, so nothing here can
 * be proved by "the page loads". The only question worth asking is whether the
 * numbers printed on them are true, so every check below computes the expected
 * figure straight from the database and compares it with what the rendered
 * page says.
 *
 * The three defects it pins:
 *
 *   1. every revenue figure counted EVERY row created in the window, so a
 *      cancelled order and a failed VTU top-up were reported as income;
 *   2. the delivery-health table read only `service_transactions`, so SMM —
 *      the panel's largest domain — had no delivery row at all;
 *   3. `wallets.total_spent` was displayed on three admin screens and written
 *      by nothing, so every customer showed ₦0.00 spent forever.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/analytics_check.mjs --admin-password '…'
 */
import path from 'node:path';
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
/** The money a card is showing, as a number (₦1,234.56 → 1234.56). */
function cardValue(html, label) {
  const idx = html.indexOf(label);
  if (idx === -1) return null;
  const after = html.slice(idx, idx + 900);
  const m = /class="text-2xl font-bold"[^>]*>\s*([^<]+)</.exec(after);
  if (!m) return null;
  const digits = m[1].replace(/[^0-9.\-]/g, '');
  return digits === '' ? null : Number(digits);
}
const near = (a, b, tol = 0.02) => a !== null && b !== null && Math.abs(a - b) <= tol;

/* ------------------------------ expectations ----------------------------- */

const EARNED_ORDERS = "('COMPLETED','PARTIAL','IN_PROGRESS','PROCESSING','REFUNDED')";
const EARNED_SERVICES = "('SUCCESSFUL','PROCESSING','REFUNDED')";

function expectedRevenue(days) {
  return withDb((db) => {
    const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 19).replace('T', ' ');
    // The panels report revenue "as of" the last update to the row, not the
    // day it was first created: a pending order completed (or refunded) today
    // must move into today's report.
    const o = db.prepare(`SELECT COUNT(*) n, COALESCE(SUM(charge),0) g, COALESCE(SUM(refunded_amount),0) r
                            FROM orders WHERE updated_at >= ? AND status IN ${EARNED_ORDERS}`).get(since);
    const s = db.prepare(`SELECT COUNT(*) n, COALESCE(SUM(amount),0) g, COALESCE(SUM(refunded_amount),0) r
                            FROM service_transactions WHERE updated_at >= ? AND status IN ${EARNED_SERVICES}`).get(since);
    const un = db.prepare(`SELECT COUNT(*) n FROM orders
                            WHERE updated_at >= ? AND status NOT IN ${EARNED_ORDERS}`).get(since).n
             + db.prepare(`SELECT COUNT(*) n FROM service_transactions
                            WHERE updated_at >= ? AND status NOT IN ${EARNED_SERVICES}`).get(since).n;
    const gross = Number(o.g) + Number(s.g);
    const refunded = Number(o.r) + Number(s.r);
    return { sales: o.n + s.n, gross, refunded, net: gross - refunded, unearned: un };
  });
}

/* -------------------------------- sign in -------------------------------- */

const admin = new Client(BASE);
await admin.get('/admin/login');
const alogin = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
check('admin signed in', /\/admin/.test(alogin.url) && !/login/.test(alogin.url), alogin.url);
if (/login/.test(alogin.url)) process.exit(2);

/* ==================== 1 · the headline figures are true =================== */

console.log('\n── Analytics · the numbers match the database');
let page = await admin.get('/admin/analytics?days=30');
check('the analytics screen loads', page.status === 200);

let expected = expectedRevenue(30);
check('net revenue equals earned sales minus refunds',
  near(cardValue(page.text, 'Net revenue'), expected.net),
  `page=${cardValue(page.text, 'Net revenue')} db=${expected.net.toFixed(2)}`);
check('gross equals the earned charges',
  near(cardValue(page.text, '>Gross<'), expected.gross),
  `page=${cardValue(page.text, '>Gross<')} db=${expected.gross.toFixed(2)}`);

/* ============ 2 · a cancelled sale is an attempt, not revenue ============= */

console.log('\n── Analytics · a cancelled order is not income');
const stamp = Date.now();
const fx = withDb((db) => {
  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const service = db.prepare(`SELECT id FROM services LIMIT 1`).get();
  const ids = [];
  const insert = (suffix, status, charge) => {
    const publicId = ('E2EANL' + suffix + stamp).slice(0, 26);
    db.prepare(`INSERT INTO orders
        (public_id, user_id, service_id, status, link, quantity, charge, rate_at_order,
         currency, source, created_at, updated_at)
       VALUES (?, ?, ?, ?, 'https://example.com/a', 1000, ?, '1.00000000', 'NGN', 'WEB',
               datetime('now'), datetime('now'))`)
      .run(publicId, user.id, service.id, status, charge);
    ids.push(publicId);
    return publicId;
  };
  insert('CAN', 'CANCELED', '777777.00000000');
  insert('FAI', 'FAILED',   '888888.00000000');
  insert('PEN', 'PENDING',  '999999.00000000');
  insert('OK',  'COMPLETED','123456.00000000');
  return { ids, userId: user.id };
});

page = await admin.get('/admin/analytics?days=30');
expected = expectedRevenue(30);
check('the cancelled charge is nowhere on the page', !page.text.includes('777,777'));
check('nor the failed one', !page.text.includes('888,888'));
check('nor the one still pending delivery', !page.text.includes('999,999'));
check('the delivered one is counted',
  near(cardValue(page.text, 'Net revenue'), expected.net),
  `page=${cardValue(page.text, 'Net revenue')} db=${expected.net.toFixed(2)}`);
check('the attempts that earned nothing are still reported',
  new RegExp(`${expected.unearned}\\s*not counted`).test(page.text.replace(/<[^>]+>/g, ' ')),
  `expected "${expected.unearned} not counted"`);

/* ================== 3 · the chart agrees with the cards ================== */

console.log('\n── Analytics · the chart and the cards agree');
const todayExpected = withDb((db) => {
  const day = new Date().toISOString().slice(0, 10);
  const o = db.prepare(`SELECT COALESCE(SUM(charge),0) g, COALESCE(SUM(refunded_amount),0) r, COUNT(*) n
                          FROM orders WHERE SUBSTR(updated_at,1,10) = ? AND status IN ${EARNED_ORDERS}`).get(day);
  const s = db.prepare(`SELECT COALESCE(SUM(amount),0) g, COALESCE(SUM(refunded_amount),0) r, COUNT(*) n
                          FROM service_transactions WHERE SUBSTR(updated_at,1,10) = ? AND status IN ${EARNED_SERVICES}`).get(day);
  return { day, net: Number(o.g) - Number(o.r) + Number(s.g) - Number(s.r), sales: o.n + s.n };
});
const barTitle = new RegExp(`title="${todayExpected.day} — ([^"]+?) from (\\d+) sale`).exec(page.text);
check('today’s bar carries today’s earned total',
  barTitle && near(Number(barTitle[1].replace(/[^0-9.\-]/g, '')), todayExpected.net),
  barTitle ? `${barTitle[1]} vs ${todayExpected.net.toFixed(2)}` : 'no bar for today');
check('and today’s earned sale count',
  barTitle && Number(barTitle[2]) === todayExpected.sales,
  barTitle ? `${barTitle[2]} vs ${todayExpected.sales}` : '');

/* ====================== 4 · SMM has a delivery row ======================= */

console.log('\n── Analytics · delivery health covers SMM');
const healthBlock = page.text.slice(page.text.indexOf('Delivery health'), page.text.indexOf('Vendor reliability'));
check('the delivery-health table lists SMM orders', /SMM orders/.test(healthBlock),
  healthBlock.slice(0, 0));
const smmExpected = withDb((db) => db.prepare(
  `SELECT COALESCE(SUM(CASE WHEN status IN ('PENDING','PROCESSING','IN_PROGRESS') THEN 1 ELSE 0 END),0) in_flight
     FROM orders`).get().in_flight);
check('with the real in-flight count',
  new RegExp(`SMM orders[\\s\\S]{0,400}?>\\s*${smmExpected}\\s*<`).test(healthBlock),
  `expected in_flight=${smmExpected}`);

/* ================== 5 · the customer's "spent" is honest ================= */

console.log('\n── Dashboard · what the customer is told they spent');
const spentExpected = withDb((db) => {
  const r = db.prepare(`SELECT COALESCE(SUM(charge),0) c, COALESCE(SUM(refunded_amount),0) r
                          FROM orders WHERE user_id = ?`).get(fx.userId);
  return Number(r.c) - Number(r.r);
});
const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login', { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);
const dash = await cust.get('/dashboard');
// The overview's "Completed" card carries it: <p class="hint">₦15.00 spent</p>
const spentShown = /class="hint">\s*([^<]*?)\s*spent\s*</.exec(dash.text);
check('the "spent" figure is charges minus refunds, not gross charges',
  spentShown && near(Number(spentShown[1].replace(/[^0-9.\-]/g, '')), spentExpected, 0.05),
  spentShown ? `page=${spentShown[1]} db=${spentExpected.toFixed(2)}` : 'no spent figure found');

/* ============== 6 · the wallet lifetime counters are maintained ========== */

console.log('\n── Admin · the wallet counters are no longer dead columns');
const counters = withDb((db) => db.prepare(
  `SELECT w.total_spent AS spent, w.total_deposited AS deposited,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions
            WHERE wallet_id = w.id AND direction = 'DEBIT') AS debits,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions
            WHERE wallet_id = w.id AND direction = 'CREDIT' AND type = 'REFUND') AS refunds,
          (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions
            WHERE wallet_id = w.id AND direction = 'CREDIT' AND type = 'DEPOSIT') AS deposits
     FROM wallets w WHERE w.user_id = ?`).get(fx.userId));
check('total_deposited matches the deposits actually recorded',
  Math.abs(Number(counters.deposited) - Number(counters.deposits)) < 0.01,
  JSON.stringify(counters));
// total_spent is a RUNNING counter the ledger maintains as money moves — not
// a figure recomputed from history. Two consequences make an absolute
// reconstruction the wrong assertion here:
//
//   * it is floored at zero on every refund, so once a decrement has stopped
//     at zero the information is gone and the counter sits permanently above
//     `debits - refunds`;
//   * this dev database contains wallet_transactions written DIRECTLY by
//     `seed_load.mjs`, which never went through the ledger at all.
//
// Reconstructing from those rows measures the fixtures, not the code. What
// actually needs proving is that the counter moves correctly when the
// application moves money — so this drives a real wallet adjustment through
// the admin form and asserts the counter tracked it exactly.
check('total_spent is never negative', Number(counters.spent) >= 0, JSON.stringify(counters));

const customerPublicId = withDb((db) => db.prepare(
  `SELECT public_id FROM users WHERE id = ?`).get(fx.userId).public_id);
const adjustPage = await admin.get('/admin/customers/' + customerPublicId);
const spentBefore = Number(withDb((db) => db.prepare(
  `SELECT total_spent AS s FROM wallets WHERE user_id = ?`).get(fx.userId).s));

await admin.postForm('/admin/customers/' + customerPublicId + '/adjust',
  { amount: '250', direction: 'DEBIT', reason: 'analytics counter probe',
    nonce: 'probe-' + Date.now() },
  { fromHtml: adjustPage.text });

const spentAfterDebit = Number(withDb((db) => db.prepare(
  `SELECT total_spent AS s FROM wallets WHERE user_id = ?`).get(fx.userId).s));
check('a debit moves total_spent by exactly the amount charged',
  Math.abs((spentAfterDebit - spentBefore) - 250) < 0.01,
  `${spentBefore} -> ${spentAfterDebit}`);

await admin.postForm('/admin/customers/' + customerPublicId + '/adjust',
  { amount: '250', direction: 'CREDIT', reason: 'analytics counter probe reversal',
    nonce: 'probe-back-' + Date.now() },
  { fromHtml: (await admin.get('/admin/customers/' + customerPublicId)).text });

const spentAfterCredit = Number(withDb((db) => db.prepare(
  `SELECT total_spent AS s FROM wallets WHERE user_id = ?`).get(fx.userId).s));
check('and a goodwill credit is not counted as spending',
  Math.abs(spentAfterCredit - spentAfterDebit) < 0.01,
  `${spentAfterDebit} -> ${spentAfterCredit} — only refunds reduce lifetime spend`);

const wallets = await admin.get('/admin/wallets');
check('the wallets screen loads', wallets.status === 200);
const depositedShown = withDb((db) => db.prepare(
  `SELECT COALESCE(SUM(total_deposited),0) t FROM wallets`).get().t);
check('the platform "deposited" total is no longer a dead zero',
  Number(depositedShown) > 0 && wallets.text.includes(
    Number(depositedShown).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })),
  `db total=${depositedShown}`);

/* -------------------------------- cleanup -------------------------------- */

withDb((db) => {
  for (const id of fx.ids) {
    const row = db.prepare(`SELECT id FROM orders WHERE public_id = ?`).get(id);
    if (!row) continue;
    db.prepare(`DELETE FROM order_status_history WHERE order_id = ?`).run(row.id);
    db.prepare(`DELETE FROM orders WHERE id = ?`).run(row.id);
  }
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
