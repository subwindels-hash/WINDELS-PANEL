/**
 * Image audit — crawls the site (public + signed in) and reports every <img>
 * whose src does not resolve, so a missing asset can never sit unnoticed on a
 * live page.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/image_audit.mjs --password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (n, d = null) => (argv.indexOf(n) === -1 ? d : argv[argv.indexOf(n) + 1]);
const base = arg('--base', 'http://127.0.0.1:8080');
const password = arg('--password', 'Demo!bc7f5590');

const PUBLIC_PAGES = ['/', '/services', '/pricing', '/about', '/faq', '/contact', '/terms',
  '/privacy', '/refund-policy', '/acceptable-use', '/blog', '/shop', '/login', '/register',
  '/api/docs', '/assistant', '/design-system'];
const CUSTOMER_PAGES = ['/dashboard', '/dashboard/orders', '/dashboard/new-order', '/dashboard/services',
  '/dashboard/add-funds', '/dashboard/profile', '/dashboard/marketplace', '/dashboard/vtu',
  '/dashboard/referrals', '/dashboard/tickets', '/cart'];
const ADMIN_PAGES = ['/admin', '/admin/orders', '/admin/customers', '/admin/media', '/admin/settings'];

function imagesIn(html) {
  const out = new Set();
  for (const tag of html.match(/<img\s[^>]*>/gi) || []) {
    const src = (/src="([^"]+)"/i.exec(tag) || [])[1];
    if (src && !src.startsWith('data:')) out.add(src);
  }
  for (const m of html.matchAll(/url\((['"]?)(\/[^)'"]+\.(?:png|jpe?g|webp|svg|gif))\1\)/gi)) out.add(m[2]);
  return out;
}

const seen = new Map(); // src -> status
const pagesFor = new Map();

async function check(client, pages, label) {
  for (const page of pages) {
    const r = await client.get(page);
    if (r.status >= 400) { console.log(`  page ${r.status} ${page}`); continue; }
    for (const src of imagesIn(r.text)) {
      if (!pagesFor.has(src)) pagesFor.set(src, page);
      if (seen.has(src)) continue;
      const url = src.startsWith('http') ? src : base + (src.startsWith('/') ? src : '/' + src);
      if (!url.startsWith(base) && !url.includes('localhost:8080')) { seen.set(src, 'external'); continue; }
      const res = await client.raw(url.replace(/^https?:\/\/[^/]+/, ''));
      seen.set(src, res.status);
    }
  }
  console.log(`  ${label}: ${pages.length} pages scanned`);
}

const guest = new Client(base);
await check(guest, PUBLIC_PAGES, 'public');

const cust = new Client(base);
await cust.get('/login');
await cust.postForm('/login', { identifier: 'demo@marvy.local', password });
await check(cust, CUSTOMER_PAGES, 'customer');

const admin = new Client(base);
await admin.get('/admin/login');
await admin.postForm('/admin/login', { identifier: 'admin@marvy.local', password });
await check(admin, ADMIN_PAGES, 'admin');

const broken = [...seen.entries()].filter(([, status]) => status !== 'external' && status !== 200);
console.log(`\n${seen.size} distinct image(s) referenced, ${broken.length} broken`);
for (const [src, status] of broken) console.log(`  ${status}  ${src}   (first seen on ${pagesFor.get(src)})`);
process.exit(broken.length ? 1 : 0);
