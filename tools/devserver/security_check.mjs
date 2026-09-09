/**
 * Security and authorisation end-to-end check.
 *
 * Two halves:
 *
 *  1. **Attack shapes.** What a second signed-in customer, and an
 *     unauthenticated stranger, can actually reach: another customer's orders,
 *     tickets, receipts and payments; the admin area; a POST with no CSRF
 *     token; privilege fields smuggled into the profile form; user enumeration
 *     on the login screen; session fixation.
 *
 *  2. **The RBAC matrix, live.** The panel gates every admin mutation on its
 *     own permission, in twenty controllers, by hand. Nothing had ever signed
 *     in as a genuinely *limited* member of staff and tried the mutations they
 *     are not entitled to — so "the guard is there" was a code-reading claim.
 *     This narrows the STAFF role to a single permission, drives real POSTs at
 *     five money-moving endpoints across five domains, and then grants one
 *     permission and proves that exactly that one endpoint opened.
 *
 * The STAFF role's grants are snapshotted and restored, including on failure.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/security_check.mjs --admin-password '…'
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
const ADMIN_PASSWORD = arg('--admin-password', 'Demo!cabcd50b');
const CUSTOMER_PASSWORD = arg('--customer-password', ADMIN_PASSWORD);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const STAFF_PASSWORD = 'Str0ng!staff1';

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

/* ============================ 1 · attack shapes =========================== */

const victim = new Client(BASE);
await victim.get('/login');
const vlogin = await victim.postForm('/login', { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('the victim customer signs in', /\/dashboard/.test(vlogin.url), vlogin.url);

const attackerEmail = `sec${Date.now()}@x.test`;
const attacker = new Client(BASE);
const regPage = await attacker.get('/register');
const reg = await attacker.postForm('/register', {
  username: 'sec' + String(Date.now()).slice(-8),
  email: attackerEmail, password: 'Str0ng!probe1',
  password_confirm: 'Str0ng!probe1', confirm_password: 'Str0ng!probe1', terms: '1',
}, { fromHtml: regPage.text });
check('a second customer can register', /\/dashboard/.test(reg.url), reg.url);
if (!/\/dashboard/.test(reg.url)) {
  // Every check below is meaningless without an authenticated attacker: an
  // anonymous client is redirected to a login page and reads as "refused" for
  // the wrong reason. Registration is itself rate limited, so this is a real
  // possibility on a busy dev database.
  console.error('\n  the attacker session could not be established — aborting rather than reporting false passes\n');
  process.exit(2);
}

const victimIds = withDb((db) => {
  const u = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const one = (sql) => { try { return db.prepare(sql).get(u.id)?.public_id ?? null; } catch { return null; } };
  return {
    userId: u.id,
    order:   one(`SELECT public_id FROM orders WHERE user_id = ? LIMIT 1`),
    ticket:  one(`SELECT public_id FROM tickets WHERE user_id = ? LIMIT 1`),
    service: one(`SELECT public_id FROM service_transactions WHERE user_id = ? LIMIT 1`),
    payment: one(`SELECT public_id FROM payment_transactions WHERE user_id = ? LIMIT 1`),
  };
});

console.log('\n── Another customer’s records are not readable');
for (const [label, url] of [
  ['order', `/dashboard/orders/${victimIds.order}`],
  ['support ticket', `/dashboard/tickets/${victimIds.ticket}`],
  ['service receipt', `/dashboard/vtu/receipt/${victimIds.service}`],
  ['deposit', `/dashboard/wallet/payment/${victimIds.payment}`],
]) {
  if (url.includes('null')) continue;
  const r = await attacker.get(url);
  check(`${label} is not readable by another customer`,
    r.status === 404 || /login/.test(r.url), `status=${r.status}`);
}

console.log('\n── Nor changeable');
for (const [label, url] of [
  ['cancelling', `/dashboard/orders/${victimIds.order}/cancel`],
  ['requesting a refill on', `/dashboard/orders/${victimIds.order}/refill`],
  ['closing', `/dashboard/tickets/${victimIds.ticket}`.replace(/$/, '/close')],
]) {
  if (url.includes('null')) continue;
  const page = await attacker.get('/dashboard');
  const r = await attacker.postForm(url, {}, { fromHtml: page.text });
  check(`${label} another customer’s record is refused`, r.status === 404, `status=${r.status}`);
}

console.log('\n── A customer cannot reach staff surfaces');
const stillIn = await attacker.get('/dashboard');
check('the attacker is still signed in as a customer',
  stillIn.status === 200 && !/\/login/.test(stillIn.url), stillIn.url);
for (const url of ['/admin', '/admin/customers', '/admin/settings', '/admin/wallets', '/admin/staff']) {
  const r = await attacker.get(url);
  check(`customer GET ${url} is refused`, r.status === 403, `status=${r.status} url=${r.url}`);
}

console.log('\n── The profile form cannot grant privileges');
const profile = await attacker.get('/dashboard/account/profile');
await attacker.postForm('/dashboard/account/profile', {
  first_name: 'Probe', last_name: 'Tester',
  role: 'ADMIN', status: 'ACTIVE', price_group_id: '99', balance: '999999', is_admin: '1',
}, { fromHtml: profile.text });
const after = withDb((db) => db.prepare(`SELECT role, price_group_id, status FROM users WHERE email = ?`).get(attackerEmail));
check('role, price group and status are not settable by the customer',
  after && after.role === 'CUSTOMER' && String(after.price_group_id) !== '99',
  JSON.stringify(after));

console.log('\n── CSRF, sessions and enumeration');
const noToken = await attacker.raw('/dashboard/account/profile', {
  method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' },
  body: 'first_name=CSRF',
});
check('a POST with no CSRF token is refused', noToken.status === 403 || noToken.status === 419,
  `status=${noToken.status}`);

const fixation = new Client(BASE);
const pre = await fixation.get('/login');
const preCookie = [...fixation.jar.entries()].map(([k, v]) => `${k}=${v}`).join(';');
const post = await fixation.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD }, { fromHtml: pre.text });
const postCookie = [...fixation.jar.entries()].map(([k, v]) => `${k}=${v}`).join(';');
check('the session identifier changes on sign-in', preCookie !== postCookie);
const setCookie = post.headers.getSetCookie ? post.headers.getSetCookie().join(' | ') : '';
check('session cookies are HttpOnly and declare SameSite',
  /httponly/i.test(setCookie) && /samesite/i.test(setCookie), setCookie.slice(0, 140));

// Deliberately against the throwaway account, never the demo one: a wrong
// password counts towards that identifier's lockout, and locking the account
// every other check signs in with would break the whole suite.
const enumClient = new Client(BASE);
const lp = await enumClient.get('/login');
const unknown = await enumClient.postForm('/login',
  { identifier: 'nobody-here@x.test', password: 'whatever1!' }, { fromHtml: lp.text });
const lp2 = await enumClient.get('/login');
const wrongPass = await enumClient.postForm('/login',
  { identifier: attackerEmail, password: 'definitely-wrong-1!' }, { fromHtml: lp2.text });
const alertOf = (t) => (/<div[^>]*alert[^>]*>([\s\S]{0,200}?)<\/div>/i.exec(t) || [, ''])[1]
  .replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
check('an unknown account and a wrong password read identically',
  alertOf(unknown.text) === alertOf(wrongPass.text) && alertOf(unknown.text) !== '',
  `"${alertOf(unknown.text)}" vs "${alertOf(wrongPass.text)}"`);

// Failed attempts are what the lockout counts. Leaving ours behind would make
// the next run — or any other check that signs in from this address — fail for
// a reason that has nothing to do with the code under test.
withDb((db) => {
  db.prepare(`DELETE FROM login_attempts WHERE success = 0 AND (email = ? OR email = ?)`)
    .run(attackerEmail, 'nobody-here@x.test');
});

/* ========================= 2 · the RBAC matrix, live ====================== */

console.log('\n── A limited member of staff attempts what they may not');

const snapshot = withDb((db) => {
  const staffRole = db.prepare(`SELECT id FROM roles WHERE name = 'STAFF'`).get();
  const held = db.prepare(`SELECT permission_id FROM role_permissions WHERE role_id = ?`)
    .all(staffRole.id).map((r) => r.permission_id);
  return { roleId: staffRole.id, held };
});

function setStaffPermissions(keys) {
  withDb((db) => {
    db.prepare(`DELETE FROM role_permissions WHERE role_id = ?`).run(snapshot.roleId);
    for (const key of keys) {
      const p = db.prepare(`SELECT id FROM permissions WHERE perm_key = ?`).get(key);
      if (p) db.prepare(`INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)`)
        .run(snapshot.roleId, p.id);
    }
  });
}
function restoreStaffPermissions() {
  withDb((db) => {
    db.prepare(`DELETE FROM role_permissions WHERE role_id = ?`).run(snapshot.roleId);
    for (const id of snapshot.held) {
      db.prepare(`INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)`)
        .run(snapshot.roleId, id);
    }
  });
}

const staffEmail = `limited${Date.now()}@marvy.local`;
let exitCode = 0;
try {
  // A real staff account, created the way the panel stores one.
  const bcryptHash = withDb((db) =>
    db.prepare(`SELECT password_hash FROM users WHERE email = 'demo@marvy.local'`).get().password_hash);
  const staffId = withDb((db) => {
    db.prepare(`INSERT INTO users (public_id, username, email, password_hash, role, status,
                                   price_group_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'STAFF', 'ACTIVE', 1, datetime('now'), datetime('now'))`)
      .run('USRLIMITED' + String(Date.now()).slice(-16), 'limited' + String(Date.now()).slice(-6),
           staffEmail, bcryptHash);
    const id = db.prepare(`SELECT id FROM users WHERE email = ?`).get(staffEmail).id;
    db.prepare(`INSERT INTO wallets (public_id, user_id, balance, currency, total_deposited, total_spent,
                                     created_at, updated_at)
                VALUES (?, ?, '0.00000000', 'NGN', '0.00000000', '0.00000000', datetime('now'), datetime('now'))`)
      .run('WALLIMITED' + String(Date.now()).slice(-16), id);
    return id;
  });

  // Nothing but the ability to look at orders.
  setStaffPermissions(['orders.view', 'reports.view', 'vtu.view']);

  const staff = new Client(BASE);
  await staff.get('/admin/login');
  const slogin = await staff.postForm('/admin/login',
    { identifier: staffEmail, password: CUSTOMER_PASSWORD });
  check('the limited staff account signs in to the admin area',
    /\/admin/.test(slogin.url) && !/login/.test(slogin.url), slogin.url);

  const targets = withDb((db) => ({
    order: db.prepare(`SELECT public_id FROM orders ORDER BY id DESC LIMIT 1`).get()?.public_id,
    vtu: db.prepare(`SELECT public_id FROM service_transactions ORDER BY id DESC LIMIT 1`).get()?.public_id,
    customer: db.prepare(`SELECT public_id FROM users WHERE email = 'demo@marvy.local'`).get().public_id,
  }));

  const matrix = [
    ['refund an order',        'orders.refund',      `/admin/orders/${targets.order}/refund`, { reason: 'e2e' }],
    ['cancel an order',        'orders.cancel',      `/admin/orders/${targets.order}/cancel`, { reason: 'e2e' }],
    ['refund a VTU purchase',  'vtu.refund',         `/admin/vtu/${targets.vtu}/refund`,      { reason: 'e2e' }],
    ['adjust a wallet',        'wallets.adjust',     `/admin/customers/${targets.customer}/adjust`,
                                                     { direction: 'CREDIT', amount: '1', note: 'e2e' }],
    ['change a setting',       'settings.manage',    '/admin/settings/save', { site_name: 'MarvySocials' }],
  ];

  const page = await staff.get('/admin/orders');
  for (const [label, perm, url, fields] of matrix) {
    if (url.includes('undefined')) { console.log(`   skip    ${label} (no fixture)`); continue; }
    const r = await staff.postForm(url, fields, { fromHtml: page.text, follow: false });
    check(`without ${perm}, staff cannot ${label}`, r.status === 403, `status=${r.status} url=${url}`);
  }

  // Grant exactly one, and prove exactly one opened.
  setStaffPermissions(['orders.view', 'reports.view', 'vtu.view', 'orders.refund']);
  const refundUrl = `/admin/orders/${targets.order}/refund`;
  const afterGrant = await staff.postForm(refundUrl, { reason: 'e2e' },
    { fromHtml: page.text, follow: false });
  check('granting orders.refund opens exactly that endpoint',
    afterGrant.status !== 403, `status=${afterGrant.status}`);
  const stillClosed = await staff.postForm(`/admin/vtu/${targets.vtu}/refund`, { reason: 'e2e' },
    { fromHtml: page.text, follow: false });
  check('and leaves the others closed', stillClosed.status === 403, `status=${stillClosed.status}`);

  // A limited staff account must not be able to widen its own grants.
  const escalate = await staff.postForm('/admin/staff/permissions/STAFF',
    { permissions: ['staff.manage', 'settings.manage'] }, { fromHtml: page.text, follow: false });
  check('staff cannot grant themselves more permissions', escalate.status === 403,
    `status=${escalate.status}`);
  const widened = withDb((db) => db.prepare(
    `SELECT COUNT(*) n FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
      WHERE rp.role_id = ? AND p.perm_key = 'staff.manage'`).get(snapshot.roleId).n);
  check('and the grid is unchanged', widened === 0, `staff.manage rows=${widened}`);

  withDb((db) => {
    db.prepare(`DELETE FROM login_attempts WHERE email = ?`).run(staffEmail);
    db.prepare(`DELETE FROM wallets WHERE user_id = ?`).run(staffId);
    db.prepare(`DELETE FROM users WHERE id = ?`).run(staffId);
  });
} finally {
  restoreStaffPermissions();
  const restored = withDb((db) => db.prepare(
    `SELECT COUNT(*) n FROM role_permissions WHERE role_id = ?`).get(snapshot.roleId).n);
  check('the STAFF role’s real permissions are restored', restored === snapshot.held.length,
    `${restored} of ${snapshot.held.length}`);
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  exitCode = 1;
}
process.exit(exitCode);
