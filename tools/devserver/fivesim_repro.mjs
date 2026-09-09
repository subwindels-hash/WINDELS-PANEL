/**
 * Reproduction of the reported 5SIM "404 after entering the API key".
 *
 * DEV TOOLING ONLY. Drives the real application over HTTP the way an operator
 * does: log in as admin, add a FIVESIM provider through the form, press
 * "Test connection". The API key is read from $FIVESIM_KEY_FILE (default
 * /home/user/.fivesim_key) and is never printed.
 *
 *   node tools/devserver/fivesim_repro.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const KEY_FILE = process.env.FIVESIM_KEY_FILE || path.resolve('/home/user/.fivesim_key');
const PASSWORD = process.env.DEMO_PASSWORD || 'Repro!2026Pass';
// A real key file pins the repro to the operator's key; without one, use a
// random token so the create (which requires a non-empty key) still reaches
// the redirect-and-id assertion instead of bouncing back to the list.
const token = fs.existsSync(KEY_FILE) ? fs.readFileSync(KEY_FILE, 'utf8').trim()
  : 'dev-' + crypto.randomBytes(32).toString('hex');

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n      ${detail}`}`);
  return ok;
}

function flashFrom(text) {
  const m = text.match(/alert (?:alert-)?(?:success|error|danger|warning)[^>]*>([\s\S]{0,700}?)<\//);
  return m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
}

async function login(client, identifier, pathname) {
  await client.get(pathname);
  return client.postForm(pathname, { identifier, password: PASSWORD });
}

async function createProvider(client, { name, apiUrl, apiType, key }) {
  const page = await client.get('/admin/providers');
  return client.postForm('/admin/providers/create', {
    name, api_url: apiUrl, api_key: key, api_type: apiType,
    status: 'ACTIVE', timeout_ms: '15000', sync_interval_minutes: '60',
    markup_percent: '0',
  }, { fromHtml: page.text, follow: false });
}

async function testProvider(client, publicId) {
  const page = await client.get(`/admin/providers/${publicId}`);
  return client.postForm(`/admin/providers/${publicId}/test`, {}, { fromHtml: page.text });
}

const c = new Client(BASE);

console.log(`# 5SIM repro — base=${BASE}, key: ${token ? token.slice(0, 12) + '…(len ' + token.length + ')' : '(none)'}`);

let r = await login(c, 'admin', '/admin/login');
check('admin login lands in the back office', /admin|dashboard/.test(r.url || '') && !/login/.test(r.url || ''), `status=${r.status} url=${r.url}`);

// 1. Create a FIVESIM provider with the CORRECT current-protocol URL.
r = await createProvider(c, {
  name: `5sim-v1-${Date.now() % 100000}`,
  apiUrl: 'https://5sim.net/v1',
  apiType: 'FIVESIM',
  key: token,
});
check('create provider (api_url=https://5sim.net/v1) returns a redirect, not 404', r.status >= 300 && r.status < 400, `status=${r.status} url=${r.url}`);
const loc = r.headers.get('location') || '';
const publicId = loc.match(/admin\/providers\/([^/?#]+)/)?.[1] || null;
check('redirect carries the new provider public id', !!publicId, `location=${loc}`);

const detail = publicId ? await c.get(`/admin/providers/${publicId}`) : null;
check('provider detail page renders (no 404)', !!detail && detail.status === 200, detail ? `status=${detail.status}` : 'no id');
if (detail && detail.status === 200) {
  check('detail shows the stored api_url', detail.text.includes('5sim.net/v1'), 'api_url row rendered');
}

// 2. Press "Test connection" exactly like the button does.
if (publicId) {
  r = await testProvider(c, publicId);
  const flash = flashFrom(r.text);
  check('test connection POST is answered with a result (not a 404)', r.status === 200 && !!flash, `status=${r.status} url=${r.url}`);
  console.log(`   ⤷ flash: ${flash.slice(0, 260)}`);
}

// 3. Deprecated-URL provider: what an operator carrying the old row would hit.
r = await createProvider(c, {
  name: `5sim-legacy-${Date.now() % 100000}`,
  apiUrl: 'https://5sim.net/stubs/handler_api.php',
  apiType: 'FIVESIM',
  key: token,
});
const legacyLoc = r.headers.get('location') || '';
const legacyId = legacyLoc.match(/admin\/providers\/([^/?#]+)/)?.[1] || null;
check('create provider with DEPRECATED handler_api.php URL is accepted (then fails on test)', r.status >= 300 && r.status < 400, `status=${r.status}`);
if (legacyId) {
  r = await testProvider(c, legacyId);
  check('test connection on the deprecated URL does NOT 404', r.status >= 300 && r.status < 400, `status=${r.status}`);
  const flash = flashFrom(r.text);
  console.log(`   ⤷ flash: ${flash.slice(0, 300)}`);
}

// 4. Customer side: dashboard/numbers renders?
const cust = new Client(BASE);
await login(cust, 'demo', '/login');
r = await cust.get('/dashboard/numbers');
check('customer /dashboard/numbers renders (no 404)', r.status === 200, `status=${r.status} url=${r.url}`);

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
