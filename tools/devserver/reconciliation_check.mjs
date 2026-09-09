/**
 * Deposit reconciliation end-to-end check.
 *
 * Runs the real cron job (`php index.php cron payment_reconciliation`) against
 * the dev database and proves the guarantees that matter when a gateway
 * callback goes missing:
 *
 *   - a stored, signature-verified callback that never finished processing is
 *     replayed and credits the wallet exactly once;
 *   - a deposit whose gateway cannot be reached is NEVER written off, however
 *     old it is — an outage must not cost a customer their money;
 *   - a deposit with no gateway to ask is still aged out after the window;
 *   - a deposit inside the grace period is left alone;
 *   - nothing is credited twice when the job runs again.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/reconciliation_check.mjs [--db storage/devdb/marvy.sqlite]
 */
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
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

function runCron() {
  return execFileSync('node', ['tools/devserver/php_run.mjs', 'index.php', 'cron', 'payment_reconciliation'],
    { cwd: ROOT, encoding: 'utf8', timeout: 120000 });
}

const ids = withDb((db) => {
  const user = db.prepare(`SELECT id FROM users WHERE email = 'demo@marvy.local'`).get();
  const manual = db.prepare(`SELECT id FROM payment_methods WHERE code = 'manual'`).get();
  const paystack = db.prepare(`SELECT id FROM payment_methods WHERE code = 'paystack'`).get();
  const wallet = db.prepare(`SELECT id, balance FROM wallets WHERE user_id = ?`).get(user.id);

  const insert = db.prepare(`INSERT INTO payment_transactions
      (public_id, internal_reference, user_id, payment_method_id, provider, provider_tx_id,
       amount, fee, bonus, credited_amount, currency, status, idempotency_key, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, '0.00000000', '0.00000000', ?, 'NGN', 'PENDING', ?, ?)`);

  const stamp = Date.now();
  const old = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 19).replace('T', ' ');
  const recent = new Date(Date.now() - 60 * 1000).toISOString().slice(0, 19).replace('T', ' ');

  // 1. A missed callback: verified, stored, never processed.
  const missedRef = `MVS-RECON-MISSED-${stamp}`;
  insert.run(`RECONMISS${stamp}`, missedRef, user.id, paystack.id, 'paystack', missedRef,
    '2500.00000000', '2500.00000000', `idem-miss-${stamp}`, old);
  db.prepare(`INSERT INTO payment_webhooks (gateway_type, event_id, event_type, payload, signature_valid, processed, created_at)
              VALUES ('paystack', ?, 'charge_success', ?, 1, 0, datetime('now'))`)
    .run(`evt-miss-${stamp}`, JSON.stringify({
      event: 'charge.success',
      data: { id: stamp, reference: missedRef, status: 'success', amount: 250000, currency: 'NGN' },
    }));

  // 2. An old deposit on a gateway that cannot be reached from this sandbox.
  const unreachableRef = `MVS-RECON-UNREACH-${stamp}`;
  insert.run(`RECONUNR${stamp}`, unreachableRef, user.id, paystack.id, 'paystack', unreachableRef,
    '900.00000000', '900.00000000', `idem-unreach-${stamp}`, old);

  // 3. An old manual transfer: nobody to ask, so the window applies.
  const manualRef = `MVS-RECON-MANUAL-${stamp}`;
  insert.run(`RECONMAN${stamp}`, manualRef, user.id, manual.id, 'manual', null,
    '700.00000000', '700.00000000', `idem-manual-${stamp}`, old);

  // 4. A deposit a minute old: inside the grace period.
  const freshRef = `MVS-RECON-FRESH-${stamp}`;
  insert.run(`RECONNEW${stamp}`, freshRef, user.id, manual.id, 'manual', null,
    '600.00000000', '600.00000000', `idem-fresh-${stamp}`, recent);

  return { walletId: wallet.id, balance: wallet.balance, missedRef, unreachableRef, manualRef, freshRef };
});

function statusOf(ref) {
  return withDb((db) => db.prepare(`SELECT status FROM payment_transactions WHERE internal_reference = ?`).get(ref)?.status);
}
function balance() {
  return withDb((db) => db.prepare(`SELECT balance FROM wallets WHERE id = ?`).get(ids.walletId).balance);
}

console.log('\n── Reconciliation · the cron job runs');
// Paystack needs a secret for the replay path to be considered verified work;
// the stored row already carries signature_valid = 1, which is what matters.
const output = runCron();
check('the job completes', /payment_reconciliation|processed|replayed/i.test(output), output.trim().slice(0, 300));

console.log('\n── Reconciliation · a missed callback still credits the customer');
check('the deposit whose webhook never finished is now SUCCESS',
  statusOf(ids.missedRef) === 'SUCCESS', `status=${statusOf(ids.missedRef)}`);
const afterFirst = balance();
check('the wallet is credited by that deposit',
  Number(afterFirst) - Number(ids.balance) === 2500, `${ids.balance} -> ${afterFirst}`);
check('the stored callback is marked processed',
  withDb((db) => db.prepare(`SELECT processed FROM payment_webhooks WHERE payload LIKE ?`)
    .get(`%${ids.missedRef}%`).processed) === 1);

console.log('\n── Reconciliation · what must NOT be written off');
check('a deposit whose gateway is unreachable is left open, however old',
  statusOf(ids.unreachableRef) === 'PENDING', `status=${statusOf(ids.unreachableRef)}`);
check('a deposit inside the grace period is untouched',
  statusOf(ids.freshRef) === 'PENDING', `status=${statusOf(ids.freshRef)}`);

console.log('\n── Reconciliation · genuinely dead deposits are closed');
check('an old deposit with no gateway to ask is expired',
  statusOf(ids.manualRef) === 'FAILED', `status=${statusOf(ids.manualRef)}`);
check('the reason says why', withDb((db) => /Expired/i.test(
  db.prepare(`SELECT reason FROM payment_events WHERE payment_transaction_id =
              (SELECT id FROM payment_transactions WHERE internal_reference = ?)
              ORDER BY id DESC LIMIT 1`).get(ids.manualRef)?.reason || '')));

console.log('\n── Reconciliation · running again changes nothing');
runCron();
check('the credited deposit is not credited twice', String(balance()) === String(afterFirst),
  `${afterFirst} -> ${balance()}`);
check('the unreachable deposit is still open, not expired on the second pass',
  statusOf(ids.unreachableRef) === 'PENDING', `status=${statusOf(ids.unreachableRef)}`);

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
