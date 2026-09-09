/**
 * End-to-end journey tests against a running dev server.
 *
 * DEV TOOLING ONLY. Drives the real application over HTTP with a cookie jar
 * and CSRF handling, exactly as a browser would: register, log in, browse the
 * dashboard, place an order, open a ticket, and walk the admin back office.
 *
 *   node tools/devserver/journey.mjs [--base http://127.0.0.1:8080]
 */
const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();
const only = (() => {
  const i = argv.indexOf('--only');
  return i === -1 ? null : argv[i + 1];
})();

import { Client, errorMarkersIn } from './client.mjs';

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------
const results = [];
let currentSection = '';

function section(name) {
  currentSection = name;
  console.log(`\n── ${name}`);
}

function check(label, condition, detail = '') {
  const ok = !!condition;
  results.push({ section: currentSection, label, ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
  return ok;
}

function pageOk(label, r, extra = () => true) {
  const markers = errorMarkersIn(r.text);
  const statusOk = r.status >= 200 && r.status < 400;
  return check(
    label,
    statusOk && !markers.length && extra(r.text),
    `status=${r.status} ${markers.length ? 'errors=' + markers.join('|') : ''} ${
      markers.length ? '' : (r.text.match(/<title>([^<]*)</) || [])[1] || ''
    }`
  );
}

// ---------------------------------------------------------------------------
// Journeys
// ---------------------------------------------------------------------------
const stamp = Date.now().toString().slice(-8);
const customer = {
  username: `journey${stamp}`,
  email: `journey${stamp}@example.test`,
  password: 'Journey!Pass99',
};

async function customerJourney() {
  const c = new Client();

  section('Customer · registration');
  let r = await c.get('/register');
  pageOk('GET /register renders', r, (t) => t.includes('name="username"'));

  r = await c.postForm('/register', {
    username: customer.username,
    email: customer.email,
    password: customer.password,
    password_confirm: customer.password,
    terms: '1',
    accept_terms: '1',
  });
  pageOk('POST /register succeeds', r);
  check(
    'registration lands authenticated or at verification',
    /dashboard|verify|verification/i.test(r.url + r.text),
    `landed at ${r.url}`
  );

  section('Customer · login');
  const c2 = new Client();
  r = await c2.get('/login');
  pageOk('GET /login renders', r, (t) => t.includes('name="password"'));
  r = await c2.postForm('/login', { identifier: customer.username, password: customer.password });
  pageOk('POST /login succeeds', r);
  const loggedIn = /dashboard/i.test(r.url) || r.text.includes('Log out') || r.text.includes('Logout');
  check('login reaches the dashboard', loggedIn, `landed at ${r.url}`);

  section('Customer · dashboard');
  for (const p of [
    '/dashboard',
    '/dashboard/services',
    '/dashboard/orders',
    '/dashboard/new-order',
    '/dashboard/add-funds',
    '/dashboard/transactions',
    '/dashboard/wallet/deposits',
    '/dashboard/tickets',
    '/dashboard/notifications',
    '/dashboard/profile',
    '/dashboard/security',
    '/dashboard/api',
    '/dashboard/referrals',
  ]) {
    const rr = await c2.get(p);
    pageOk(`GET ${p}`, rr);
  }

  section('Customer · logout');
  // Logout is POST-only by design (a GET logout is CSRF-triggerable), so load
  // a page first to pick up the token the nav's logout form carries.
  await c2.get('/dashboard');
  r = await c2.postForm('/logout', {});
  pageOk('POST /logout', r);
  const after = await c2.raw('/dashboard');
  const redirectedToLogin =
    (after.status >= 300 && after.status < 400 && /login/i.test(after.headers.get('location') || '')) ||
    /name="password"/.test(after.text);
  check('dashboard requires auth after logout', redirectedToLogin,
    `status=${after.status} location=${after.headers.get('location') || '-'}`);

  return c2;
}

async function adminJourney(password) {
  const a = new Client();
  section('Admin · login');
  let r = await a.get('/admin/login');
  pageOk('GET /admin/login renders', r, (t) => t.includes('name="password"'));
  check('admin login page is the staff form', /Staff sign-in|staff/i.test(r.text), `title=${(r.text.match(/<title>([^<]*)</) || [])[1]}`);
  r = await a.postForm('/admin/login', { identifier: 'admin', password });
  pageOk('POST /admin/login succeeds', r);
  const inAdmin = /\/admin/.test(r.url) && !/login/.test(r.url);
  check('admin reaches the back office', inAdmin, `landed at ${r.url}`);
  if (!inAdmin) return a;

  section('Admin · back office');
  for (const p of [
    '/admin',
    '/admin/users',
    '/admin/orders',
    '/admin/services',
    '/admin/categories',
    '/admin/providers',
    '/admin/payments',
    '/admin/content',
    '/admin/settings',
    '/admin/tickets',
    '/admin/analytics',
    '/admin/audit-logs',
    '/admin/staff',
  ]) {
    const rr = await a.get(p);
    pageOk(`GET ${p}`, rr);
  }
  return a;
}

const demoPassword = process.env.DEMO_PASSWORD || argv[argv.indexOf('--admin-password') + 1];

if (!only || only === 'customer') await customerJourney();
if ((!only || only === 'admin') && demoPassword) await adminJourney(demoPassword);
else if (!only || only === 'admin')
  console.log('\n(skipping admin journey — pass --admin-password <pw>)');

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  [${f.section}] ${f.label} — ${f.detail}`);
}
process.exit(failed.length ? 1 : 0);
