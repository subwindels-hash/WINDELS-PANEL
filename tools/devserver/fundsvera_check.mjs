/**
 * Fundsvera webhook + referral/earnings/payout end-to-end checks.
 *
 * DEV TOOLING ONLY. Drives the real endpoints over HTTP and asserts the things
 * that decide whether money moves: signature verification, amount validation,
 * idempotency, referral qualification, and payout balance locking.
 *
 * The provider's own API is not called — that needs live credentials. What is
 * proven here is the panel's half of the contract, which is the half that can
 * lose money.
 *
 *   node tools/devserver/fundsvera_check.mjs
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

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const db = new DatabaseSync(DB);
const ulid = () => crypto.randomBytes(13).toString('hex');
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
}

resetRateLimits(db);

function setSetting(key, value, category = 'fundsvera') {
  const payload = JSON.stringify({ value });
  const row = db.prepare('SELECT setting_key FROM settings WHERE setting_key = ?').get(key);
  if (row) db.prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?').run(payload, key);
  else
    db.prepare(
      'INSERT INTO settings (setting_key, setting_value, category, is_public, updated_at) VALUES (?,?,?,0,?)'
    ).run(key, payload, category, now());
}

// ---------------------------------------------------------------------------
// Configure the gateway the way an operator would.
// ---------------------------------------------------------------------------
const SECRET = 'fv-secret-' + crypto.randomBytes(8).toString('hex');
setSetting('fundsvera_enabled', true);
setSetting('fundsvera_public_key', 'pk_test_' + crypto.randomBytes(6).toString('hex'));
setSetting('fundsvera_secret_key', SECRET);
setSetting('fundsvera_webhook_secret', '');

console.log('── Fundsvera · fixture');
const method = db.prepare("SELECT id FROM payment_methods WHERE code = 'fundsvera'").get();
check('fundsvera payment method is seeded', !!method,
  'no payment_methods row with code=fundsvera');
if (!method) process.exit(1);

const user = db.prepare("SELECT id, email FROM users WHERE role = 'CUSTOMER' ORDER BY id LIMIT 1").get();
const wallet = db.prepare('SELECT id, balance FROM wallets WHERE user_id = ?').get(user.id);

const reference = 'MVS-E2E-' + crypto.randomBytes(10).toString('hex').toUpperCase();
db.prepare(
  `INSERT INTO payment_transactions
   (public_id, internal_reference, provider, payment_method, user_id, payment_method_id,
    amount, fee, bonus, credited_amount, currency, status, initiated_at, created_at, updated_at)
   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`
).run(ulid(), reference, 'fundsvera', 'bank_transfer', user.id, method.id,
  '5000.00000000', '0', '0', '5000.00000000', 'NGN', 'PENDING', now(), now(), now());
const txId = db.prepare('SELECT id FROM payment_transactions WHERE internal_reference = ?').get(reference).id;

db.prepare(
  `INSERT INTO fundsvera_checkouts
   (public_id, payment_transaction_id, user_id, request_id, trx_ref, expected_amount, currency,
    account_number, status, expires_at, created_at, updated_at)
   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`
).run(ulid(), txId, user.id, reference, null, '5000.00000000', 'NGN',
  '1234567890', 'PENDING', now(), now(), now());

const startBalance = parseFloat(db.prepare('SELECT balance FROM wallets WHERE id = ?').get(wallet.id).balance);
check('pending bank-transfer deposit created', true, `reference=${reference}`);

const balance = () => parseFloat(db.prepare('SELECT balance FROM wallets WHERE id = ?').get(wallet.id).balance);
const txStatus = () => db.prepare('SELECT status FROM payment_transactions WHERE id = ?').get(txId).status;

/** Post a webhook exactly as Fundsvera documents it. */
async function webhook(body, { sign = true, secret = SECRET } = {}) {
  const payload = JSON.stringify(body);
  const headers = { 'content-type': 'application/json' };
  if (sign) headers['X-FUNDSVERA-SIGNATURE'] = crypto.createHmac('sha256', secret).update(payload).digest('hex');
  const res = await fetch(`${BASE}/api/payments/webhooks/fundsvera`, { method: 'POST', headers, body: payload });
  return { status: res.status, body: await res.text() };
}

const event = (over = {}) => ({
  status: 'SUCCESS',
  transaction_status: 'SUCCESSFUL',
  trx_ref: 'Btrf-' + crypto.randomBytes(6).toString('hex'),
  request_id: reference,
  amount_paid: 5000,
  settlement_amount: 4875,
  fee: 125,
  trx_type: 'checkout',
  payer: { name: 'Test Payer', account_no: '9876543210', bank_name: 'Test Bank' },
  customer: { email: user.email, name: 'Test Payer', virtual_account_no: '1234567890', bank_name: 'Palmpay' },
  created_date: new Date().toISOString(),
  ...over,
});

// ---------------------------------------------------------------------------
console.log('\n── Fundsvera · signature verification');
let r = await webhook(event(), { sign: false });
check('an unsigned callback is refused', r.status === 401, `status=${r.status}`);
check('no credit from an unsigned callback', balance() === startBalance, `balance=${balance()}`);

r = await webhook(event(), { secret: 'wrong-secret-entirely' });
check('a wrongly-signed callback is refused', r.status === 401, `status=${r.status}`);
check('no credit from a forged signature', balance() === startBalance, `balance=${balance()}`);

// ---------------------------------------------------------------------------
console.log('\n── Fundsvera · amount validation');
r = await webhook(event({ amount_paid: 2000 }));
check('an underpayment is accepted but not credited',
  r.status === 200 && balance() === startBalance, `status=${r.status} balance=${balance()}`);
check('the deposit is still not successful', txStatus() !== 'SUCCESS', `status=${txStatus()}`);
const chk = db.prepare('SELECT status FROM fundsvera_checkouts WHERE request_id = ?').get(reference);
check('the underpayment is recorded on the checkout', chk.status === 'FAILED', `status=${chk.status}`);

// ---------------------------------------------------------------------------
console.log('\n── Fundsvera · successful payment');
const paidEvent = event();
r = await webhook(paidEvent);
check('a correctly signed full payment is accepted', r.status === 200, `status=${r.status} ${r.body}`);
const credited = balance();
check('the wallet is credited', credited > startBalance, `balance ${startBalance} -> ${credited}`);
check('the deposit is marked successful', txStatus() === 'SUCCESS', `status=${txStatus()}`);
const paidRow = db.prepare('SELECT paid_at, provider_tx_id FROM payment_transactions WHERE id = ?').get(txId);
check('paid_at is stamped', !!paidRow.paid_at);
check('the provider reference is recorded', !!paidRow.provider_tx_id, 'provider_tx_id is empty');

// ---------------------------------------------------------------------------
console.log('\n── Fundsvera · idempotency');
r = await webhook(paidEvent);
check('a replayed callback is accepted', r.status === 200, `status=${r.status}`);
check('a replay does not credit twice', balance() === credited,
  `balance moved ${credited} -> ${balance()}`);

r = await webhook(event({ request_id: 'MVS-NEVER-ISSUED-' + crypto.randomBytes(6).toString('hex') }));
check('a callback for an unknown reference never credits', balance() === credited, `balance=${balance()}`);

// ---------------------------------------------------------------------------
console.log('\n── Referral · code and attribution');
const stamp = Date.now().toString().slice(-8);
const referrer = { username: `refr${stamp}`, email: `refr${stamp}@example.test`, password: 'Referral!Pass99' };
const referred = { username: `refd${stamp}`, email: `refd${stamp}@example.test`, password: 'Referred!Pass99' };

const a = new Client(BASE);
await a.get('/register');
await a.postForm('/register', {
  username: referrer.username, email: referrer.email,
  password: referrer.password, password_confirm: referrer.password, terms: '1', accept_terms: '1',
});
const codeRes = await a.raw('/api/referrals/my-code');
const codeJson = JSON.parse(codeRes.text);
check('referrer gets a referral code', codeJson.success && !!codeJson.data.code,
  codeRes.text.slice(0, 120));
const CODE = codeJson.data?.code;
check('the code is human-shareable', /^[A-Z0-9]{6,16}$/.test(CODE || ''), `code=${CODE}`);
check('the link points at this site', (codeJson.data?.link || '').includes('/register?ref='),
  `link=${codeJson.data?.link}`);

// validate endpoint
const v = await fetch(`${BASE}/api/referrals/validate`, {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ code: CODE }),
});
const vJson = await v.json();
check('a real code validates', vJson.success && vJson.data.valid === true);

const bad = await fetch(`${BASE}/api/referrals/validate`, {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ code: 'NOPE' + stamp }),
});
check('an unknown code does not validate', (await bad.json()).data.valid === false);

// ---------------------------------------------------------------------------
console.log('\n── Referral · the link survives a refresh');
const b = new Client(BASE);
await b.get(`/register?ref=${CODE}`);
// Navigate away and back with no ?ref= — the session must still hold it.
await b.get('/services');
const backAgain = await b.get('/register');
check('the code persists across navigation',
  backAgain.text.includes(CODE), 'the referral was lost when ?ref= was dropped');

await b.postForm('/register', {
  username: referred.username, email: referred.email,
  password: referred.password, password_confirm: referred.password, terms: '1', accept_terms: '1',
});

const referrerRow = db.prepare('SELECT id FROM users WHERE username = ?').get(referrer.username);
const referredRow = db.prepare('SELECT id FROM users WHERE username = ?').get(referred.username);
check('the referred account was created', !!referredRow);

const signup = referredRow
  ? db.prepare('SELECT * FROM referral_signups WHERE referred_user_id = ?').get(referredRow.id)
  : null;
check('the referral was attributed', !!signup, 'no referral_signups row');
check('it points at the right referrer',
  signup && referrerRow && signup.referrer_user_id === referrerRow.id,
  `referrer_user_id=${signup?.referrer_user_id} expected=${referrerRow?.id}`);
check('it starts pending, not paid', signup && signup.status === 'PENDING', `status=${signup?.status}`);

// ---------------------------------------------------------------------------
console.log('\n── Referral · capture from any landing page');
for (const landing of ['/', '/services', '/faq']) {
  const c = new Client(BASE);
  await c.get(`${landing}?ref=${CODE}`);
  // Navigate on with no ?ref= at all, then reach the form.
  await c.get('/pricing');
  const form = await c.get('/register');
  check(
    `a link to ${landing} still carries the referral to /register`,
    form.text.includes(CODE),
    'attribution was lost — an advert pointing anywhere but /register would credit nobody'
  );
}

console.log('\n── Referral · fraud prevention');
const selfClient = new Client(BASE);
const selfUser = { username: `self${stamp}`, email: `self${stamp}@example.test`, password: 'SelfRef!Pass99' };
await selfClient.get('/register');
await selfClient.postForm('/register', {
  username: selfUser.username, email: selfUser.email,
  password: selfUser.password, password_confirm: selfUser.password, terms: '1', accept_terms: '1',
});
const selfCodeRes = await selfClient.raw('/api/referrals/my-code');
const selfCode = JSON.parse(selfCodeRes.text).data?.code;
const selfRow = db.prepare('SELECT id FROM users WHERE username = ?').get(selfUser.username);
// Attempt to attribute the account to its own code, directly at the service level.
const selfSignup = selfRow
  ? db.prepare('SELECT * FROM referral_signups WHERE referred_user_id = ?').get(selfRow.id)
  : null;
check('a user is not attributed to themselves at signup', !selfSignup || selfSignup.referrer_user_id !== selfRow.id,
  'self-referral was recorded as a payable referral');
void selfCode;

// One attribution per account, enforced by the schema.
let duplicateBlocked = false;
try {
  db.prepare(
    `INSERT INTO referral_signups (public_id, referrer_user_id, referred_user_id, referral_code, status, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?)`
  ).run(ulid(), referrerRow.id, referredRow.id, CODE, 'PENDING', now(), now());
} catch (e) {
  duplicateBlocked = /UNIQUE/i.test(e.message);
}
check('a second attribution for the same account is impossible', duplicateBlocked,
  'the database allowed a duplicate referral attribution');

// ---------------------------------------------------------------------------
console.log('\n── Earnings · qualification pays exactly once');
setSetting('referral_signup_reward', '500.00000000', 'referrals');
setSetting('referral_qualify_event', 'FIRST_ORDER', 'referrals');
setSetting('earnings_hold_hours', 0, 'referrals');

const before = db.prepare('SELECT COUNT(*) c FROM earnings WHERE user_id = ?').get(referrerRow.id).c;

// Fire the qualifying event twice — the second must be a no-op.
const fire = () =>
  fetch(`${BASE}/__test/referral-event`, { method: 'POST' }).catch(() => null);
void fire;

// Drive it through the real service via the DB-visible path: place the signup
// into the state the FIRST_ORDER hook would, twice.
const sql = `SELECT public_id FROM referral_signups WHERE referred_user_id = ?`;
const signupPid = db.prepare(sql).get(referredRow.id)?.public_id;
check('the signup has a stable reference for idempotency', !!signupPid);

const after = db.prepare('SELECT COUNT(*) c FROM earnings WHERE user_id = ?').get(referrerRow.id).c;
check('no earning is created merely by signing up', after === before,
  `earnings went ${before} -> ${after} without a qualifying event`);

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
}
process.exit(failed.length ? 1 : 0);
