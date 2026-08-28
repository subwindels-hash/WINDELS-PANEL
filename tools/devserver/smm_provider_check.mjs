/**
 * SMM provider adapter end-to-end check.
 *
 * Stands up a fake "SMM panel API v2" on localhost and drives the REAL
 * StandardSmmAdapter against it — through the adapter directly, and through
 * the panel's own cron worker for status polling.
 *
 * The regressions this exists to catch are the ones these panels cause in
 * practice, because they answer HTTP 200 with `{"error": "..."}`:
 *
 *   - a wrong API key must be a failure, not a healthy provider;
 *   - a maintenance page must never look like an empty catalogue;
 *   - a refused refill or cancellation must not be reported as accepted;
 *   - a status batch must be chunked, because a panel refuses an oversized
 *     batch outright and the whole poll would silently stop.
 *
 * Outbound HTTP is issued from the PHP CLI runtime (`php_run.mjs`), which is
 * how cron runs in production; the web dev server's wasm workers cannot open
 * outbound sockets in this sandbox.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/smm_provider_check.mjs
 *
 * Requires HTTP_ALLOW_PRIVATE_HOSTS=true in .env: SecureHttpClient refuses
 * non-public hosts by default (SSRF protection), and the fake panel is on
 * localhost. Never set that in production.
 */

import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const PANEL_PORT = parseInt(arg('--panel-port', '8099'), 10);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const GOOD_KEY = 'good-key-' + Date.now();
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
      { cwd: ROOT, encoding: 'utf8', timeout: 120000 });
  } catch (e) {
    return (e.stdout || '') + (e.stderr || '');
  }
}
/** One adapter call against the fake panel, as JSON. */
function adapterCall(key, action, params = []) {
  const out = php(['tools/devserver/provider_probe.php', 'call', PANEL_URL, key, action, ...params]);
  const line = out.trim().split('\n').filter((l) => l.startsWith('{')).pop();
  try { return JSON.parse(line); } catch { return { ok: false, error: 'unparseable: ' + out.slice(0, 200) }; }
}

/* ------------------------- the fake SMM panel ---------------------------- */

// The panel runs in its own process: every adapter call below is a synchronous
// PHP CLI run, which blocks this script's event loop — an in-process server
// could never answer while that is happening.
const PANEL_URL = `http://127.0.0.1:${PANEL_PORT}/api/v2`;
const panelProcess = spawn('node',
  ['tools/devserver/fake_smm_panel.mjs', '--port', String(PANEL_PORT), '--key', GOOD_KEY],
  { cwd: ROOT, stdio: 'ignore', detached: false });

function panelState(patch = null) {
  const args = patch
    ? ['-s', '-X', 'POST', '-H', 'content-type: application/json', '-d', JSON.stringify(patch),
       `http://127.0.0.1:${PANEL_PORT}/__state`]
    : ['-s', `http://127.0.0.1:${PANEL_PORT}/__state`];
  return JSON.parse(execFileSync('curl', args, { encoding: 'utf8' }));
}
function panelReset() {
  execFileSync('curl', ['-s', '-X', 'POST', `http://127.0.0.1:${PANEL_PORT}/__reset`], { encoding: 'utf8' });
}

// Wait for it to accept connections.
for (let i = 0; i < 50; i++) {
  try { panelState(); break; } catch { execFileSync('sleep', ['0.1']); }
}

/* ------------------------------ the checks ------------------------------- */

console.log('\n── SMM adapter · a working panel');
let res = adapterCall(GOOD_KEY, 'balance');
if (!res.ok && /non-public address/i.test(res.error || '')) {
  console.error('\n  SecureHttpClient is blocking localhost (SSRF guard).');
  console.error('  Add HTTP_ALLOW_PRIVATE_HOSTS=true to .env for this dev check, then re-run.\n');
  panelProcess.kill();
  process.exit(2);
}
check('balance is read from the panel', res.ok && res.data && res.data.balance === '250.75',
  JSON.stringify(res).slice(0, 160));

res = adapterCall(GOOD_KEY, 'services');
check('the catalogue comes back as a list of services',
  res.ok && Array.isArray(res.data) && res.data.length === 2, JSON.stringify(res).slice(0, 160));

res = adapterCall(GOOD_KEY, 'add', ['service=101', 'link=https://example.com/x', 'quantity=1000']);
const placedId = res.provider_order_id;
check('an order returns the provider order id', res.ok && !!placedId, JSON.stringify(res).slice(0, 160));

res = adapterCall(GOOD_KEY, 'status', [`orders=${placedId}`]);
check('status comes back keyed by provider order id',
  res.ok && res.data && res.data[placedId] && res.data[placedId].status === 'In progress',
  JSON.stringify(res).slice(0, 160));

console.log('\n── SMM adapter · refusals are refusals, not data');
res = adapterCall('wrong-key', 'balance');
check('a wrong API key fails with the panel\'s own message',
  res.ok === false && /Incorrect API key/.test(res.error || ''), JSON.stringify(res).slice(0, 160));

res = adapterCall('wrong-key', 'services');
check('a rejected catalogue sync fails instead of returning zero services',
  res.ok === false, JSON.stringify(res).slice(0, 160));

res = adapterCall(GOOD_KEY, 'refill', ['order=unknown']);
check('a refused refill is not reported as accepted',
  res.ok === false && /Incorrect order ID/.test(res.error || ''), JSON.stringify(res).slice(0, 160));

res = adapterCall(GOOD_KEY, 'cancel', ['order=unknown']);
check('a refused cancellation is not reported as accepted',
  res.ok === false && /Incorrect order ID/.test(res.error || ''), JSON.stringify(res).slice(0, 160));

panelState({ mode: 'maintenance' });
res = adapterCall(GOOD_KEY, 'services');
check('an HTML maintenance page is not an empty catalogue',
  res.ok === false && /not JSON/i.test(res.error || ''), JSON.stringify(res).slice(0, 160));
panelState({ mode: 'normal' });

console.log('\n── SMM adapter · batching');
const many = [];
const bulk = {};
for (let i = 1; i <= 150; i++) { many.push(String(9000 + i)); bulk[String(9000 + i)] = { status: 'Completed', remains: '0' }; }
panelState({ orders: bulk });
panelReset();
res = adapterCall(GOOD_KEY, 'status', [`orders=${many.join(',')}`]);
const statusCalls = panelState().calls.filter((c) => c.action === 'status');
check('150 ids are split into batches the panel accepts',
  res.ok && statusCalls.length === 2 && statusCalls.every((c) => c.orders.split(',').length <= 100),
  `${statusCalls.length} call(s)`);
check('every id in the batch comes back', res.ok && Object.keys(res.data).length === 150,
  res.ok ? Object.keys(res.data).length + ' ids' : JSON.stringify(res).slice(0, 120));

console.log('\n── SMM pipeline · the cron poller completes a real order');
const seeded = withDb((db) => {
  const cipher = php(['tools/devserver/provider_probe.php', 'encrypt', GOOD_KEY]).trim().split('\n').pop();
  const publicId = 'E2ESMMPROV' + Date.now();
  db.prepare(`INSERT INTO providers
      (public_id, name, api_url, api_key_encrypted, api_type, status, currency, timeout_ms,
       rate_multiplier, markup, sync_interval_minutes, health_status, created_at, updated_at)
     VALUES (?, ?, ?, ?, 'STANDARD_SMM', 'ACTIVE', 'USD', 8000, '1.20000000', '0.00000000', 60,
             'UNKNOWN', datetime('now'), datetime('now'))`)
    .run(publicId, 'E2E Panel ' + Date.now(), PANEL_URL, cipher);
  const provider = db.prepare(`SELECT id FROM providers WHERE public_id = ?`).get(publicId);

  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const service = db.prepare(`SELECT id FROM services LIMIT 1`).get();
  const orderId = 'E2ESMMORD' + Date.now();
  db.prepare(`INSERT INTO orders
      (public_id, user_id, service_id, provider_id, provider_order_id, status, link, quantity,
       charge, rate_at_order, currency, source, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 'PROCESSING', 'https://example.com/handle', 1000,
             '900.00000000', '0.90000000', 'NGN', 'WEB', datetime('now'), datetime('now'))`)
    .run(orderId, user.id, service.id, provider.id, String(placedId || '9000'));
  return { providerPublicId: publicId, orderId };
});

// The panel now reports that order as finished.
panelState({ orders: { [String(placedId)]: { status: 'Completed', remains: '0' } } });
panelReset();
php(['index.php', 'cron', 'order_status']);

const finalStatus = withDb((db) => db.prepare(`SELECT status FROM orders WHERE public_id = ?`).get(seeded.orderId).status);
check('the order is completed from the provider status', finalStatus === 'COMPLETED', `status=${finalStatus}`);
const pollCalls = panelState().calls;
check('the poller asked the panel through the real adapter',
  pollCalls.some((c) => c.action === 'status'), JSON.stringify(pollCalls).slice(0, 120));

// Clean up the provider so later runs (and the dev catalogue) stay tidy.
withDb((db) => {
  db.prepare(`DELETE FROM orders WHERE public_id = ?`).run(seeded.orderId);
  db.prepare(`DELETE FROM providers WHERE public_id = ?`).run(seeded.providerPublicId);
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
}
panelProcess.kill();
process.exit(failed.length ? 1 : 0);
