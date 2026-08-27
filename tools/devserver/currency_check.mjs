/**
 * Currency management — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves Admin → Currencies actually controls the
 * application (not a switch that saves and does nothing):
 *   - the base/accounting currency is immutable and stays NGN;
 *   - enabling/disabling a currency, setting a default, and updating an
 *     exchange rate all persist and are reflected back in the UI;
 *   - the base currency can never be disabled;
 *   - disabling the current default falls back to the base currency rather
 *     than leaving a dangling setting;
 *   - rate validation rejects zero/negative/absurd values;
 *   - every action is audited;
 *   - the public catalogue actually renders a converted estimate once the
 *     display currency differs from the base currency, and stops once it
 *     matches again — proving this is live application behaviour, not a
 *     cosmetic-only admin form.
 *
 *   node tools/devserver/currency_check.mjs --admin-password <pw> [--db storage/devdb/marvy.sqlite]
 */
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
  console.error('Usage: node tools/devserver/currency_check.mjs --admin-password <pw>');
  process.exit(2);
}

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

const a = new Client(BASE);
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

console.log('\n── Currencies · page and base currency');
let p = await a.get('/admin/currencies');
check('currencies page loads', p.status === 200);
check('the base currency is shown as NGN and fixed', p.text.includes('base') && /Base currency<\/div><div class="mono font-medium">NGN/.test(p.text));
check('USD, EUR and GBP are listed', ['USD', 'EUR', 'GBP'].every((c) => p.text.includes(c)));

console.log('\n── Currencies · the base currency cannot be disabled');
let disableBase = await a.postForm('/admin/currencies/active', { code: 'NGN', active: '' }, { fromHtml: p.text });
check('disabling NGN is refused', /cannot be disabled/i.test(disableBase.text));

console.log('\n── Currencies · exchange rate validation');
p = await a.get('/admin/currencies');
let zero = await a.postForm('/admin/currencies/rate', { code: 'GBP', rate: '0' }, { fromHtml: p.text });
check('a zero rate is rejected', /must be a positive number/i.test(zero.text));
let negative = await a.postForm('/admin/currencies/rate', { code: 'GBP', rate: '-5' }, { fromHtml: p.text });
check('a negative rate is rejected', /must be a positive number/i.test(negative.text));
let absurd = await a.postForm('/admin/currencies/rate', { code: 'GBP', rate: '5000000' }, { fromHtml: p.text });
check('an absurd rate is rejected', /looks like a mistake/i.test(absurd.text));
let baseRate = await a.postForm('/admin/currencies/rate', { code: 'NGN', rate: '2' }, { fromHtml: p.text });
check('the base currency rate cannot be changed', /always exactly 1\.0/i.test(baseRate.text));

console.log('\n── Currencies · a valid rate update persists with provenance');
p = await a.get('/admin/currencies');
let goodRate = await a.postForm('/admin/currencies/rate', { code: 'GBP', rate: '0.00051500' }, { fromHtml: p.text });
check('valid rate update accepted', goodRate.status === 200);
p = await a.get('/admin/currencies');
check('the new rate is shown', p.text.includes('0.00051500'));
check('the source is now MANUAL', /GBP[\s\S]{0,600}?>MANUAL</.test(p.text));
check('the acting admin is recorded', /GBP[\s\S]{0,700}?>by admin</.test(p.text));

console.log('\n── Currencies · enable/disable and default');
let disableGbp = await a.postForm('/admin/currencies/active', { code: 'GBP', active: '' }, { fromHtml: p.text });
check('disable accepted', disableGbp.status === 200);
p = await a.get('/admin/currencies');
check('GBP shows as Disabled', /GBP[\s\S]{0,900}?>Disabled</.test(p.text));

let reenableGbp = await a.postForm('/admin/currencies/active', { code: 'GBP', active: '1' }, { fromHtml: p.text });
check('re-enable accepted', reenableGbp.status === 200);
p = await a.get('/admin/currencies');
check('GBP shows as Active again', />Active<\/span>[\s\S]{0,20}|GBP[\s\S]{0,900}?badge-success/.test(p.text));

let setDefault = await a.postForm('/admin/currencies/default', { code: 'USD' }, { fromHtml: p.text });
check('setting USD as default is accepted', setDefault.status === 200);
p = await a.get('/admin/currencies');
check('USD now shows as the default display currency',
  /Default display currency<\/div><div class="mono font-medium">USD/.test(p.text));

console.log('\n── Currencies · disabling the current default falls back safely');
let disableDefault = await a.postForm('/admin/currencies/active', { code: 'USD', active: '' }, { fromHtml: p.text });
check('disabling the default currency is accepted', disableDefault.status === 200);
p = await a.get('/admin/currencies');
check('the default falls back to the base currency (NGN)',
  /Default display currency<\/div><div class="mono font-medium">NGN/.test(p.text));
// restore USD for the next section
await a.postForm('/admin/currencies/active', { code: 'USD', active: '1' }, { fromHtml: p.text });

console.log('\n── Currencies · settings actually change public output');
p = await a.get('/admin/currencies');
await a.postForm('/admin/currencies/default', { code: 'USD' }, { fromHtml: p.text });
const c = new Client(BASE);
let pub = await c.get('/services');
check('an estimated converted price appears on the public catalogue once the display currency differs from base',
  pub.text.includes('≈'));

p = await a.get('/admin/currencies');
await a.postForm('/admin/currencies/default', { code: 'NGN' }, { fromHtml: p.text });
pub = await c.get('/services');
check('the estimate disappears once the display currency matches the base currency again',
  !pub.text.includes('≈'));

console.log('\n── Currencies · every mutation is audited');
const auditActions = withDb((db) => db.prepare(
  "SELECT DISTINCT action FROM audit_logs WHERE action LIKE 'currency.%' ORDER BY action"
).all()).map((r) => r.action);
for (const expected of ['currency.active_changed', 'currency.default_display_changed', 'currency.rate_changed']) {
  check(`audit log contains ${expected}`, auditActions.includes(expected));
}

console.log('\n── Currencies · existing NGN-denominated data is untouched');
const wallets = withDb((db) => db.prepare(
  "SELECT DISTINCT currency FROM wallets"
).all()).map((r) => r.currency);
check('wallets remain denominated in NGN', wallets.length > 0 && wallets.every((c) => c === 'NGN'), `found: ${wallets.join(',')}`);
const ngnRow = withDb((db) => db.prepare("SELECT is_base, exchange_rate FROM currencies WHERE code = 'NGN'").get());
check('NGN is still the base currency at exchange_rate 1.0', ngnRow && ngnRow.is_base === 1 && ngnRow.exchange_rate === '1.00000000');

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
