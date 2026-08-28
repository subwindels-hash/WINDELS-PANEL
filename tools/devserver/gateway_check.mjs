/**
 * Hosted gateway end-to-end check.
 *
 * Proves the whole deposit path for a hosted gateway without a live provider
 * account: admin configuration → what the customer is offered → a signed
 * webhook crediting the wallet exactly once → replay and forgery refused.
 *
 * The provider's own API is unreachable from this sandbox, which is itself
 * worth asserting: an unreachable gateway must produce a readable refusal and
 * NO wallet movement, never a half-finished deposit.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/gateway_check.mjs --admin-password <pw>
 */
import path from 'node:path';
import crypto from 'node:crypto';
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
  console.error('Usage: node tools/devserver/gateway_check.mjs --admin-password <pw>');
  process.exit(2);
}

const SECRET = 'sk_test_devserver_secret';
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

function settingsForm(html) {
  const fields = {};
  // Text/number/secret inputs only. A checkbox also carries value="1", so
  // scooping those up here would re-post every boolean setting as ON — which
  // is exactly how an earlier version of this script switched the panel into
  // maintenance mode.
  for (const m of html.matchAll(/<input(?![^>]*type="checkbox")[^>]*id="set-([a-z0-9_]+)"[^>]*value="([^"]*)"/g)) {
    fields[m[1]] = m[2];
  }
  for (const m of html.matchAll(/<textarea[^>]*id="set-([a-z0-9_]+)"[^>]*>([\s\S]*?)<\/textarea>/g)) {
    fields[m[1]] = m[2];
  }
  for (const m of html.matchAll(/<select[^>]*id="set-([a-z0-9_]+)"[\s\S]*?<\/select>/g)) {
    const sel = /<option[^>]*value="([^"]*)"[^>]*selected/.exec(m[0]);
    if (sel) fields[m[1]] = sel[1];
  }
  for (const m of html.matchAll(/name="__rendered_([a-z0-9_]+)"/g)) {
    const key = m[1];
    const on = new RegExp(`id="set-${key}"[^>]*checked`).test(html);
    fields[`__rendered_${key}`] = '1';
    if (on) fields[key] = '1';
  }
  return fields;
}

const admin = new Client(BASE);
await admin.get('/admin/login');
const login = await admin.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

// ---------------------------------------------------------------------------
console.log('\n── Gateways · the admin can see and switch deposit methods');
let methods = await admin.get('/admin/payments/methods');
check('deposit methods screen loads', methods.status === 200);
check('every seeded gateway is listed',
  ['paystack', 'flutterwave', 'stripe', 'paypal', 'razorpay', 'coinpayments']
    .every((code) => methods.text.includes(`(${code})`)));
check('an unconfigured gateway is flagged, not silently broken', /Not configured/.test(methods.text));
check('manual transfer is not reported as missing credentials', /Manual review/.test(methods.text));

// Switch Razorpay on while it has no credentials at all — this script never
// configures it, so it stays the "enabled but unusable" case.
let save = await admin.postForm('/admin/payments/methods/razorpay/save', {
  is_active: '1', name: 'Razorpay', fee_percent: '0', fee_fixed: '0', bonus_percent: '0',
  min_amount: '500', max_amount: '1000000', sorting: '60',
}, { fromHtml: methods.text });
check('enabling a gateway warns that it still needs credentials',
  /stays hidden from Add funds/i.test(save.text));

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login', { identifier: 'demo@marvy.local', password: customerPassword });
check('customer signed in', /\/dashboard/.test(clogin.url));

let addFunds = await cust.get('/dashboard/add-funds');
check('an enabled but unconfigured gateway is NOT offered to customers',
  addFunds.status === 200 && !/value="razorpay"/.test(addFunds.text));

// ---------------------------------------------------------------------------
console.log('\n── Gateways · configuring credentials makes the method payable');
let settings = await admin.get('/admin/settings');
const fields = settingsForm(settings.text);
fields['paystack_secret_key'] = SECRET;
fields['paystack_enabled'] = '1';
fields['__rendered_paystack_enabled'] = '1';
methods = await admin.get('/admin/payments/methods');
await admin.postForm('/admin/payments/methods/paystack/save', {
  is_active: '1', name: 'Paystack', fee_percent: '0', fee_fixed: '0', bonus_percent: '0',
  min_amount: '500', max_amount: '1000000', sorting: '40',
}, { fromHtml: methods.text });
let saved = await admin.postForm('/admin/settings/save', fields, { fromHtml: settings.text });
check('gateway credentials save from the admin settings screen', saved.status === 200 && /setting/i.test(saved.text));

addFunds = await cust.get('/dashboard/add-funds');
check('the configured gateway now appears on Add funds', /value="paystack"/.test(addFunds.text));

// ---------------------------------------------------------------------------
console.log('\n── Gateways · an unreachable provider refuses cleanly');
const before = withDb((db) => db.prepare(
  `SELECT balance FROM wallets WHERE user_id = (SELECT id FROM users WHERE email = 'demo@marvy.local')`
).get());

addFunds = await cust.get('/dashboard/add-funds');
const attempt = await cust.postForm('/dashboard/wallet/deposit', {
  payment_method: 'paystack', amount: '1500', idempotency_key: 'gw-check-' + Date.now(),
}, { fromHtml: addFunds.text });
check('the deposit attempt is handled, not fatal', attempt.status === 200 || attempt.status === 302);
check('the customer is told the provider could not be reached',
  /could not reach the payment provider|rejected the request|not configured/i.test(attempt.text),
  attempt.text.replace(/\s+/g, ' ').slice(0, 200));

const afterAttempt = withDb((db) => db.prepare(
  `SELECT balance FROM wallets WHERE user_id = (SELECT id FROM users WHERE email = 'demo@marvy.local')`
).get());
check('a failed initiation moves no money', String(before.balance) === String(afterAttempt.balance),
  `${before.balance} -> ${afterAttempt.balance}`);

// ---------------------------------------------------------------------------
console.log('\n── Gateways · a signed webhook credits the wallet exactly once');
// A pending deposit as the gateway would have left it: our own reference is
// stored as provider_tx_id, which is what the callback is matched on.
const reference = 'MVS-GWCHECK' + Date.now();
withDb((db) => {
  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const method = db.prepare(`SELECT id FROM payment_methods WHERE code = 'paystack'`).get();
  db.prepare(`INSERT INTO payment_transactions
      (public_id, internal_reference, user_id, payment_method_id, provider, provider_tx_id,
       amount, fee, bonus, credited_amount, currency, status, idempotency_key, created_at)
     VALUES (?, ?, ?, ?, 'paystack', ?, '1500.00000000', '0.00000000', '0.00000000',
             '1500.00000000', 'NGN', 'PENDING', ?, datetime('now'))`)
    .run(reference.replace('MVS-', ''), reference, user.id, method.id, reference, 'idem-' + reference);
});

const payload = JSON.stringify({
  event: 'charge.success',
  data: { id: Date.now(), reference, status: 'success', amount: 150000, currency: 'NGN' },
});
const signature = crypto.createHmac('sha512', SECRET).update(payload).digest('hex');

async function postWebhook(body, sig) {
  const res = await fetch(`${BASE}/webhook/paystack`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-paystack-signature': sig },
    body,
  });
  return { status: res.status, text: await res.text() };
}

const balanceBefore = withDb((db) => db.prepare(
  `SELECT balance FROM wallets WHERE user_id = (SELECT id FROM users WHERE email = 'demo@marvy.local')`
).get().balance);

const forged = await postWebhook(payload, 'not-the-signature');
check('a forged signature is refused with 401', forged.status === 401, `status=${forged.status}`);

// The forged delivery above carried the SAME event id. A gateway event id is
// guessable, so a refused row must never be mistaken for "already handled" —
// otherwise anyone could poison an id and stop the real payment crediting.
const first = await postWebhook(payload, signature);
check('a correctly signed callback is accepted', first.status === 200, `status=${first.status} body=${first.text}`);

const afterCredit = withDb((db) => {
  const balance = db.prepare(
    `SELECT balance FROM wallets WHERE user_id = (SELECT id FROM users WHERE email = 'demo@marvy.local')`
  ).get().balance;
  const tx = db.prepare(`SELECT status FROM payment_transactions WHERE internal_reference = ?`).get(reference);
  return { balance, status: tx ? tx.status : null };
});
check('the deposit is marked successful', afterCredit.status === 'SUCCESS', `status=${afterCredit.status}`);
check('the wallet is credited by the deposit amount',
  Number(afterCredit.balance) - Number(balanceBefore) === 1500,
  `${balanceBefore} -> ${afterCredit.balance}`);

const replay = await postWebhook(payload, signature);
check('replaying the same event is a no-op', replay.status === 200 && /duplicate/.test(replay.text), replay.text);

const afterReplay = withDb((db) => db.prepare(
  `SELECT balance FROM wallets WHERE user_id = (SELECT id FROM users WHERE email = 'demo@marvy.local')`
).get().balance);
check('a replayed callback never double-credits',
  String(afterReplay) === String(afterCredit.balance), `${afterCredit.balance} -> ${afterReplay}`);

// ---------------------------------------------------------------------------
console.log('\n── Gateways · the callback is visible to staff');
const hooks = await admin.get('/admin/payments/webhooks');
check('the webhook log lists the paystack event', hooks.status === 200 && /paystack/i.test(hooks.text));

// ---------------------------------------------------------------------------
// Leave the panel as we found it.
settings = await admin.get('/admin/settings');
const reset = settingsForm(settings.text);
delete reset['paystack_enabled'];
reset['__rendered_paystack_enabled'] = '1';
await admin.postForm('/admin/settings/save', reset, { fromHtml: settings.text });
methods = await admin.get('/admin/payments/methods');
await admin.postForm('/admin/payments/methods/paystack/save', {
  name: 'Paystack', fee_percent: '0', fee_fixed: '0', bonus_percent: '0', sorting: '40',
}, { fromHtml: methods.text });
methods = await admin.get('/admin/payments/methods');
await admin.postForm('/admin/payments/methods/razorpay/save', {
  name: 'Razorpay', fee_percent: '0', fee_fixed: '0', bonus_percent: '0', sorting: '60',
}, { fromHtml: methods.text });

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
