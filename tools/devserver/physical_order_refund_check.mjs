/**
 * Physical order refund workflow — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves the named gap in the follow-up audit: the admin
 * Shop → Shipments screen had no way to refund the underlying order — a
 * staff member had to know to go find it in the separate Marketplace admin
 * screen instead. This adds a "Refund this order" action directly on the
 * shipment detail page that delegates to the exact same
 * MarketplaceService::refund() escrow-refund path admin/Marketplace's
 * dispute-resolution screen already uses (no second refund implementation).
 *
 *   node tools/devserver/physical_order_refund_check.mjs --admin-password <pw>
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
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);
if (!adminPassword) {
  console.error('Usage: node tools/devserver/physical_order_refund_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const stamp = Date.now().toString().slice(-8);
const { DatabaseSync } = require('node:sqlite');
const dbPath = path.resolve(process.cwd(), DB_PATH);
function withDb(fn) {
  const db = new DatabaseSync(dbPath);
  try { return fn(db); } finally { db.close(); }
}

console.log('── Physical order refund: admin creates a physical listing');
const a = new Client(BASE);
await a.get('/admin/login');
const adminLogin = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(adminLogin.url) && !/login/.test(adminLogin.url));

let form = await a.get('/admin/marketplace/listings/new');
const categorySection = form.text.slice(form.text.indexOf('id="category"'));
const realCategory = (/<option value="([A-Z0-9_]+)"/.exec(categorySection) || [])[1] || 'GAMING';
const title = `Refund Test ${stamp}`;
await a.postForm('/admin/marketplace/listings/save', {
  title, category: realCategory,
  description: 'A physical product used to test the shipment-screen refund workflow.',
  price: '5000', stock: '10', delivery_days: '3', product_type: 'PHYSICAL',
}, { fromHtml: form.text });

function listingIdByTitle(html, t) {
  const rowStart = html.indexOf(`<strong>${t}</strong>`);
  if (rowStart === -1) return null;
  const idMatch = /class="mono text-xs muted">([A-Za-z0-9]+)</.exec(html.slice(rowStart, rowStart + 400));
  return idMatch ? idMatch[1] : null;
}
let listingsPage = await a.get('/admin/marketplace?tab=listings');
const listingId = listingIdByTitle(listingsPage.text, title);
check('physical listing created', !!listingId);

// Checkout deliberately refuses a PHYSICAL listing with no package metadata;
// attach the same server-side row the admin shipping-details form would have
// created so this refund check reaches the shipment screen, not a validation
// redirect.
if (listingId) {
  withDb((db) => {
    const listing = db.prepare('SELECT id FROM marketplace_listings WHERE public_id = ?').get(listingId);
    if (listing) {
      db.prepare(`INSERT OR IGNORE INTO physical_products
        (public_id, listing_id, sku, weight_grams, length_cm, width_cm, height_cm,
         requires_shipping, created_at, updated_at)
        VALUES (?, ?, ?, 500, '20.00', '15.00', '8.00', 1, datetime('now'), datetime('now'))`)
        .run(('E2EREFPP' + stamp).slice(0, 26).padEnd(26, '0'), listing.id, `E2E-REF-${stamp}`);
    }
  });
}

withDb((db) => {
  db.exec(`INSERT OR IGNORE INTO shipping_methods (public_id, name, carrier, price, currency, estimated_days_min, estimated_days_max, is_active, sorting, created_at, updated_at)
    VALUES ('E2EREFUNDSHIP${stamp}', 'Standard shipping', 'DHL', '500.00000000', 'NGN', 3, 7, 1, 0, datetime('now'), datetime('now'))`);
});

console.log('\n── Physical order refund: a customer buys it');
const user = { username: `refundshopper${stamp}`, email: `refundshopper${stamp}@example.test`, password: 'RefundE2E!Pass99' };
const c = new Client(BASE);
await c.get('/register');
await c.postForm('/register', {
  username: user.username, email: user.email, password: user.password, password_confirm: user.password,
  terms: '1', accept_terms: '1',
});
await c.get('/login');
const login = await c.postForm('/login', { identifier: user.username, password: user.password });
check('customer signed in', /dashboard/i.test(login.url));

const userId = withDb((db) => db.prepare('SELECT id FROM users WHERE username = ?').get(user.username)).id;
withDb((db) => {
  const wallet = db.prepare('SELECT id FROM wallets WHERE user_id = ?').get(userId);
  db.exec(`UPDATE wallets SET balance = '20000.00000000' WHERE id = ${wallet.id}`);
});

let productPage = await c.get(`/shop/product/${listingId}`);
let addRes = await c.postForm('/cart/add', { listing: listingId, quantity: '1', redirect_to: 'cart' }, { fromHtml: productPage.text });
check('added to cart', addRes.status === 200);

let checkoutPage = await c.get('/checkout');
check('checkout loads and asks for a shipping address', checkoutPage.status === 200 && /Shipping address/.test(checkoutPage.text));

let place = await c.postForm('/checkout/place', {
  full_name: 'Refund Tester', phone: '08000000001', line1: '2 Refund Street', city: 'Abuja',
  state: 'FCT', postal_code: '900001', country_code: 'NG',
  shipping_method: `E2EREFUNDSHIP${stamp}`,
}, { fromHtml: checkoutPage.text });
check('checkout completes', place.status === 200);

const walletAfterPurchase = withDb((db) => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(userId)).balance;
check('the wallet was actually charged', parseFloat(walletAfterPurchase) < 20000, `balance=${walletAfterPurchase}`);

console.log('\n── Physical order refund: admin finds the shipment and refunds it');
let shipmentsPage = await a.get('/admin/shop/shipments');
check('shipments queue loads', shipmentsPage.status === 200);
const orderRowIdx = shipmentsPage.text.indexOf(title);
check('the new order appears in the shipments queue', orderRowIdx !== -1);

const shipmentLinkMatch = /href="([^"]*\/admin\/shop\/shipments\/[A-Za-z0-9]+)"/.exec(
  shipmentsPage.text.slice(orderRowIdx, orderRowIdx + 800)
);
check('found a link to the shipment detail page', !!shipmentLinkMatch);

let shipmentDetail = shipmentLinkMatch ? await a.get(shipmentLinkMatch[1].replace(BASE, '')) : { text: '', status: 0 };
check('shipment detail page loads', shipmentDetail.status === 200);
check('the shipment detail shows the order status', /Order:/.test(shipmentDetail.text));
check('a "Refund this order" card is offered while the order is refundable', /Refund this order/.test(shipmentDetail.text));
check('a refund reason field is present', /name="reason"/.test(shipmentDetail.text));

const refundFormMatch = /action="([^"]*\/refund)"/.exec(shipmentDetail.text);
check('found the refund form action', !!refundFormMatch);

console.log('\n── Physical order refund: actually refunding it moves money back and cancels the shipment');
const refundRes = refundFormMatch
  ? await a.postForm(refundFormMatch[1].replace(BASE, ''), { reason: 'Customer never received the item' }, { fromHtml: shipmentDetail.text })
  : { text: '', status: 0 };
check('refund request succeeds', refundRes.status === 200);
check('a success message confirms the refund', /refunded from escrow/i.test(refundRes.text));

const walletAfterRefund = withDb((db) => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(userId)).balance;
check('the wallet balance was actually restored', parseFloat(walletAfterRefund) > parseFloat(walletAfterPurchase),
  `before=${walletAfterPurchase} after=${walletAfterRefund}`);

shipmentDetail = shipmentLinkMatch ? await a.get(shipmentLinkMatch[1].replace(BASE, '')) : { text: '' };
check('the order now shows status REFUNDED', /Order:\s*REFUNDED/.test(shipmentDetail.text) || shipmentDetail.text.includes('REFUNDED'));
check('the shipment itself is now marked CANCELLED', /badge-default">CANCELLED</.test(shipmentDetail.text));
check('the refund card now says it has already been refunded, not offering a second one',
  /already been refunded/i.test(shipmentDetail.text));

console.log('\n── Physical order refund: refunding twice is refused, not double-credited');
const doubleRefund = refundFormMatch
  ? await a.postForm(refundFormMatch[1].replace(BASE, ''), { reason: 'trying again' }, { fromHtml: shipmentDetail.text })
  : { text: '', status: 0 };
const walletAfterDouble = withDb((db) => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(userId)).balance;
check('a second refund attempt does not move money again',
  parseFloat(walletAfterDouble) === parseFloat(walletAfterRefund),
  `after first=${walletAfterRefund} after second attempt=${walletAfterDouble}`);

console.log('\n── Physical order refund: every step is audited');
const auditPage = await a.get('/admin/audit-logs');
check('the audit log records the marketplace order refund', /marketplace\.order\.refund/.test(auditPage.text));
check('the audit log records the shipment-level refund entry', /shop\.shipment\.refunded/.test(auditPage.text));

/* ------------------------------------------------------------------------
 * A part refund is compensation for goods the buyer keeps — so while the
 * parcel is still with the carrier it must be refused (an in-transit part
 * refund would strand the order: a part-refunded order can never be
 * recorded delivered, and its escrow remainder would ride the abandonment
 * sweep back to the buyer who still receives the parcel). After delivery
 * the same part refund stands.
 * ---------------------------------------------------------------------- */
console.log('\n── Part refund: refused while the parcel is in transit, allowed after delivery');
productPage = await c.get(`/shop/product/${listingId}`);
addRes = await c.postForm('/cart/add', { listing: listingId, quantity: '1', redirect_to: 'cart' }, { fromHtml: productPage.text });
checkoutPage = await c.get('/checkout');
place = await c.postForm('/checkout/place', {
  full_name: 'Refund Tester', phone: '08000000002', line1: '2 Refund Street', city: 'Abuja',
  state: 'FCT', postal_code: '900002', country_code: 'NG',
  shipping_method: `E2EREFUNDSHIP${stamp}`,
}, { fromHtml: checkoutPage.text });
check('second physical order placed (still in transit)', place.status === 200);

const secondOrder = withDb((db) => db.prepare(
  'SELECT id, public_id, status FROM marketplace_orders WHERE buyer_id = ? ORDER BY id DESC LIMIT 1').get(userId));
check('the second order row is PAID with a shipment',
  secondOrder && secondOrder.status === 'PAID');
const secondShipment = withDb((db) => db.prepare(
  'SELECT public_id, status FROM shop_order_shipments WHERE marketplace_order_id = ?').get(secondOrder.id));
check('the second shipment exists and is PENDING',
  secondShipment && secondShipment.status === 'PENDING');

const walletBeforePart = withDb((db) => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(userId)).balance;
let orderPage = await a.get(`/admin/marketplace/orders/${secondOrder.public_id}`);
check('the admin order page offers the part-refund resolution',
  orderPage.status === 200 && /Refund part of it/.test(orderPage.text));

const partRefused = await a.postForm(`/admin/marketplace/orders/${secondOrder.public_id}/resolve`, {
  resolution: 'PARTIAL_REFUND', amount: '1000', restock: '0',
  reason: 'agreed discount while the parcel is in transit',
}, { fromHtml: orderPage.text });
check('the in-transit part refund is refused, with the reason on screen',
  /still with the carrier/i.test(partRefused.text), partRefused.url);
check('the refusal points at the two honest options',
  /refund it in full/i.test(partRefused.text) && /wait for delivery/i.test(partRefused.text));
check('no money moved for the refused part refund',
  withDb((db) => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(userId)).balance === walletBeforePart);
check('the order is still PAID, not part-refunded',
  withDb((db) => db.prepare('SELECT status FROM marketplace_orders WHERE id = ?').get(secondOrder.id)).status === 'PAID');

let shipmentPage = await a.get(`/admin/shop/shipments/${secondShipment.public_id}`);
const shipped = await a.postForm(`/admin/shop/shipments/${secondShipment.public_id}/status`, {
  status: 'SHIPPED', carrier: 'DHL', tracking_number: `E2E-PART-${stamp}`,
}, { fromHtml: shipmentPage.text });
check('the shipment can be marked SHIPPED', shipped.status === 200);
shipmentPage = await a.get(`/admin/shop/shipments/${secondShipment.public_id}`);
const deliveredRes = await a.postForm(`/admin/shop/shipments/${secondShipment.public_id}/status`, {
  status: 'DELIVERED', carrier: 'DHL', tracking_number: `E2E-PART-${stamp}`,
}, { fromHtml: shipmentPage.text });
check('the shipment can be marked DELIVERED', deliveredRes.status === 200);
check('delivery moved the escrow order to DELIVERED with a release deadline',
  (() => { const r = withDb((db) => db.prepare('SELECT status, release_due_at FROM marketplace_orders WHERE id = ?').get(secondOrder.id));
           return r.status === 'DELIVERED' && !!r.release_due_at; })());

orderPage = await a.get(`/admin/marketplace/orders/${secondOrder.public_id}`);
const partAllowed = await a.postForm(`/admin/marketplace/orders/${secondOrder.public_id}/resolve`, {
  resolution: 'PARTIAL_REFUND', amount: '1000', restock: '0',
  reason: 'agreed discount — the parcel arrived scratched',
}, { fromHtml: orderPage.text });
check('after delivery the same part refund succeeds',
  /Refunded 1000\.00000000 to the buyer/i.test(partAllowed.text), partAllowed.url);
check('the buyer was actually credited',
  parseFloat(withDb((db) => db.prepare('SELECT balance FROM wallets WHERE user_id = ?').get(userId)).balance)
    === parseFloat(walletBeforePart) + 1000);
const afterPart = withDb((db) => db.prepare(
  'SELECT status, refunded_amount FROM marketplace_orders WHERE id = ?').get(secondOrder.id));
check('the order is PARTIALLY_REFUNDED for exactly the part',
  afterPart.status === 'PARTIALLY_REFUNDED' && afterPart.refunded_amount === '1000.00000000');

const passed = results.filter(r => r.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
if (passed !== results.length) {
  console.log('\nFailures:');
  for (const r of results) if (!r.ok) console.log(`  ${r.label} — ${r.detail}`);
  process.exit(1);
}
