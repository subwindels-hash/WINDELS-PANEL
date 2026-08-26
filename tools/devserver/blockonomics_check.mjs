/**
 * Blockonomics callback checks against a running dev server.
 *
 * DEV TOOLING ONLY. Exercises the webhook endpoint the way Blockonomics would:
 * a GET carrying status/addr/value/txid and the shared secret. It uses a
 * deposit row created directly in the dev database, because reserving a real
 * address would call the live Blockonomics API.
 *
 * This proves the *panel's* half of the integration — routing, secret
 * verification, idempotency and crediting. It does not prove the live API
 * behaves as documented; that needs production credentials.
 *
 *   node tools/devserver/blockonomics_check.mjs --db storage/devdb/marvy.sqlite
 */
import { DatabaseSync } from 'node:sqlite';
import crypto from 'node:crypto';

const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();
const DB = (() => {
  const i = argv.indexOf('--db');
  return i === -1 ? 'storage/devdb/marvy.sqlite' : argv[i + 1];
})();

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const db = new DatabaseSync(DB);
const ulid = () => crypto.randomBytes(13).toString('hex');
const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

// --- configure the gateway the way an operator would --------------------
const SECRET = 'e2e-callback-secret-' + Date.now();
function setSetting(key, value, category = 'crypto') {
  const payload = JSON.stringify({ value });
  const existing = db.prepare('SELECT setting_key FROM settings WHERE setting_key = ?').get(key);
  if (existing) db.prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?').run(payload, key);
  else
    db.prepare(
      'INSERT INTO settings (setting_key, setting_value, category, is_public, updated_at) VALUES (?,?,?,0,?)'
    ).run(key, payload, category, now());
}
setSetting('blockonomics_api_key', 'e2e-api-key');
setSetting('blockonomics_callback_secret', SECRET);
setSetting('blockonomics_btc_enabled', true);
setSetting('blockonomics_confirmations', 2);

// --- build a pending BTC deposit ----------------------------------------
const user = db.prepare("SELECT id FROM users WHERE role = 'CUSTOMER' ORDER BY id LIMIT 1").get();
const wallet = db.prepare('SELECT id, balance FROM wallets WHERE user_id = ?').get(user.id);
const method = db.prepare("SELECT id FROM payment_methods WHERE code = 'blockonomics'").get();

console.log('── Blockonomics · fixture');
check('blockonomics payment method is seeded', !!method, 'no payment_methods row with code=blockonomics');
if (!method) process.exit(1);

const txPublic = ulid();
db.prepare(
  `INSERT INTO payment_transactions
   (public_id, user_id, payment_method_id, amount, fee, bonus, credited_amount, currency, status, created_at, updated_at)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)`
).run(txPublic, user.id, method.id, '50000.00000000', '0', '0', '50000.00000000', 'NGN', 'PENDING', now(), now());
const txId = db.prepare('SELECT id FROM payment_transactions WHERE public_id = ?').get(txPublic).id;

const address = 'bc1qe2e' + crypto.randomBytes(8).toString('hex');
// Unique per run: a bitcoin txid is globally unique in reality, and reusing a
// literal across runs makes the second run match the first run's transaction.
const TXID1 = 'e2e-' + crypto.randomBytes(6).toString('hex');
const TXID2 = 'e2e-' + crypto.randomBytes(6).toString('hex');
db.prepare(
  `INSERT INTO blockonomics_addresses
   (public_id, payment_transaction_id, user_id, crypto, address, expected_crypto_amount,
    fiat_amount, fiat_currency, rate_used, required_confirmations, status, created_at, updated_at)
   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`
).run(ulid(), txId, user.id, 'BTC', address, '0.00050000', '50000.00000000', 'NGN',
  '100000000.00000000', 2, 'AWAITING', now(), now());

const startBalance = parseFloat(db.prepare('SELECT balance FROM wallets WHERE id = ?').get(wallet.id).balance);
check('pending BTC deposit created', true, `tx=${txPublic} address=${address}`);

const callback = (params) =>
  fetch(`${BASE}/webhook/blockonomics?${new URLSearchParams(params)}`).then(async (r) => ({
    status: r.status,
    body: await r.text(),
  }));

const balance = () => parseFloat(db.prepare('SELECT balance FROM wallets WHERE id = ?').get(wallet.id).balance);
const txStatus = () => db.prepare('SELECT status FROM payment_transactions WHERE id = ?').get(txId).status;

// --- wrong secret --------------------------------------------------------
console.log('\n── Blockonomics · verification');
let r = await callback({ status: 2, addr: address, value: 50000, txid: TXID1, secret: 'wrong' });
check('a callback with the wrong secret is refused', r.status === 401, `status=${r.status}`);
check('no credit from a forged callback', balance() === startBalance, `balance moved to ${balance()}`);

// --- unconfirmed ---------------------------------------------------------
r = await callback({ status: 0, addr: address, value: 50000, txid: TXID1, secret: SECRET });
check('an unconfirmed callback is accepted', r.status === 200, `status=${r.status}`);
check('unconfirmed does not credit the wallet', balance() === startBalance, `balance=${balance()}`);
check('the address row tracks progress',
  db.prepare('SELECT status FROM blockonomics_addresses WHERE address = ?').get(address).status === 'CONFIRMING');

// --- underpaid + confirmed ----------------------------------------------
r = await callback({ status: 2, addr: address, value: 20000, txid: TXID1, secret: SECRET });
check('a confirmed underpayment is accepted but not credited',
  r.status === 200 && balance() === startBalance, `balance=${balance()}`);

// --- fully paid + confirmed ---------------------------------------------
r = await callback({ status: 2, addr: address, value: 50000, txid: TXID2, secret: SECRET });
check('a confirmed full payment is accepted', r.status === 200, `status=${r.status} ${r.body}`);
const credited = balance();
check('the wallet is credited', credited > startBalance, `balance ${startBalance} -> ${credited}`);
check('the deposit is marked successful', txStatus() === 'SUCCESS', `status=${txStatus()}`);

// --- replay --------------------------------------------------------------
console.log('\n── Blockonomics · idempotency');
r = await callback({ status: 2, addr: address, value: 50000, txid: TXID2, secret: SECRET });
check('replaying the same confirmation is a duplicate', r.status === 200, `status=${r.status}`);
check('a replay does not credit twice', balance() === credited, `balance moved to ${balance()}`);

// --- unknown address -----------------------------------------------------
r = await callback({ status: 2, addr: 'bc1qnever-issued', value: 999999, txid: 'e2e-' + crypto.randomBytes(6).toString('hex'), secret: SECRET });
check('a callback for an unissued address never credits', balance() === credited, `balance=${balance()}`);

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
