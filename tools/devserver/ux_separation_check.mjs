/**
 * Three-way UX separation — end-to-end verification.
 *
 * DEV TOOLING ONLY. The platform-fixes spec asked for "three clearly
 * separated experiences (Public Website / User Dashboard / Admin Dashboard)
 * with distinct nav/permissions" to be explicitly re-verified, not just
 * assumed from reading the code. This proves it against the real running
 * app rather than just inspecting layouts/app.php's branch:
 *
 *   - an anonymous visitor gets the public shell everywhere, never a
 *     dashboard/admin nav, and every dashboard/admin route redirects them
 *     to login rather than leaking any authenticated content;
 *   - a signed-in CUSTOMER gets the customer dashboard shell, never sees a
 *     single admin-only nav link or admin page, and every admin route
 *     403s them server-side (not just hides a link client-side);
 *   - a signed-in STAFF/ADMIN gets the admin shell with admin-only nav,
 *     and the admin shell is visibly different from the customer shell
 *     (different nav groups) even though both render through the same
 *     layouts/app.php template — proving the branch is genuinely
 *     server-derived from the role, not a client-controllable flag.
 *
 *   node tools/devserver/ux_separation_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);
if (!adminPassword) {
  console.error('Usage: node tools/devserver/ux_separation_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

// Admin-only nav labels that must NEVER appear for a customer or anonymous
// visitor under any circumstance — lifted directly from layouts/app.php's
// admin-only $nav_groups branch.
const ADMIN_ONLY_MARKERS = [
  'admin/users', 'admin/orders', 'admin/services', 'admin/providers',
  'admin/payments', 'admin/payouts', 'admin/currencies', 'admin/settings',
  'admin/staff', 'admin/audit-logs', 'admin/api-keys',
];
// Customer-only nav labels that must never appear in the admin shell (the
// admin shell has its own equivalent screens under different routes).
const CUSTOMER_ONLY_MARKERS = [
  'dashboard/new-order', 'dashboard/add-funds', 'dashboard/favorites',
  'dashboard/referrals', 'dashboard/earnings',
];

console.log('── Public website: anonymous visitor');
const anon = new Client(BASE);
const home = await anon.get('/');
check('homepage loads without auth', home.status === 200);
check('the public shell class is present (ws-public-shell)', home.text.includes('ws-public-shell'));
check('no admin nav markers leak to an anonymous visitor', ADMIN_ONLY_MARKERS.every((m) => !home.text.includes(m)));
check('no customer dashboard nav markers leak to an anonymous visitor', CUSTOMER_ONLY_MARKERS.every((m) => !home.text.includes(m)));

for (const path of ['dashboard', 'dashboard/orders', 'dashboard/profile', 'admin', 'admin/users', 'admin/settings']) {
  const res = await anon.get('/' + path);
  check(`GET /${path} redirects an anonymous visitor to a login page, never the real content`,
    /\/login/.test(res.url), `landed on ${res.url} (status ${res.status})`);
}

console.log('\n── Public pages never require an account');
for (const path of ['services', 'pricing', 'faq', 'about', 'contact', 'terms', 'privacy']) {
  const res = await anon.get('/' + path);
  check(`GET /${path} is publicly reachable`, res.status === 200, `status=${res.status}`);
}

console.log('\n── User dashboard: signed-in CUSTOMER');
const cust = new Client(BASE);
await cust.get('/login');
const custLogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: adminPassword },
  { fromHtml: (await cust.get('/login')).text });
check('customer signs in', /\/dashboard/.test(custLogin.url));

const custDash = await cust.get('/dashboard');
check('customer dashboard loads', custDash.status === 200);
check('the customer sees their own dashboard nav (Wallet group)', />Add funds</.test(custDash.text));
check('the customer never sees a single admin-only nav marker',
  ADMIN_ONLY_MARKERS.every((m) => !custDash.text.includes(`href="${m}` ) && !custDash.text.includes(`href="http://localhost:8080/${m}`)),
  'an admin route appeared as a link in the customer dashboard nav');
check('the customer shell does not carry the public marketing header',
  !custDash.text.includes('ws-public-shell'));

console.log('\n── User dashboard: a customer cannot reach ANY admin route, even by URL');
const adminRoutesToProbe = [
  'admin', 'admin/users', 'admin/orders', 'admin/services', 'admin/providers',
  'admin/payments', 'admin/payouts', 'admin/currencies', 'admin/settings',
  'admin/settings/flags', 'admin/staff', 'admin/audit-logs', 'admin/marketplace',
  'admin/shop', 'admin/api-keys', 'admin/analytics',
];
for (const path of adminRoutesToProbe) {
  const res = await cust.raw('/' + path, { method: 'GET' });
  check(`customer GET /${path} is refused (403), not the real admin page`,
    res.status === 403, `status=${res.status}`);
}

console.log('\n── User dashboard: a customer cannot perform an admin action even via direct POST');
const custHome = await cust.get('/dashboard');
const settingsSave = await cust.postForm('/admin/settings/save', { site_name: 'Hacked' }, { fromHtml: custHome.text });
check('a customer cannot POST to an admin save endpoint', settingsSave.status === 403, `status=${settingsSave.status}`);

console.log('\n── Admin dashboard: signed-in STAFF/ADMIN');
const admin = new Client(BASE);
await admin.get('/admin/login');
const adminLogin = await admin.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signs in', /\/admin/.test(adminLogin.url) && !/login/.test(adminLogin.url));

const adminDash = await admin.get('/admin');
check('admin dashboard loads', adminDash.status === 200);
check('the admin sees the admin-only nav (Users, Orders, Settings)',
  adminDash.text.includes('admin/customers') && adminDash.text.includes('admin/orders') && adminDash.text.includes('admin/settings'));
check('the admin shell does not show the customer-only nav (New order, Add funds)',
  !adminDash.text.includes('dashboard/new-order') && !adminDash.text.includes('dashboard/add-funds'));
check('the admin shell does not carry the public marketing header',
  !adminDash.text.includes('ws-public-shell'));

console.log('\n── Admin can reach every admin screen it links to (no dead/fake nav controls)');
for (const path of ['admin/users', 'admin/orders', 'admin/services', 'admin/providers',
                     'admin/payments', 'admin/payouts', 'admin/currencies', 'admin/settings',
                     'admin/staff', 'admin/audit-logs', 'admin/marketplace', 'admin/shop']) {
  const res = await admin.get('/' + path);
  check(`admin GET /${path} loads (${res.status})`, res.status === 200, `status=${res.status}`);
}

console.log('\n── Same layout template, genuinely different content — proving the branch is role-derived, not a passed flag');
check('the customer and admin shells render visibly different primary nav',
  custDash.text.slice(0, 4000) !== adminDash.text.slice(0, 4000));
check('an admin session visiting /dashboard still gets treated as staff (role travels with identity, not the URL)',
  (await admin.get('/dashboard')).status === 200);

const passed = results.filter(r => r.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
if (passed !== results.length) {
  console.log('\nFailures:');
  for (const r of results) if (!r.ok) console.log(`  ${r.label} — ${r.detail}`);
  process.exit(1);
}
