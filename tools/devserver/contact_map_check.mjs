/**
 * Contact map — first-party rendering, end to end.
 *
 * DEV TOOLING ONLY. Proves the privacy claim behind #7: a visitor who opens
 * /contact makes requests to exactly ONE origin (this one). No iframe, no
 * third-party tile or geocoder request from the browser. What this drives:
 *
 *   · the map is OFF → no map markup at all;
 *   · the map is a "lat,lng" query with the centre tile cached → a 3×3 grid
 *     of <img> tiles served from this origin by /contact/map/tile/…, a pin at
 *     the right offset, and a user-initiated "Open in maps" link;
 *   · the tile endpoint: a cached tile comes back as image/png with a long
 *     Cache-Control, an unknown map key and an out-of-grid cell are 404s,
 *     and a tile that is neither cached nor fetchable (no outbound route)
 *     degrades to a 404 — never a broken image;
 *   · the Content-Security-Policy no longer allows ANY third-party frame;
 *   · a free-text query with no geocode cache and no outbound route → the
 *     map box is omitted entirely while the address card and the "Open in
 *     maps" button remain.
 *
 * The map cache lives on this machine's storage/ (the dev server boots the
 * working tree), so the check seeds the centre tile directly, exactly the
 * way a month of production traffic would have cached it.
 *
 *   node tools/devserver/contact_map_check.mjs --admin-password <pw>
 *       [--base http://127.0.0.1:8080] [--storage <dir>]
 */
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const argv = process.argv.slice(2);
const arg = (name, dflt) => { const i = argv.indexOf(name); return i === -1 ? dflt : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const STORAGE = path.resolve(arg('--storage', path.join(__dirname, '../..', 'storage')));
const pw = arg('--admin-password', null);
if (!pw) { console.error('usage: contact_map_check.mjs --admin-password <pw>'); process.exit(2); }

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

/* The same slippy-map maths ContactMapService uses, to seed what it serves. */
const tileX = (lng, z) => { const n = 1 << z; return Math.floor((lng + 180) / 360 * n) % n; };
const tileY = (lat, z) => {
  const n = 1 << z;
  lat = Math.max(-85.05112878, Math.min(85.05112878, lat));
  const rad = (lat * Math.PI) / 180;
  return Math.floor((1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2 * n);
};
const mapKey = (query, zoom) => createHash('sha1').update(`${query}|${zoom}`).digest('hex').slice(0, 24);

/** Extract the frame-src directive value from a CSP header. */
function frameSrcDirective(csp) {
  const m = (csp || '').match(/frame-src\s+([^;]+)/);
  return m ? m[1].trim() : '(absent)';
}
/** True only when frame-src allows exactly 'self' and nothing else. */
function framesOnlySelf(csp) {
  return frameSrcDirective(csp) === "'self'";
}

/* A real 1×1 transparent PNG — the service accepts anything with a PNG
   magic under 2 MB, and the browser scales one pixel to a whole tile. */
const PNG_1x1 = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
  'base64');

const MAPS_DIR = path.join(STORAGE, 'cache', 'maps');

/** The values the operator had before the check touched anything. */
function originalSettings(html) {
  const text = (name) => {
    const m = html.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));
    return m ? m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#039;/g, "'") : '';
  };
  const bool = (name) =>
    /<input[^>]*name="${name}"[^>]*checked/.test(html) || /<input[^>]*checked[^>]*name="${name}".*value="1"/.test(html);
  return {
    enabled: bool('contact_map_enabled'),
    query: text('contact_map_query'),
    zoom: text('contact_map_zoom'),
    address: text('contact_address'),
  };
}

const a = new Client(BASE);   // admin
const pub = new Client(BASE); // anonymous visitor

/* Wait for the dev server to be up. */
let up = false;
for (let i = 0; i < 20 && !up; i++) {
  try { const r = await pub.get('/'); up = r.status === 200; } catch { up = false; }
  if (!up) await new Promise((r) => setTimeout(r, 750));
}
if (!up) { console.error('dev server did not come up at ' + BASE); process.exit(2); }

console.log('── Contact map · setup');
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: pw });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url), `at ${login.url}`);

const settingsPage = await a.get('/admin/settings');
check('settings page loads', settingsPage.status === 200);
const original = originalSettings(settingsPage.text);
console.log(`   (original: enabled=${original.enabled} query=${JSON.stringify(original.query)} zoom=${original.zoom} address=${JSON.stringify(original.address.slice(0, 40))})`);

/** Persist contact settings; every bool the form renders is declared. */
async function setMap({ enabled = null, query = null, zoom = null, address = null } = {}) {
  const fields = { __rendered_contact_map_enabled: '1' };
  if (enabled !== null) { if (enabled) fields.contact_map_enabled = '1'; }
  if (query !== null) fields.contact_map_query = query;
  if (zoom !== null) fields.contact_map_zoom = zoom;
  if (address !== null) fields.contact_address = address;
  const form = await a.get('/admin/settings');
  const r = await a.postForm('/admin/settings/save', fields, { fromHtml: form.text });
  return r;
}

/* ------------------------------------------------------------------ */
console.log('\n── Phase 1 · map disabled → the page mentions no map at all');
await setMap({ enabled: false });
let page = await pub.get('/contact');
check('contact page loads', page.status === 200);
check('no iframe of any kind', !/<iframe[\s>]/i.test(page.text));
check('no tile-grid markup', !/ws-map-tile/.test(page.text) && !/ws-map-pin/.test(page.text));
check('no tile URL rendered', !/\/contact\/map\/tile\//.test(page.text));
check('CSP frames only self', framesOnlySelf(page.headers.get('content-security-policy') || ''),
  'frame-src=' + frameSrcDirective(page.headers.get('content-security-policy') || ''));


/* ------------------------------------------------------------------ */
console.log('\n── Phase 2 · "lat,lng" with the centre tile cached');
const LAT = 9.06, LNG = 7.5, Z = 15;
const QUERY = `${LAT}, ${LNG}`;
const key = mapKey(QUERY, Z);
const x0 = tileX(LNG, Z), y0 = tileY(LAT, Z);
const tileDir = path.join(MAPS_DIR, 'tiles', String(Z), String(x0));
fs.mkdirSync(tileDir, { recursive: true });
fs.writeFileSync(path.join(tileDir, `${y0}.png`), PNG_1x1);
console.log(`   (seeded ${Z}/${x0}/${y0}.png → cache key ${key})`);

await setMap({ enabled: true, query: QUERY, zoom: Z });
page = await pub.get('/contact');
check('contact page loads', page.status === 200);
check('still no iframe', !/<iframe[\s>]/i.test(page.text));

const tiles = [...page.text.matchAll(/<img class="ws-map-tile" src="([^"]+)"[^>]*style="grid-column:(\d);grid-row:(\d)"/g)];
check('a 3×3 first-party tile grid rendered', tiles.length === 9, `found ${tiles.length}`);
let gridOk = true, originOk = true;
tiles.forEach(([, src, col, row], idx) => {
  const i = idx % 3, j = Math.floor(idx / 3);
  if (!src.endsWith(`/contact/map/tile/${key}/${i}/${j}`)) gridOk = false;
  if (Number(col) !== i + 1 || Number(row) !== j + 1) gridOk = false;
  if (!src.startsWith(BASE)) originOk = false;
});
check('every tile is one of the 9 configured cells, in order', gridOk);
check('every tile comes from this origin (no third-party host)', originOk);

const pin = page.text.match(/<span class="ws-map-pin"[^>]*style="left:([\d.]+)%;top:([\d.]+)%"/);
check('the pin is placed inside the centre tile',
  !!pin && Number(pin[1]) >= 100 / 3 - 0.5 && Number(pin[1]) <= 200 / 3 + 0.5
    && Number(pin[2]) >= 100 / 3 - 0.5 && Number(pin[2]) <= 200 / 3 + 0.5,
  pin ? `left=${pin[1]} top=${pin[2]}` : 'no pin found');

const openInMaps = page.text.match(/href="(https:\/\/www\.openstreetmap\.org\/\?mlat=[^"]+)"/);
check('user-initiated "Open in maps" points at the configured place',
  !!openInMaps && openInMaps[1].includes(`mlat=${encodeURIComponent(String(LAT))}`)
    && openInMaps[1].includes(`mlon=${encodeURIComponent(String(LNG))}`),
  openInMaps ? openInMaps[1] : 'button missing');

check('CSP no longer allows third-party frames', framesOnlySelf(page.headers.get('content-security-policy') || ''),
  'frame-src=' + frameSrcDirective(page.headers.get('content-security-policy') || ''));

/* The tile endpoint itself. The body is binary, so fetch it raw — the
   shared Client decodes bodies as text and would mangle the PNG bytes. */
const centreRes = await fetch(BASE + `/contact/map/tile/${key}/1/1`);
const centreBuf = Buffer.from(await centreRes.arrayBuffer());
check('cached centre tile served as image/png', centreRes.status === 200 && (centreRes.headers.get('content-type') || '').includes('image/png'),
  `status=${centreRes.status} type=${centreRes.headers.get('content-type')}`);
check('long Cache-Control on first-party tiles', /public,\s*max-age=2592000/.test(centreRes.headers.get('cache-control') || ''),
  centreRes.headers.get('cache-control'));
check('tiles are noindex-able by search engines', (centreRes.headers.get('x-robots-tag') || '') === 'noindex');
check('tile bytes are exactly the cached bytes', centreBuf.equals(PNG_1x1),
  `served ${centreBuf.length} bytes, cache has ${PNG_1x1.length}`);

const badKey = await pub.raw(`/contact/map/tile/${'f'.repeat(24)}/1/1`);
check('an unknown map key is a 404', badKey.status === 404, `status=${badKey.status}`);
const badCell = await pub.raw(`/contact/map/tile/${key}/3/1`);
check('a cell outside the 3×3 grid is a 404', badCell.status === 404, `status=${badCell.status}`);
const unseeded = await pub.raw(`/contact/map/tile/${key}/0/0`);
check('an uncached, unfetchable tile is a 404 (no broken image, no proxy)', unseeded.status === 404, `status=${unseeded.status}`);

/* ------------------------------------------------------------------ */
console.log('\n── Phase 3 · free text with no geocode cache and no outbound route');
const FREE = '123 Main Street, Abuja';
await setMap({ enabled: true, query: FREE, zoom: 15, address: '123 Main Street, Abuja 10124' });
page = await pub.get('/contact');
check('contact page loads', page.status === 200);
check('still no iframe', !/<iframe[\s>]/i.test(page.text));
check('unresolvable map is omitted, not broken', !/ws-map-tile/.test(page.text) && !/ws-map-pin/.test(page.text));
check('the printed address stands in', page.text.includes('123 Main Street, Abuja 10124'));
check('"Open in maps" falls back to a search for the address',
  /href="https:\/\/www\.openstreetmap\.org\/search\?query=123%20Main%20Street%2C%20Abuja"/.test(page.text));

/* ------------------------------------------------------------------ */
console.log('\n── Phase 4 · restore the operator\'s settings');
const restore = await setMap({
  enabled: original.enabled,
  query: original.query,
  zoom: original.zoom || '15',
  address: original.address,
});
check('settings restored', restore.status === 200, `status=${restore.status} url=${restore.url}`);

/* ------------------------------------------------------------------ */
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed${failed.length ? ` — ${failed.length} FAILED` : ''}`);
process.exit(failed.length ? 1 : 0);
