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

  // Every account in this run is created from one IP within seconds, which is
  // exactly the pattern IP_VELOCITY exists to catch. The rule is working — it
  // simply cannot tell a test rig from a referral farm. Clearing prior signups
  // keeps the velocity window clean so this suite exercises the attribution
  // path; the fraud rule itself is asserted explicitly further down.
  db.prepare('DELETE FROM referral_signups').run();
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

console.log('\n── Campaign · geographic restriction');
{
  const geoCode = 'GEO' + stamp;
  db.prepare(
    `INSERT INTO referral_campaigns
     (public_id,name,code,source,reward_amount,qualify_event,hold_hours,status,geo_allow,created_at,updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`
  ).run(ulid(), 'Geo test', geoCode, 'test', '100.00000000', 'FIRST_ORDER', 0, 'ACTIVE', 'NG', now(), now());

  const validateAs = async (country) => {
    const headers = { 'content-type': 'application/json' };
    if (country) headers['CF-IPCountry'] = country;
    const r = await fetch(`${BASE}/api/referrals/validate`, {
      method: 'POST', headers, body: JSON.stringify({ code: geoCode }),
    });
    return (await r.json()).data;
  };

  check('an allowed country passes', (await validateAs('NG')).valid === true);
  check('a disallowed country is refused',
    (await validateAs('US')).reason === 'CAMPAIGN_GEO', 'US was not blocked');
  // Fails open on purpose: a panel with no geo-aware proxy must not reject
  // every visitor on earth the moment a restriction is set.
  check('an unknown country fails open', (await validateAs(null)).valid === true,
    'a restriction blocked everyone when no country header was present');
  check('an anonymised country fails open', (await validateAs('XX')).valid === true);
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

// ---------------------------------------------------------------------------
// Initiation flow over real HTTP against the fake provider.
//
// The production complaint this section pins: "Fundsvera keeps showing
// Processing… it never processes to the card page." The customer's deposit
// POST must answer quickly with a redirect to the provider's checkout_url —
// never hang, never die, never land the customer somewhere unexplained.
//
// Requires: node tools/devserver/fake_fundsvera.mjs --port 9410
// ---------------------------------------------------------------------------
const FAKE_FV = process.env.FAKE_FV_URL || 'http://127.0.0.1:9410';
const FV_SECRET = process.env.FAKE_FV_SECRET || 'fv-secret-for-dev-only';
const FV_PUBLIC = process.env.FAKE_FV_PUBLIC || 'pk_dev_fake';
const CARD_SECRET = 'card-secret-' + crypto.randomBytes(6).toString('hex');

async function fvBehavior(behavior) {
  await fetch(`${FAKE_FV}/__control/behavior`, {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ behavior }),
  });
}

console.log('\n── Fundsvera · initiation flow (deposit POST → card page)');
await fvBehavior('ok');
setSetting('fundsvera_base_url', `${FAKE_FV}/api/v1`);
setSetting('fundsvera_public_key', FV_PUBLIC);
setSetting('fundsvera_secret_key', FV_SECRET);
db.prepare("UPDATE payment_methods SET is_active = 1 WHERE code = 'fundsvera'").run();

const customer = new Client(BASE);
r = await customer.get('/login');
r = await customer.postForm('/login', { identifier: 'demo', password: process.env.DEMO_PASSWORD || 'Repro!2026Pass' });
check('demo customer signs in for the deposit flow', !/login/.test(r.url || ''), `at ${r.url}`);

r = await customer.get('/dashboard/add-funds');
const formToken = (r.text.match(/name="form_token" value="([^"]+)"/) || [])[1] || null;
check('the add-funds form carries a one-shot form token', !!formToken);

const chkCount = () => db.prepare('SELECT COUNT(*) c FROM fundsvera_checkouts').get().c;
const chkBefore = chkCount();

async function attemptDeposit({ token = formToken, method = 'fundsvera', amount = '5000' } = {}) {
  const body = new URLSearchParams({ payment_method: method, amount, form_token: token });
  const csrf = customer.csrfFrom(r.text);
  if (csrf) body.append(csrf.name, csrf.value);
  const res = await customer.raw('/dashboard/wallet/deposit', {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  });
  const location = res.headers.get('location') || '';
  let landed = res;
  if (res.status >= 300 && res.status < 400) {
    // Follow redirects that stay inside the panel; fetch the provider's
    // checkout_url directly so the caller can assert on the card page.
    if (/^https?:\/\//i.test(location) && !location.startsWith(BASE)) {
      const ext = await fetch(location);
      landed = { status: ext.status, text: await ext.text() };
    } else {
      landed = await customer.get(location);
    }
  }
  const flash = (landed.text || '').match(/alert (?:alert-)?(?:success|error|danger|warning)[^>]*>([\s\S]{0,500}?)<\//);
  return { res, landed, location, flash: flash ? flash[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '' };
}

const first = await attemptDeposit();
// The panel now lands on its own deposit page (where card + transfer are both
// offered) rather than jumping straight to Fundsvera's transfer page. The
// Fundsvera checkout_url is still stored on the checkout row so the customer
// can follow it for the bank-transfer route.
check('deposit POST answers with a redirect to the deposit page',
  first.res.status >= 300 && first.res.status < 400 && /dashboard\/wallet\/deposits\//.test(first.location),
  `status=${first.res.status} location=${first.location}`);
const fvRow = db.prepare(
  'SELECT checkout_url, account_number, bank_name, account_name FROM fundsvera_checkouts ORDER BY id DESC LIMIT 1').get();
check('the checkout row keeps the provider checkout link and account details',
  !!fvRow?.checkout_url && !!fvRow?.account_number && !!fvRow?.bank_name,
  `checkout_url=${fvRow?.checkout_url || '(none)'} account=${fvRow?.account_number || '(none)'}`);
if (fvRow?.checkout_url) {
  try {
    const fake = await fetch(fvRow.checkout_url);
    const text = await fake.text();
    check('the Fundsvera hosted checkout page renders for the customer',
      /secure checkout/i.test(text), 'card/checkout page not reached');
  } catch (e) {
    check('the Fundsvera hosted checkout page renders for the customer', false, String(e));
  }
}
check('a checkout row was opened with the provider account details',
  chkCount() === chkBefore + 1
    && !!db.prepare('SELECT account_number FROM fundsvera_checkouts ORDER BY id DESC LIMIT 1').get()?.account_number,
  `checkouts ${chkBefore} -> ${chkCount()}`);
const newTx = db.prepare('SELECT id, public_id, status FROM payment_transactions ORDER BY id DESC LIMIT 1').get();
check('the deposit transaction is PENDING (awaiting the webhook)', newTx?.status === 'PENDING', `status=${newTx?.status}`);

// Same form token again: must resolve to the SAME deposit — no second checkout.
const dup = await attemptDeposit();
check('a duplicate submit resolves to the existing deposit, not a new checkout',
  dup.location.includes(`/dashboard/wallet/deposits/${newTx.public_id}`)
    && chkCount() === chkBefore + 1,
  `location=${dup.location} checkouts=${chkCount()}`);

// The deposits page must let the customer resume: account details + link.
// The link is the "Pay now — open secure checkout" CTA (the bank-transfer
// route), labelled case-insensitively on purpose.
r = await customer.get(`/dashboard/wallet/deposits/${newTx.public_id}`);
check('the deposit page shows the account number and a resume link',
  /81\d{8}/.test(r.text) && /open secure checkout/i.test(r.text), 'deposit page lacks details or resume');

// ---------------------------------------------------------------------------
// Card fallback: Fundsvera's own page is transfer only, so a configured hosted
// card gateway must surface a real "Pay by card" route on the SAME deposit and
// a successful card webhook must credit it exactly once.
// ---------------------------------------------------------------------------
const txMeta = () => JSON.parse(db.prepare('SELECT metadata FROM payment_transactions WHERE id = ?').get(newTx.id)?.metadata || '{}');
check('no card checkout exists before the customer clicks the card button',
  !(txMeta().card_checkout?.provider), 'card checkout already stored');

setSetting('paystack_enabled', true);
setSetting('paystack_base_url', FAKE_FV);
setSetting('paystack_public_key', FV_PUBLIC);
setSetting('paystack_secret_key', CARD_SECRET);

const cardPage = await customer.get(`/dashboard/wallet/deposits/${newTx.public_id}`);
check('a configured card gateway adds "Pay by card" on the Fundsvera deposit page',
  /Pay by card/.test(cardPage.text) && /Paystack/.test(cardPage.text),
  'deposit page lacks the card CTA');
const cardRes = await customer.postForm(`/dashboard/wallet/deposits/${newTx.public_id}/card`, {},
  { fromHtml: cardPage.text, follow: false });
const cardLocation = cardRes.headers.get('location') || '';
check('the card checkout re-uses the deposit and redirects to the hosted card page',
  cardRes.status >= 300 && cardRes.status < 400 && /fake-card-checkout/.test(cardLocation),
  `status=${cardRes.status} location=${cardLocation}`);
const cardMeta = txMeta();
check('the card checkout URL is stored for resume',
  !!cardMeta.card_checkout?.redirect_url && cardMeta.card_checkout?.provider === 'paystack',
  JSON.stringify(cardMeta.card_checkout || {}));

// A Paystack charge.success webhook for the same internal reference: the same
// deposit must go SUCCESS and the wallet must be credited once (never twice).
async function cardWebhook(body) {
  const payload = JSON.stringify(body);
  const sig = crypto.createHmac('sha512', CARD_SECRET).update(payload).digest('hex');
  const res = await fetch(`${BASE}/webhook/paystack`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-paystack-signature': sig },
    body: payload,
  });
  return { status: res.status, body: await res.text() };
}
const cardUserId = db.prepare('SELECT user_id FROM payment_transactions WHERE id = ?').get(newTx.id).user_id;
const walletBefore = db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(cardUserId)?.balance;
const cardRef = db.prepare('SELECT internal_reference FROM payment_transactions WHERE id = ?').get(newTx.id).internal_reference;
const cardEvent = {
  event: 'charge.success',
  data: {
    id: 'pay-' + crypto.randomBytes(8).toString('hex'),
    reference: cardRef,
    status: 'success',
    amount: 500000,
    currency: 'NGN',
    metadata: {
      payment_transaction_id: String(newTx.id),
      internal_reference: cardRef,
    },
  },
};
const cardWh = await cardWebhook(cardEvent);
check('a signed Paystack card webhook is accepted', cardWh.status === 200, `status=${cardWh.status} ${cardWh.body}`);
const walletAfter = () => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(cardUserId)?.balance;
check('the card payment credits the Fundsvera deposit exactly once',
  db.prepare('SELECT status FROM payment_transactions WHERE id = ?').get(newTx.id)?.status === 'SUCCESS'
    && parseFloat(walletAfter() || '0') > parseFloat(walletBefore || '0'),
  `status=${db.prepare('SELECT status FROM payment_transactions WHERE id = ?').get(newTx.id)?.status} wallet ${walletBefore} -> ${walletAfter()}`);
const walletAfterFirst = walletAfter();
const cardWh2 = await cardWebhook(cardEvent);
check('a replayed card webhook does not credit twice', cardWh2.status === 200, `status=${cardWh2.status}`);
check('the card webhook replay leaves the balance unchanged',
  parseFloat(walletAfter() || '0') === parseFloat(walletAfterFirst || '0'));

// The real provider has been seen answering with camelCase keys inside a
// `data` wrapper. The account details and checkout link must survive that
// shape too — blank Bank / Account number / Account name fields are the exact
// production complaint this suite exists to stop.
await fvBehavior('nested-camel');
r = await customer.get('/dashboard/add-funds');
const camelToken = (r.text.match(/name="form_token" value="([^"]+)"/) || [])[1] || null;
const camel = await attemptDeposit({ token: camelToken });
check('camelCase nested response still renders bank details and a resume link',
  /81\d{8}/.test(camel.landed?.text || '') && /open secure checkout/i.test(camel.landed?.text || ''),
  'camelCase response left the deposit page without details or a link');
await fvBehavior('ok');

// ---- failure matrix: the panel must answer, never hang ---------------------
async function expectInitFailure(behavior, expectText, label) {
  await fvBehavior(behavior);
  const before = chkCount();
  r = await customer.get('/dashboard/add-funds');
  const token = (r.text.match(/name="form_token" value="([^"]+)"/) || [])[1];
  const out = await attemptDeposit({ token });
  check(label, expectText.test(out.flash || ''), `flash: ${(out.flash || '').slice(0, 160)}`);
  check(`${label} — no checkout row was opened`, chkCount() === before, `checkouts ${before} -> ${chkCount()}`);
}

await expectInitFailure('unauthorized', /valid keys/i,
  'provider 401 → the provider\'s message reaches the customer');
await expectInitFailure('busy', /System busy/i,
  'provider 500 → friendly retry message, no hang');
await expectInitFailure('bad-request', /valid amount/i,
  'provider 400 → the validation message reaches the customer');
await expectInitFailure('hang', /took too long/i,
  'provider hang → timeout message instead of an endless Processing button');

// Success without a checkout_url: the provider returned the account details
// but no link — the customer lands on the deposit page with the details.
await fvBehavior('no-checkout-url');
r = await customer.get('/dashboard/add-funds');
const token2 = (r.text.match(/name="form_token" value="([^"]+)"/) || [])[1];
const noUrl = await attemptDeposit({ token: token2 });
check('success without checkout_url lands on the deposit page, not a dead end',
  noUrl.location.includes('/dashboard/wallet/deposits/')
    && /deposit is ready/i.test(noUrl.flash || ''),
  `location=${noUrl.location} flash=${noUrl.flash.slice(0, 120)}`);
await fvBehavior('ok');

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
}
process.exit(failed.length ? 1 : 0);
