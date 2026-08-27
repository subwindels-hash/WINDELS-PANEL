/**
 * Physical product shipping details (SKU/weight/dimensions) — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves the admin gap found in the follow-up audit: the
 * `physical_products` table (migration 025) had no model and no admin form,
 * so a PHYSICAL listing's SKU, weight and package dimensions could never
 * actually be set from the UI even though the schema existed. This checks
 * the new "Shipping details" card on the listing edit page: it saves, it
 * persists, it enforces a unique SKU, and it validates input server-side.
 *
 *   node tools/devserver/physical_product_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);
if (!adminPassword) {
  console.error('Usage: node tools/devserver/physical_product_check.mjs --admin-password <pw>');
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

function listingIdByTitle(html, title) {
  const rowStart = html.indexOf(`<strong>${title}</strong>`);
  if (rowStart === -1) return null;
  const idMatch = /class="mono text-xs muted">([A-Za-z0-9]+)</.exec(html.slice(rowStart, rowStart + 400));
  return idMatch ? idMatch[1] : null;
}

console.log('\n── Physical product: create a listing to attach shipping details to');
let form = await a.get('/admin/marketplace/listings/new');
const categorySection = form.text.slice(form.text.indexOf('id="category"'));
const realCategory = (/<option value="([A-Z0-9_]+)"/.exec(categorySection) || [])[1] || 'GAMING';
const title = `PP Test ${stamp}`;
await a.postForm('/admin/marketplace/listings/save', {
  title, category: realCategory,
  description: 'A physical product used to test the shipping-details form.',
  price: '4200', stock: '25', delivery_days: '3', product_type: 'PHYSICAL',
}, { fromHtml: form.text });

let listingsPage = await a.get('/admin/marketplace?tab=listings');
const listingId = listingIdByTitle(listingsPage.text, title);
check('listing created and found', !!listingId, `title=${title}`);

console.log('\n── Physical product: the edit page offers a shipping-details form');
let editPage = await a.get(`/admin/marketplace/listings/${listingId}/edit`);
check('the edit page has a shipping details card', /Shipping details/.test(editPage.text));
check('before saving, the page says no SKU is set yet', /No SKU set yet/.test(editPage.text));
check('the form posts to the physical-details route',
  editPage.text.includes(`/admin/marketplace/listings/${listingId}/physical`));

console.log('\n── Physical product: saving SKU + dimensions persists them');
const sku = `E2E-SKU-${stamp}`;
let save = await a.postForm(`/admin/marketplace/listings/${listingId}/physical`, {
  sku, weight_grams: '450', length_cm: '20.5', width_cm: '15', height_cm: '8', requires_shipping: '1',
}, { fromHtml: editPage.text });
check('save succeeds', save.status === 200, `status=${save.status}`);
check('a success message is shown', /Shipping details saved/.test(save.text));

editPage = await a.get(`/admin/marketplace/listings/${listingId}/edit`);
check('SKU persisted', editPage.text.includes(`value="${sku}"`));
check('weight persisted', editPage.text.includes('value="450"'));
check('length persisted', editPage.text.includes('value="20.50"') || editPage.text.includes('value="20.5"'));
check('the placeholder "no SKU" message is gone now', !/No SKU set yet/.test(editPage.text));

console.log('\n── Physical product: editing again updates the same row, not a duplicate');
let resave = await a.postForm(`/admin/marketplace/listings/${listingId}/physical`, {
  sku, weight_grams: '500', length_cm: '', width_cm: '', height_cm: '', requires_shipping: '',
}, { fromHtml: editPage.text });
check('re-save with the same SKU succeeds (update, not a duplicate-SKU rejection)', resave.status === 200 && /Shipping details saved/.test(resave.text));
editPage = await a.get(`/admin/marketplace/listings/${listingId}/edit`);
check('updated weight persisted', editPage.text.includes('value="500"'));
check('clearing an optional dimension saves it as blank, not zero', !editPage.text.match(/name="length_cm"[^>]*value="0"/));
check('unchecking "requires shipping" persists as unchecked',
  !new RegExp(`name="requires_shipping" value="1"\\s+checked`).test(editPage.text));

console.log('\n── Physical product: SKU uniqueness is enforced across listings');
form = await a.get('/admin/marketplace/listings/new');
const title2 = `PP Test B ${stamp}`;
await a.postForm('/admin/marketplace/listings/save', {
  title: title2, category: realCategory,
  description: 'A second physical product used to test SKU uniqueness.',
  price: '1000', stock: '5', delivery_days: '2', product_type: 'PHYSICAL',
}, { fromHtml: form.text });
listingsPage = await a.get('/admin/marketplace?tab=listings');
const listingId2 = listingIdByTitle(listingsPage.text, title2);
check('second listing created', !!listingId2);

const editPage2 = await a.get(`/admin/marketplace/listings/${listingId2}/edit`);
const clash = await a.postForm(`/admin/marketplace/listings/${listingId2}/physical`, {
  sku, weight_grams: '100', length_cm: '', width_cm: '', height_cm: '', requires_shipping: '1',
}, { fromHtml: editPage2.text });
check('reusing another listing\'s SKU is refused', /already used by another listing/.test(clash.text));

console.log('\n── Physical product: input is validated server-side');
const badSku = await a.postForm(`/admin/marketplace/listings/${listingId2}/physical`, {
  sku: '', weight_grams: '100', length_cm: '', width_cm: '', height_cm: '', requires_shipping: '1',
}, { fromHtml: editPage2.text });
check('an empty SKU is refused', /SKU is required/.test(badSku.text));

console.log('\n── Physical product: only PHYSICAL listings accept shipping details');
form = await a.get('/admin/marketplace/listings/new');
const digitalTitle = `PP Digital Guard ${stamp}`;
await a.postForm('/admin/marketplace/listings/save', {
  title: digitalTitle, category: 'DIGITAL_GOODS',
  description: 'A digital listing that must refuse shipping details.',
  price: '900', stock: '', delivery_days: '1', product_type: 'DIGITAL',
}, { fromHtml: form.text });
listingsPage = await a.get('/admin/marketplace?tab=listings');
const digitalListingId = listingIdByTitle(listingsPage.text, digitalTitle);
check('digital listing created', !!digitalListingId);
const digitalEditPage = await a.get(`/admin/marketplace/listings/${digitalListingId}/edit`);
check('the edit page for a DIGITAL listing has no shipping-details card', !/Shipping details/.test(digitalEditPage.text));
const wrongTypeAttempt = await a.postForm(`/admin/marketplace/listings/${digitalListingId}/physical`, {
  sku: `SHOULDNOTSAVE-${stamp}`, weight_grams: '1', length_cm: '', width_cm: '', height_cm: '', requires_shipping: '1',
}, { fromHtml: digitalEditPage.text });
check('posting shipping details to a DIGITAL listing is refused',
  /Only physical listings have shipping details/.test(wrongTypeAttempt.text));

const passed = results.filter(r => r.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
if (passed !== results.length) {
  console.log('\nFailures:');
  for (const r of results) if (!r.ok) console.log(`  ${r.label} — ${r.detail}`);
  process.exit(1);
}
