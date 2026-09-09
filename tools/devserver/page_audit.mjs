/**
 * Page audit — logs in as a customer (and optionally an admin) and GETs every
 * dashboard/admin route, reporting status codes and PHP/CI error markers.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/page_audit.mjs --password <pw> [--admin-password <pw>]
 */
import { Client, errorMarkersIn } from './client.mjs';

const argv = process.argv.slice(2);
function arg(name, def = null) {
  const i = argv.indexOf(name);
  return i === -1 ? def : argv[i + 1];
}
const password = arg('--password', 'Demo!bc7f5590');
const adminPassword = arg('--admin-password', password);
const base = arg('--base', 'http://127.0.0.1:8080');

const CUSTOMER_ROUTES = [
  '/dashboard',
  '/dashboard/orders', '/dashboard/new-order', '/dashboard/mass-order',
  '/dashboard/drip-feed', '/dashboard/subscriptions',
  '/dashboard/services', '/dashboard/favorites',
  '/dashboard/add-funds', '/dashboard/transactions', '/dashboard/wallet/deposits',
  '/dashboard/history',
  '/dashboard/vtu', '/dashboard/vtu/airtime', '/dashboard/vtu/data',
  '/dashboard/vtu/cable', '/dashboard/vtu/electricity', '/dashboard/vtu/history',
  '/dashboard/numbers', '/dashboard/numbers/history',
  '/dashboard/identity', '/dashboard/identity/history',
  '/dashboard/giftcards', '/dashboard/giftcards/history',
  '/dashboard/marketplace', '/dashboard/marketplace/orders',
  '/dashboard/referrals', '/dashboard/earnings',
  '/dashboard/tickets',
  '/dashboard/notifications',
  '/dashboard/downloads',
  '/dashboard/profile', '/dashboard/security', '/dashboard/api',
  '/shop', '/cart', '/checkout',
];

const ADMIN_ROUTES = [
  '/admin', '/admin/dashboard',
  '/admin/orders', '/admin/customers', '/admin/services', '/admin/providers',
  '/admin/payments', '/admin/payouts', '/admin/analytics', '/admin/settings',
  '/admin/staff', '/admin/content', '/admin/media', '/admin/marketplace',
  '/admin/giftcards', '/admin/identity', '/admin/numbers', '/admin/vtu',
  '/admin/currencies', '/admin/catalogue', '/admin/shop', '/admin/tickets',
  '/admin/api-keys', '/admin/affiliates', '/admin/refills', '/admin/logs', '/admin/pages', '/admin/blog',
  '/admin/administrators', '/admin/staff/permissions', '/admin/settings/flags',
  '/admin/email-templates', '/admin/api-logs', '/admin/messages', '/admin/categories',
  '/admin/refunds', '/admin/orders/failed', '/admin/marketplace/categories',
];

let failures = 0;

async function crawl(label, client, routes) {
  console.log(`\n== ${label} ==`);
  for (const route of routes) {
    let r;
    try {
      r = await client.get(route);
    } catch (e) {
      console.log(`  ERR  ---  ${route}  (${e.message})`);
      failures++;
      continue;
    }
    const markers = errorMarkersIn(r.text);
    const redirectedToLogin = /login/.test(r.url);
    const bad = r.status >= 400 || markers.length || redirectedToLogin;
    if (bad) failures++;
    const note = [
      markers.length ? markers.join(', ') : '',
      redirectedToLogin ? 'redirected to login' : '',
      r.url.replace(base, '') !== route ? `-> ${r.url.replace(base, '')}` : '',
    ].filter(Boolean).join(' | ');
    console.log(`  ${bad ? 'FAIL' : 'ok  '} ${r.status}  ${route}${note ? '   ' + note : ''}`);
    if (bad && markers.length) {
      const snippet = r.text.replace(/\s+/g, ' ').slice(0, 400);
      console.log(`        ${snippet}`);
    }
  }
}

const c = new Client(base);
await c.get('/login');
const login = await c.postForm('/login', { identifier: 'demo@marvy.local', password });
if (/login/.test(login.url)) {
  console.log('customer login FAILED', login.status, login.url);
  process.exit(2);
}
await crawl('customer', c, CUSTOMER_ROUTES);

const a = new Client(base);
await a.get('/admin/login');
const alog = await a.postForm('/admin/login', { identifier: 'admin@marvy.local', password: adminPassword });
if (/login/.test(alog.url)) {
  console.log('\nadmin login FAILED', alog.status, alog.url);
  process.exit(2);
}
await crawl('admin', a, ADMIN_ROUTES);

console.log(`\n${failures} failing page(s)`);
process.exit(failures ? 1 : 0);
