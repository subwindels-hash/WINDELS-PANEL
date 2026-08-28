/**
 * seed_load.mjs — fill the dev database with a realistic amount of history.
 *
 * DEV TOOLING ONLY. Every performance claim in this project was, until now,
 * made against a database with a dozen orders in it — where an N+1 costs
 * twelve queries and nobody notices. This inserts the volume a panel that has
 * been trading for a year would carry, so the admin screens can be measured
 * under a load that makes the difference visible.
 *
 * Rows are written straight to SQLite in one transaction (tens of thousands of
 * HTTP purchases would take hours and prove nothing extra): the shapes match
 * what the application writes, and every row is tagged so `--clean` can remove
 * exactly what this tool created and nothing else.
 *
 *   node tools/devserver/seed_load.mjs                 # default volume
 *   node tools/devserver/seed_load.mjs --orders 40000
 *   node tools/devserver/seed_load.mjs --clean
 */
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const flag = (name) => argv.includes(name);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const DB_PATH = path.resolve(ROOT, arg('--db', 'storage/devdb/marvy.sqlite'));

const USERS = parseInt(arg('--users', '400'), 10);
const ORDERS = parseInt(arg('--orders', '12000'), 10);
const SERVICE_TX = parseInt(arg('--service-transactions', '4000'), 10);
const WALLET_TX = parseInt(arg('--wallet-transactions', '20000'), 10);
const TICKETS = parseInt(arg('--tickets', '800'), 10);

/** Everything this tool writes carries this marker, so cleanup is exact. */
const TAG = 'LOADSEED';

const { DatabaseSync } = require('node:sqlite');
const db = new DatabaseSync(DB_PATH);

const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
// The counter is zero-PADDED, not the whole string: padding the tail made
// 'USRLOADSEED1' and 'USRLOADSEED10' collide once both were filled to 26.
const id = (prefix, n) => (prefix + TAG + String(n).padStart(26 - prefix.length - TAG.length, '0'))
  .slice(0, 26);

function clean() {
  const before = counts();
  db.exec('BEGIN');
  db.prepare(`DELETE FROM wallet_transactions WHERE reference_id LIKE '${TAG}%'`).run();
  db.prepare(`DELETE FROM ticket_messages WHERE ticket_id IN
              (SELECT id FROM tickets WHERE subject LIKE '${TAG}%')`).run();
  db.prepare(`DELETE FROM tickets WHERE subject LIKE '${TAG}%'`).run();
  db.prepare(`DELETE FROM order_status_history WHERE order_id IN
              (SELECT id FROM orders WHERE public_id LIKE 'ORD${TAG}%')`).run();
  db.prepare(`DELETE FROM orders WHERE public_id LIKE 'ORD${TAG}%'`).run();
  db.prepare(`DELETE FROM service_transactions WHERE public_id LIKE 'STX${TAG}%'`).run();
  db.prepare(`DELETE FROM notifications WHERE public_id LIKE 'NTF${TAG}%'`).run();
  db.prepare(`DELETE FROM wallets WHERE user_id IN
              (SELECT id FROM users WHERE username LIKE '${TAG}%')`).run();
  db.prepare(`DELETE FROM users WHERE username LIKE '${TAG}%'`).run();
  db.exec('COMMIT');
  console.log('removed:', diff(before, counts()));
}

function counts() {
  const one = (t) => { try { return db.prepare(`SELECT COUNT(*) n FROM ${t}`).get().n; } catch { return 0; } };
  return {
    users: one('users'), orders: one('orders'), service_transactions: one('service_transactions'),
    wallet_transactions: one('wallet_transactions'), tickets: one('tickets'),
    notifications: one('notifications'),
  };
}
const diff = (a, b) => Object.fromEntries(Object.keys(a).map((k) => [k, b[k] - a[k]]));

if (flag('--clean')) { clean(); db.close(); process.exit(0); }

/* ------------------------------- the seed -------------------------------- */

const before = counts();
const started = Date.now();

const service = db.prepare(`SELECT id FROM services LIMIT 1`).get();
const provider = db.prepare(`SELECT id FROM providers LIMIT 1`).get();
if (!service) { console.error('seed the panel first (php index.php seed core)'); process.exit(1); }

db.exec('BEGIN');

// Customers, each with a wallet, spread over the last year.
const insertUser = db.prepare(`INSERT INTO users
  (public_id, username, email, password_hash, role, status, price_group_id, created_at, updated_at)
  VALUES (?, ?, ?, ?, 'CUSTOMER', 'ACTIVE', 1, datetime('now', '-' || ? || ' hours'), datetime('now'))`);
const insertWallet = db.prepare(`INSERT INTO wallets
  (public_id, user_id, balance, currency, total_deposited, total_spent, created_at, updated_at)
  VALUES (?, ?, ?, 'NGN', ?, ?, datetime('now'), datetime('now'))`);

const hash = db.prepare(`SELECT password_hash FROM users WHERE email = 'demo@marvy.local'`).get()?.password_hash
  || '$2y$10$abcdefghijklmnopqrstuv';
const userIds = [];
for (let i = 0; i < USERS; i++) {
  insertUser.run(id('USR', i), `${TAG}user${i}`, `${TAG.toLowerCase()}${i}@load.test`, hash,
    String(Math.floor(Math.random() * 8000)));
  const uid = db.prepare('SELECT last_insert_rowid() AS id').get().id;
  insertWallet.run(id('WAL', i), uid, (Math.random() * 50000).toFixed(8),
    (Math.random() * 90000).toFixed(8), (Math.random() * 40000).toFixed(8));
  userIds.push(uid);
}

const statuses = ['COMPLETED', 'COMPLETED', 'COMPLETED', 'PARTIAL', 'IN_PROGRESS',
                  'PROCESSING', 'PENDING', 'CANCELED', 'FAILED', 'REFUNDED'];
const insertOrder = db.prepare(`INSERT INTO orders
  (public_id, user_id, service_id, provider_id, provider_order_id, status, link, quantity,
   charge, rate_at_order, provider_charge, refunded_amount, currency, source, created_at, updated_at)
  VALUES (?, ?, ?, ?, ?, ?, 'https://example.com/handle', ?, ?, '2.00000000', ?, ?, 'NGN', 'WEB',
          datetime('now', '-' || ? || ' hours'), datetime('now'))`);
for (let i = 0; i < ORDERS; i++) {
  const status = pick(statuses);
  const quantity = 100 * (1 + Math.floor(Math.random() * 20));
  const charge = (quantity * 2).toFixed(8);
  insertOrder.run(id('ORD', i), pick(userIds), service.id, provider ? provider.id : null,
    'P' + i, status, quantity, charge, (quantity * 1.4).toFixed(8),
    status === 'REFUNDED' ? charge : '0.00000000', Math.floor(Math.random() * 8000));
}

const domains = ['VTU', 'NUMBER', 'IDENTITY', 'GIFTCARD'];
const insertService = db.prepare(`INSERT INTO service_transactions
  (public_id, user_id, service_domain, service_type, status, amount, provider_cost,
   refunded_amount, currency, source, created_at)
  VALUES (?, ?, ?, 'AIRTIME', ?, ?, ?, '0.00000000', 'NGN', 'WEB',
          datetime('now', '-' || ? || ' hours'))`);
for (let i = 0; i < SERVICE_TX; i++) {
  const amount = (500 + Math.floor(Math.random() * 9500)).toFixed(8);
  insertService.run(id('STX', i), pick(userIds), pick(domains),
    pick(['SUCCESSFUL', 'SUCCESSFUL', 'FAILED', 'PROCESSING']), amount,
    (Number(amount) * 0.95).toFixed(8), Math.floor(Math.random() * 8000));
}

const walletIds = db.prepare(`SELECT id FROM wallets`).all().map((r) => r.id);
const insertTx = db.prepare(`INSERT INTO wallet_transactions
  (public_id, wallet_id, type, direction, amount, balance_before, balance_after,
   reference_type, reference_id, created_at)
  VALUES (?, ?, ?, ?, ?, '0.00000000', '0.00000000', 'ORDER', ?, datetime('now', '-' || ? || ' hours'))`);
for (let i = 0; i < WALLET_TX; i++) {
  const credit = Math.random() < 0.4;
  insertTx.run(id('WTX', i), pick(walletIds), credit ? 'DEPOSIT' : 'ORDER_CHARGE',
    credit ? 'CREDIT' : 'DEBIT', (Math.random() * 20000).toFixed(8),
    `${TAG}-${i}`, Math.floor(Math.random() * 8000));
}

const insertTicket = db.prepare(`INSERT INTO tickets
  (public_id, user_id, subject, department, priority, status, created_at, updated_at)
  VALUES (?, ?, ?, 'ORDERS', 'MEDIUM', ?, datetime('now', '-' || ? || ' hours'), datetime('now'))`);
const insertMessage = db.prepare(`INSERT INTO ticket_messages
  (public_id, ticket_id, author_id, message, is_staff, is_internal_note, created_at)
  VALUES (?, ?, ?, ?, ?, 0, datetime('now'))`);
for (let i = 0; i < TICKETS; i++) {
  const uid = pick(userIds);
  insertTicket.run(id('TKT', i), uid, `${TAG} ticket ${i}`,
    pick(['OPEN', 'PENDING', 'ANSWERED', 'CLOSED']), Math.floor(Math.random() * 8000));
  const tid = db.prepare('SELECT last_insert_rowid() AS id').get().id;
  for (let m = 0; m < 1 + Math.floor(Math.random() * 4); m++) {
    insertMessage.run(id('TMS', i * 10 + m), tid, uid, 'Message body number ' + m, m % 2);
  }
}

const insertNote = db.prepare(`INSERT INTO notifications
  (public_id, user_id, type, channel, title, body, is_read, created_at)
  VALUES (?, ?, 'order.completed', 'IN_APP', 'Order completed', 'Your order is complete.', ?,
          datetime('now', '-' || ? || ' hours'))`);
for (let i = 0; i < Math.floor(ORDERS / 4); i++) {
  insertNote.run(id('NTF', i), pick(userIds), Math.random() < 0.7 ? 1 : 0,
    Math.floor(Math.random() * 8000));
}

db.exec('COMMIT');

console.log(`seeded in ${((Date.now() - started) / 1000).toFixed(1)}s:`, diff(before, counts()));
console.log('totals:', counts());
console.log(`\nremove it again with: node tools/devserver/seed_load.mjs --clean`);
db.close();
