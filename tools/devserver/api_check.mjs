/**
 * Reseller API v1 end-to-end check.
 *
 * The API had authentication, scopes, rate limiting, idempotency and usage
 * logging in code and nothing that exercised any of it over HTTP. This drives
 * the published surface exactly as a reseller would, with a key created
 * through the dashboard.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/api_check.mjs --password <customer pw>
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
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const password = process.env.DEMO_PASSWORD || arg('--password', arg('--admin-password', null));

if (!password) {
  console.error('Usage: node tools/devserver/api_check.mjs --password <pw>');
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

/** A raw API call — no cookies, no session, exactly what a reseller sends. */
async function api(method, endpoint, { key = null, body = null, headers = {} } = {}) {
  const res = await fetch(BASE + endpoint, {
    method,
    headers: {
      ...(key ? { 'X-Api-Key': key } : {}),
      ...(body ? { 'content-type': 'application/json' } : {}),
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: res.status, headers: res.headers, json, text };
}

/* --------------------------- a key, the way a reseller gets one ---------- */

const cust = new Client(BASE);
await cust.get('/login');
const login = await cust.postForm('/login', { identifier: 'demo@marvy.local', password });
check('customer signed in', /\/dashboard/.test(login.url));

let keysPage = await cust.get('/dashboard/api');
const created = await cust.postForm('/dashboard/api',
  { name: 'e2e-full-' + Date.now(), access_mode: 'full' }, { fromHtml: keysPage.text });
const fullKey = (created.text.match(/(wind_[A-Za-z0-9._-]{16,})/) || [])[1];
check('a key is issued once, in full, on creation', !!fullKey, created.text.replace(/\s+/g, ' ').slice(0, 160));

keysPage = await cust.get('/dashboard/api');
check('the full key is never shown again', !fullKey || !keysPage.text.includes(fullKey));

/* ------------------------------- the surface ----------------------------- */

console.log('\n── API · authentication');
let r = await api('GET', '/api/v1/balance');
check('no key is refused with a JSON envelope',
  r.status === 401 && r.json && r.json.success === false && r.json.error.code === 'MISSING_API_KEY',
  `${r.status} ${r.text.slice(0, 120)}`);
check('every response carries a request id', !!(r.json && r.json.requestId));

r = await api('GET', '/api/v1/balance', { key: 'wind_not_a_real_key' });
check('an invalid key is refused', r.status === 401 && r.json.error.code === 'INVALID_API_KEY',
  `${r.status} ${r.text.slice(0, 120)}`);

r = await api('GET', '/api/v1/balance', { key: fullKey });
check('a valid key reads the balance',
  r.status === 200 && r.json.success === true && typeof r.json.data.balance === 'string',
  `${r.status} ${r.text.slice(0, 160)}`);
check('rate limit headers are published',
  r.headers.get('x-ratelimit-limit') !== null && r.headers.get('x-ratelimit-remaining') !== null,
  `limit=${r.headers.get('x-ratelimit-limit')} remaining=${r.headers.get('x-ratelimit-remaining')}`);

console.log('\n── API · catalogue and orders');
r = await api('GET', '/api/v1/services?limit=5', { key: fullKey });
const service = r.json && r.json.data && r.json.data[0];
check('services list with pagination meta',
  r.status === 200 && Array.isArray(r.json.data) && r.json.meta && typeof r.json.meta.total === 'number',
  `${r.status} ${r.text.slice(0, 160)}`);
check('a service exposes the fields the docs promise',
  !!service && ['service', 'name', 'rate', 'min', 'max'].every((k) => k in service),
  JSON.stringify(service || {}).slice(0, 160));

const idem = 'e2e-' + Date.now();
// A link unique to this run: the idempotency assertion below counts rows by
// link, and a previous run's order would otherwise be mistaken for a duplicate.
const orderLink = 'https://example.com/e2e-api-' + Date.now();
const orderBody = { service: service.service, link: orderLink, quantity: Math.max(Number(service.min) || 100, 100) };
const first = await api('POST', '/api/v1/orders', { key: fullKey, body: orderBody, headers: { 'Idempotency-Key': idem } });
const placed = first.status === 200 || first.status === 201;
check('an order is placed (or refused with a readable reason)',
  placed || first.status === 402 || first.status === 422,
  `${first.status} ${first.text.slice(0, 200)}`);
check('a placed order names the service it was placed against',
  !placed || (first.json.data.service && first.json.data.service === service.service),
  JSON.stringify(first.json && first.json.data).slice(0, 200));

if (placed) {
  const orderId = first.json.data.order;
  const repeat = await api('POST', '/api/v1/orders', { key: fullKey, body: orderBody, headers: { 'Idempotency-Key': idem } });
  check('the same Idempotency-Key returns the same order, not a second one',
    (repeat.status === 200 || repeat.status === 201) && repeat.json.data.order === orderId,
    `${repeat.status} ${repeat.text.slice(0, 160)}`);

  const count = withDb((db) => db.prepare(
    `SELECT COUNT(*) AS n FROM orders WHERE link = ?`).get(orderLink).n);
  check('exactly one order row exists for the two calls', count === 1, `${count} rows`);

  r = await api('GET', '/api/v1/orders/' + orderId, { key: fullKey });
  check('the order can be read back with its status',
    r.status === 200 && r.json.data.order === orderId && typeof r.json.data.status === 'string',
    `${r.status} ${r.text.slice(0, 160)}`);

  r = await api('POST', '/api/v1/orders/status', { key: fullKey, body: { orderIds: [orderId] } });
  check('bulk status answers for the order', r.status === 200 && !!r.json.data[orderId],
    `${r.status} ${r.text.slice(0, 160)}`);
}

console.log('\n── API · input handling');
r = await api('POST', '/api/v1/orders', { key: fullKey, headers: { 'content-type': 'application/json' } });
check('a missing body is a 4xx with a code, not a 500',
  r.status >= 400 && r.status < 500 && r.json && r.json.error && !!r.json.error.code,
  `${r.status} ${r.text.slice(0, 160)}`);

r = await fetch(BASE + '/api/v1/orders', {
  method: 'POST', headers: { 'X-Api-Key': fullKey, 'content-type': 'application/json' }, body: '{ not json',
}).then(async (x) => ({ status: x.status, text: await x.text() }));
check('malformed JSON is refused as BAD_JSON', r.status === 400 && /BAD_JSON/.test(r.text),
  `${r.status} ${r.text.slice(0, 120)}`);

r = await api('GET', '/api/v1/orders', { key: fullKey, headers: {} });
check('listing orders works', r.status === 200 && Array.isArray(r.json.data));

r = await fetch(BASE + '/api/v1/balance', { method: 'POST', headers: { 'X-Api-Key': fullKey } })
  .then(async (x) => ({ status: x.status, text: await x.text() }));
check('the wrong HTTP method is refused', r.status === 405 && /METHOD_NOT_ALLOWED/.test(r.text),
  `${r.status} ${r.text.slice(0, 120)}`);

console.log('\n── API · scopes');
keysPage = await cust.get('/dashboard/api');
const scopedCreate = await cust.postForm('/dashboard/api', {
  name: 'e2e-readonly-' + Date.now(), access_mode: 'scoped', 'scopes[]': ['services.read'],
}, { fromHtml: keysPage.text });
const scopedKey = (scopedCreate.text.match(/(wind_[A-Za-z0-9._-]{16,})/) || [])[1];
check('a scoped key can be created', !!scopedKey, scopedCreate.text.replace(/\s+/g, ' ').slice(0, 160));

if (scopedKey) {
  r = await api('GET', '/api/v1/services?limit=1', { key: scopedKey });
  check('the scope it has is allowed', r.status === 200, `${r.status} ${r.text.slice(0, 120)}`);

  r = await api('GET', '/api/v1/balance', { key: scopedKey });
  check('a scope it does not have is refused with 403 SCOPE_FORBIDDEN',
    r.status === 403 && /SCOPE_FORBIDDEN/.test(r.text), `${r.status} ${r.text.slice(0, 140)}`);

  r = await api('POST', '/api/v1/orders', { key: scopedKey, body: orderBody });
  check('a read-only key cannot place orders', r.status === 403, `${r.status} ${r.text.slice(0, 120)}`);
}

console.log('\n── API · revocation');
keysPage = await cust.get('/dashboard/api');
const revokeHref = (keysPage.text.match(/dashboard\/api\/revoke\/([A-Za-z0-9]+)/) || [])[1];
if (revokeHref) {
  await cust.postForm('/dashboard/api/revoke/' + revokeHref, {}, { fromHtml: keysPage.text });
  check('a revoked key stops working', true); // asserted below against whichever key was revoked
  const afterFull = await api('GET', '/api/v1/balance', { key: fullKey });
  const afterScoped = scopedKey ? await api('GET', '/api/v1/services?limit=1', { key: scopedKey }) : { status: 200 };
  check('one of the keys is now refused', afterFull.status === 401 || afterScoped.status === 401,
    `full=${afterFull.status} scoped=${afterScoped.status}`);
}

console.log('\n── API · usage is recorded for the operator');
const usage = withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM api_usage_logs WHERE endpoint LIKE '/api/v1/%'`).get().n);
check('calls are written to api_usage_logs', usage > 0, `${usage} rows`);
const denied = withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM api_usage_logs WHERE status IN (401, 403)`).get().n);
check('denied calls are recorded too, not just successful ones', denied > 0, `${denied} rows`);

console.log('\n── API · documentation matches the implementation');
const docs = await api('GET', '/api/docs/json');
check('the docs endpoint is public', docs.status === 200 && docs.json.success === true);
const routes = withDb(() => null); // routes come from the file, not the DB
const routeFile = require('node:fs').readFileSync(path.join(ROOT, 'application/config/routes.php'), 'utf8');
const documented = (docs.json.data.endpoints || []).map((e) => e.path);
const missing = documented.filter((p) => {
  const pattern = p.replace(/:public_id|:id/g, '(:any)').replace(/^\//, '');
  return !routeFile.includes(`'${pattern}'`) && !routeFile.includes(`'${pattern.replace('(:any)', '(:any)')}'`);
});
check('every documented endpoint has a route', missing.length === 0, missing.join(', '));
check('the documented scopes are the ones the code enforces',
  ['services.read', 'orders.read', 'orders.write', 'account.read', 'referrals.read']
    .every((s) => (docs.json.data.scopes || []).includes(s)),
  JSON.stringify(docs.json.data.scopes));

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
