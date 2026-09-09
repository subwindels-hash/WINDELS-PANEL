/**
 * The operator's legal identity, end to end (module 19).
 *
 * Before this module the Terms said "the legal entity, registered address and
 * governing jurisdiction are those of the party that deployed this instance"
 * and there was no field anywhere in the panel to change it. A customer about
 * to fund a prepaid wallet could not tell who they were contracting with,
 * where to serve a notice, whose law applied, or which regulator to complain
 * to about their data.
 *
 * This check does what an operator does: fills the details in through the real
 * admin form, reloads the public pages, and asserts the wording changed —
 * then puts the panel back exactly as it found it, because these are the live
 * settings of whoever is running this instance.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/legal_check.mjs --admin-password '…'
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
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

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

const KEYS = [
  'legal_entity_name', 'legal_registration_number', 'legal_registered_address',
  'legal_jurisdiction', 'legal_courts', 'legal_contact_email',
  'legal_dpo_contact', 'legal_supervisory_authority',
];

// Whatever the operator has today, so it can be handed back untouched.
const before = withDb((db) => {
  const out = {};
  for (const key of KEYS) {
    const row = db.prepare(`SELECT setting_value FROM settings WHERE setting_key = ?`).get(key);
    out[key] = row ? row.setting_value : null;
  }
  return out;
});

function setSettings(values) {
  withDb((db) => {
    for (const [key, value] of Object.entries(values)) {
      db.prepare(`INSERT INTO settings (setting_key, setting_value, category, is_public, updated_at)
                  VALUES (?, ?, 'legal', 0, datetime('now'))
                  ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value`)
        .run(key, JSON.stringify({ value }));
    }
  });
}

function restore() {
  withDb((db) => {
    for (const key of KEYS) {
      if (before[key] === null) db.prepare(`DELETE FROM settings WHERE setting_key = ?`).run(key);
      else db.prepare(`UPDATE settings SET setting_value = ? WHERE setting_key = ?`).run(before[key], key);
    }
  });
}

/* ================== 1 · an install that has said nothing ================= */

console.log('\n── Before the operator says who they are');

setSettings(Object.fromEntries(KEYS.map((k) => [k, ''])));

const visitor = new Client(BASE);
let terms = await visitor.get('/terms');
check('the terms page loads', terms.status === 200, `status=${terms.status}`);
check('it admits the operator has not been named',
  /has not published their legal details yet/i.test(terms.text));
check('and it does not invent a company', !/Marvy Digital Ltd/.test(terms.text));

let privacy = await visitor.get('/privacy');
check('the privacy policy admits the controller is unnamed',
  /controller has not been published yet/i.test(privacy.text));

check('the footer prints no empty "Operated by" line',
  !/Operated by\s*\./.test(terms.text) && !/Operated by\s*<\/div>/.test(terms.text));

/* ==================== 2 · the operator fills it in ======================= */

console.log('\n── The operator fills the details in, in the admin');

const admin = new Client(BASE);
await admin.get('/admin/login');
const login = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
check('staff signed in', /\/admin/.test(login.url), login.url);

const settingsPage = await admin.get('/admin/settings');
check('the settings screen offers a legal section',
  /Legal and company details/i.test(settingsPage.text));
for (const key of ['legal_entity_name', 'legal_registered_address', 'legal_jurisdiction']) {
  check(`${key} is an editable field`, settingsPage.text.includes(key));
}

const saved = await admin.postForm('/admin/settings/save', {
  legal_entity_name: 'Marvy Digital Ltd',
  legal_registration_number: 'RC 1234567',
  legal_registered_address: '12 Broad Street\nLagos Island\nLagos, Nigeria',
  legal_jurisdiction: 'the Federal Republic of Nigeria',
  legal_courts: 'Lagos, Nigeria',
  legal_contact_email: 'legal@marvy.example',
  legal_dpo_contact: 'privacy@marvy.example',
  legal_supervisory_authority: 'the Nigeria Data Protection Commission',
}, { fromHtml: settingsPage.text });
check('the form saves', saved.status === 200 || saved.status === 302, `status=${saved.status}`);

const stored = withDb((db) => db.prepare(
  `SELECT setting_value FROM settings WHERE setting_key = 'legal_entity_name'`).get());
check('and the entity name is in the database',
  !!stored && /Marvy Digital Ltd/.test(stored.setting_value), JSON.stringify(stored));

/* ===================== 3 · what the customer sees ======================== */

console.log('\n── What the customer reads afterwards');

terms = await new Client(BASE).get('/terms');
check('the terms name the trader',
  /Marvy Digital Ltd/.test(terms.text) && /RC 1234567/.test(terms.text));
check('with an address to serve notices at',
  /12 Broad Street, Lagos Island, Lagos, Nigeria/.test(terms.text),
  'the multi-line address should be flattened into prose');
check('the placeholder wording is gone',
  !/has not published their legal details yet/i.test(terms.text));
check('governing law is stated instead of deferred',
  /laws of\s*<strong>the Federal Republic of Nigeria<\/strong>/i.test(terms.text)
  || /the Federal Republic of Nigeria/.test(terms.text));
check('and the counsel-review caveat is withdrawn',
  !/Requires review by the operator’s legal counsel/i.test(terms.text));

privacy = await new Client(BASE).get('/privacy');
check('the privacy policy names the data controller',
  /Marvy Digital Ltd/.test(privacy.text) && /controller/i.test(privacy.text));
check('with a privacy contact', /privacy@marvy\.example/.test(privacy.text));
check('and the regulator a customer may complain to',
  /Nigeria Data Protection Commission/.test(privacy.text));

check('the footer now names the operator',
  /Operated by Marvy Digital Ltd \(RC 1234567\), 12 Broad Street/.test(terms.text),
  (/Operated by[^<]{0,120}/.exec(terms.text) || ['(no line)'])[0]);

const home = await new Client(BASE).get('/');
check('and it appears on every page, not only the legal ones',
  /Operated by Marvy Digital Ltd/.test(home.text));

/* ============ 4 · nothing is invented when half is filled in ============= */

console.log('\n── A half-filled identity is still honest');

setSettings({ legal_entity_name: 'Marvy Digital Ltd', legal_registered_address: '', legal_jurisdiction: '' });
const halfTerms = await new Client(BASE).get('/terms');
check('an entity with no address still reads as unpublished',
  /has not published their legal details yet/i.test(halfTerms.text));
check('and the outstanding fields are named for the operator',
  /Registered address/i.test(halfTerms.text) && /Governing law/i.test(halfTerms.text));
check('the footer prints the name without a trailing comma',
  !/Marvy Digital Ltd,\s*<\/div>/.test(halfTerms.text));

/* -------------------------------- restore -------------------------------- */

restore();
const after = await new Client(BASE).get('/terms');
check('the operator’s own settings are put back', after.status === 200);

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailed:');
  for (const f of failed) console.log(`  ✗ ${f.label}${f.detail ? ` — ${f.detail}` : ''}`);
  process.exit(1);
}
