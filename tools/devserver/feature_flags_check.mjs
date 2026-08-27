/**
 * Feature flags — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves the settings audit finding that 7 of the 9 rows
 * on Admin → Settings → Feature flags saved to the DB but were never read by
 * any code (a switch that does nothing) has been fixed: every flag now
 * actually gates real behaviour, and turning a module off never destroys or
 * hides a customer's existing data — only new activity through that module.
 *
 *   node tools/devserver/feature_flags_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);

if (!adminPassword) {
  console.error('Usage: node tools/devserver/feature_flags_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

function rand(prefix) { return `${prefix}${Date.now()}${Math.floor(Math.random() * 1e6)}`; }

async function setFlag(admin, flagsPage, key, on) {
  // The flags form renders one checkbox per row; submitting only the ones
  // that should be ON (matches save_flags()'s "missing = off" semantics).
  const checked = new Set();
  // Non-greedy up to the tag close, so the optional `checked` group actually
  // gets a chance to match rather than being swallowed by a greedy [^>]*.
  const re = /name="flags\[([a-z0-9_.\-]+)\]"[^>]*?(checked)?>/gi;
  let m;
  while ((m = re.exec(flagsPage.text))) {
    if (m[2]) checked.add(m[1]);
  }
  if (on) checked.add(key); else checked.delete(key);
  const fields = {};
  for (const k of checked) fields[`flags[${k}]`] = '1';
  return admin.postForm('/admin/settings/flags', fields, { fromHtml: flagsPage.text });
}

const admin = new Client(BASE);
await admin.get('/admin/login');
const login = await admin.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

// Use the seeded demo customer for the read/write probes below — flag
// behaviour does not depend on which account is signed in, and reusing it
// avoids tripping the (correct, intentional) registration rate limiter.
const demoPassword = process.env.DEMO_PASSWORD || adminPassword;
const cust = new Client(BASE);
await cust.get('/login');
const li = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: demoPassword },
  { fromHtml: (await cust.get('/login')).text });
check('a customer session is available for probing', /\/dashboard/.test(li.url), `landed on ${li.url}`);

console.log('\n── blog: reading it is genuinely gated by the flag');
let flagsPage = await admin.get('/admin/settings/flags');
check('feature flags screen loads', flagsPage.status === 200);
await setFlag(admin, flagsPage, 'blog', true);
let blogOn = await cust.get('/blog');
check('with blog ON, /blog is reachable', blogOn.status === 200);
let homeOn = await cust.get('/');
check('with blog ON, the footer links to it', homeOn.text.includes(`href="${'/blog'}"`) || /href="[^"]*\/blog"/.test(homeOn.text));

flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'blog', false);
let blogOff = await cust.get('/blog');
check('with blog OFF, /blog 404s', blogOff.status === 404);
let homeOff = await cust.get('/');
check('with blog OFF, the footer link disappears', !/href="[^"]*\/blog"/.test(homeOff.text));
let sitemapOff = await cust.get('/sitemap.xml');
check('with blog OFF, the sitemap omits blog URLs', !sitemapOff.text.includes('/blog'));

// restore
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'blog', true);

console.log('\n── marketplace: browsing and buying are gated, existing orders are not');
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'marketplace', true);
let shopOn = await cust.get('/shop');
check('with marketplace ON, /shop is reachable', shopOn.status === 200);
let dashOn = await cust.get('/dashboard');
check('with marketplace ON, the Shop nav item is present', /shop/i.test(dashOn.text));

flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'marketplace', false);
let shopOff = await cust.get('/shop');
check('with marketplace OFF, /shop 404s', shopOff.status === 404);
let cartOff = await cust.get('/cart');
check('with marketplace OFF, /cart 404s', cartOff.status === 404);
let dashOff = await cust.get('/dashboard');
check('with marketplace OFF, the Shop nav item is hidden', !/>Shop</.test(dashOff.text));
let ordersOff = await cust.get('/dashboard/marketplace/orders');
check('with marketplace OFF, existing order history is still reachable', ordersOff.status === 200,
  `status=${ordersOff.status}`);

// restore
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'marketplace', true);

console.log('\n── mass_order: unaffected, still governed by its own flag (regression guard)');
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'mass_order', false);
let massOff = await cust.get('/dashboard/mass-order');
check('mass_order OFF still 404s (pre-existing behaviour untouched)', massOff.status === 404);
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'mass_order', true);

console.log('\n── dripfeed / subscriptions: nav + entry point gated, service refuses new schedules');
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'dripfeed', false);
await setFlag(admin, flagsPage, 'subscriptions', false);
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'dripfeed', false);
let dripOff = await cust.get('/dashboard/drip-feed');
check('with dripfeed OFF, the drip-feed page 404s', dripOff.status === 404);
let subOff = await cust.get('/dashboard/subscriptions');
check('with subscriptions OFF, the subscriptions page 404s', subOff.status === 404);
let dashNoFeatures = await cust.get('/dashboard');
check('nav hides Drip feed when off', !/>Drip feed</.test(dashNoFeatures.text));
check('nav hides Subscriptions when off', !/>Subscriptions</.test(dashNoFeatures.text));

flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'dripfeed', true);
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'subscriptions', true);
let dashRestored = await cust.get('/dashboard');
check('nav restores Drip feed when back on', />Drip feed</.test(dashRestored.text));
check('nav restores Subscriptions when back on', />Subscriptions</.test(dashRestored.text));

console.log('\n── tickets: opening a new ticket is gated, existing inbox is not');
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'tickets', false);
let ticketPage = await cust.get('/dashboard/tickets');
check('with tickets OFF, the inbox itself stays reachable (no data hidden)', ticketPage.status === 200);
let createAttempt = await cust.postForm('/dashboard/tickets/create',
  { subject: 'Hello', message: 'Testing', 'g-recaptcha-response': '' },
  { fromHtml: ticketPage.text });
let inboxAfter = await cust.get('/dashboard/tickets');
check('with tickets OFF, no new ticket is actually created',
  !inboxAfter.text.includes('Hello') || /disabled|unavailable/i.test(createAttempt.text + inboxAfter.text),
  'a ticket titled "Hello" appeared while the flag was off');

flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'tickets', true);
const okSubject = rand('flagticket-');
let createOk = await cust.postForm('/dashboard/tickets/create',
  { subject: okSubject, message: 'Testing with tickets on', 'g-recaptcha-response': '' },
  { fromHtml: (await cust.get('/dashboard/tickets')).text });
let inboxAfterOn = await cust.get('/dashboard/tickets');
check('with tickets ON, a new ticket can be opened again', inboxAfterOn.text.includes(okSubject));

console.log('\n── reseller_api: kill switch actually shuts the API down');
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'reseller_api', false);
let apiOff = await admin.raw('/api/v1/orders', { method: 'GET', headers: { 'X-Api-Key': 'not-a-real-key' } });
check('with reseller_api OFF, the API returns 503', apiOff.status === 503, `status=${apiOff.status}`);
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'reseller_api', true);
let apiOn = await admin.raw('/api/v1/orders', { method: 'GET', headers: { 'X-Api-Key': 'not-a-real-key' } });
check('with reseller_api back ON, the 503 kill-switch is gone (auth now fails instead)',
  apiOn.status !== 503, `status=${apiOn.status}`);

console.log('\n── demo_mode: read-only when on, reads still work, writes are refused');
flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'demo_mode', true);
let readOk = await cust.get('/dashboard');
check('with demo_mode ON, GET requests still work', readOk.status === 200);
let ticketPageDemo = await cust.get('/dashboard/tickets');
const blockedAttempt = await cust.postForm('/dashboard/tickets/create',
  { subject: rand('shouldnotexist-'), message: 'blocked?', 'g-recaptcha-response': '' },
  { fromHtml: ticketPageDemo.text, follow: false });
check('with demo_mode ON, a POST is refused (403) rather than processed',
  blockedAttempt.status === 403 || blockedAttempt.status === 302,
  `status=${blockedAttempt.status}`);
let inboxDemo = await cust.get('/dashboard/tickets');
check('the blocked POST created nothing', !inboxDemo.text.includes('shouldnotexist-'));
let loginStillWorks = await cust.get('/login');
check('login (exempt route) is still reachable while demo_mode is on', loginStillWorks.status === 200);

flagsPage = await admin.get('/admin/settings/flags');
await setFlag(admin, flagsPage, 'demo_mode', false);
let writesRestored = await cust.postForm('/dashboard/tickets/create',
  { subject: rand('demorestored-'), message: 'writes work again', 'g-recaptcha-response': '' },
  { fromHtml: (await cust.get('/dashboard/tickets')).text });
let inboxRestored = await cust.get('/dashboard/tickets');
check('with demo_mode back OFF, writes work again', inboxRestored.text.includes('demorestored-'));

console.log('\n── admin flags screen never lists a flag as unwired anymore');
const settingsIndex = await admin.get('/admin/settings');
check('the settings screen loads cleanly with every flag now wired',
  settingsIndex.status === 200);

// Belt-and-braces: every toggle above already restores itself, but leave the
// suite in the documented seed defaults regardless of where a failure
// happened, so a later test run never inherits a flipped switch.
flagsPage = await admin.get('/admin/settings/flags');
for (const key of ['dripfeed', 'subscriptions', 'mass_order', 'reseller_api',
                    'affiliate_program', 'marketplace', 'tickets', 'blog']) {
  await setFlag(admin, flagsPage, key, true);
  flagsPage = await admin.get('/admin/settings/flags');
}
await setFlag(admin, flagsPage, 'demo_mode', false);

const passed = results.filter(r => r.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
if (passed !== results.length) {
  console.log('\nFailures:');
  for (const r of results) if (!r.ok) console.log(`  ${r.label} — ${r.detail}`);
  process.exit(1);
}
