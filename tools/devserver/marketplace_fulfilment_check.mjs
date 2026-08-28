/**
 * Marketplace fulfilment end-to-end check.
 *
 * Buys a real digital product over HTTP with a real wallet charge, then drives
 * the two cases this module exists for:
 *
 *  1. **Escrow is not "stuck".** A marketplace purchase sits in PROCESSING for
 *     the whole inspection window — that is what escrow is. The stuck-purchase
 *     sweep introduced for abandoned vendor purchases treated any in-flight
 *     purchase older than 24 hours as abandoned, so it would have refunded
 *     buyers of goods already delivered, left the order at DELIVERED and the
 *     stock decremented, and then broken the release worker.
 *
 *  2. **A refund takes the goods back.** Refunding a digital order used to
 *     leave the download live in "My Downloads": the buyer kept the file and
 *     the money. (The admin-initiated refund path is covered by
 *     MarketplaceFulfilmentTest against the same service method; here it is
 *     proved through the sweep, which is the path no human triggers.)
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/marketplace_fulfilment_check.mjs --admin-password '…'
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
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
function cron(job) {
  try {
    return execFileSync('node', ['tools/devserver/php_run.mjs', 'index.php', 'cron', job],
      { cwd: ROOT, encoding: 'utf8', timeout: 180000 });
  } catch (e) { return (e.stdout || '') + (e.stderr || ''); }
}
const money = (v) => Number(v || 0);

/* -------------------------------- fixtures -------------------------------- */

const stamp = Date.now();
const fx = withDb((db) => {
  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const listingPublic = ('MLSE2E' + stamp).slice(0, 26).padEnd(26, '0');
  db.prepare(`INSERT INTO marketplace_listings
      (public_id, category, title, description, product_type, price, currency,
       stock, delivery_days, status, is_featured, created_at, updated_at)
     VALUES (?, 'DIGITAL_GOODS', ?, 'An e2e product.', 'DIGITAL', '1500.00000000', 'NGN',
             3, 1, 'ACTIVE', 0, datetime('now'), datetime('now'))`)
    .run(listingPublic, 'E2E ebook ' + stamp);
  const listing = db.prepare(`SELECT * FROM marketplace_listings WHERE public_id = ?`).get(listingPublic);

  // A file behind the listing, so buying it grants a real download.
  //
  // This fixture used to invent a storage key (`storage/digital/…`) that
  // pointed at nothing and did not even match the prefix
  // ShopDeliveryService confines lookups to, so every download in this check
  // failed as MISSING_FILE and no stage ever noticed — the script only looked
  // at database columns. A real PDF is written to the real private store so
  // the bytes can actually be fetched, and revocation can be proved against a
  // link that genuinely worked a moment earlier.
  const key = 'digital_products/e2e-' + stamp + '.pdf';
  db.prepare(`INSERT INTO digital_products
      (public_id, listing_id, storage_key, original_filename, mime_type, size_bytes,
       download_limit, link_ttl_hours, created_at, updated_at)
     VALUES (?, ?, ?, 'ebook.pdf', 'application/pdf', 4096, 5, 24, datetime('now'), datetime('now'))`)
    .run(('DGPE2E' + stamp).slice(0, 26).padEnd(26, '0'), listing.id, key);

  return { userId: user.id, listing, listingPublic };
});

// The bytes behind that key, in the private store the service reads from.
const digitalPath = path.resolve(ROOT, 'storage/digital_products/e2e-' + stamp + '.pdf');
fs.mkdirSync(path.dirname(digitalPath), { recursive: true });
fs.writeFileSync(digitalPath, Buffer.from('%PDF-1.4\n% e2e fixture\n%%EOF\n', 'utf8'));

const balance = () => withDb((db) =>
  money(db.prepare(`SELECT balance FROM wallets WHERE user_id = ?`).get(fx.userId).balance));
const orderRow = () => withDb((db) => db.prepare(
  `SELECT * FROM marketplace_orders WHERE listing_id = ? ORDER BY id DESC LIMIT 1`).get(fx.listing.id));
const stock = () => withDb((db) => Number(db.prepare(
  `SELECT stock FROM marketplace_listings WHERE id = ?`).get(fx.listing.id).stock));
const delivery = () => withDb((db) => db.prepare(
  `SELECT d.* FROM digital_deliveries d
     JOIN marketplace_orders o ON o.id = d.marketplace_order_id
    WHERE o.listing_id = ? ORDER BY d.id DESC LIMIT 1`).get(fx.listing.id));

/* -------------------------------- the buy --------------------------------- */

const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);

console.log('\n── Buying a digital product');
const before = balance();
const listingPage = await cust.get('/dashboard/marketplace/' + fx.listingPublic);
check('the listing page loads', listingPage.status === 200, `status=${listingPage.status}`);
// form_token is the double-click guard the real form sends; a purchase
// without one is deliberately not deduplicated.
const bought = await cust.postForm(`/dashboard/marketplace/${fx.listingPublic}/buy`,
  { quantity: '1', form_token: 'e2e-' + Date.now() + '-' + Math.random() },
  { fromHtml: listingPage.text });

const order = orderRow();
if (!order) {
  const alert = (/alert alert-(?:danger|warning)[^>]*>([\s\S]{0,200}?)</.exec(bought.text) || [, ''])[1]
    .replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  console.log(`   note    the purchase did not complete: ${alert || bought.status}`);
}
check('the order exists and is in escrow', order && ['PAID', 'DELIVERED'].includes(order.status),
  order ? order.status : 'no order');
check('the wallet was charged', before - balance() === 1500, `${before} -> ${balance()}`);
check('a unit came out of stock', stock() === 2, `stock=${stock()}`);
check('the download was granted', !!delivery() && Number(delivery().revoked) === 0,
  JSON.stringify(delivery()));

const downloads = await cust.get('/dashboard/downloads');
check('and it appears in My Downloads', downloads.text.includes('ebook.pdf'));

// Capture a live download link the way an attacker (or a customer's browser
// history, or a forwarded email) would: issue it while the purchase is good,
// keep the URL, and try it again after the money goes back. Module 11 left
// this as an open question — "a URL captured before revocation still resolves
// until the storage key is rotated" — and it has to be answered with a real
// request, not by reading the code.
const issued = await cust.postForm(`/dashboard/downloads/${delivery().public_id}/link`,
  {}, { fromHtml: downloads.text, follow: false });
const capturedLink = issued.headers.get('location') || '';
check('a signed download link is issued while the purchase is good',
  /\/downloads\/file\?token=/.test(capturedLink), `${issued.status} ${capturedLink}`);
const usedNow = await cust.raw(capturedLink);
check('and it serves the file', usedNow.status === 200 && usedNow.text.includes('%PDF'),
  `status=${usedNow.status} ${(/(unavailable|expired|revoked|limit|no longer available|invalid)[^<]*/i.exec(usedNow.text.replace(/<[^>]+>/g,' ')) || ['?'])[0]}`);

/* ==================== 1 · escrow survives the sweep ====================== */

console.log('\n── The stuck-purchase sweep must not raid escrow');
withDb((db) => db.prepare(
  `UPDATE service_transactions SET created_at = datetime('now', '-48 hours') WHERE id = ?`)
  .run(order.service_transaction_id));
const held = balance();

cron('service_recovery');

check('a purchase 48 hours into a 72-hour escrow is left alone',
  orderRow().status === order.status, `status=${orderRow().status}`);
check('and the buyer is not refunded for goods on their way',
  balance() === held, `${held} -> ${balance()}`);
check('the download stays live', Number(delivery().revoked) === 0);

console.log('\n── …but an escrow nobody ever released is eventually returned');
withDb((db) => db.prepare(
  `UPDATE service_transactions SET created_at = datetime('now', '-40 days') WHERE id = ?`)
  .run(order.service_transaction_id));

cron('service_recovery');

const settled = orderRow();
check('the order is refunded rather than left in escrow for ever',
  settled.status === 'REFUNDED', `status=${settled.status}`);
check('the buyer gets their money back',
  balance() - held === 1500, `${held} -> ${balance()}`);
check('the stock goes back on the shelf', stock() === 3, `stock=${stock()}`);
check('and the download is revoked with the money',
  Number(delivery().revoked) === 1, JSON.stringify(delivery()));

const afterRevoke = await cust.get('/dashboard/downloads');
check('My Downloads shows it as revoked rather than offering the file',
  /revoked/i.test(afterRevoke.text) || !/Download/i.test(afterRevoke.text));

// The captured link, replayed after the refund. This is the whole question:
// a refunded buyer who kept the URL must not still be able to fetch the goods.
const replayed = await cust.raw(capturedLink);
check('a link captured BEFORE the refund no longer serves the file',
  replayed.status !== 200 && !replayed.text.includes('%PDF'),
  `status=${replayed.status}`);
check('and the buyer is told why, not given a blank page',
  /revoked/i.test(replayed.text), replayed.text.slice(0, 120));
const reissue = await cust.postForm(`/dashboard/downloads/${delivery().public_id}/link`,
  {}, { fromHtml: afterRevoke.text, follow: false });
check('nor can a fresh link be minted for it',
  !/\/downloads\/file\?token=/.test(reissue.headers.get('location') || ''),
  reissue.headers.get('location') || String(reissue.status));

/* -------------------------------- cleanup -------------------------------- */

withDb((db) => {
  const orders = db.prepare(`SELECT id, service_transaction_id FROM marketplace_orders WHERE listing_id = ?`)
    .all(fx.listing.id);
  for (const o of orders) {
    db.prepare(`DELETE FROM digital_deliveries WHERE marketplace_order_id = ?`).run(o.id);
    db.prepare(`DELETE FROM marketplace_order_events WHERE order_id = ?`).run(o.id);
    db.prepare(`DELETE FROM marketplace_orders WHERE id = ?`).run(o.id);
    db.prepare(`DELETE FROM service_transaction_status_history WHERE service_transaction_id = ?`)
      .run(o.service_transaction_id);
    db.prepare(`DELETE FROM service_transactions WHERE id = ?`).run(o.service_transaction_id);
  }
  db.prepare(`DELETE FROM digital_products WHERE listing_id = ?`).run(fx.listing.id);
  db.prepare(`DELETE FROM marketplace_listings WHERE id = ?`).run(fx.listing.id);
});
// The fixture PDF goes with the fixture rows: a dev store that fills up with
// abandoned e2e files is how a checker starts passing for the wrong reason.
try { fs.unlinkSync(digitalPath); } catch { /* already gone */ }

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
