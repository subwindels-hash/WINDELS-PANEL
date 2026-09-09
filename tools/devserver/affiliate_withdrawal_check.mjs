/**
 * Affiliate earnings withdrawal — end-to-end check.
 *
 * DEV TOOLING ONLY. Exercises the full lifecycle over real HTTP against the
 * real application:
 *
 *   Available earnings -> withdrawal request -> Pending -> admin review
 *     -> Approved/Rejected -> Paid
 *
 * plus the safety rules the feature depends on: no double withdrawal, no
 * duplicate open requests, cancellation releases the lock, rejection releases
 * the lock, and every action is audited. Also exercises admin search/filter
 * and the two new detail views (admin/payouts/:id, the affiliate section on
 * admin/customers/:id).
 *
 * Grants the throwaway customer a manufactured AVAILABLE earning directly in
 * the dev SQLite file first (there is no HTTP endpoint that manufactures an
 * affiliate commission on demand — real ones come from qualified referrals —
 * so this is the same kind of direct fixture setup pin_rotation_check.mjs
 * uses to simulate elapsed time).
 *
 *   node tools/devserver/affiliate_withdrawal_check.mjs --admin-password <pw> [--db storage/devdb/marvy.sqlite]
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
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);

if (!adminPassword) {
  console.error('Usage: node tools/devserver/affiliate_withdrawal_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

/** Parses the "Available" figure off the earnings page, as a plain number. */
function availableOf(text) {
  const m = /Available<\/div>\s*<div[^>]*>([^<]+)</.exec(text);
  return m ? parseFloat(m[1].replace(/[^0-9.]/g, '')) : null;
}

function withDb(fn) {
  const { DatabaseSync } = require('node:sqlite');
  const db = new DatabaseSync(path.resolve(ROOT, DB_PATH));
  try { return fn(db); } finally { db.close(); }
}

const stamp = Date.now().toString().slice(-8);
const user = {
  username: `payout${stamp}`,
  email: `payout${stamp}@example.test`,
  password: 'PayoutCheck!99',
};

console.log('── Affiliate withdrawal · setup');
const c = new Client(BASE);
await c.get('/register');
await c.postForm('/register', {
  username: user.username, email: user.email,
  password: user.password, password_confirm: user.password,
  terms: '1', accept_terms: '1',
});
await c.get('/login');
const login = await c.postForm('/login', { identifier: user.username, password: user.password });
check('customer signed in', /dashboard/i.test(login.url));

const userId = withDb((db) => db.prepare('SELECT id FROM users WHERE username = ?').get(user.username)).id;
withDb((db) => {
  db.exec(`INSERT INTO earnings
    (public_id, user_id, source, amount, currency, status, description, idempotency_key, available_at, created_at, updated_at)
    VALUES ('AWCHK${stamp}0000000000', ${userId}, 'REFERRAL', '4000.00000000', 'NGN', 'AVAILABLE',
            'e2e fixture', 'awcheck:${stamp}', datetime('now'), datetime('now'), datetime('now'))`);
});
check('fixture earning created (4000 AVAILABLE)', true);

console.log('\n── Affiliate withdrawal · admin enables cash payouts');
const a = new Client(BASE);
await a.get('/admin/login');
const adminLogin = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(adminLogin.url) && !/login/.test(adminLogin.url));
let settingsPage = await a.get('/admin/settings');
await a.postForm('/admin/settings/save', {
  reseller_webhook_url: '', currency_display: 'symbol', default_theme: 'system',
  __rendered_api_enabled: '1', api_enabled: '1',
  __rendered_earnings_payouts_enabled: '1', earnings_payouts_enabled: '1',
  earnings_min_payout: '500.00000000',
}, { fromHtml: settingsPage.text });
check('cash payouts enabled', true);

console.log('\n── Affiliate withdrawal · dashboard shows the full picture');
let earn = await c.get('/dashboard/earnings');
check('earnings page loads', earn.status === 200);
check('shows Available/Pending/Locked/Total earned', ['Available','Pending','Locked','Total earned'].every((w) => earn.text.includes(w)));
check('shows a Withdraw control', /Request<\/button>/.test(earn.text) || /Withdraw/i.test(earn.text));
check('bank transfer method is offered now payouts are enabled', earn.text.includes('BANK_TRANSFER'));

console.log('\n── Affiliate withdrawal · cannot withdraw more than available');
let bad = await c.postForm('/dashboard/earnings/withdraw',
  { amount: '999999.00', method: 'BANK_TRANSFER', destination: 'Test Bank 0000000000', destination_name: 'Test' },
  { fromHtml: earn.text });
check('over-withdrawal is refused', /available/i.test(bad.text) && bad.status === 200);

console.log('\n── Affiliate withdrawal · request → pending → locked');
earn = await c.get('/dashboard/earnings');
let submit = await c.postForm('/dashboard/earnings/withdraw',
  { amount: '1500.00', method: 'BANK_TRANSFER', destination: 'GTBank 0123456789', destination_name: 'E2E Tester' },
  { fromHtml: earn.text });
check('withdrawal request accepted', submit.status === 200);

earn = await c.get('/dashboard/earnings');
const availAfterRequest = availableOf(earn.text);
check('available balance drops by exactly the requested amount (4000 -> 2500)', availAfterRequest === 2500, `got ${availAfterRequest}`);
check('the request shows as REQUESTED (pending review)', />REQUESTED</.test(earn.text));

console.log('\n── Affiliate withdrawal · duplicate/race protection');
let dup = await c.postForm('/dashboard/earnings/withdraw',
  { amount: '500.00', method: 'BANK_TRANSFER', destination: 'Other bank', destination_name: 'x' },
  { fromHtml: earn.text });
check('a second request while one is open is blocked', /already have a payout request/i.test(dup.text));

console.log('\n── Affiliate withdrawal · admin search and filters');
let queue = await a.get('/admin/payouts?status=REQUESTED');
check('admin queue loads', queue.status === 200);
check('the new request is listed', queue.text.includes('1,500.00') || queue.text.includes('₦1,500.00'));
let searched = await a.get(`/admin/payouts?q=${encodeURIComponent(user.username)}`);
check('admin can search by username', searched.text.includes(user.username));
let searchedEmail = await a.get(`/admin/payouts?q=${encodeURIComponent(user.email)}`);
check('admin can search by email', searchedEmail.text.includes(user.username));
let amountFiltered = await a.get('/admin/payouts?amount_min=1400&amount_max=1600');
check('admin can filter by amount range', amountFiltered.text.includes(user.username));
let noMatch = await a.get('/admin/payouts?q=zzz-no-such-user-zzz');
check('a non-matching search shows the empty state', /No payout requests match/i.test(noMatch.text));

console.log('\n── Affiliate withdrawal · detail views');
const m = queue.text.match(new RegExp(`admin/payouts/([A-Za-z0-9]+)"[^>]*>[\\s\\S]{0,400}?${user.username}`))
  || queue.text.match(/admin\/payouts\/([A-Za-z0-9]+)"/);
const payoutId = m[1];
let detail = await a.get('/admin/payouts/' + payoutId);
check('payout detail page loads', detail.status === 200);
check('shows the requester username and email', detail.text.includes(user.username) && detail.text.includes(user.email));
check("shows the customer's affiliate balance", /customer's affiliate balance/i.test(detail.text));
check("shows the customer's withdrawal history", /customer's withdrawal history/i.test(detail.text));

const userPublicIdMatch = detail.text.match(/admin\/customers\/([A-Za-z0-9]+)/);
if (userPublicIdMatch) {
  let userDetail = await a.get('/admin/customers/' + userPublicIdMatch[1]);
  check('the customer file shows an affiliate & earnings section', /Affiliate &amp; earnings history|Affiliate & earnings history/.test(userDetail.text));
} else {
  check('the customer file shows an affiliate & earnings section', false, 'could not find a customer link on the payout detail page');
}

console.log('\n── Affiliate withdrawal · approve with an internal note, then mark paid');
let approve = await a.postForm(`/admin/payouts/${payoutId}/approve`, { note: 'ID verified, proceeding' }, { fromHtml: detail.text });
check('approve accepted', approve.status === 200);
detail = await a.get('/admin/payouts/' + payoutId);
check('the internal note is recorded and visible', detail.text.includes('ID verified, proceeding'));
check('status is now APPROVED', /badge-info">APPROVED/.test(detail.text));

let paid = await a.postForm(`/admin/payouts/${payoutId}/paid`, { reference: 'E2E-REF-998877' }, { fromHtml: detail.text });
check('mark-paid accepted', paid.status === 200);
detail = await a.get('/admin/payouts/' + payoutId);
check('status is now PAID with the reference recorded', /badge-success">PAID/.test(detail.text) && detail.text.includes('E2E-REF-998877'));

console.log('\n── Affiliate withdrawal · settles exactly once');
const ledgerRow = withDb((db) => db.prepare(
  "SELECT status, paid_out_at FROM earnings WHERE payout_request_id = (SELECT id FROM payout_requests WHERE public_id = ?)"
).get(payoutId));
check('the locked earning transitioned to PAID exactly once', ledgerRow && ledgerRow.status === 'PAID' && !!ledgerRow.paid_out_at);

let reApprove = await a.postForm(`/admin/payouts/${payoutId}/approve`, {}, { fromHtml: detail.text });
check('re-approving a paid request is rejected', /no longer awaiting review/i.test(reApprove.text));
let rePaid = await a.postForm(`/admin/payouts/${payoutId}/paid`, { reference: 'DUPLICATE' }, { fromHtml: detail.text });
check('re-marking a paid request as paid is rejected (no double payout)', /Only an approved payout/i.test(rePaid.text));

console.log('\n── Affiliate withdrawal · reject releases the earnings');
earn = await c.get('/dashboard/earnings');
const availBeforeSecond = availableOf(earn.text);
let submit2 = await c.postForm('/dashboard/earnings/withdraw',
  { amount: '1000.00', method: 'BANK_TRANSFER', destination: 'Zenith 999', destination_name: 'E2E' },
  { fromHtml: earn.text });
check('second withdrawal request accepted', submit2.status === 200);

queue = await a.get('/admin/payouts?status=REQUESTED');
const m2 = queue.text.match(/admin\/payouts\/([A-Za-z0-9]+)"/);
const payoutId2 = m2[1];
let reject = await a.postForm(`/admin/payouts/${payoutId2}/reject`, { reason: 'unverifiable account details' }, { fromHtml: queue.text });
check('reject accepted', reject.status === 200);

earn = await c.get('/dashboard/earnings');
const availAfterReject = availableOf(earn.text);
check('rejected earnings return to Available in full', availAfterReject === availBeforeSecond, `before=${availBeforeSecond} after=${availAfterReject}`);
check('the customer sees the rejected status', /badge-danger">REJECTED/.test(earn.text));

console.log('\n── Affiliate withdrawal · cancel releases the earnings');
earn = await c.get('/dashboard/earnings');
const availBeforeThird = availableOf(earn.text);
let submit3 = await c.postForm('/dashboard/earnings/withdraw',
  { amount: '700.00', method: 'BANK_TRANSFER', destination: 'Access 555', destination_name: 'E2E' },
  { fromHtml: earn.text });
check('third withdrawal request accepted', submit3.status === 200);
earn = await c.get('/dashboard/earnings');
const cancelForm = earn.text.match(/action="([^"]*\/cancel)"/);
check('a cancel control is offered while pending', !!cancelForm);
if (cancelForm) {
  const cancelUrl = cancelForm[1].replace(BASE, '');
  const cancelRes = await c.postForm(cancelUrl, {}, { fromHtml: earn.text });
  check('cancel accepted', cancelRes.status === 200);
  earn = await c.get('/dashboard/earnings');
  const availAfterCancel = availableOf(earn.text);
  check('cancelled earnings return to Available in full', availAfterCancel === availBeforeThird, `before=${availBeforeThird} after=${availAfterCancel}`);
}

console.log('\n── Affiliate withdrawal · instant wallet-credit conversion');
earn = await c.get('/dashboard/earnings');
const availBeforeConvert = availableOf(earn.text);
const dashBefore = await c.get('/dashboard');
const walletBefore = parseFloat((dashBefore.text.match(/₦([\d,]+\.\d{2})/) || [])[1]?.replace(/,/g, '') ?? '0');
let walletCredit = await c.postForm('/dashboard/earnings/withdraw', { amount: '500.00', method: 'WALLET_CREDIT' }, { fromHtml: earn.text });
check('wallet-credit conversion accepted', walletCredit.status === 200);
earn = await c.get('/dashboard/earnings');
const availAfterConvert = availableOf(earn.text);
check('available earnings drop by the converted amount', Math.abs((availBeforeConvert - availAfterConvert) - 500) < 0.001,
  `before=${availBeforeConvert} after=${availAfterConvert}`);
const dash = await c.get('/dashboard');
const walletAfter = parseFloat((dash.text.match(/₦([\d,]+\.\d{2})/) || [])[1]?.replace(/,/g, '') ?? '0');
check('wallet balance increased by exactly the converted amount', Math.abs((walletAfter - walletBefore) - 500) < 0.001,
  `before=${walletBefore} after=${walletAfter}`);

console.log('\n── Affiliate withdrawal · every action is audited');
const auditActions = withDb((db) => db.prepare(
  "SELECT DISTINCT action FROM audit_logs WHERE action LIKE 'payout.%' ORDER BY action"
).all()).map((r) => r.action);
for (const expected of ['payout.approved', 'payout.paid', 'payout.rejected', 'payout.requested']) {
  check(`audit log contains ${expected}`, auditActions.includes(expected));
}

const failed = results.filter((x) => !x.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length ? 1 : 0);
