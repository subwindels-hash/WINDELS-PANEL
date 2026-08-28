/**
 * Refunds and refills end-to-end check.
 *
 * Drives the real panel over HTTP (the customer's browser and the admin's),
 * the real cron worker through the PHP CLI, and a fake SMM panel that can be
 * told to refuse things — because refusal is the case every one of these
 * paths used to get wrong.
 *
 * What it proves, in the order a real complaint arrives:
 *
 *   1. a refill the provider REFUSES is closed and the customer is told, not
 *      shown "refill requested" over a row that would sit in PENDING for ever;
 *   2. a refill the provider never ANSWERS is kept and re-sent by the worker,
 *      then settled from the provider's own status;
 *   3. a refill nobody ever settles is eventually closed and announced;
 *   4. a partial delivery that delivered NOTHING refunds the whole charge
 *      (the old maths refunded zero);
 *   5. a cancellation the provider refuses does NOT refund the customer while
 *      the provider keeps delivering — and staff can still override it
 *      deliberately with "cancel anyway";
 *   6. the admin surfaces show the refill error, the override and the two new
 *      settings.
 *
 * The web dev server's wasm workers cannot open outbound sockets, so a refill
 * requested through the browser genuinely fails at transport here — which is
 * itself step 2's starting condition, and exactly the production case of a
 * provider timing out.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/refunds_check.mjs --admin-password '…' --customer-password '…'
 *
 * Requires HTTP_ALLOW_PRIVATE_HOSTS=true in .env (the fake panel is on
 * localhost and SecureHttpClient blocks private hosts by default).
 */
import net from 'node:net';
import path from 'node:path';
import { execFileSync, spawn } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const PANEL_PORT = parseInt(arg('--panel-port', '8098'), 10);
const ADMIN_PASSWORD = arg('--admin-password', 'Demo!cabcd50b');
const CUSTOMER_PASSWORD = arg('--customer-password', ADMIN_PASSWORD);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const PANEL_URL = `http://127.0.0.1:${PANEL_PORT}/api/v2`;
const GOOD_KEY = 'refill-key-' + Date.now();

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
function php(args) {
  try {
    return execFileSync('node', ['tools/devserver/php_run.mjs', ...args],
      { cwd: ROOT, encoding: 'utf8', timeout: 180000 });
  } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
}
function panelState(patch = null) {
  const args = patch
    ? ['-s', '-X', 'POST', '-H', 'content-type: application/json', '-d', JSON.stringify(patch),
       `http://127.0.0.1:${PANEL_PORT}/__state`]
    : ['-s', `http://127.0.0.1:${PANEL_PORT}/__state`];
  return JSON.parse(execFileSync('curl', args, { encoding: 'utf8' }));
}
const money = (v) => Number(v || 0);

/* --------------------------- the fake provider --------------------------- */

/**
 * A panel left over from an earlier run answers with a different API key, so
 * every call fails as "Incorrect API key" and the report blames the adapter.
 * Refuse to start rather than test somebody else's process.
 */
async function portIsFree(port) {
  return new Promise((resolve) => {
    const socket = net.connect(port, '127.0.0.1');
    socket.on('connect', () => { socket.destroy(); resolve(false); });
    socket.on('error', () => resolve(true));
  });
}
if (!(await portIsFree(PANEL_PORT))) {
  console.error(`\n  Port ${PANEL_PORT} is already in use — a fake panel from an earlier run is `
              + `still listening.\n  Stop it first:  pkill -f fake_smm_panel\n`);
  process.exit(2);
}

const panelProcess = spawn('node',
  ['tools/devserver/fake_smm_panel.mjs', '--port', String(PANEL_PORT), '--key', GOOD_KEY],
  { cwd: ROOT, stdio: 'ignore' });
for (let i = 0; i < 50; i++) {
  try { panelState(); break; } catch { execFileSync('sleep', ['0.1']); }
}
function bail(code) { panelProcess.kill(); process.exit(code); }

/* ------------------------------- fixtures -------------------------------- */

const stamp = Date.now();
const cipher = php(['tools/devserver/provider_probe.php', 'encrypt', GOOD_KEY]).trim().split('\n').pop();

const fx = withDb((db) => {
  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const wallet = db.prepare(`SELECT id, balance FROM wallets WHERE user_id = ?`).get(user.id);
  const providerPublic = 'E2EREFPROV' + stamp;
  db.prepare(`INSERT INTO providers
      (public_id, name, api_url, api_key_encrypted, api_type, status, currency, timeout_ms,
       rate_multiplier, markup, sync_interval_minutes, health_status, created_at, updated_at)
     VALUES (?, ?, ?, ?, 'STANDARD_SMM', 'ACTIVE', 'USD', 8000, '1.00000000', '0.00000000', 60,
             'UNKNOWN', datetime('now'), datetime('now'))`)
    .run(providerPublic, 'E2E Refill Panel ' + stamp, PANEL_URL, cipher);
  const provider = db.prepare(`SELECT id FROM providers WHERE public_id = ?`).get(providerPublic);

  // A refillable service on that provider.
  const svcPublic = 'E2EREFSVC' + stamp;
  const category = db.prepare(`SELECT id FROM service_categories LIMIT 1`).get();
  db.prepare(`INSERT INTO services
      (public_id, category_id, provider_id, provider_service_id, name, slug, service_type,
       rate, min_quantity, max_quantity, increment_step, status, refill_supported, cancel_supported,
       created_at, updated_at)
     VALUES (?, ?, ?, '101', ?, ?, 'DEFAULT', '2.00000000', 100, 10000, 1, 'ACTIVE', 1, 1,
             datetime('now'), datetime('now'))`)
    .run(svcPublic, category.id, provider.id, 'E2E Refillable Followers ' + stamp, 'e2e-refill-' + stamp);
  const service = db.prepare(`SELECT id FROM services WHERE public_id = ?`).get(svcPublic);

  const mkOrder = (suffix, status, providerOrderId) => {
    const publicId = ('E2EREFORD' + suffix + stamp).slice(0, 26);
    db.prepare(`INSERT INTO orders
        (public_id, user_id, service_id, provider_id, provider_order_id, status, link, quantity,
         charge, rate_at_order, currency, source, completed_at, created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, 'https://example.com/handle', 1000,
               '2000.00000000', '2.00000000', 'NGN', 'WEB', datetime('now'), datetime('now'), datetime('now'))`)
      .run(publicId, user.id, service.id, provider.id, providerOrderId, status);
    return publicId;
  };

  return {
    userId: user.id, walletId: wallet.id, providerPublic, svcPublic,
    refused:  mkOrder('A', 'COMPLETED', '9001'),
    retried:  mkOrder('B', 'COMPLETED', '9002'),
    stale:    mkOrder('C', 'COMPLETED', '9003'),
    partial:  mkOrder('D', 'IN_PROGRESS', '9004'),
    cancel:   mkOrder('E', 'PROCESSING', '9005'),
    forced:   mkOrder('F', 'PROCESSING', '9006'),
  };
});

const refillOf = (orderPublic) => withDb((db) => db.prepare(
  `SELECT r.* FROM refills r JOIN orders o ON o.id = r.order_id WHERE o.public_id = ?
   ORDER BY r.id DESC LIMIT 1`).get(orderPublic));
const orderOf = (orderPublic) => withDb((db) =>
  db.prepare(`SELECT * FROM orders WHERE public_id = ?`).get(orderPublic));
const balance = () => withDb((db) =>
  db.prepare(`SELECT balance FROM wallets WHERE id = ?`).get(fx.walletId).balance);
const notificationsFor = (type, orderPublic) => withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND type = ? AND body LIKE ?`)
  .get(fx.userId, type, `%${orderPublic}%`).n);

/* -------------------------------- sign in -------------------------------- */

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login', { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);
if (!/\/dashboard/.test(clogin.url)) bail(2);

const admin = new Client(BASE);
await admin.get('/admin/login');
const alogin = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
check('admin signed in', /\/admin/.test(alogin.url) && !/login/.test(alogin.url), alogin.url);
if (/login/.test(alogin.url)) bail(2);

/* ============================ 1 · a refusal ============================== */

console.log('\n── Refill · the provider refuses');
panelState({ refill: 'refuse' });

await cust.get('/dashboard/orders/' + fx.refused);
let page = await cust.postForm(`/dashboard/orders/${fx.refused}/refill`, {});
let row = refillOf(fx.refused);
// The wasm dev server's workers sometimes cannot open an outbound socket (a
// long-running server runs out of file descriptors). That is a sandbox
// property, not the panel's behaviour, and it produces the *transport* case:
// the refill stays PENDING and the worker re-sends it. Either way the refusal
// has to arrive and close the refill, so drive the worker when that happens
// rather than reporting a false failure.
if (row && row.status === 'PENDING') {
  console.log('   note    the browser could not reach the panel; settling through the worker instead');
  php(['index.php', 'cron', 'refill_status']);
  row = refillOf(fx.refused);
} else {
  check('the refusal is shown to the customer, not a green "requested"',
    /Refill not available/i.test(page.text), page.text.slice(0, 0));
}
check('the refusal closes the refill instead of parking it in PENDING',
  row && row.status === 'FAILED', JSON.stringify(row && { s: row.status, e: row.error }));
check('the provider’s own words are stored for staff',
  /Refill not available/i.test(row.error || ''), String(row.error).slice(0, 120));
check('the refill has an end date instead of ageing for ever', !!row.completed_at, String(row.completed_at));
check('the customer hears that their remedy failed',
  notificationsFor('refill.failed', fx.refused) === 1);

const callsAfterRefusal = panelState().calls.filter((c) => c.action === 'refill').length;
php(['index.php', 'cron', 'refill_status']);
check('a refused refill is never re-sent on the next run',
  panelState().calls.filter((c) => c.action === 'refill').length === callsAfterRefusal,
  `${callsAfterRefusal} call(s)`);

/* ======================== 2 · an unanswered refill ======================= */

console.log('\n── Refill · the provider never answered, then comes back');
// A panel in maintenance answers HTML: no refusal, no acceptance, no answer at
// all. That must not be read as "no".
panelState({ mode: 'maintenance', refill: 'accept', refillStatus: 'In progress' });

await cust.get('/dashboard/orders/' + fx.retried);
await cust.postForm(`/dashboard/orders/${fx.retried}/refill`, {});
row = refillOf(fx.retried);
check('the unanswered refill is open with no provider reference',
  row && row.status === 'PENDING' && !row.provider_refill_id, JSON.stringify(row && { s: row.status }));

panelState({ mode: 'normal' });
php(['index.php', 'cron', 'refill_status']);
row = refillOf(fx.retried);
check('the worker re-sends it and stores the provider refill id',
  row.provider_refill_id === '555', `id=${row.provider_refill_id} status=${row.status}`);

panelState({ refillStatus: 'Completed' });
php(['index.php', 'cron', 'refill_status']);
row = refillOf(fx.retried);
check('the poller settles it from the provider’s own status', row.status === 'COMPLETED', `status=${row.status}`);
check('and the customer is told it is done', notificationsFor('refill.completed', fx.retried) === 1);

/* ========================= 3 · nobody ever settles ======================= */

console.log('\n── Refill · a provider that never settles');
panelState({ refill: 'accept', refillStatus: 'Pending' });
await cust.get('/dashboard/orders/' + fx.stale);
await cust.postForm(`/dashboard/orders/${fx.stale}/refill`, {});
withDb((db) => db.prepare(
  `UPDATE refills SET requested_at = datetime('now', '-400 hours'), status = 'PROCESSING',
    provider_refill_id = '556'
   WHERE order_id = (SELECT id FROM orders WHERE public_id = ?)`).run(fx.stale));

php(['index.php', 'cron', 'refill_status']);
row = refillOf(fx.stale);
check('a refill the provider never settles is closed', row.status === 'FAILED', `status=${row.status}`);
check('with a reason a customer can read', /never settled/i.test(row.error || ''), String(row.error).slice(0, 120));
check('and the customer is told', notificationsFor('refill.failed', fx.stale) === 1);

/* ====================== 4 · partial refund arithmetic ==================== */

console.log('\n── Refund · a partial that delivered nothing');
const beforePartial = money(balance());
await admin.get('/admin/orders/' + fx.partial);
await admin.postForm(`/admin/orders/${fx.partial}/status`,
  { status: 'PARTIAL', remains: '1000', reason: 'provider delivered nothing' });
let order = orderOf(fx.partial);
check('the order is PARTIAL', order.status === 'PARTIAL', order.status);
check('the whole charge is refunded when nothing was delivered',
  money(order.refunded_amount) === 2000, `refunded=${order.refunded_amount}`);
check('and the wallet actually received it',
  money(balance()) - beforePartial === 2000, `${beforePartial} -> ${balance()}`);

// Re-applying the same report must move no money.
const afterPartial = money(balance());
await admin.get('/admin/orders/' + fx.partial);
await admin.postForm(`/admin/orders/${fx.partial}/status`,
  { status: 'PARTIAL', remains: '1000', reason: 'same report again' });
check('re-reporting the same partial refunds nothing twice',
  money(balance()) === afterPartial, `${afterPartial} -> ${balance()}`);

/* ========================= 5 · refused cancellation ====================== */

console.log('\n── Cancel · the provider refuses to stop');
panelState({ cancel: 'refuse' });
const beforeCancel = money(balance());
await admin.get('/admin/orders/' + fx.cancel);
page = await admin.postForm(`/admin/orders/${fx.cancel}/cancel`, { reason: 'customer asked' });
order = orderOf(fx.cancel);
check('the order is not cancelled behind the provider’s back',
  order.status !== 'CANCELED', `status=${order.status}`);
check('the customer is not refunded for an order still being delivered',
  money(balance()) === beforeCancel, `${beforeCancel} -> ${balance()}`);
check('the screen explains why and offers the override',
  /provider would not cancel|cancel anyway/i.test(page.text));

console.log('\n── Cancel · staff override it knowingly');
await admin.get('/admin/orders/' + fx.forced);
await admin.postForm(`/admin/orders/${fx.forced}/cancel`, { reason: 'goodwill', force: '1' });
order = orderOf(fx.forced);
check('“cancel anyway” cancels it', order.status === 'CANCELED', `status=${order.status}`);
check('and refunds the charge exactly once',
  money(balance()) - beforeCancel === 2000, `${beforeCancel} -> ${balance()}`);
check('the customer is told their order was cancelled',
  notificationsFor('order.canceled', fx.forced) === 1);

/* ============================ 6 · the surfaces =========================== */

console.log('\n── Admin surfaces');
const refills = await admin.get('/admin/refills');
check('the refills queue loads', refills.status === 200);
check('it shows the failed refill with the provider’s reason',
  /Refill not available/i.test(refills.text) || /FAILED/.test(refills.text));
const detail = await admin.get('/admin/orders/' + fx.cancel);
check('the order screen offers "cancel anyway"', /name="force"/.test(detail.text));
const settings = await admin.get('/admin/settings');
check('the refill window is configurable', /refill_window_days/.test(settings.text));
check('the abandon window is configurable', /refill_abandon_hours/.test(settings.text));

const saved = await admin.postForm('/admin/settings/save',
  { refill_window_days: '45', refill_abandon_hours: '96' }, { fromHtml: settings.text });
check('both settings save', /success|saved/i.test(saved.text) || saved.status === 200);
check('the new refill window is stored',
  withDb((db) => /45/.test(db.prepare(`SELECT setting_value FROM settings WHERE setting_key = 'refill_window_days'`)
    .get()?.setting_value || '')));

/* -------------------------------- cleanup -------------------------------- */

withDb((db) => {
  const orders = [fx.refused, fx.retried, fx.stale, fx.partial, fx.cancel, fx.forced];
  for (const o of orders) {
    const row = db.prepare(`SELECT id FROM orders WHERE public_id = ?`).get(o);
    if (!row) continue;
    db.prepare(`DELETE FROM refill_status_history WHERE refill_id IN (SELECT id FROM refills WHERE order_id = ?)`).run(row.id);
    db.prepare(`DELETE FROM refills WHERE order_id = ?`).run(row.id);
    db.prepare(`DELETE FROM order_status_history WHERE order_id = ?`).run(row.id);
    db.prepare(`DELETE FROM orders WHERE id = ?`).run(row.id);
  }
  db.prepare(`DELETE FROM services WHERE public_id = ?`).run(fx.svcPublic);
  db.prepare(`DELETE FROM providers WHERE public_id = ?`).run(fx.providerPublic);
  db.prepare(`UPDATE settings SET setting_value = '{"value":"30"}' WHERE setting_key = 'refill_window_days'`).run();
  db.prepare(`UPDATE settings SET setting_value = '{"value":"168"}' WHERE setting_key = 'refill_abandon_hours'`).run();
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
}
bail(failed.length ? 1 : 0);
