/**
 * Commerce end-to-end checks against a running dev server.
 *
 * DEV TOOLING ONLY. Drives the money-and-orders path the way a customer does:
 * register, request a deposit, have an admin approve it, place an order
 * against the seeded catalogue, and open a support ticket. Balances are read
 * back from the rendered pages, so a broken ledger shows up as a wrong number
 * rather than a passing test.
 *
 *   node tools/devserver/commerce_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const BASE = (() => {
  const i = argv.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : argv[i + 1];
})();
const adminPassword = argv[argv.indexOf('--admin-password') + 1];

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const stamp = Date.now().toString().slice(-8);
const user = {
  username: `buy${stamp}`,
  email: `buy${stamp}@example.test`,
  password: 'Commerce!Pass99',
};

/** Pull the first wallet-looking figure out of a rendered page. */
function balanceOf(text) {
  const m = /₦\s*([\d,]+\.\d{2})/.exec(text) || /NGN\s*([\d,]+\.\d{2})/.exec(text);
  return m ? parseFloat(m[1].replace(/,/g, '')) : null;
}

// ---------------------------------------------------------------------------
console.log('── Commerce · account');
const c = new Client(BASE);
await c.get('/register');
await c.postForm('/register', {
  username: user.username,
  email: user.email,
  password: user.password,
  password_confirm: user.password,
  terms: '1',
  accept_terms: '1',
});
await c.get('/login');
const login = await c.postForm('/login', { identifier: user.username, password: user.password });
check('customer signed in', /dashboard/i.test(login.url), `at ${login.url}`);

const dash = await c.get('/dashboard');
const startBalance = balanceOf(dash.text);
check('dashboard shows a wallet balance', startBalance !== null, 'no balance rendered');
check('new account starts at zero', startBalance === 0, `balance was ${startBalance}`);

// ---------------------------------------------------------------------------
console.log('\n── Commerce · add funds');
const addFunds = await c.get('/dashboard/add-funds');
check('add-funds page loads', addFunds.status === 200);

const methodMatch = /name="payment_method"[^>]*value="([^"]+)"/.exec(addFunds.text)
  || /<option value="([^"]+)"/.exec(addFunds.text);
check('at least one payment method is offered', !!methodMatch,
  'no payment method rendered — an operator could not top up');

let depositRef = null;
if (methodMatch) {
  const deposit = await c.postForm(
    '/dashboard/wallet/deposit',
    { payment_method: methodMatch[1], amount: '5000' },
    { fromHtml: addFunds.text }
  );
  check('deposit request accepted', deposit.status === 200 && !/error/i.test(deposit.url),
    `status=${deposit.status} at ${deposit.url}`);

  const deposits = await c.get('/dashboard/wallet/deposits');
  check('deposit appears in the customer history', /PENDING|CREATED|Pending/i.test(deposits.text));
  const ref = /([0-9A-Za-z]{26})/.exec(
    (/\/deposits\/([0-9A-Za-z]{26})/.exec(deposits.text) || [])[1] || deposits.text
  );
  depositRef = ref ? ref[1] : null;
  check('deposit has a public reference', !!depositRef, 'no ULID found in the deposits page');

  const afterRequest = await c.get('/dashboard');
  check(
    'an unconfirmed deposit does NOT credit the wallet',
    balanceOf(afterRequest.text) === 0,
    `balance moved to ${balanceOf(afterRequest.text)} before confirmation`
  );
}

// ---------------------------------------------------------------------------
if (adminPassword && depositRef) {
  console.log('\n── Commerce · admin approves the deposit');
  const a = new Client(BASE);
  await a.get('/admin/login');
  const adminIn = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
  check('admin signed in', /\/admin/.test(adminIn.url) && !/login/.test(adminIn.url));

  const payments = await a.get('/admin/payments');
  check('payments queue loads', payments.status === 200);
  check('the pending deposit is listed', payments.text.includes(depositRef),
    'the customer deposit is not visible to staff');

  const detail = await a.get(`/admin/payments/${depositRef}`);
  check('payment detail loads', detail.status === 200);

  const approved = await a.postForm(`/admin/payments/${depositRef}/approve`, {}, { fromHtml: detail.text });
  check('approval accepted', approved.status === 200, `status=${approved.status}`);

  const afterApproval = await c.get('/dashboard');
  const credited = balanceOf(afterApproval.text);
  check('wallet is credited after approval', credited !== null && credited > 0, `balance=${credited}`);

  // Approving twice must not credit twice.
  const detail2 = await a.get(`/admin/payments/${depositRef}`);
  await a.postForm(`/admin/payments/${depositRef}/approve`, {}, { fromHtml: detail2.text });
  const afterDouble = await c.get('/dashboard');
  check(
    'a second approval does not double-credit',
    balanceOf(afterDouble.text) === credited,
    `balance changed from ${credited} to ${balanceOf(afterDouble.text)}`
  );
}

// ---------------------------------------------------------------------------
console.log('\n── Commerce · place an order');
const newOrder = await c.get('/dashboard/new-order');
check('new-order page loads', newOrder.status === 200);
// The select is name="service" and carries the service's ULID public_id.
const serviceOption = /<option value="([0-9A-Za-z]{20,})"[^>]*data-rate/.exec(newOrder.text);
check('the catalogue offers a service', !!serviceOption, 'no orderable service rendered');

if (serviceOption) {
  const placed = await c.postForm(
    '/dashboard/orders/create',
    {
      service: serviceOption[1],
      link: 'https://instagram.com/marvysocials.e2e',
      quantity: '100',
    },
    { fromHtml: newOrder.text }
  );
  check('order submission is handled', placed.status === 200, `status=${placed.status}`);

  const orders = await c.get('/dashboard/orders');
  check('orders page loads', orders.status === 200);
  check(
    'the order (or a clear refusal) is reflected',
    /PENDING|PROCESSING|COMPLETED|insufficient|balance/i.test(orders.text + placed.text),
    'no order and no explanation'
  );
}

// ---------------------------------------------------------------------------
console.log('\n── Commerce · support ticket');
const tickets = await c.get('/dashboard/tickets');
check('tickets page loads', tickets.status === 200);
const opened = await c.postForm(
  '/dashboard/tickets/create',
  {
    subject: 'End-to-end verification ticket',
    category: 'GENERAL',
    priority: 'NORMAL',
    message: 'Automated end-to-end check of the support flow.',
  },
  { fromHtml: tickets.text }
);
check('ticket submission is handled', opened.status === 200, `status=${opened.status}`);
const ticketList = await c.get('/dashboard/tickets');
check(
  'the ticket appears in the customer history',
  /End-to-end verification ticket/.test(ticketList.text),
  'ticket not listed after creation'
);

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
}
process.exit(failed.length ? 1 : 0);
