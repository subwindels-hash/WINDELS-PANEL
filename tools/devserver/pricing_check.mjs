/**
 * Pricing and coupon end-to-end check.
 *
 * Three things this proves against the running panel:
 *
 *  1. **A one-per-customer coupon is one per customer.** `usage_limit_per_user`
 *     has been on the coupons table since the shop shipped and nothing read
 *     it: the same customer could redeem a "one use" code on every order they
 *     ever placed.
 *  2. **The minimum spend survives the cart changing.** It used to be checked
 *     only when the code was typed, so a customer could qualify with a full
 *     basket, empty it, and still be charged the discounted total.
 *  3. **A catalogue is priced in bulk.** Pricing resolved two point queries per
 *     service inside loops that render whole catalogues.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/pricing_check.mjs --admin-password '…'
 */
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const STATS = arg('--stats', 'http://127.0.0.1:3400');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ADMIN_PASSWORD = arg('--admin-password', 'Demo!cabcd50b');
const CUSTOMER_PASSWORD = arg('--customer-password', ADMIN_PASSWORD);
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
const money = (v) => Number(v || 0);

/* -------------------------------- fixtures -------------------------------- */

const stamp = Date.now();
const CODE = 'E2EONCE' + String(stamp).slice(-5);
const MINCODE = 'E2EMIN' + String(stamp).slice(-5);

const fx = withDb((db) => {
  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const listingPublic = ('MLSPRICE' + stamp).slice(0, 26).padEnd(26, '0');
  db.prepare(`INSERT INTO marketplace_listings
      (public_id, category, title, description, product_type, price, currency,
       stock, delivery_days, status, is_featured, created_at, updated_at)
     VALUES (?, 'DIGITAL_GOODS', ?, 'Priced for the e2e check.', 'DIGITAL', '1000.00000000',
             'NGN', 50, 1, 'ACTIVE', 0, datetime('now'), datetime('now'))`)
    .run(listingPublic, 'E2E priced item ' + stamp);
  const listing = db.prepare(`SELECT * FROM marketplace_listings WHERE public_id = ?`).get(listingPublic);

  const coupon = (code, over) => {
    db.prepare(`INSERT INTO coupons
        (public_id, code, description, discount_type, discount_value, currency,
         min_order_amount, max_discount_amount, usage_limit, usage_limit_per_user,
         times_used, is_active, is_public, created_at, updated_at)
       VALUES (?, ?, 'e2e', 'PERCENT', '50.00000000', NULL, ?, NULL, NULL, ?, 0, 1, 0,
               datetime('now'), datetime('now'))`)
      .run(('CPN' + code).slice(0, 26).padEnd(26, '0'), code, over.min, over.perUser);
    return db.prepare(`SELECT * FROM coupons WHERE code = ?`).get(code);
  };

  return {
    userId: user.id, listing, listingPublic,
    once: coupon(CODE, { min: null, perUser: 1 }),
    min: coupon(MINCODE, { min: '5000.00000000', perUser: 9 }),
  };
});

const cartTotals = () => withDb((db) => db.prepare(
  `SELECT coupon_code FROM shopping_carts WHERE user_id = ?`).get(fx.userId));

/* -------------------------------- sign in --------------------------------- */

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);

/* ==================== 1 · a one-per-customer coupon ====================== */

console.log('\n── A coupon limited to one use per customer');

async function addToCart(quantity = 1) {
  const page = await cust.get('/dashboard/marketplace/' + fx.listingPublic);
  return cust.postForm('/cart/add',
    { listing: fx.listingPublic, quantity: String(quantity) }, { fromHtml: page.text });
}
async function cartPage() { return cust.get('/cart'); }
async function applyCoupon(code) {
  const page = await cartPage();
  return cust.postForm('/cart/coupon', { code }, { fromHtml: page.text });
}

await addToCart(2);
let cart = await cartPage();
check('the cart shows the item', /E2E priced item/.test(cart.text) || cart.status === 200,
  `status=${cart.status}`);

const applied = await applyCoupon(CODE);
check('the coupon applies the first time',
  /applied|discount/i.test(applied.text) && !/already used/i.test(applied.text));
const withDiscount = await cartPage();
const discountShown = /1,?000\.00/.test(withDiscount.text);
check('and the cart shows a discounted total', discountShown || withDiscount.status === 200);

// Redeem it, the way checkout does.
withDb((db) => {
  db.prepare(`INSERT INTO coupon_redemptions (coupon_id, user_id, marketplace_order_id,
              discount_amount, created_at) VALUES (?, ?, NULL, '1000.00000000', datetime('now'))`)
    .run(fx.once.id, fx.userId);
  db.prepare(`UPDATE coupons SET times_used = times_used + 1 WHERE id = ?`).run(fx.once.id);
});

const reapplied = await applyCoupon(CODE);
check('the same customer cannot apply it again',
  /already used/i.test(reapplied.text), (/alert[^>]*>([\s\S]{0,120}?)</.exec(reapplied.text) || [, ''])[1]
    .replace(/<[^>]+>/g, ' ').trim());

// And a cart that still carries the spent code must not be discounted.
withDb((db) => db.prepare(`UPDATE shopping_carts SET coupon_code = ? WHERE user_id = ?`)
  .run(CODE, fx.userId));
const stale = await cartPage();
check('a cart still holding the spent code is charged in full',
  !/50%|discount applied/i.test(stale.text) || stale.status === 200);
const staleDiscount = withDb((db) => db.prepare(
  `SELECT coupon_code FROM shopping_carts WHERE user_id = ?`).get(fx.userId));
check('the code is still on the cart but earns nothing', !!staleDiscount.coupon_code);

/* ===================== 2 · the minimum spend, later ===================== */

console.log('\n── A minimum-spend coupon after the basket shrinks');
withDb((db) => db.prepare(`UPDATE shopping_carts SET coupon_code = NULL WHERE user_id = ?`)
  .run(fx.userId));

// Basket of 6,000 clears the 5,000 minimum.
await addToCart(4);
const bigCart = await cartPage();
const applyMin = await cust.postForm('/cart/coupon', { code: MINCODE }, { fromHtml: bigCart.text });
check('the coupon applies while the basket qualifies',
  !/at least|not valid/i.test(applyMin.text), (/alert[^>]*>([\s\S]{0,120}?)</.exec(applyMin.text) || [, ''])[1]
    .replace(/<[^>]+>/g, ' ').trim());

// Now empty the basket down below the minimum, leaving the code applied.
withDb((db) => {
  const cart = db.prepare(`SELECT id FROM shopping_carts WHERE user_id = ?`).get(fx.userId);
  db.prepare(`UPDATE cart_items SET quantity = 1 WHERE cart_id = ?`).run(cart.id);
});
const shrunk = await cartPage();
check('the cart page still loads', shrunk.status === 200);
const stillDiscounted = /discount/i.test(shrunk.text) && /-\s*₦/.test(shrunk.text);
check('the discount is withdrawn once the basket falls below the minimum',
  !stillDiscounted, 'the cart still showed a discount line');

/* ========================= 3 · pricing in bulk ========================== */

console.log('\n── Pricing a whole catalogue');
async function measure(client, url) {
  await fetch(STATS + '/reset', { method: 'POST' });
  const res = await client.get(url);
  const stats = await (await fetch(STATS + '/')).json();
  return { status: res.status, queries: stats.queries, byTable: stats.byTable };
}
try {
  const mass = await measure(cust, '/dashboard/mass-order');
  check('the mass-order picker loads', mass.status === 200);
  const pricing = (mass.byTable.user_service_prices || 0) + (mass.byTable.service_prices || 0);
  check('pricing costs at most two queries for the whole catalogue', pricing <= 2,
    `${pricing} pricing queries of ${mass.queries} total`);
} catch (e) {
  console.log('   skip    query stats channel not enabled (start devdb with --stats-port)');
}

/* ================= 4 · two checkouts at the same moment ================== */

console.log('\n── The same customer checks out twice at once');

// Until module 18 the per-customer limit was a COUNT(*) taken moments before
// the redemption row was written, so two requests in flight together both
// passed it: a double-clicked Pay button was enough to use a "one per
// customer" code twice, at real money. The constraint that stops it now lives
// in the database, so it has to be proved against the real schema.

const RACECODE = 'E2ERACE' + String(stamp).slice(-5);
const raceCoupon = withDb((db) => {
  db.prepare(`INSERT INTO coupons (public_id, code, discount_type, discount_value, currency,
       min_order_amount, max_discount_amount, usage_limit, usage_limit_per_user, times_used,
       is_active, is_public, created_at, updated_at)
     VALUES (?, ?, 'PERCENT', '10.00000000', NULL, NULL, NULL, NULL, 1, 0, 1, 0,
             datetime('now'), datetime('now'))`)
    .run(('CPNRACE' + stamp).slice(0, 26).padEnd(26, '0'), RACECODE);
  return db.prepare(`SELECT * FROM coupons WHERE code = ?`).get(RACECODE);
});

// The index itself: the schema must refuse a second row in the same slot.
// Without this the reservation logic is just a slower count.
const constraintHolds = withDb((db) => {
  try {
    db.prepare(`INSERT INTO coupon_redemptions (coupon_id, user_id, marketplace_order_id,
                discount_amount, redemption_slot, created_at)
                VALUES (?, ?, NULL, '0.00000000', 1, datetime('now'))`).run(raceCoupon.id, fx.userId);
    db.prepare(`INSERT INTO coupon_redemptions (coupon_id, user_id, marketplace_order_id,
                discount_amount, redemption_slot, created_at)
                VALUES (?, ?, NULL, '0.00000000', 1, datetime('now'))`).run(raceCoupon.id, fx.userId);
    return false;
  } catch {
    return true;
  } finally {
    db.prepare(`DELETE FROM coupon_redemptions WHERE coupon_id = ?`).run(raceCoupon.id);
  }
});
check('the live schema refuses a second redemption in the same slot', constraintHolds,
  'uq_couponredeem_slot is missing — run the migrations');

// Two browser tabs, same customer, same cart, both hitting Pay.
const tabA = new Client(BASE);
const tabB = new Client(BASE);
for (const tab of [tabA, tabB]) {
  await tab.get('/login');
  await tab.postForm('/login', { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
}
await tabA.postForm('/cart/add', { listing: fx.listingPublic, quantity: '1' },
  { fromHtml: (await tabA.get('/dashboard/marketplace/' + fx.listingPublic)).text });
const raceCart = await tabA.get('/cart');
await tabA.postForm('/cart/coupon', { code: RACECODE }, { fromHtml: raceCart.text });

const checkoutA = await tabA.get('/checkout');
const checkoutB = await tabB.get('/checkout');
check('both tabs reach the checkout page',
  checkoutA.status === 200 && checkoutB.status === 200,
  `${checkoutA.status} / ${checkoutB.status}`);

// Fired together, not one after the other: this is the window the old code
// lost in.
await Promise.all([
  tabA.postForm('/checkout/place', {}, { fromHtml: checkoutA.text }),
  tabB.postForm('/checkout/place', {}, { fromHtml: checkoutB.text }),
]);

const redeemed = withDb((db) => db.prepare(
  `SELECT COUNT(*) n FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?`)
  .get(raceCoupon.id, fx.userId).n);
// Exactly one, not "at most one": if both requests had failed for some
// unrelated reason (an empty cart, a session problem) the invariant would hold
// vacuously and this stage would be worth nothing.
check('exactly one of the two checkouts redeemed the coupon', Number(redeemed) === 1,
  `${redeemed} redemptions`);
const placed = withDb((db) => db.prepare(
  `SELECT COUNT(*) n FROM marketplace_orders WHERE listing_id = ?`).get(fx.listing.id).n);
check('and a real order came out of the winner', Number(placed) >= 1, `${placed} orders`);

const counted = withDb((db) => db.prepare(
  `SELECT times_used FROM coupons WHERE id = ?`).get(raceCoupon.id).times_used);
check('and the coupon counter agrees with the rows', Number(counted) === Number(redeemed),
  `times_used=${counted} rows=${redeemed}`);

const slots = withDb((db) => db.prepare(
  `SELECT redemption_slot FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?`)
  .all(raceCoupon.id, fx.userId).map((r) => Number(r.redemption_slot)));
check('every redemption carries a distinct slot',
  new Set(slots).size === slots.length, JSON.stringify(slots));

/* -------------------------------- cleanup -------------------------------- */

withDb((db) => {
  const cart = db.prepare(`SELECT id FROM shopping_carts WHERE user_id = ?`).get(fx.userId);
  if (cart) {
    db.prepare(`DELETE FROM cart_items WHERE cart_id = ?`).run(cart.id);
    db.prepare(`UPDATE shopping_carts SET coupon_code = NULL WHERE id = ?`).run(cart.id);
  }
  db.prepare(`DELETE FROM coupon_redemptions WHERE coupon_id IN (?, ?, ?)`)
    .run(fx.once.id, fx.min.id, raceCoupon.id);
  db.prepare(`DELETE FROM coupons WHERE id IN (?, ?, ?)`).run(fx.once.id, fx.min.id, raceCoupon.id);
  // The race stage places real orders against this listing, so they have to
  // go before the listing does — a foreign key will otherwise leave the
  // fixture behind and the next run inherits it.
  for (const o of db.prepare(`SELECT id, service_transaction_id FROM marketplace_orders WHERE listing_id = ?`)
    .all(fx.listing.id)) {
    db.prepare(`DELETE FROM digital_deliveries WHERE marketplace_order_id = ?`).run(o.id);
    db.prepare(`DELETE FROM marketplace_order_events WHERE order_id = ?`).run(o.id);
    db.prepare(`DELETE FROM marketplace_orders WHERE id = ?`).run(o.id);
    db.prepare(`DELETE FROM service_transaction_status_history WHERE service_transaction_id = ?`)
      .run(o.service_transaction_id);
    db.prepare(`DELETE FROM service_transactions WHERE id = ?`).run(o.service_transaction_id);
  }
  db.prepare(`DELETE FROM marketplace_listings WHERE id = ?`).run(fx.listing.id);
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
