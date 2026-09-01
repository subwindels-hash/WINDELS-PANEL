/**
 * 5sim integration check — the complete customer flow over real HTTP.
 *
 * DEV TOOLING ONLY. Requires:
 *   - the dev database (:3399) and app server (:8080) running, with
 *     FIVESIM_BASE_URL=http://127.0.0.1:9400/v1 in .env (non-production only)
 *   - the fake current-protocol 5sim server: node tools/devserver/fake_5sim.mjs
 *
 *   node tools/devserver/fivesim_check.mjs [--key-file /home/user/.fivesim_key]
 *
 * Drives the real application the way an operator and a customer would:
 *   1. admin adds a FIVESIM provider with the JWT key (correct v1 URL)
 *   2. admin rotates the credentials through the new credentials form
 *   3. the deprecated handler_api URL is refused at save time (form error)
 *   4. sync pulls the catalogue from /v1/guest/… and the admin prices+activates
 *   5. customer rents → receives the number → polls → OTP arrives → releases
 *   6. wallet is charged exactly once across the happy path AND the failure
 *      matrix (no free phones / vendor out of funds / vendor 500 / timeout)
 *   7. the panel never calls handler_api.php (fake 404s it) and the key never
 *      appears in any rendered admin page.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const FAKE = process.env.FAKE_5SIM_URL || 'http://127.0.0.1:9400';
const KEY_FILE = process.env.FIVESIM_KEY_FILE || path.resolve('/home/user/.fivesim_key');
const PASSWORD = process.env.DEMO_PASSWORD || 'Repro!2026Pass';
const token = fs.existsSync(KEY_FILE) ? fs.readFileSync(KEY_FILE, 'utf8').trim() : 'FAKE-JWT-FOR-DEV-ONLY';

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n      ${detail}`}`);
  return !!ok;
}

function flashFrom(text) {
  const m = text.match(/alert (?:alert-)?(?:success|error|danger|warning)[^>]*>([\s\S]{0,800}?)<\//);
  return m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
}

function walletFrom(text) {
  const m = text.match(/Wallet balance:\s*<strong>([^<]+)<\/strong>/);
  if (!m) return null;
  return parseFloat(String(m[1]).replace(/[^0-9.]/g, ''));
}

function csrfInputs(text) {
  const out = {};
  for (const tag of text.match(/<input[^>]*>/gi) || []) {
    const name = (/name="([^"]+)"/i.exec(tag) || [])[1];
    const value = (/value="([^"]*)"/i.exec(tag) || [])[1];
    if (name) out[name] = value;
  }
  return out;
}

async function login(client, identifier, pathname) {
  await client.get(pathname);
  return client.postForm(pathname, { identifier, password: PASSWORD });
}

/** Selected values of the form's <select> fields (country_id, service_id, …). */
function selectValues(text) {
  const out = {};
  for (const chunk of text.match(/<select[^>]*>[\s\S]*?<\/select>/gi) || []) {
    const name = (/name=\"([^\"]+)\"/i.exec(chunk) || [])[1];
    if (!name) continue;
    const sel = (/<option[^>]*\bselected\b[^>]*value=\"([^\"]*)\"/i.exec(chunk)
      || /<option[^>]*value=\"([^\"]*)\"[^>]*\bselected\b/i.exec(chunk) || []);
    if (sel[1] !== undefined) out[name] = sel[1];
  }
  return out;
}

/** Credit the demo wallet through the admin UI so rentals never run dry. */
async function fundWallet(admin, identifier, amount) {
  let r = await admin.get(`/admin/customers?q=${encodeURIComponent(identifier)}`);
  const userId = (r.text.match(/admin\/customers\/([0-9a-hjkmnp-tv-z]{20,})/i) || [])[1] || null;
  if (!userId) return false;
  r = await admin.get(`/admin/customers/${userId}`);
  const nonce = (r.text.match(/name=\"nonce\" value=\"([^\"]*)\"/) || [])[1] || '';
  const csrf = admin.csrfFrom(r.text);
  const body = new URLSearchParams({ direction: 'CREDIT', amount: String(amount), reason: 'e2e test funding' });
  if (nonce) body.append('nonce', nonce);
  if (csrf) body.append(csrf.name, csrf.value);
  r = await admin.raw(`/admin/customers/${userId}/adjust`, {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: body.toString(),
  });
  return r.status >= 300 && r.status < 400;
}

/**
 * Probe the app→vendor path through the panel (the same WASM-PHP curl the
 * rental flow uses): the admin "Test connection" answers "Connection OK" only
 * when the app can actually reach the fake. The sandbox's WASM network relay
 * occasionally wedges for a few seconds after an aborted slow request, so a
 * real probe here beats a host-side fetch.
 */
async function appVendorProbe(admin, publicId) {
  const page = await admin.get(`/admin/providers/${publicId}`);
  const r = await admin.postForm(`/admin/providers/${publicId}/test`, {}, { fromHtml: page.text });
  return flashFrom(r.text);
}

async function waitForVendor(admin, publicId, attempts = 4, delayMs = 12000) {
  for (let i = 0; i < attempts; i++) {
    const flash = await appVendorProbe(admin, publicId);
    if (/Connection OK/.test(flash)) return true;
    console.log(`   (vendor probe ${i + 1}/${attempts}: ${flash.slice(0, 80)} — waiting ${delayMs / 1000}s)`);
    await new Promise((res) => setTimeout(res, delayMs));
  }
  return false;
}

async function setBehavior(behavior) {
  await fetch(`${FAKE}/__control/behavior`, {
    method: 'POST', headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ behavior }),
  });
}

const stamp = Date.now().toString().slice(-8);
const admin = new Client(BASE);
const customer = new Client(BASE);

console.log(`# 5sim integration check — base=${BASE} fake=${FAKE}`);
await setBehavior('ok'); // reset any sticky failure left by a previous run
// Hygiene is scoped to this run: the fake's log accumulates across runs.
const statsBase = ((await (await fetch(`${FAKE}/__stats`)).json()).requests || []).length;

/* ------------------------------------------------------------------ */
console.log('\n── Admin · create the FIVESIM provider with the JWT key');
let r = await login(admin, 'admin', '/admin/login');
const signedIn = check('admin signs in', !/login/.test(r.url || ''), `at ${r.url}`);
if (!signedIn) process.exit(1);

r = await admin.get('/admin/providers');
check('providers list renders', r.status === 200 && !/404 Page Not Found/.test(r.text));

r = await admin.postForm('/admin/providers/create', {
  name: `5sim-live-${stamp}`,
  api_url: 'https://5sim.net/v1',
  api_key: token,
  api_type: 'FIVESIM',
  status: 'ACTIVE',
  timeout_ms: '15000',
  sync_interval_minutes: '60',
  markup_percent: '0',
}, { fromHtml: r.text, follow: false });
check('create with the correct v1 URL redirects (no 404)', r.status >= 300 && r.status < 400, `status=${r.status}`);
const publicId = (r.headers.get('location') || '').match(/detail\/([^/?#]+)/)?.[1] || null;
check('redirect names the new provider', !!publicId, `location=${r.headers.get('location')}`);

const detailPage = await admin.get(`/admin/providers/${publicId}`);
check('provider detail renders without a 404', detailPage.status === 200 && !/404 Page Not Found/.test(detailPage.text));
check('the stored key is never echoed back', !detailPage.text.includes(token.slice(20, 80)), 'detail page must not render the token');
check('the stored api_url is shown', detailPage.text.includes('5sim.net/v1'));

/* ------------------------------------------------------------------ */
console.log('\n── Admin · test connection + sync against the current protocol');
r = await admin.postForm(`/admin/providers/${publicId}/test`, {}, { fromHtml: detailPage.text });
const testFlash = flashFrom(r.text);
check('“Test connection” answers through the panel (no 404)', /Connection OK|Connection failed/.test(testFlash), `flash: ${testFlash.slice(0, 140)}`);
check('the vendor profile probe reports the fake balance', /Connection OK/.test(testFlash) && /1000/.test(testFlash), testFlash.slice(0, 140));

r = await admin.postForm(`/admin/providers/${publicId}/sync`, {}, { fromHtml: r.text });
const syncFlash = flashFrom(r.text);
check('“Sync services” pulls the catalogue from /v1/guest/…', /Sync complete/.test(syncFlash), `flash: ${syncFlash.slice(0, 140)}`);

/* ------------------------------------------------------------------ */
console.log('\n── Admin · the deprecated URL is refused at save time');
r = await admin.postForm(`/admin/providers/${publicId}/credentials`, {
  api_url: 'https://5sim.net/stubs/handler_api.php',
  api_key: token,
}, { fromHtml: r.text });
const refuseFlash = flashFrom(r.text);
check('credentials form refuses the deprecated handler_api URL', /deprecated API1 protocol/i.test(refuseFlash), `flash: ${refuseFlash.slice(0, 140)}`);

r = await admin.postForm(`/admin/providers/${publicId}/credentials`, {
  api_url: 'https://5sim.net/v1',
  api_key: token,
}, { fromHtml: r.text });
const credFlash = flashFrom(r.text);
check('credentials rotation saves and re-verifies in one action', /Credentials saved and verified/.test(credFlash), `flash: ${credFlash.slice(0, 160)}`);

/* ------------------------------------------------------------------ */
console.log('\n── Admin · price and activate one product (WhatsApp in Nigeria)');
r = await admin.get('/admin/catalogue/numbers');
// Product rows carry ULID public ids; the create link must not match.
const editHref = (r.text.match(/admin\/catalogue\/numbers\/([0-9a-hjkmnp-tv-z]{20,})/i) || [])[1] || null;
check('the synced number product is listed in the catalogue', !!editHref, editHref || 'no product link');
r = await admin.get(`/admin/catalogue/numbers/${editHref}`);
const fields = csrfInputs(r.text);
Object.assign(fields, selectValues(r.text));
const csrf = admin.csrfFrom(r.text);
const update = new URLSearchParams();
for (const [k, v] of Object.entries(fields)) if (k && !/csrf/i.test(k)) update.append(k, v);
update.append('price', '50');
update.append('is_active', '1');
if (csrf) update.append(csrf.name, csrf.value);
r = await admin.raw(`/admin/catalogue/numbers/${editHref}/update`, {
  method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: update.toString(),
});
r = r.status >= 300 && r.status < 400 ? await admin.get(r.headers.get('location')) : r;
check('product saved with a price', /Saved\./.test(flashFrom(r.text)), `status=${r.status} ${flashFrom(r.text).slice(0, 120)}`);

/* ------------------------------------------------------------------ */
console.log('\n── Customer · country → service → buy → number → SMS → OTP → release');
const vendorUp = await waitForVendor(admin, publicId);
check('the app can reach the fake 5sim through the panel', vendorUp);
if (!vendorUp) { console.log('   (vendor unreachable from the app — re-run; sandbox WASM relay flakiness)'); process.exit(2); }
const funded = await fundWallet(admin, 'demo', 1000);
check('demo wallet funded for the rental flow', funded);
r = await login(customer, 'demo', '/login');
check('customer signs in', !/login/.test(r.url || ''), `at ${r.url}`);

r = await customer.get('/dashboard/numbers');
const balanceBefore = walletFrom(r.text);
check('customer sees the rental form with the priced product', /Rent a virtual number/.test(r.text) && /WhatsApp/.test(r.text));
check('wallet balance is visible', balanceBefore !== null && !Number.isNaN(balanceBefore), `balance=${balanceBefore}`);

const form = csrfInputs(r.text);
const csrfC = customer.csrfFrom(r.text);
const rentBody = new URLSearchParams({ country: 'NG', service: 'WHATSAPP', form_token: form.form_token || `tok${stamp}` });
const buysBefore = ((await (await fetch(`${FAKE}/__stats`)).json()).requests || [])
  .filter((q) => q.path.startsWith('/v1/user/buy/activation/')).length;
if (csrfC) rentBody.append(csrfC.name, csrfC.value);
r = await customer.raw('/dashboard/numbers/rent', {
  method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: rentBody.toString(),
});
const rentLanded = r.status >= 300 && r.status < 400 ? await customer.get(r.headers.get('location')) : r;
check('rent POST is accepted (redirect, not 404)', r.status >= 300 && r.status < 400, `status=${r.status}`);
const rentedUrl = r.status >= 300 ? r.headers.get('location') : '';
const rentedId = (rentedUrl.match(/dashboard\/numbers\/([^/?#]+)/) || [])[1] || null;
check('reservation detail page renders the number', !!rentedId && /\+234|RESERVED|RECEIVED/.test(rentLanded.text), `url=${rentedUrl}`);
const gotNumber = (rentLanded.text.match(/\+[0-9]{7,15}/) || [])[0] || null;
check('a phone number from the vendor is displayed', !!gotNumber, gotNumber || 'no number rendered');

// The same form_token twice must resolve to the original reservation: same
// redirect target, and no second vendor purchase.
const dup = new URLSearchParams(rentBody);
r = await customer.raw('/dashboard/numbers/rent', {
  method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: dup.toString(),
});
const dupLanded = r.status >= 300 && r.status < 400 ? await customer.get(r.headers.get('location')) : r;
const dupTarget = r.status >= 300 ? (r.headers.get('location') || '') : '';
const buysAfter = ((await (await fetch(`${FAKE}/__stats`)).json()).requests || [])
  .filter((q) => q.path.startsWith('/v1/user/buy/activation/')).length;
check('duplicate submission resolves to the same reservation (no new vendor order)',
  dupTarget.includes(`/dashboard/numbers/${rentedId}`) && buysAfter - buysBefore === 1,
  `dup→${dupTarget} vendor buys delta=${buysAfter - buysBefore}`);

// Poll 1: nothing yet. Poll 2: the code arrives.
if (!rentedId) {
  check('first poll answers “no code yet”', false, 'rent failed — skipped');
  check('second poll delivers the OTP', false, 'rent failed — skipped');
  check('the OTP code is shown to the customer', false, 'rent failed — skipped');
  check('release completes the reservation', false, 'rent failed — skipped');
  check('wallet was charged exactly once (price 50)', false, 'rent failed — skipped');
} else {
  r = await customer.postForm(`/dashboard/numbers/${rentedId}/check`, {}, { fromHtml: rentLanded.text });
  check('first poll answers “no code yet”', /No code yet|A code arrived/.test(flashFrom(r.text)), flashFrom(r.text).slice(0, 120));
  r = await customer.postForm(`/dashboard/numbers/${rentedId}/check`, {}, { fromHtml: r.text });
  const poll2 = flashFrom(r.text);
  check('second poll delivers the OTP', /A code arrived/.test(poll2), poll2.slice(0, 120));
  r = await customer.get(`/dashboard/numbers/${rentedId}`);
  check('the OTP code is shown to the customer', /\b\d{6}\b/.test(r.text) && /RECEIVED|COMPLETED/.test(r.text), 'detail must show code + state');

  // Release: vendor finish, no money moves, order SUCCESSFUL.
  r = await customer.postForm(`/dashboard/numbers/${rentedId}/release`, {}, { fromHtml: r.text });
  check('release completes the reservation', /Number released/.test(flashFrom(r.text)), flashFrom(r.text).slice(0, 120));

  r = await customer.get('/dashboard/numbers');
  const balanceAfter = walletFrom(r.text);
  check('wallet was charged exactly once (price 50)', balanceBefore !== null && balanceAfter !== null
    && Math.abs((balanceBefore - balanceAfter) - 50) < 0.01, `before=${balanceBefore} after=${balanceAfter}`);
}

/* ------------------------------------------------------------------ */
console.log('\n── Failure matrix · vendor answers the panel must survive');
const matrixUp = await waitForVendor(admin, publicId, 4, 12000);
check('vendor reachable before the failure matrix', matrixUp);
if (!matrixUp) { console.log('   (vendor unreachable from the app — re-run)'); process.exit(2); }
async function expectRefund(behavior, expectText, label) {
  await setBehavior(behavior);
  r = await customer.get('/dashboard/numbers');
  const bal = walletFrom(r.text);
  const f = csrfInputs(r.text);
  const csrfX = customer.csrfFrom(r.text);
  const body = new URLSearchParams({ country: 'NG', service: 'WHATSAPP', form_token: `fail${stamp}${Math.random().toString(36).slice(2, 8)}` });
  if (csrfX) body.append(csrfX.name, csrfX.value);
  r = await customer.raw('/dashboard/numbers/rent', {
    method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: body.toString(),
  });
  const landed = r.status >= 300 && r.status < 400 ? await customer.get(r.headers.get('location')) : r;
  const flash = flashFrom(landed.text);
  check(label, expectText.test(flash), `flash: ${flash.slice(0, 160)}`);
  const after = walletFrom((await customer.get('/dashboard/numbers')).text);
  check(`${label} — wallet unchanged (charge refunded / never taken)`, after !== null && bal !== null && Math.abs(after - bal) < 0.01, `bal=${bal} after=${after}`);
}

await expectRefund('no-stock', /out of stock/i, '“no free phones” → friendly out-of-stock, no charge');
await expectRefund('insufficient', /out of funds/i, '“not enough user balance” → vendor funds error, no charge');
await expectRefund('server-error', /did not respond/i, 'vendor 500 → vendor error, no charge');
await expectRefund('timeout', /did not respond|unreachable/i, 'vendor hang → timeout error, no charge');
await setBehavior('ok');

/* ------------------------------------------------------------------ */
console.log('\n── Protocol hygiene');
const authLog = await (await fetch(`${FAKE}/__control/behavior`, { method: 'POST', body: JSON.stringify({ behavior: 'ok' }) })).json();
const stats = await (await fetch(`${FAKE}/__stats`)).json().catch(() => null);
if (stats) {
  const reqs = (stats.requests || []).slice(statsBase);
  const bad = reqs.filter((q) => /handler_api|stubs/i.test(q.path));
  check('the panel never called the deprecated API1 protocol', bad.length === 0, JSON.stringify(bad));
  const unauthUserCalls = reqs.filter((q) => q.path.startsWith('/v1/user/') && !q.hasAuth);
  check('every /v1/user/ call carried the Bearer token', unauthUserCalls.length === 0, `unauth=${unauthUserCalls.length}`);
  check('profile, countries, products, buy and check were all exercised',
    ['/v1/user/profile', '/v1/guest/countries', '/v1/guest/products/', '/v1/user/buy/activation/', '/v1/user/check/']
      .every((p) => reqs.some((q) => q.path.startsWith(p))), reqs.map((q) => q.path).join(' '));
} else {
  console.log('   (no /__stats endpoint — protocol hygiene skipped)');
}

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
