/**
 * Multi-currency wallets — end-to-end verification (module 37).
 *
 * DEV TOOLING ONLY. A wallet could only ever hold the base currency: the
 * `currency` column existed but nothing could set it and nothing that
 * charged a wallet knew what to do if it had been. This proves, against the
 * running panel, that:
 *
 *   - a customer picks a currency for a virgin wallet through the real
 *     add-funds form, and can never change it once money has moved;
 *   - the admin customer file shows the wallet's currency, adjusts are
 *     entered in it, and the admin may set a virgin wallet's currency too;
 *   - an SMM order and a VTU purchase from a foreign wallet charge the
 *     CONVERTED amount at a rate pinned on the movement (wallet rows carry
 *     amount + fx_rate + base_amount; the order itself stays priced in ₦);
 *   - the refund-rate policy holds under a MOVED rate: a staff refund
 *     returns exactly the wallet currency that was taken, not what today's
 *     rate would give — FX drift can never make a refund create or destroy
 *     money;
 *   - the ledger balances per currency, not just in total;
 *   - a base-currency customer's experience is unchanged to the kobo.
 *
 *   node tools/devserver/currency_wallet_check.mjs
 *   node tools/devserver/currency_wallet_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const argvPw = (process.argv.indexOf('--admin-password') >= 0)
  ? process.argv[process.argv.indexOf('--admin-password') + 1] : null;
const ADMIN_PASSWORD = argvPw || process.env.DEMO_PASSWORD || 'Demo!c7e2331b';

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}
// FXCHECK_DEBUG=1 prints every request as it is made — the php-wasm dev
// server can wedge on rare occasions and this shows exactly where.
const DEBUG = !!process.env.FXCHECK_DEBUG;
function traced(name, client) {
  if (!DEBUG) return client;
  return new Proxy(client, {
    get(target, prop) {
      if (['get', 'postForm', 'raw'].includes(prop)) {
        return async (...args) => {
          process.stdout.write(`  [${name}] ${prop} ${String(args[0]).slice(0, 70)} … `);
          try {
            const r = await target[prop](...args);
            console.log(String(r.status));
            return r;
          } catch (e) { console.log('ERR ' + e.message); throw e; }
        };
      }
      return target[prop];
    },
  });
}
function withDb(fn) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync('/home/user/WINDELS-PANEL/storage/devdb/marvy.sqlite');
  try { return fn(db); } finally { db.close(); }
}
const walletOf = (userId) => withDb((db) => db.prepare(
  'SELECT * FROM wallets WHERE user_id = ?').get(userId));

const stamp = Date.now().toString().slice(-8);

/* ----------------------------- admin setup ------------------------------- */

console.log('── Admin fixes a known rate and meets the customer');
const admin = traced('admin', new Client(BASE));
await admin.get('/admin/login');
await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });

const currenciesPage = await admin.get('/admin/currencies');
const originalRate = withDb((db) => db.prepare(
  "SELECT exchange_rate FROM currencies WHERE code = 'USD'").get()).exchange_rate;

// A test rate of exactly 0.001 keeps every converted figure exact at 8dp,
// so the assertions compare real strings, not float tolerances.
await admin.postForm('/admin/currencies/rate', { code: 'USD', rate: '0.001' },
  { fromHtml: currenciesPage.text });

const fxCustomer = traced('fx', new Client(BASE));
await fxCustomer.get('/register');
await fxCustomer.postForm('/register', {
  username: `fxholder${stamp}`, email: `fxholder${stamp}@example.test`,
  password: 'F!xPass99', password_confirm: 'F!xPass99', terms: '1', accept_terms: '1',
});
const fxRow = withDb((db) => db.prepare(
  'SELECT id, public_id FROM users WHERE username = ?').get(`fxholder${stamp}`));
check('the customer exists', !!fxRow);
check('a fresh wallet defaults to the base currency',
  walletOf(fxRow.id).currency === 'NGN');

/* ------------------------- the one-time choice --------------------------- */

console.log('── The customer chooses what their wallet holds');
const addFunds = await fxCustomer.get('/dashboard/add-funds');
check('the add-funds page offers the one-time choice',
  /Hold my wallet in/.test(addFunds.text) && /dashboard\/wallet\/currency/.test(addFunds.text));

const chosen = await fxCustomer.postForm('/dashboard/wallet/currency', { currency: 'USD' },
  { fromHtml: addFunds.text });
check('the choice succeeds with a plain-words confirmation',
  /now holds USD/i.test(chosen.text), chosen.url);
check('the wallet row now holds USD', walletOf(fxRow.id).currency === 'USD');

// Fund it through the real admin adjust form — which must be labelled in the
// wallet's own currency, or a staff member types dollars into a naira field.
const custFile = await admin.get(`/admin/customers/${fxRow.public_id}`);
check('the admin customer file labels the adjust amount in USD',
  /Amount \(USD\)/.test(custFile.text));
const nonce = (/name="nonce" value="([^"]+)"/.exec(custFile.text) || [])[1];
await admin.postForm(`/admin/customers/${fxRow.public_id}/adjust`, {
  direction: 'CREDIT', amount: '100', reason: 'Module 37 foreign wallet funding', nonce,
}, { fromHtml: custFile.text });
check('a $100 adjustment lands in the wallet as one hundred dollars',
  walletOf(fxRow.id).balance === '100.00000000',
  JSON.stringify(walletOf(fxRow.id)));

/* --------------------------- the SMM leg --------------------------------- */

console.log('── An SMM order charges the USD wallet, converted and pinned');
const newOrder = await fxCustomer.get('/dashboard/new-order');
const opt = newOrder.text.match(
  /<option value="([0-9a-f]{16,})"\s+data-rate="([\d.]+)"\s+data-min="(\d+)"(?:\s+data-max="(\d+)")?/);
check('a service with a price is selectable', !!opt);
const [, serviceId, rate, minQty, maxQty] = opt.map(String);
const quantity = Math.min(parseInt(maxQty || '1000000', 10), Math.max(parseInt(minQty, 10) * 10, 1000));
const chargeNgn = (parseFloat(rate) * quantity) / 1000;
const chargeUsd = (chargeNgn * 0.001).toFixed(8);

const placed = await fxCustomer.postForm('/dashboard/orders/create', {
  service: serviceId, link: 'https://instagram.com/fx-wallet-test', quantity: String(quantity),
}, { fromHtml: newOrder.text });
check('the order goes through from a foreign wallet', /Order placed|already exists/.test(placed.text));

const orderRow = withDb((db) => db.prepare(
  'SELECT public_id, charge, currency FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1').get(fxRow.id));
check('the order itself is still priced in the base currency',
  orderRow.currency === 'NGN' && Math.abs(parseFloat(orderRow.charge) - chargeNgn) < 0.000001,
  `charge=${orderRow.charge} expected≈${chargeNgn}`);

const debit = withDb((db) => db.prepare(
  'SELECT * FROM wallet_transactions WHERE wallet_id = ? AND direction = ? ORDER BY id DESC LIMIT 1'
).get(walletOf(fxRow.id).id, 'DEBIT'));
check(`the wallet was debited the converted amount ($${chargeUsd})`,
  debit.amount === chargeUsd && debit.currency === 'USD',
  JSON.stringify({ amount: debit.amount, currency: debit.currency }));
check('the rate is pinned on the movement with the base value it bought',
  debit.fx_rate === '0.00100000' && debit.base_amount === chargeNgn.toFixed(8),
  JSON.stringify({ fx_rate: debit.fx_rate, base_amount: debit.base_amount }));
check('the charge is stamped with the order it paid for',
  debit.reference_id === orderRow.public_id,
  `reference_id=${debit.reference_id} order=${orderRow.public_id}`);
check('the wallet balance is the dollar figure after the charge',
  walletOf(fxRow.id).balance === (100 - parseFloat(chargeUsd)).toFixed(8),
  walletOf(fxRow.id).balance);

/* ------------------ the refund-rate policy, under a moved rate ------------ */

console.log('── A refund replays the charge-day rate, not today\'s');
// A week passes; the rate halves in meaning (doubles).
await admin.postForm('/admin/currencies/rate', { code: 'USD', rate: '0.002' },
  { fromHtml: currenciesPage.text });

// Staff cancel the order: the customer's money must come back through the
// same refund path a provider cancellation or staff refund uses.
const refunded = await admin.postForm(`/admin/orders/${orderRow.public_id}/cancel`, {
  reason: 'module 37 refund-rate policy check',
}, { fromHtml: await admin.get(`/admin/orders/${orderRow.public_id}`).text });
check('the staff cancel-with-refund succeeds', /canceled|refund/i.test(refunded.text));

const credit = withDb((db) => db.prepare(
  'SELECT * FROM wallet_transactions WHERE wallet_id = ? AND direction = ? ORDER BY id DESC LIMIT 1'
).get(walletOf(fxRow.id).id, 'CREDIT'));
check(`the customer gets back exactly what was taken ($${chargeUsd}), not today's rate`,
  credit.amount === chargeUsd, JSON.stringify({ amount: credit.amount, fx_rate: credit.fx_rate }));
check('the refund row replays the pinned rate',
  credit.fx_rate === '0.00100000' && credit.base_amount === chargeNgn.toFixed(8));
check('the wallet is whole again',
  walletOf(fxRow.id).balance === '100.00000000', walletOf(fxRow.id).balance);

/* --------------------------- the VTU leg --------------------------------- */

console.log('── A VTU purchase converts through the same boundary');
const vtuPage = await fxCustomer.get('/dashboard/vtu');
const netOpt = vtuPage.text.match(/<option value="([A-Z0-9]+)">/);
const network = netOpt ? netOpt[1] : 'MTN';
const airtimeProduct = withDb((db) => db.prepare(
  "SELECT discount_percent FROM vtu_products p JOIN vtu_networks n ON n.id = p.network_id"
  + " WHERE n.code = ? AND p.service_type = 'AIRTIME' AND p.is_active = 1").get(network));
const afterDiscount = 1000 * (1 - parseFloat(airtimeProduct.discount_percent) / 100);
// This purchase happens AFTER the rate moved to 0.002 — a fresh charge
// converts at the CURRENT rate; only refunds replay the pinned one.
const currentRate = 0.002;

const bought = await fxCustomer.postForm('/dashboard/vtu/buy/airtime', {
  network, msisdn: '08031234567', amount: '1000',
}, { fromHtml: vtuPage.text });
check('the airtime purchase succeeds from the foreign wallet',
  /purchase (successful|processing)\./i.test(bought.text));

const vtuDebit = withDb((db) => db.prepare(
  'SELECT * FROM wallet_transactions WHERE wallet_id = ? AND direction = ? ORDER BY id DESC LIMIT 1'
).get(walletOf(fxRow.id).id, 'DEBIT'));
check(`the VTU charge converts at the current rate ($${(afterDiscount * currentRate).toFixed(8)} for ₦${afterDiscount})`,
  vtuDebit.amount === (afterDiscount * currentRate).toFixed(8)
  && vtuDebit.base_amount === afterDiscount.toFixed(8)
  && vtuDebit.fx_rate === currentRate.toFixed(8),
  JSON.stringify({ amount: vtuDebit.amount, base_amount: vtuDebit.base_amount }));

/* ------------------- the choice is frozen; books balance ------------------ */

console.log('── One wallet, one currency, forever — and books that balance');
const refused = await fxCustomer.postForm('/dashboard/wallet/currency', { currency: 'NGN' },
  { fromHtml: bought.text });
check('a used wallet cannot change currency',
  /cannot change currency/.test(refused.text));

const perCurrency = withDb((db) => {
  const rows = db.prepare(
    "SELECT currency, SUM(CASE WHEN direction='DEBIT' THEN amount ELSE 0 END) AS d,"
    + " SUM(CASE WHEN direction='CREDIT' THEN amount ELSE 0 END) AS c"
    + " FROM ledger_entries GROUP BY currency").all();
  return rows;
});
let balanced = true, why = '';
for (const r of perCurrency) {
  if (Math.abs(parseFloat(r.d) - parseFloat(r.c)) > 0.0000001) {
    balanced = false; why = `${r.currency}: debits ${r.d} vs credits ${r.c}`;
  }
}
check('the ledger balances in each currency, not just in total', balanced, why);

const walletsScreen = await admin.get('/admin/wallets');
check('the admin wallets summary reports foreign holdings per currency',
  /in USD \(/.test(walletsScreen.text));

const txPage = await fxCustomer.get('/dashboard/transactions');
check('the transactions page shows the ₦ value beside the $ movement',
  /≈ ₦/.test(txPage.text));

/* ------------------- a base-currency customer, unchanged ----------------- */

console.log('── A naira customer notices nothing');
const ngnCustomer = traced('ngn', new Client(BASE));
await ngnCustomer.get('/register');
await ngnCustomer.postForm('/register', {
  username: `ngnholder${stamp}`, email: `ngnholder${stamp}@example.test`,
  password: 'Ng!Pass99', password_confirm: 'Ng!Pass99', terms: '1', accept_terms: '1',
});
const ngnRow = withDb((db) => db.prepare(
  'SELECT id, public_id FROM users WHERE username = ?').get(`ngnholder${stamp}`));
const ngnFile = await admin.get(`/admin/customers/${ngnRow.public_id}`);
const ngnNonce = (/name="nonce" value="([^"]+)"/.exec(ngnFile.text) || [])[1];
await admin.postForm(`/admin/customers/${ngnRow.public_id}/adjust`, {
  direction: 'CREDIT', amount: '5000', reason: 'Module 37 base-currency control', nonce: ngnNonce,
}, { fromHtml: ngnFile.text });
check('a naira adjustment still lands as ₦5,000',
  walletOf(ngnRow.id).balance === '5000.00000000' && walletOf(ngnRow.id).currency === 'NGN');

const ngnOrder = await ngnCustomer.postForm('/dashboard/orders/create', {
  service: serviceId, link: 'https://instagram.com/ngn-control', quantity: String(quantity),
}, { fromHtml: await ngnCustomer.get('/dashboard/new-order').text });
check('the naira order goes through', /Order placed|already exists/.test(ngnOrder.text));
const ngnDebit = withDb((db) => db.prepare(
  'SELECT * FROM wallet_transactions WHERE wallet_id = ? AND direction = ? ORDER BY id DESC LIMIT 1'
).get(walletOf(ngnRow.id).id, 'DEBIT'));
check('the naira wallet moves in naira, with no conversion record',
  ngnDebit.currency === 'NGN' && ngnDebit.fx_rate === null && ngnDebit.base_amount === null,
  JSON.stringify({ currency: ngnDebit.currency, fx_rate: ngnDebit.fx_rate }));
check('the balance drops by exactly the charge',
  walletOf(ngnRow.id).balance === (5000 - chargeNgn).toFixed(8), walletOf(ngnRow.id).balance);

/* ------------------------------ restore ---------------------------------- */

withDb((db) => db.prepare("UPDATE currencies SET exchange_rate = ? WHERE code = 'USD'")
  .run(originalRate));

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('Failed:');
  for (const f of failed) console.log(`  ✗ ${f.label}`);
}
process.exit(failed.length ? 1 : 0);
