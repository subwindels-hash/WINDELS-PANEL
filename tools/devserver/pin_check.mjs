/**
 * Security-PIN end-to-end checks against a running dev server.
 *
 * DEV TOOLING ONLY. Registers a throwaway customer and drives the real PIN
 * flow over HTTP: the signup-issued starting PIN (read back from the email
 * queue, never from the page), weak-value rejections, a wrong current PIN,
 * a change, and the rule that the PIN itself is never rendered back to the
 * browser.
 *
 *   node tools/devserver/pin_check.mjs [--db storage/devdb/marvy.sqlite]
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/** The PIN a signup email delivered, read from the mail queue. */
function issuedPin(email) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync(path.resolve(ROOT, 'storage/devdb/marvy.sqlite'));
  try {
    const row = db.prepare(
      "SELECT body_text FROM email_queue WHERE to_email = ? AND template_key = 'security.pin_issued' ORDER BY id DESC LIMIT 1"
    ).get(email);
    const m = row && /Your 4-digit transaction PIN is:\s*(\d{4})/.exec(row.body_text || '');
    return m ? m[1] : null;
  } finally {
    db.close();
  }
}

const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const stamp = Date.now().toString().slice(-8);
const user = {
  username: `pin${stamp}`,
  email: `pin${stamp}@example.test`,
  password: 'PinCheck!Pass99',
};

const c = new Client(BASE);

console.log('── PIN · setup');
await c.get('/register');
await c.postForm('/register', {
  username: user.username,
  email: user.email,
  password: user.password,
  password_confirm: user.password,
  terms: '1',
  accept_terms: '1',
});
await c.get('/login');
const login = await c.postForm('/login', { identifier: user.username, password: user.password });
check('customer signed in', /dashboard/i.test(login.url), `at ${login.url}`);

const issued = issuedPin(user.email);
check('sign-up issued a PIN and emailed it', !!issued, 'no signup PIN email found in the queue');

let page = await c.get('/dashboard/security');
check('security page loads', page.status === 200);
check('PIN section is rendered', /Transaction PIN/i.test(page.text));
check('the PIN is already set at sign-up', !/Not set/i.test(page.text));
check('the issued PIN never appears in the page', !page.text.includes(issued || '____'),
  'the PIN was rendered back');

console.log('\n── PIN · validation');
let r = await c.postForm('/dashboard/security',
  { action: 'set_pin', current_pin: issued, new_pin: '1111', confirm_pin: '1111' },
  { fromHtml: page.text });
check('repeated-digit PIN is rejected', /too easy to guess/i.test(r.text), 'weak PIN was accepted');

page = await c.get('/dashboard/security');
r = await c.postForm('/dashboard/security',
  { action: 'set_pin', current_pin: issued, new_pin: '1234', confirm_pin: '1234' },
  { fromHtml: page.text });
check('sequential PIN is rejected', /too easy to guess/i.test(r.text), 'weak PIN was accepted');

page = await c.get('/dashboard/security');
r = await c.postForm('/dashboard/security',
  { action: 'set_pin', current_pin: issued, new_pin: '12', confirm_pin: '12' },
  { fromHtml: page.text });
check('short PIN is rejected', !/PIN was updated/i.test(r.text));

page = await c.get('/dashboard/security');
r = await c.postForm('/dashboard/security',
  { action: 'set_pin', current_pin: issued, new_pin: '8317', confirm_pin: '8317' },
  { fromHtml: page.text });
check('a strong PIN is accepted', /PIN is now set|PIN was updated/i.test(r.text), 'PIN was not set');

console.log('\n── PIN · confidentiality');
page = await c.get('/dashboard/security');
check('PIN now shows as set', /badge-success/.test(page.text) && /Transaction PIN/i.test(page.text));
check('the PIN itself never appears in the page', !page.text.includes('8317'), 'the PIN was rendered back');
check(
  'no PIN hash is exposed',
  !/\$2y\$/.test(page.text),
  'a bcrypt hash appeared in the page'
);

console.log('\n── PIN · change');
r = await c.postForm(
  '/dashboard/security',
  { action: 'set_pin', current_pin: '0000', new_pin: '4926', confirm_pin: '4926' },
  { fromHtml: page.text }
);
check('changing with a wrong current PIN is refused', /incorrect/i.test(r.text), 'wrong current PIN accepted');

page = await c.get('/dashboard/security');
r = await c.postForm(
  '/dashboard/security',
  { action: 'set_pin', current_pin: '8317', new_pin: '4926', confirm_pin: '4926' },
  { fromHtml: page.text }
);
check('changing with the correct current PIN works', /PIN was updated/i.test(r.text));

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
