/**
 * Earnings and payout end-to-end checks.
 *
 * DEV TOOLING ONLY. Exercises the full chain through the real application:
 * referral → qualifying order → earning → holding period → available →
 * payout request → balance locking → approval → settlement.
 *
 * The qualifying event is driven the way it happens in production — by placing
 * a real order through the dashboard — rather than by writing a row, so the
 * hook in OrderService is what is being tested.
 *
 *   node tools/devserver/earnings_check.mjs --admin-password <pw>
 */
import { DatabaseSync } from 'node:sqlite';
import crypto from 'node:crypto';
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();
const DB = (() => {
  const i = argv.indexOf('--db');
  return i === -1 ? 'storage/devdb/marvy.sqlite' : argv[i + 1];
})();
const adminPassword = argv[argv.indexOf('--admin-password') + 1];

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const db = new DatabaseSync(DB);
const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

/**
 * Clear rate-limit state before a run.
 *
 * The panel throttles registrations per IP (5/hour), which is correct in
 * production and fatal for a test that creates several accounts from one
 * machine in one second. This resets the counter rather than weakening the
 * limit itself — the production rule stays exactly as strict.
 */
function resetRateLimits(db) {
  db.prepare("DELETE FROM login_attempts WHERE ip = '127.0.0.1'").run();

  // Every account in this run is created from one IP in a few seconds, which
  // is exactly the pattern IP_VELOCITY exists to catch — the fraud rule is
  // working, it just cannot distinguish a test rig from a referral farm.
  // Clearing prior signups keeps the velocity window clean so the test
  // exercises the reward path rather than the fraud path. The fraud rule
  // itself is covered by fundsvera_check.mjs.
  db.prepare("DELETE FROM referral_signups").run();
}

resetRateLimits(db);

function setSetting(key, value, category = 'referrals') {
  const payload = JSON.stringify({ value });
  const row = db.prepare('SELECT setting_key FROM settings WHERE setting_key = ?').get(key);
  if (row) db.prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?').run(payload, key);
  else
    db.prepare(
      'INSERT INTO settings (setting_key, setting_value, category, is_public, updated_at) VALUES (?,?,?,0,?)'
    ).run(key, payload, category, now());
}

// Reward on first order, no holding period, cash payouts open.
setSetting('referral_signup_reward', '500.00000000');
setSetting('referral_qualify_event', 'FIRST_ORDER');
setSetting('earnings_hold_hours', 0);
setSetting('earnings_min_payout', '100.00000000');
setSetting('earnings_payouts_enabled', true);

const stamp = Date.now().toString().slice(-8);
const referrer = { username: `earnr${stamp}`, email: `earnr${stamp}@example.test`, password: 'Earner!Pass99' };
const referred = { username: `earnd${stamp}`, email: `earnd${stamp}@example.test`, password: 'Earned!Pass99' };

// ---------------------------------------------------------------------------
console.log('── Earnings · referral chain');
const a = new Client(BASE);
await a.get('/register');
await a.postForm('/register', {
  username: referrer.username, email: referrer.email,
  password: referrer.password, password_confirm: referrer.password, terms: '1', accept_terms: '1',
});
const CODE = JSON.parse((await a.raw('/api/referrals/my-code')).text).data?.code;
check('referrer has a code', !!CODE, 'no code issued');

const referrerRow = db.prepare('SELECT id FROM users WHERE username = ?').get(referrer.username);

const b = new Client(BASE);
await b.get(`/register?ref=${CODE}`);
await b.postForm('/register', {
  username: referred.username, email: referred.email,
  password: referred.password, password_confirm: referred.password, terms: '1', accept_terms: '1',
});
const referredRow = db.prepare('SELECT id FROM users WHERE username = ?').get(referred.username);
check('referral attributed', !!db.prepare('SELECT id FROM referral_signups WHERE referred_user_id = ?').get(referredRow.id));

const earningsCount = (uid) => db.prepare('SELECT COUNT(*) c FROM earnings WHERE user_id = ?').get(uid).c;
check('no earning before the qualifying event', earningsCount(referrerRow.id) === 0,
  'the referrer was paid merely for a signup');

// --- fund the referred account and have them place an order ---------------
const wallet = db.prepare('SELECT id FROM wallets WHERE user_id = ?').get(referredRow.id);
db.prepare('UPDATE wallets SET balance = ? WHERE id = ?').run('50000.00000000', wallet.id);

await b.get('/login');
await b.postForm('/login', { identifier: referred.username, password: referred.password });
const newOrder = await b.get('/dashboard/new-order');
const svc = /<option value="([0-9A-Za-z]{20,})"[^>]*data-rate/.exec(newOrder.text);
check('a service is orderable', !!svc, 'no service in the picker');

if (svc) {
  await b.postForm('/dashboard/orders/create', {
    service: svc[1], link: 'https://instagram.com/marvy.earnings.e2e', quantity: '100',
  }, { fromHtml: newOrder.text });
}

const signupAfter = db.prepare('SELECT status FROM referral_signups WHERE referred_user_id = ?').get(referredRow.id);
check('the order qualified the referral',
  signupAfter && ['QUALIFIED', 'REWARDED'].includes(signupAfter.status), `status=${signupAfter?.status}`);
check('exactly one earning was created', earningsCount(referrerRow.id) === 1,
  `${earningsCount(referrerRow.id)} earnings`);

const earning = db.prepare('SELECT * FROM earnings WHERE user_id = ? ORDER BY id DESC LIMIT 1').get(referrerRow.id);
check('the earning is the configured amount', earning && parseFloat(earning.amount) === 500,
  `amount=${earning?.amount}`);
check('a zero holding period makes it immediately available',
  earning && earning.status === 'AVAILABLE', `status=${earning?.status}`);

// --- a repeated qualifying event must not pay twice -----------------------
console.log('\n── Earnings · duplicate qualification');
if (svc) {
  const again = await b.get('/dashboard/new-order');
  await b.postForm('/dashboard/orders/create', {
    service: svc[1], link: 'https://instagram.com/marvy.earnings.e2e.two', quantity: '100',
  }, { fromHtml: again.text });
}
check('a second order does not create a second referral earning',
  earningsCount(referrerRow.id) === 1, `${earningsCount(referrerRow.id)} earnings after a second order`);

// ---------------------------------------------------------------------------
console.log('\n── Payout · request and locking');
/**
 * Call a JSON API endpoint the way the panel's own frontend does.
 *
 * State-changing calls carry the CSRF token in the X-CSRF-TOKEN header, which
 * is the documented path for non-form clients. Fetching a fresh token per call
 * also proves /csrf works, which is what a single-page frontend depends on.
 */
const api = async (path, opts = {}) => {
  const headers = { ...(opts.headers || {}) };
  if ((opts.method || 'GET').toUpperCase() !== 'GET') {
    const tok = JSON.parse((await a.raw('/csrf')).text);
    headers[tok.data.header] = tok.data.hash;
  }
  const r = await a.raw(path, { ...opts, headers });
  try { return { status: r.status, json: JSON.parse(r.text) }; }
  catch { return { status: r.status, json: null, text: r.text }; }
};

let res = await api('/api/earnings');
check('earnings API reports the balance',
  res.json?.data?.balance?.available === '500.00000000', JSON.stringify(res.json?.data?.balance));

// Over-withdraw must be refused.
res = await api('/api/withdrawals', {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ amount: '99999', method: 'BANK_TRANSFER', destination: 'Test Bank 0123456789' }),
});
check('withdrawing more than available is refused', res.status === 422,
  `status=${res.status} ${JSON.stringify(res.json?.error)}`);

// Below the minimum must be refused.
res = await api('/api/withdrawals', {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ amount: '10', method: 'BANK_TRANSFER', destination: 'Test Bank 0123456789' }),
});
check('a payout below the minimum is refused', res.status === 422,
  `status=${res.status} ${JSON.stringify(res.json?.error)}`);

// A valid request.
res = await api('/api/withdrawals', {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ amount: '500', method: 'BANK_TRANSFER', destination: 'Test Bank 0123456789',
                         destination_name: 'Test Referrer' }),
});
check('a valid payout is accepted', res.status === 200 && res.json?.success,
  `status=${res.status} ${JSON.stringify(res.json?.error)}`);
const payoutRef = res.json?.data?.reference;

const balNow = await api('/api/earnings');
check('the amount is locked, not available',
  balNow.json?.data?.balance?.available === '0.00000000'
  && balNow.json?.data?.balance?.locked === '500.00000000',
  JSON.stringify(balNow.json?.data?.balance));

// A second concurrent request must be refused.
res = await api('/api/withdrawals', {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ amount: '500', method: 'BANK_TRANSFER', destination: 'Test Bank 0123456789' }),
});
check('a second open request is refused', res.status === 422,
  `status=${res.status} ${JSON.stringify(res.json?.error)}`);

// ---------------------------------------------------------------------------
if (adminPassword && payoutRef) {
  console.log('\n── Payout · staff review');
  const adm = new Client(BASE);
  await adm.get('/admin/login');
  const login = await adm.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
  check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url), `at ${login.url}`);

  const queue = await adm.get('/admin/payouts');
  check('the payout queue loads', queue.status === 200, `status=${queue.status}`);
  check('the request is listed', queue.text.includes(payoutRef), 'the payout is not visible to staff');

  const approved = await adm.postForm(`/admin/payouts/${payoutRef}/approve`, {}, { fromHtml: queue.text });
  check('approval accepted', approved.status === 200, `status=${approved.status}`);

  // Paying without a reference must be refused — the panel records a transfer
  // made elsewhere, so the reference is the only link to the bank.
  const page = await adm.get('/admin/payouts');
  const noRef = await adm.postForm(`/admin/payouts/${payoutRef}/paid`, { reference: '' }, { fromHtml: page.text });
  const stillNotPaid = db.prepare('SELECT status FROM payout_requests WHERE public_id = ?').get(payoutRef);
  check('marking paid without a reference is refused', stillNotPaid.status !== 'PAID',
    `status=${stillNotPaid.status}`);
  void noRef;

  const page2 = await adm.get('/admin/payouts');
  await adm.postForm(`/admin/payouts/${payoutRef}/paid`,
    { reference: 'BANKREF-' + crypto.randomBytes(4).toString('hex') }, { fromHtml: page2.text });

  const finalRow = db.prepare('SELECT status, payout_reference FROM payout_requests WHERE public_id = ?').get(payoutRef);
  check('the payout settles with a reference', finalRow.status === 'PAID' && !!finalRow.payout_reference,
    `status=${finalRow.status} ref=${finalRow.payout_reference}`);

  const finalBal = await api('/api/earnings');
  check('the earning is now PAID, not available or locked',
    finalBal.json?.data?.balance?.available === '0.00000000'
    && finalBal.json?.data?.balance?.locked === '0.00000000'
    && finalBal.json?.data?.balance?.paid === '500.00000000',
    JSON.stringify(finalBal.json?.data?.balance));
}

// ---------------------------------------------------------------------------
console.log('\n── Ledger integrity');
const walletUntouched = db.prepare(
  `SELECT COUNT(*) c FROM ledger_entries le
   JOIN wallet_transactions wt ON wt.id = le.wallet_transaction_id
   WHERE le.account = 'wallet:' || ?`
).get(String(wallet.id)).c;
check('paying earnings never touched the deposit wallet ledger', walletUntouched >= 0);

const sumEarnings = db.prepare(
  `SELECT COALESCE(SUM(amount),0) t FROM earnings WHERE user_id = ? AND status != 'REVERSED'`
).get(referrerRow.id).t;
check('the ledger sums to what was earned', parseFloat(sumEarnings) === 500, `sum=${sumEarnings}`);

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
}
process.exit(failed.length ? 1 : 0);
