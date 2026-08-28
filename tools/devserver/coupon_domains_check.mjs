/**
 * Coupons beyond the shop — end-to-end verification (module 36).
 *
 * DEV TOOLING ONLY. A coupon used to be redeemable only against a marketplace
 * checkout; this proves the code now works on every purchase surface that has
 * data in the dev database, against the real running app:
 *
 *   - an SMM order from /dashboard/new-order with the coupon field;
 *   - a VTU airtime purchase from /dashboard/vtu;
 *   - the per-user limit travelling across domains (use it on SMM, be refused
 *     on VTU with the same code);
 *   - a below-minimum and an unknown code refused before anything charges;
 *   - the redemption bookkeeping: domain + reference + discount on every row,
 *     times_used moving with it, and the wallet charged the discounted total.
 *
 *   node tools/devserver/coupon_domains_check.mjs
 *   DEMO_PASSWORD=... node tools/devserver/coupon_domains_check.mjs
 */
import { Client } from './client.mjs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
// verify_all.sh passes --admin-password; standalone runs may use DEMO_PASSWORD.
const argvPw = (process.argv.indexOf('--admin-password') >= 0)
  ? process.argv[process.argv.indexOf('--admin-password') + 1] : null;
const ADMIN_PASSWORD = argvPw || process.env.DEMO_PASSWORD || 'Demo!c7e2331b';

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}
function withDb(fn) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync('/home/user/WINDELS-PANEL/storage/devdb/marvy.sqlite');
  try { return fn(db); } finally { db.close(); }
}
const walletOf = (id) => withDb((db) => db.prepare(
  'SELECT balance FROM wallets WHERE user_id = ?').get(id)).balance;

const stamp = Date.now().toString().slice(-8);

/* ---------------------- admin: two coupons, one wallet ------------------- */

console.log('── Admin creates the coupons and funds the customer');
const admin = new Client(BASE);
await admin.get('/admin/login');
await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });

const couponsPage = await admin.get('/admin/shop/coupons');
check('the coupon screen still manages codes as before', couponsPage.status === 200);

async function makeCoupon(over = {}) {
  const code = ('DOM' + stamp + Math.random().toString(36).slice(2, 5)).toUpperCase().slice(0, 14);
  const fields = {
    code, discount_type: 'PERCENT', discount_value: '10',
    is_active: '1', ...over,
  };
  const res = await admin.postForm('/admin/shop/coupons/save', fields, { fromHtml: couponsPage.text });
  const row = withDb((db) => db.prepare('SELECT * FROM coupons WHERE code = ?').get(code));
  return { res, row, code };
}

const smmCoupon = await makeCoupon();
check('coupon #1 (10%, one per customer) is created', !!smmCoupon.row);
const vtuCoupon = await makeCoupon();
check('coupon #2 for the VTU leg is created', !!vtuCoupon.row);
const minCoupon = await makeCoupon({ min_order_amount: '100000000' });
check('coupon #3 with an unreachable minimum is created', !!minCoupon.row);

// A customer with a funded wallet.
const cust = new Client(BASE);
await cust.get('/register');
await cust.postForm('/register', {
  username: `couponer${stamp}`, email: `couponer${stamp}@example.test`,
  password: 'Coup!Pass99', password_confirm: 'Coup!Pass99', terms: '1', accept_terms: '1',
});
const custRow = withDb((db) => db.prepare(
  'SELECT id, public_id FROM users WHERE username = ?').get(`couponer${stamp}`));
check('the customer exists', !!custRow);

const custFile = await admin.get(`/admin/customers/${custRow.public_id}`);
const nonce = (/name="nonce" value="([^"]+)"/.exec(custFile.text) || [])[1];
await admin.postForm(`/admin/customers/${custRow.public_id}/adjust`, {
  direction: 'CREDIT', amount: '50000', reason: 'Coupon domain testing', nonce,
}, { fromHtml: custFile.text });
check('the wallet was credited', walletOf(custRow.id) === '50000.00000000');

/* ------------------------------ the SMM leg ------------------------------ */

console.log('── An SMM order redeems a coupon');
const newOrder = await cust.get('/dashboard/new-order');
check('the order form carries the coupon field', /name="coupon_code"/.test(newOrder.text));

// Pick the first service from the server-rendered picker and read its frozen
// rate + minimum straight from the option's data attributes.
const opt = newOrder.text.match(/<option value="([0-9a-f]{16,})"\s+data-rate="([\d.]+)"\s+data-min="(\d+)"/);
check('a service with a price is selectable', !!opt);
const [, serviceId, rate, minQty] = opt ? opt.map(String) : ['0', '0', '0'];
const quantity = parseInt(minQty, 10) || 100;
const fullPrice = (parseFloat(rate) * quantity) / 1000;

const balanceBeforeSmm = walletOf(custRow.id);
const placed = await cust.postForm('/dashboard/orders/create', {
  service: serviceId, link: 'https://instagram.com/coupon-test', quantity: String(quantity),
  coupon_code: smmCoupon.code,
}, { fromHtml: newOrder.text });
check('the order goes through with the coupon', /Order placed|already exists/.test(placed.text),
  `status=${placed.status}`);
check('the confirmation names the saving', /you saved/.test(placed.text));
check('the order detail shows the coupon badge', /coupon<\/span>/.test(placed.text));

const orderRow = withDb((db) => db.prepare(
  'SELECT public_id, charge FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1').get(custRow.id));
const expectedCharge = (fullPrice * 0.9).toFixed(8);
check(`the charge is the discounted total (${expectedCharge})`,
  Math.abs(parseFloat(orderRow.charge) - parseFloat(expectedCharge)) < 0.000001,
  `charge=${orderRow.charge} expected≈${expectedCharge}`);

const smmRedemption = withDb((db) => db.prepare(
  'SELECT domain, reference, discount_amount FROM coupon_redemptions WHERE coupon_id = ?').get(smmCoupon.row.id));
check('the redemption is booked against the SMM order',
  !!smmRedemption && smmRedemption.domain === 'SMM' && smmRedemption.reference === orderRow.public_id,
  JSON.stringify(smmRedemption));
check('the wallet was charged the discounted amount only',
  Math.abs(parseFloat(walletOf(custRow.id)) - (parseFloat(balanceBeforeSmm) - parseFloat(expectedCharge))) < 0.000001,
  `balance=${walletOf(custRow.id)}`);

/* ------------------------------ the VTU leg ------------------------------ */

console.log('── A VTU airtime purchase redeems another coupon');
const vtuPage = await cust.get('/dashboard/vtu');
check('the VTU form carries the coupon field', /name="coupon_code"/.test(vtuPage.text));

const netOpt = vtuPage.text.match(/<option value="([A-Z0-9]+)">/);
check('an airtime network is selectable', !!netOpt);
const network = netOpt ? netOpt[1] : 'MTN';
const airtimeProduct = withDb((db) => db.prepare(
  "SELECT discount_percent FROM vtu_products p JOIN vtu_networks n ON n.id = p.network_id"
  + " WHERE n.code = ? AND p.service_type = 'AIRTIME' AND p.is_active = 1").get(network));
const face = 1000;
const afterProductDiscount = face * (1 - parseFloat(airtimeProduct.discount_percent) / 100);
const expectedVtu = afterProductDiscount * 0.9;

const balanceBeforeVtu = walletOf(custRow.id);
const bought = await cust.postForm('/dashboard/vtu/buy/airtime', {
  network, msisdn: '08031234567', amount: String(face), coupon_code: vtuCoupon.code,
}, { fromHtml: vtuPage.text });
check('the airtime purchase succeeds with the coupon', /purchase (successful|processing)\./i.test(bought.text),
  `status=${bought.status}`);

const vtuTx = withDb((db) => db.prepare(
  "SELECT public_id, amount, metadata FROM service_transactions WHERE user_id = ? AND service_domain = 'VTU' ORDER BY id DESC LIMIT 1").get(custRow.id));
check(`the VTU transaction is charged the discounted total (${expectedVtu.toFixed(2)})`,
  Math.abs(parseFloat(vtuTx.amount) - expectedVtu) < 0.000001, `amount=${vtuTx.amount}`);
check('the receipt metadata carries the coupon',
  /"coupon_code":"VTU2/.test(vtuTx.metadata) === false && (JSON.parse(vtuTx.metadata).coupon_code || '') !== '',
  vtuTx.metadata);
check('the wallet reflects the discounted charge',
  Math.abs(parseFloat(walletOf(custRow.id)) - (parseFloat(balanceBeforeVtu) - expectedVtu)) < 0.000001,
  `balance=${walletOf(custRow.id)}`);

const vtuRedemption = withDb((db) => db.prepare(
  'SELECT domain, reference FROM coupon_redemptions WHERE coupon_id = ?').get(vtuCoupon.row.id));
check('the redemption is booked against the VTU transaction',
  !!vtuRedemption && vtuRedemption.domain === 'VTU' && vtuRedemption.reference === vtuTx.public_id,
  JSON.stringify(vtuRedemption));

/* --------------------- the limit travels across domains ------------------ */

console.log('── One code, one customer — in every domain');
const balanceBeforeReuse = walletOf(custRow.id);
const refused = await cust.postForm('/dashboard/vtu/buy/airtime', {
  network, msisdn: '08031234567', amount: String(face), coupon_code: smmCoupon.code,
}, { fromHtml: bought.text });
check('the SMM coupon is refused on VTU with "already used"',
  /already used this coupon/i.test(refused.text));
check('and nothing was charged for the refusal', walletOf(custRow.id) === balanceBeforeReuse);

/* ------------------------ refused before charging ------------------------ */

console.log('── Invalid and below-minimum codes refuse the purchase');
const balanceBeforeBad = walletOf(custRow.id);
const badCode = await cust.postForm('/dashboard/orders/create', {
  service: serviceId, link: 'https://instagram.com/coupon-test-2', quantity: String(quantity),
  coupon_code: 'GHOST' + stamp,
}, { fromHtml: newOrder.text });
check('an unknown code is refused with a readable message', /not valid or has expired/.test(badCode.text));
check('nothing was charged for the unknown code', walletOf(custRow.id) === balanceBeforeBad);

const belowMin = await cust.postForm('/dashboard/orders/create', {
  service: serviceId, link: 'https://instagram.com/coupon-test-3', quantity: String(quantity),
  coupon_code: minCoupon.code,
}, { fromHtml: newOrder.text });
check('a below-minimum coupon explains itself', /requires a subtotal of at least/.test(belowMin.text));
check('nothing was charged for the below-minimum code', walletOf(custRow.id) === balanceBeforeBad);
check('no redemption row was written for either refusal', withDb((db) => db.prepare(
  'SELECT COUNT(*) n FROM coupon_redemptions WHERE coupon_id = ?').get(minCoupon.row.id).n === 0
  && db.prepare(
    'SELECT COUNT(*) n FROM coupon_redemptions WHERE coupon_id = ?').get(smmCoupon.row.id).n === 1));

/* ------------------------------ bookkeeping ------------------------------ */

check('times_used moved with the two real redemptions',
  withDb((db) => db.prepare('SELECT times_used FROM coupons WHERE id = ?').get(smmCoupon.row.id)).times_used === 1
  && withDb((db) => db.prepare('SELECT times_used FROM coupons WHERE id = ?').get(vtuCoupon.row.id)).times_used === 1);

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('Failed:');
  for (const f of failed) console.log(`  ✗ ${f.label}`);
}
process.exit(failed.length ? 1 : 0);
