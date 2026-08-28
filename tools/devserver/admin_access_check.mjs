/**
 * Admin access toolkit — end-to-end verification.
 *
 * DEV TOOLING ONLY. Three operator capabilities, each verified against the
 * real running app instead of by reading code:
 *
 *   1. An admin can add another administrator (Admin → Administrators),
 *      including the privilege rule that only a SUPER_ADMIN may mint one.
 *   2. Admins — like customers — can edit their own email (with
 *      re-verification), change their password and upload/remove a profile
 *      picture from /dashboard/profile, which the admin shell now links.
 *   3. Impersonation has two pinned modes: read-only (every write blocked)
 *      and full access (act on the customer's behalf, audited per request,
 *      credential screens still blocked, admin area still unreachable).
 *
 *   node tools/devserver/admin_access_check.mjs
 *   DEMO_PASSWORD=... node tools/devserver/admin_access_check.mjs
 */
import { Client } from './client.mjs';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const ADMIN_PASSWORD = process.env.DEMO_PASSWORD || 'Demo!c7e2331b';

// The avatar upload writes a real file into assets/uploads/. Snapshot the
// directory first so the run can delete exactly what it created — a stray
// upload would otherwise end up inside the next deployment zip build.
const UPLOADS_DIR = '/home/user/WINDELS-PANEL/assets/uploads';
const uploadsBefore = new Set(fs.existsSync(UPLOADS_DIR) ? fs.readdirSync(UPLOADS_DIR) : []);
const cleanupUploads = () => {
  if (!fs.existsSync(UPLOADS_DIR)) return;
  for (const name of fs.readdirSync(UPLOADS_DIR)) {
    if (!uploadsBefore.has(name) && name !== 'index.html') {
      try { fs.unlinkSync(path.join(UPLOADS_DIR, name)); } catch { /* best effort */ }
    }
  }
};

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}
function withDb(fn) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync('/home/user/WINDELS-PANEL/storage/devdb/marvy.sqlite');
  try { return fn(db); } finally { db.close(); }
}

// Smallest valid 1×1 transparent PNG, so the avatar upload exercises the real
// MediaService path without shipping a fixture file.
const TINY_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64',
);

const stamp = Date.now().toString().slice(-8);
const opsUsername = `opsadmin${stamp}`;
const opsEmail = `${opsUsername}@example.test`;
const opsPassword = 'First-Login-9!';
const rotatedPassword = 'Rotated-Password-7';

/* ══════════════ 1. Admin adds another administrator ══════════════ */

console.log('── Admin creates another administrator');
const admin = new Client(BASE);
await admin.get('/admin/login');
await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });

const listPage = await admin.get('/admin/administrators');
check('the administrators screen loads', listPage.status === 200);
check('it offers the create form', /Add an administrator/.test(listPage.text), 'missing "Add an administrator"');
check('the form posts to admin/administrators/create', listPage.text.includes('admin/administrators/create'));

const created = await admin.postForm('/admin/administrators/create', {
  username: opsUsername, email: opsEmail, password: opsPassword, role: 'ADMIN',
}, { fromHtml: listPage.text });
check('creation succeeds with a handover message', /account created for/.test(created.text));
check('the new administrator appears in the directory', created.text.includes(opsUsername));

const opsRow = withDb((db) => db.prepare(
  'SELECT u.id, u.public_id, u.role, u.status, u.email, u.email_verified_at, w.balance AS wallet_balance'
  + ' FROM users u LEFT JOIN wallets w ON w.user_id = u.id WHERE u.username = ?').get(opsUsername));
check('the account is stored: ADMIN, ACTIVE, verified, zero wallet',
  !!opsRow && opsRow.role === 'ADMIN' && opsRow.status === 'ACTIVE'
  && !!opsRow.email_verified_at && opsRow.wallet_balance === '0.00000000',
  JSON.stringify(opsRow));
// Password verification happens through PHP — proved by signing in below.
const ops = new Client(BASE);
await ops.get('/login');
await ops.postForm('/login', { identifier: opsUsername, password: opsPassword });
const opsHome = await ops.get('/admin');
check('the new administrator signs in and reaches the admin area',
  opsHome.status === 200 && /ws-app-shell/.test(opsHome.text),
  `status=${opsHome.status}`);

const dup = await admin.postForm('/admin/administrators/create', {
  username: opsUsername, email: `other${stamp}@example.test`, password: 'Whatever-99', role: 'ADMIN',
}, { fromHtml: created.text });
check('a duplicate username is refused', /already taken/.test(dup.text));

const deniedSuper = await ops.postForm('/admin/administrators/create', {
  username: `owner${stamp}`, email: `owner${stamp}@example.test`, password: 'Whatever-99', role: 'SUPER_ADMIN',
}, { fromHtml: opsHome.text });
check('a plain ADMIN cannot mint a SUPER_ADMIN', /Only a super admin can create another super admin/.test(deniedSuper.text));
check('and no such account was created',
  !withDb((db) => db.prepare('SELECT id FROM users WHERE username = ?').get(`owner${stamp}`)));

const creationAudit = withDb((db) => db.prepare(
  "SELECT actor_id, after_json FROM audit_logs WHERE action = 'staff.admin_created' AND resource_id = ?"
).get(opsRow.public_id));
check('the creation is audited against the creator',
  !!creationAudit && Number(creationAudit.actor_id) === 1
  && creationAudit.after_json.includes('"role":"ADMIN"')
  && !creationAudit.after_json.includes(opsPassword),
  JSON.stringify(creationAudit));

/* ══════════════ 2. Admin self-service: email, password, avatar ══════════════ */

console.log('── Administrator edits their own email, password and picture');
const profilePage = await ops.get('/dashboard/profile');
check('an administrator can open /dashboard/profile',
  profilePage.status === 200 && /Profile picture/.test(profilePage.text) && /Your details/.test(profilePage.text),
  `status=${profilePage.status}`);
check('the admin shell links "My profile"',
  (await ops.get('/admin')).text.includes('dashboard/profile'));

// Avatar upload (multipart, the real MediaService path).
const avatarFd = new FormData();
const token = ops.csrfFrom(profilePage.text);
avatarFd.append(token.name, token.value);
avatarFd.append('action', 'avatar');
avatarFd.append('avatar', new Blob([TINY_PNG], { type: 'image/png' }), 'avatar.png');
const avatarPost = await ops.raw('/dashboard/profile', { method: 'POST', body: avatarFd });
const avatarPage = await ops.get('/dashboard/profile');
check('the avatar upload is accepted and rendered',
  avatarPost.status >= 200 && avatarPost.status < 400 && /<img[^>]*src="[^"]*avatar/.test(avatarPage.text),
  `post=${avatarPost.status}`);

// Email change forces re-verification and queues the confirmation mail.
const newEmail = `rotated-${stamp}@example.test`;
const detailsPage = await ops.get('/dashboard/profile');
await ops.postForm('/dashboard/profile', {
  username: opsUsername, email: newEmail, first_name: 'Ops', last_name: 'Admin',
  phone: '', timezone: 'UTC', locale: 'en',
}, { fromHtml: detailsPage.text });
const afterEmail = withDb((db) => db.prepare(
  'SELECT email, email_verified_at FROM users WHERE username = ?').get(opsUsername));
check('the email change is saved and un-verifies the address',
  afterEmail.email === newEmail && afterEmail.email_verified_at === null,
  JSON.stringify(afterEmail));
const verifyMail = withDb((db) => db.prepare(
  "SELECT id FROM email_queue WHERE to_email = ? AND template_key = 'auth.verify_email'").get(newEmail));
check('a re-verification mail was queued', !!verifyMail);

// Restore the original address (keeps the seeded world tidy, exercises the
// change twice) — then change the password from /dashboard/security.
const restorePage = await ops.get('/dashboard/profile');
await ops.postForm('/dashboard/profile', {
  username: opsUsername, email: opsEmail, first_name: 'Ops', last_name: 'Admin',
  phone: '', timezone: 'UTC', locale: 'en',
}, { fromHtml: restorePage.text });
check('the email can be changed back',
  withDb((db) => db.prepare('SELECT email FROM users WHERE username = ?').get(opsUsername)).email === opsEmail);

const secPage = await ops.get('/dashboard/security');
check('the security screen is reachable from the admin shell', secPage.status === 200);
const pwChange = await ops.postForm('/dashboard/security', {
  action: 'change_password',
  current_password: opsPassword, new_password: rotatedPassword, confirm_password: rotatedPassword,
}, { fromHtml: secPage.text });
check('the password change is confirmed', /Password changed\./.test(pwChange.text));

const relogin = new Client(BASE);
await relogin.get('/login');
await relogin.postForm('/login', { identifier: opsUsername, password: rotatedPassword });
check('the rotated password signs the administrator back in',
  /ws-app-shell/.test((await relogin.get('/admin')).text));

// Avatar removal.
const removePage = await relogin.get('/dashboard/profile');
await relogin.postForm('/dashboard/profile', { action: 'avatar_remove' }, { fromHtml: removePage.text });
check('the avatar can be removed',
  !withDb((db) => db.prepare('SELECT avatar_url FROM users WHERE username = ?').get(opsUsername)).avatar_url);

/* ══════════════ 3. Impersonation: read-only and full access ══════════════ */

console.log('── Impersonation: read-only lens and full-access session');

// A fresh customer to work on.
const cust = new Client(BASE);
await cust.get('/register');
await cust.postForm('/register', {
  username: `imp${stamp}`, email: `imp${stamp}@example.test`,
  password: 'Cust!Pass99', password_confirm: 'Cust!Pass99', terms: '1', accept_terms: '1',
});
const custRow = withDb((db) => db.prepare(
  'SELECT id, public_id, username FROM users WHERE username = ?').get(`imp${stamp}`));
check('a test customer exists', !!custRow);

const file = await admin.get(`/admin/customers/${custRow.public_id}`);
check('the customer file offers impersonation with a mode choice',
  /Customer impersonation/.test(file.text)
  && file.text.includes('value="FULL_ACCESS"')
  && file.text.includes('value="READ_ONLY"'));

// --- read-only leg -------------------------------------------------------
const roStart = await admin.postForm(`/admin/customers/${custRow.public_id}/impersonate`, {
  mode: 'READ_ONLY', reason: 'Diagnosing why ticket T-1 does not show', confirm: '1',
}, { fromHtml: file.text });
check('read-only impersonation starts',
  roStart.status === 200 && /viewing this account as an administrator/.test(roStart.text));
check('the read-only shell greys out every form', /impersonation-read-only/.test(roStart.text));
check('and does not claim full access', !/act on their behalf/.test(roStart.text));

const roWrite = await admin.raw('/dashboard/tickets/create', {
  method: 'POST',
  headers: { 'content-type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    ...(() => {
      const t = admin.csrfFrom(roStart.text) || admin.csrfFrom(file.text) || {};
      return t.name ? { [t.name]: t.value } : {};
    })(),
    subject: 'should never exist', message: 'blocked write attempt',
  }).toString(),
});
check('a write is rejected with 403 and the read-only reason',
  roWrite.status === 403 && /read-only/.test(roWrite.text), `status=${roWrite.status}`);
check('no ticket leaked through', !withDb((db) => db.prepare(
  "SELECT id FROM tickets WHERE subject = 'should never exist'").get()));

const roAdmin = await admin.get('/admin');
check('the admin area stays unreachable', roAdmin.status === 403, `status=${roAdmin.status}`);

await admin.get('/dashboard');
await admin.postForm('/impersonation/stop', {}, { fromHtml: admin.last.text });
check('stopping restores the staff session', (await admin.get('/admin')).status === 200);

// --- full-access leg -----------------------------------------------------
const file2 = await admin.get(`/admin/customers/${custRow.public_id}`);
const faStart = await admin.postForm(`/admin/customers/${custRow.public_id}/impersonate`, {
  mode: 'FULL_ACCESS', reason: 'Placing order T-2091 on the customer request', confirm: '1',
}, { fromHtml: file2.text });
check('full-access impersonation starts',
  faStart.status === 200 && /Full access/.test(faStart.text) && /act on their behalf/.test(faStart.text));
check('the full-access shell does not grey out forms',
  /impersonation-full-access/.test(faStart.text) && !/impersonation-read-only/.test(faStart.text));

const ticket = await admin.postForm('/dashboard/tickets/create', {
  subject: 'Order follow-up on behalf', message: 'Opened by support while acting for the customer.',
}, { fromHtml: faStart.text });
check('a write succeeds: the ticket is opened', /Ticket opened/.test(ticket.text));
const ticketRow = withDb((db) => db.prepare(
  "SELECT user_id, subject FROM tickets WHERE subject = 'Order follow-up on behalf' ORDER BY id DESC").get());
check('the ticket is attributed to the customer, not the staff member',
  !!ticketRow && Number(ticketRow.user_id) === Number(custRow.id), JSON.stringify(ticketRow));

const credPage = await admin.get('/dashboard/profile');
const credWrite = await admin.postForm('/dashboard/profile', {
  username: `hijacked${stamp}`, email: `hijacked${stamp}@example.test`,
}, { fromHtml: credPage.text });
check('a credential change is blocked even in full access',
  credWrite.status === 403 && /blocked while impersonating/.test(credWrite.text), `status=${credWrite.status}`);
check('the customer identity was not touched',
  withDb((db) => db.prepare('SELECT username, email FROM users WHERE id = ?').get(custRow.id)).username === custRow.username);

const faAdmin = await admin.get('/admin');
check('the admin area stays unreachable in full access too', faAdmin.status === 403, `status=${faAdmin.status}`);

await admin.get('/dashboard');
await admin.postForm('/impersonation/stop', {}, { fromHtml: admin.last.text });
check('the full-access session ends and restores staff', (await admin.get('/admin')).status === 200);

// --- audit trail ---------------------------------------------------------
const startedRows = withDb((db) => db.prepare(
  "SELECT after_json FROM audit_logs WHERE action = 'user.impersonation.started' AND resource_id = ?"
  + ' ORDER BY id ASC').all(custRow.public_id));
check('both starts are audited with their modes',
  startedRows.length === 2
  && startedRows[0].after_json.includes('"mode":"READ_ONLY"')
  && startedRows[1].after_json.includes('"mode":"FULL_ACCESS"'),
  JSON.stringify(startedRows.map((r) => r.after_json)));
const writeView = withDb((db) => db.prepare(
  "SELECT actor_id, after_json FROM audit_logs WHERE action = 'user.impersonation.viewed'"
  + " AND after_json LIKE '%tickets\\/create%' AND after_json LIKE '%\"method\":\"POST\"%'"
  + ' ORDER BY id DESC LIMIT 1').get());
check('the write itself is recorded against the staff member',
  !!writeView && Number(writeView.actor_id) === 1
  && writeView.after_json.includes('"mode":"FULL_ACCESS"'),
  JSON.stringify(writeView));

const failed = results.filter((x) => !x.ok);
cleanupUploads();
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('Failed:');
  for (const f of failed) console.log(`  ✗ ${f.label}`);
}
process.exit(failed.length ? 1 : 0);
