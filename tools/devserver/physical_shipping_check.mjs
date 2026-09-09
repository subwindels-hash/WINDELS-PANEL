/**
 * Physical marketplace shipping end-to-end check.
 *
 * DEV TOOLING ONLY. Creates a disposable physical listing, buys it through
 * the customer checkout, then moves its shipment through SHIPPED and
 * DELIVERED from the admin screen. It proves the browser-facing path, the
 * server-side carrier quote and the escrow/shipment state boundary together.
 *
 *   node tools/devserver/physical_shipping_check.mjs \
 *     --base http://127.0.0.1:8080 --db storage/devdb/marvy.sqlite \
 *     --email demo@example.com --password '…' --admin-password '…'
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
const EMAIL = arg('--email', 'demo@marvy.local');
const PASSWORD = arg('--password', process.env.DEMO_PASSWORD || '');
const ADMIN_PASSWORD = arg('--admin-password', process.env.ADMIN_PASSWORD || PASSWORD);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const stamp = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
const publicId = (prefix) => (prefix + stamp).slice(0, 26).padEnd(26, '0');

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

let fixture = null;
try {
  fixture = withDb((db) => {
    const user = db.prepare('SELECT id, username, email FROM users WHERE email = ? LIMIT 1').get(EMAIL);
    if (!user) throw new Error(`customer ${EMAIL} was not found in the dev database`);

    const methodPublic = publicId('SHPCHK');
    const addressPublic = publicId('SADCHK');
    const listingPublic = publicId('MPSCHK');
    const productPublic = publicId('PPSCHK');
    const now = "datetime('now')";

    db.prepare(`INSERT INTO shipping_methods
      (public_id, name, carrier, price, currency, estimated_days_min,
       estimated_days_max, is_active, sorting, created_at, updated_at)
              VALUES (?, 'E2E Standard', 'E2E Carrier', '50.00000000', 'NGN', 2, 5,
              1, 999, ${now}, ${now})`).run(methodPublic);
    const method = db.prepare('SELECT * FROM shipping_methods WHERE public_id = ?').get(methodPublic);

    db.prepare(`INSERT INTO shipping_addresses
      (public_id, user_id, full_name, phone, line1, city, state, postal_code,
       country_code, is_default, created_at, updated_at)
      VALUES (?, ?, 'E2E Buyer', '08000000000', '1 E2E Street', 'Abuja', 'FCT',
              '900001', 'NG', 0, ${now}, ${now})`).run(addressPublic, user.id);
    const address = db.prepare('SELECT * FROM shipping_addresses WHERE public_id = ?').get(addressPublic);

    db.prepare(`INSERT INTO marketplace_listings
      (public_id, category, title, description, product_type, price, currency,
       promo_price, is_featured, image, stock, delivery_days, status,
       created_at, updated_at)
      VALUES (?, 'DIGITAL_GOODS', ?, 'A disposable physical e2e product.',
              'PHYSICAL', '100.00000000', 'NGN', NULL, 0, NULL, 2, 3,
              'ACTIVE', ${now}, ${now})`).run(listingPublic, `E2E mug ${stamp}`);
    const listing = db.prepare('SELECT * FROM marketplace_listings WHERE public_id = ?').get(listingPublic);

    db.prepare(`INSERT INTO physical_products
      (public_id, listing_id, sku, weight_grams, length_cm, width_cm, height_cm,
       requires_shipping, created_at, updated_at)
      VALUES (?, ?, ?, 350, '12.00', '10.00', '10.00', 1, ${now}, ${now})`)
      .run(productPublic, listing.id, `E2E-${stamp}`.slice(0, 64));

    return { user, method, address, listing, listingPublic, methodPublic, addressPublic };
  });

  if (!PASSWORD) throw new Error('pass --password (or DEMO_PASSWORD) for the customer login');

  const customer = new Client(BASE);
  let page = await customer.get('/login');
  check('customer login page loads', page.status === 200);
  page = await customer.postForm('/login', { identifier: EMAIL, password: PASSWORD });
  check('customer signs in', /dashboard/i.test(page.url), page.url);

  page = await customer.get(`/dashboard/marketplace/${fixture.listingPublic}`);
  check('physical listing page loads', page.status === 200 && /Physical/i.test(page.text));
  page = await customer.postForm(`/dashboard/marketplace/${fixture.listingPublic}/buy`,
    { quantity: '1', form_token: `physical-${stamp}` }, { fromHtml: page.text });
  check('physical buy redirects to checkout', page.status === 200 && /checkout/i.test(page.url + page.text));
  check('checkout shows an address and a method',
    page.text.includes('shipping_address_id') && page.text.includes('shipping_method'));

  const placed = await customer.postForm('/checkout/place', {
    shipping_method: fixture.methodPublic,
    shipping_address_id: fixture.addressPublic,
    idempotency_key: `physical-${stamp}`,
  }, { fromHtml: page.text, follow: false });
  const placedLocation = placed.headers.get('location') || '';
  if (/\/checkout$/.test(placedLocation)) {
    const failedCheckout = await customer.get('/checkout');
    const message = (failedCheckout.text.match(/class="[^"]*alert[^"]*"[^>]*>([\s\S]*?)<\//i) || [])[1]
      ?.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    check('checkout places the order', false, `${placed.status} ${placedLocation}${message ? ` — ${message}` : ''}`);
  } else {
    check('checkout places the order', placed.status >= 300 && placed.status < 400,
      `${placed.status} ${placedLocation}`);
  }

  const order = withDb((db) => db.prepare(
    'SELECT * FROM marketplace_orders WHERE listing_id = ? ORDER BY id DESC LIMIT 1'
  ).get(fixture.listing.id));
  const shipment = order ? withDb((db) => db.prepare(
    'SELECT * FROM shop_order_shipments WHERE marketplace_order_id = ?'
  ).get(order.id)) : null;
  check('server adds the carrier fee exactly once', !!order && Number(order.gross_amount) === 150 && Number(order.shipping_cost) === 50,
    order ? `${order.gross_amount} / ${order.shipping_cost}` : 'no order');
  check('a pending shipment is created with the quoted method', !!shipment && shipment.status === 'PENDING' && shipment.shipping_method_id === fixture.method.id,
    shipment ? shipment.status : 'no shipment');

  const admin = new Client(BASE);
  page = await admin.get('/admin/login');
  page = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
  check('admin signs in', /\/admin(?!\/login)/.test(page.url), page.url);

  const shipmentUrl = `/admin/shop/shipments/${shipment.public_id}`;
  page = await admin.get(shipmentUrl);
  check('admin shipment screen renders tracking controls', page.status === 200 && page.text.includes('tracking_number'));
  page = await admin.postForm(`${shipmentUrl}/status`, {
    status: 'SHIPPED', carrier: 'E2E Carrier', tracking_number: `TRACK-${stamp}`,
    tracking_url: 'https://carrier.example/track/e2e',
  }, { fromHtml: page.text });
  check('admin marks the shipment shipped', page.status === 200 && /SHIPPED/i.test(page.text));

  page = await admin.get(shipmentUrl);
  page = await admin.postForm(`${shipmentUrl}/status`, {
    status: 'DELIVERED', carrier: 'E2E Carrier', tracking_number: `TRACK-${stamp}`,
  }, { fromHtml: page.text });
  const delivered = withDb((db) => ({
    shipment: db.prepare('SELECT * FROM shop_order_shipments WHERE id = ?').get(shipment.id),
    order: db.prepare('SELECT * FROM marketplace_orders WHERE id = ?').get(order.id),
  }));
  check('delivery synchronizes the marketplace escrow state',
    delivered.shipment.status === 'DELIVERED' && delivered.order.status === 'DELIVERED',
    `${delivered.shipment.status} / ${delivered.order.status}`);

  // Return the disposable fixture's money through the same browser action an
  // operator uses. The check must never leave a charged wallet behind merely
  // because the test reached its final assertion.
  page = await admin.get(shipmentUrl);
  const refund = await admin.postForm(`${shipmentUrl}/refund`,
    { reason: 'Dispose of physical shipping e2e fixture' }, { fromHtml: page.text });
  check('disposable fixture is refunded before cleanup',
    refund.status === 200 && /refunded from escrow/i.test(refund.text));
} catch (error) {
  check('physical shipping journey completes', false, error.message);
} finally {
  if (fixture) {
    try {
      withDb((db) => {
        const order = db.prepare('SELECT id, status, service_transaction_id FROM marketplace_orders WHERE listing_id = ? ORDER BY id DESC LIMIT 1').get(fixture.listing.id);
        if (order && !['REFUNDED', 'CANCELLED'].includes(String(order.status))) {
          throw new Error(`cleanup refused to delete unresolved order ${order.id} (${order.status})`);
        }
        if (order) {
          db.prepare('DELETE FROM shop_order_shipments WHERE marketplace_order_id = ?').run(order.id);
          db.prepare('DELETE FROM marketplace_order_events WHERE order_id = ?').run(order.id);
          db.prepare('DELETE FROM marketplace_orders WHERE id = ?').run(order.id);
          db.prepare('DELETE FROM service_transaction_status_history WHERE service_transaction_id = ?').run(order.service_transaction_id);
          db.prepare('DELETE FROM service_transactions WHERE id = ?').run(order.service_transaction_id);
        }
        db.prepare('DELETE FROM physical_products WHERE listing_id = ?').run(fixture.listing.id);
        db.prepare('DELETE FROM marketplace_listings WHERE id = ?').run(fixture.listing.id);
        db.prepare('DELETE FROM shipping_addresses WHERE id = ?').run(fixture.address.id);
        db.prepare('DELETE FROM shipping_methods WHERE id = ?').run(fixture.method.id);
      });
    } catch (error) {
      console.error(`   cleanup failed: ${error.message}`);
      results.push({ label: 'fixture cleanup', ok: false, detail: error.message });
    }
  }
}

const failed = results.filter((result) => !result.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) process.exit(1);
