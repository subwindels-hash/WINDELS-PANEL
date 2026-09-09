/**
 * Admin → Settings validation — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves the fixes to SettingsService::coerce():
 *   - an optional 'url' setting (reseller_webhook_url) saves empty and saves
 *     a valid https URL, and rejects a malformed one with a useful message;
 *   - 'choice:' settings (currency_display, default_theme) accept every
 *     documented value and reject an invalid one, matching case-insensitively
 *     without corrupting a legitimate submission.
 *
 *   node tools/devserver/settings_validation_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);

if (!adminPassword) {
  console.error('Usage: node tools/devserver/settings_validation_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

async function save(a, page, fields) {
  const res = await a.postForm('/admin/settings/save', fields, { fromHtml: page.text });
  return res;
}

const a = new Client(BASE);
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

const base = { __rendered_api_enabled: '1', api_enabled: '1' };

console.log('\n── Settings · webhook disabled (empty URL)');
let page = await a.get('/admin/settings');
let res = await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'symbol', default_theme: 'system' });
check('save succeeds', res.status === 200 && !/cannot be empty/i.test(res.text), res.text.match(/cannot be empty[^<]*/i)?.[0] || '');
page = await a.get('/admin/settings');
check('empty webhook persisted', /id="set-reseller_webhook_url"[^>]*value=""/.test(page.text));

console.log('\n── Settings · webhook enabled (valid URL)');
res = await save(a, page, { ...base, reseller_webhook_url: 'https://example.com/hook', currency_display: 'symbol', default_theme: 'system' });
check('save succeeds', res.status === 200 && !/must be a valid/i.test(res.text));
page = await a.get('/admin/settings');
check('valid webhook persisted', page.text.includes('https://example.com/hook'));

console.log('\n── Settings · invalid webhook URL');
res = await save(a, page, { ...base, reseller_webhook_url: 'not-a-url', currency_display: 'symbol', default_theme: 'system' });
check('a useful validation message is shown', /must be a valid http\(s\) URL/i.test(res.text), 'no clear error message');

console.log('\n── Settings · currency display');
for (const value of ['symbol', 'code']) {
  page = await a.get('/admin/settings');
  res = await save(a, page, { ...base, reseller_webhook_url: '', currency_display: value, default_theme: 'system' });
  check(`currency_display=${value} saves`, res.status === 200 && !/must be one of/i.test(res.text));
  page = await a.get('/admin/settings');
  check(`currency_display=${value} persisted and selected in the dropdown`,
    new RegExp(`name="currency_display"[\\s\\S]{0,200}?value="${value}" selected`).test(page.text));
}
page = await a.get('/admin/settings');
res = await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'bogus', default_theme: 'system' });
check('an invalid currency_display value is rejected', /must be one of: symbol, code/i.test(res.text));

console.log('\n── Settings · default theme');
for (const value of ['system', 'light', 'dark']) {
  page = await a.get('/admin/settings');
  res = await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'symbol', default_theme: value });
  check(`default_theme=${value} saves`, res.status === 200 && !/must be one of/i.test(res.text));
  page = await a.get('/admin/settings');
  check(`default_theme=${value} persisted and selected in the dropdown`,
    new RegExp(`name="default_theme"[\\s\\S]{0,200}?value="${value}" selected`).test(page.text));
}
page = await a.get('/admin/settings');
res = await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'symbol', default_theme: 'neon' });
check('an invalid default_theme value is rejected', /must be one of: system, light, dark/i.test(res.text));

console.log('\n── Settings · default_theme actually changes the public site');
page = await a.get('/admin/settings');
await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'symbol', default_theme: 'dark' });
let pub = await new Client(BASE).get('/');
check('public homepage boots with the configured default theme', /var def = "dark"/.test(pub.text));

console.log('\n── Settings · currency_display actually changes rendered prices');
page = await a.get('/admin/settings');
await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'code', default_theme: 'system' });
pub = await new Client(BASE).get('/services');
check('service prices render as CODE amount', /NGN\s*[\d,]+\.\d{2}/.test(pub.text));

// Restore defaults so the shared dev environment stays clean for other checks.
page = await a.get('/admin/settings');
await save(a, page, { ...base, reseller_webhook_url: '', currency_display: 'symbol', default_theme: 'system' });

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
