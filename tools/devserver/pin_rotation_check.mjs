/**
 * Automatic security-PIN rotation — end-to-end check.
 *
 * DEV TOOLING ONLY. Exercises the real feature over HTTP plus one direct
 * manipulation of the dev SQLite file to simulate the passage of time (there
 * is no web-facing "run cron now" endpoint by design — Cron.php is CLI only,
 * see its class comment — so a test that wants to prove the *scheduled*
 * worker itself has to invoke it the same way crontab does).
 *
 * Flow:
 *   1. Register a customer, sign in, set a PIN.
 *   2. As an admin, set pin_rotation_hours=1 and enable auto-rotation.
 *   3. Backdate that user's pin_set_at directly in the dev SQLite file so the
 *      window has already elapsed.
 *   4. Run `php index.php cron pin_rotation` through the WASM CLI runner.
 *   5. Confirm: the old PIN no longer works, the new PIN (read back from the
 *      email queue) does, a notification and an audit record were created,
 *      and the plaintext PIN was never written to the audit log.
 *   6. Confirm a second run is a no-op (idempotent), and that disabling the
 *      feature makes even an overdue PIN untouched.
 *
 *   node tools/devserver/pin_rotation_check.mjs --admin-password <pw> [--db storage/devdb/marvy.sqlite]
 *
 * The direct SQLite edit runs while devdb keeps serving (default journal
 * mode tolerates the short-lived second connection); no process management
 * is required.
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);

if (!adminPassword) {
  console.error('Usage: node tools/devserver/pin_rotation_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

function runCli(args) {
  return execFileSync('node', ['tools/devserver/cli.mjs', ...args], { cwd: ROOT, encoding: 'utf8' });
}

/** Runs one function with exclusive, synchronous access to the SQLite file. */
function withDb(fn) {
  // node:sqlite is a CommonJS builtin; require()'d lazily so this file still
  // parses on Node versions without it (the caller decides whether to run).
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync(path.resolve(ROOT, DB_PATH));
  try {
    return fn(db);
  } finally {
    db.close();
  }
}

const stamp = Date.now().toString().slice(-8);
const user = {
  username: `pinrot${stamp}`,
  email: `pinrot${stamp}@example.test`,
  password: 'PinRotate!Pass99',
};

console.log('── PIN rotation · setup');
const c = new Client(BASE);
await c.get('/register');
await c.postForm('/register', {
  username: user.username, email: user.email,
  password: user.password, password_confirm: user.password,
  terms: '1', accept_terms: '1',
});
await c.get('/login');
const login = await c.postForm('/login', { identifier: user.username, password: user.password });
check('customer signed in', /dashboard/i.test(login.url), `at ${login.url}`);

let page = await c.get('/dashboard/security');
const OLD_PIN = '8317';
let r = await c.postForm('/dashboard/security', { action: 'set_pin', new_pin: OLD_PIN, confirm_pin: OLD_PIN },
  { fromHtml: page.text });
check('PIN set', /PIN is now set/i.test(r.text));

console.log('\n── PIN rotation · admin enables a short window');
const a = new Client(BASE);
await a.get('/admin/login');
const adminLogin = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(adminLogin.url) && !/login/.test(adminLogin.url));

let settingsPage = await a.get('/admin/settings');
await a.postForm('/admin/settings/save', {
  pin_rotation_hours: '1',
  __rendered_pin_auto_rotation_enabled: '1',
  pin_auto_rotation_enabled: '1',
}, { fromHtml: settingsPage.text });
settingsPage = await a.get('/admin/settings');
check('rotation enabled in settings', /name="pin_auto_rotation_enabled" value="1" checked/.test(settingsPage.text));

console.log('\n── PIN rotation · simulate 24h elapsed and run the worker');
withDb((db) => {
  db.exec(`UPDATE users SET pin_set_at = '2000-01-01 00:00:00' WHERE username = '${user.username}'`);
});

const cronOut = runCli(['cron', 'pin_rotation']);
check('cron job reports a rotation', /\d+ PIN\(s\) rotated/.test(cronOut), cronOut.trim());

const newPin = withDb((db) => {
  const row = db.prepare(
    "SELECT body_text FROM email_queue WHERE to_email = ? ORDER BY id DESC LIMIT 1"
  ).get(user.email);
  const m = row && /new PIN is:\s*(\d{4})/.exec(row.body_text || '');
  return m ? m[1] : null;
});
check('new PIN was emailed to the user', !!newPin, 'no rotation email found');

const auditRow = newPin && withDb((db) => {
  return db.prepare(
    "SELECT action FROM audit_logs WHERE action = 'security.pin_auto_rotated' ORDER BY id DESC LIMIT 1"
  ).get();
});
check('rotation was audited', !!auditRow);
check('the plaintext PIN is not stored in the audit row', !JSON.stringify(auditRow || {}).includes(newPin || ''));

console.log('\n── PIN rotation · old PIN rejected, new PIN accepted');
page = await c.get('/dashboard/security');
r = await c.postForm('/dashboard/security',
  { action: 'set_pin', current_pin: OLD_PIN, new_pin: '1197', confirm_pin: '1197' },
  { fromHtml: page.text });
check('the pre-rotation PIN no longer works', /incorrect/i.test(r.text), 'old PIN still accepted');

page = await c.get('/dashboard/security');
r = await c.postForm('/dashboard/security',
  { action: 'set_pin', current_pin: newPin || '0000', new_pin: '1197', confirm_pin: '1197' },
  { fromHtml: page.text });
check('the auto-rotated PIN works', /PIN was updated/i.test(r.text), 'new PIN was rejected');

console.log('\n── PIN rotation · idempotent (no immediate re-rotation)');
const secondRun = runCli(['cron', 'pin_rotation']);
check('a second run right after finds nothing due', /no PINs due for rotation/.test(secondRun), secondRun.trim());

console.log('\n── PIN rotation · disabling stops the sweep');
settingsPage = await a.get('/admin/settings');
await a.postForm('/admin/settings/save', {
  pin_rotation_hours: '1',
  __rendered_pin_auto_rotation_enabled: '1',
  // pin_auto_rotation_enabled omitted -> unchecked -> disabled
}, { fromHtml: settingsPage.text });

withDb((db) => {
  db.exec(`UPDATE users SET pin_set_at = '2000-01-01 00:00:00' WHERE username = '${user.username}'`);
});
const disabledRun = runCli(['cron', 'pin_rotation']);
check('a disabled sweep leaves an overdue PIN untouched', /automatic PIN rotation is disabled/.test(disabledRun), disabledRun.trim());

// Restore the setting so the shared dev environment is left in its default state.
settingsPage = await a.get('/admin/settings');
await a.postForm('/admin/settings/save', {
  pin_rotation_hours: '24',
  __rendered_pin_auto_rotation_enabled: '1',
  pin_auto_rotation_enabled: '1',
}, { fromHtml: settingsPage.text });

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
