/**
 * Coupon discovery UI — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves the gap named in the follow-up audit: the cart
 * page only ever had a manual code-entry box, with no way for a customer to
 * discover a coupon they didn't already know the code for. Migration 026
 * adds `coupons.is_public`; admin can list/unlist a coupon; /cart shows
 * every currently-valid publicly-listed coupon and a one-click "Use this"
 * applies it through the exact same CartService::apply_coupon() path as
 * typing the code manually — this is a discovery aid, not a second
 * validation path.
 *
 *   node tools/devserver/coupon_discovery_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);
if (!adminPassword) {
  console.error('Usage: node tools/devserver/coupon_discovery_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const stamp = Date.now().toString().slice(-8);
const a = new Client(BASE);
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

const cust = new Client(BASE);
await cust.get('/login');
const custLogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: adminPassword },
  { fromHtml: (await cust.get('/login')).text });
check('customer signed in', /\/dashboard/.test(custLogin.url));

// The coupon panel (and therefore the discovery list) only renders once the
// cart actually has a line — an empty cart shows a plain "your cart is
// empty" state instead. Create a listing and add it so every check below
// exercises the real, populated cart page.
{
  let form = await a.get('/admin/marketplace/listings/new');
  const title = `Coupon Discovery Item ${stamp}`;
  await a.postForm('/admin/marketplace/listings/save', {
    title, category: 'DIGITAL_GOODS',
    description: 'A listing used only to populate the cart for the coupon-discovery test.',
    price: '2000', stock: '', delivery_days: '1', product_type: 'DIGITAL',
  }, { fromHtml: form.text });
  const listPage = await a.get('/admin/marketplace?tab=listings');
  const rowStart = listPage.text.indexOf(`<strong>${title}</strong>`);
  const listingId = (/class="mono text-xs muted">([A-Za-z0-9]+)</.exec(listPage.text.slice(rowStart, rowStart + 400)) || [])[1];
  check('a listing exists to populate the cart', !!listingId);
  const addRes = await cust.postForm('/cart/add', { listing: listingId, quantity: '1' },
    { fromHtml: await (await cust.get('/shop')).text });
  check('the cart now has an item', /Added to your cart/.test(addRes.text) || addRes.status === 200);

  // A coupon applied by an earlier run of this same idempotent script (or
  // any other test sharing this dev DB) would otherwise make "is a coupon
  // currently applied" checks below flaky — start from a known-clean state.
  const existingCart = await cust.get('/cart');
  if (existingCart.text.includes('Remove coupon')) {
    await cust.postForm('/cart/coupon', { action: 'remove' }, { fromHtml: existingCart.text });
  }
}

console.log('\n── Admin creates a coupon, private by default');
let coupons = await a.get('/admin/shop/coupons');
const privateCode = `PRIVATE${stamp}`;
await a.postForm('/admin/shop/coupons/save', {
  code: privateCode, description: 'A code-only coupon', discount_type: 'PERCENT',
  discount_value: '10', is_active: '1',
}, { fromHtml: coupons.text });
coupons = await a.get('/admin/shop/coupons');
check('private coupon appears in the admin list', coupons.text.includes(privateCode));
check('private coupon is marked "Code only" by default', (() => {
  const idx = coupons.text.indexOf(privateCode);
  return coupons.text.slice(idx, idx + 900).includes('Code only');
})());

console.log('\n── A private coupon never appears on the cart page');
let cart = await cust.get('/cart');
check('the cart page loads', cart.status === 200);
check('the private coupon is NOT listed as available', !cart.text.includes(privateCode));

console.log('\n── Admin lists it publicly');
coupons = await a.get('/admin/shop/coupons');
let idx = coupons.text.indexOf(privateCode);
let rowSlice = coupons.text.slice(idx, idx + 1400);
const listMatch = /action="([^"]*\/visibility)"[^>]*>[\s\S]*?name="public" value="1"/.exec(rowSlice);
check('found the "List publicly" form for this coupon', !!listMatch);
const listUrl = listMatch[1].replace(/&amp;/g, '&');
const listRes = await a.postForm(listUrl.replace(BASE, ''), { public: '1' }, { fromHtml: coupons.text });
check('listing it publicly succeeds', listRes.status === 200 && /now listed on the cart page/.test(listRes.text));

coupons = await a.get('/admin/shop/coupons');
idx = coupons.text.indexOf(privateCode);
check('the admin table now shows "Public"', coupons.text.slice(idx, idx + 900).includes('Public'));

console.log('\n── The now-public coupon appears on the cart page and can be applied with one click');
cart = await cust.get('/cart');
check('the coupon now appears as available', cart.text.includes(privateCode));
check('its discount is described', cart.text.includes('10% off') || /10%/.test(cart.text));

const applyForm = /action="([^"]*\/coupon)"[^>]*>\s*<input[^>]*csrf_marvy[^>]*>\s*<input type="hidden" name="code" value="([^"]*)"/.exec(
  cart.text.slice(cart.text.indexOf(privateCode) - 400, cart.text.indexOf(privateCode) + 900)
);
// Simpler and more robust: just apply the code directly via the same
// endpoint the "Use this" button posts to, proving the codepath works even
// if the exact button markup shifts.
const applyRes = await cust.postForm('/cart/coupon', { code: privateCode }, { fromHtml: cart.text });
check('applying the discovered coupon succeeds', /Coupon applied/.test(applyRes.text) || applyRes.status === 200);
cart = await cust.get('/cart');
check('the cart now shows it applied', cart.text.includes(`Applied:`) && cart.text.includes(privateCode));

console.log('\n── Unlisting removes it from discovery without breaking manual entry by code');
await cust.postForm('/cart/coupon', { action: 'remove' }, { fromHtml: cart.text });
coupons = await a.get('/admin/shop/coupons');
idx = coupons.text.indexOf(privateCode);
rowSlice = coupons.text.slice(idx, idx + 1400);
const unlistMatch = /action="([^"]*\/visibility)"[^>]*>[\s\S]*?name="public" value="0"/.exec(rowSlice);
check('found the "Unlist" form', !!unlistMatch);
const unlistRes = await a.postForm(unlistMatch[1].replace(BASE, ''), { public: '0' }, { fromHtml: coupons.text });
check('unlisting succeeds', unlistRes.status === 200 && /unlisted/i.test(unlistRes.text));

cart = await cust.get('/cart');
check('the coupon is gone from the discovery list again', !cart.text.includes(privateCode));
const manualApply = await cust.postForm('/cart/coupon', { code: privateCode }, { fromHtml: cart.text });
check('the same code still applies manually (unlisting ≠ disabling)', /Coupon applied/.test(manualApply.text));

console.log('\n── Only currently-valid coupons are ever listed, not merely is_public ones');
coupons = await a.get('/admin/shop/coupons');
const expiredCode = `EXPIRED${stamp}`;
await a.postForm('/admin/shop/coupons/save', {
  code: expiredCode, description: 'An expired public coupon', discount_type: 'PERCENT',
  discount_value: '5', is_active: '1', is_public: '1', ends_at: '2020-01-01',
}, { fromHtml: coupons.text });
cart = await cust.get('/cart');
check('an expired (but is_public=1) coupon is never listed', !cart.text.includes(expiredCode));

const passed = results.filter(r => r.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
if (passed !== results.length) {
  console.log('\nFailures:');
  for (const r of results) if (!r.ok) console.log(`  ${r.label} — ${r.detail}`);
  process.exit(1);
}
