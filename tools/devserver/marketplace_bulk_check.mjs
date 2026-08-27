/**
 * Admin marketplace bulk actions + per-row feature toggle — end-to-end check.
 *
 * DEV TOOLING ONLY. Proves the admin gap named in the follow-up audit: there
 * was no way to feature/unfeature a listing after it was created (only at
 * create time), and no bulk publish/unpublish/archive/feature/unfeature
 * across several checked listings at once. Each bulk row still goes through
 * MarketplaceService::moderate_listing()/set_featured() individually, so
 * every existing rule and audit entry still applies per listing.
 *
 *   node tools/devserver/marketplace_bulk_check.mjs --admin-password <pw>
 */
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const adminPassword = process.env.DEMO_PASSWORD || arg('--admin-password', null);
if (!adminPassword) {
  console.error('Usage: node tools/devserver/marketplace_bulk_check.mjs --admin-password <pw>');
  process.exit(2);
}

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}

const stamp = Date.now().toString().slice(-8);
const a = new Client(BASE);
await a.get('/admin/login');
const login = await a.postForm('/admin/login', { identifier: 'admin', password: adminPassword });
check('admin signed in', /\/admin/.test(login.url) && !/login/.test(login.url));

function listingIdByTitle(html, title) {
  const rowStart = html.indexOf(`<strong>${title}</strong>`);
  if (rowStart === -1) return null;
  const idMatch = /class="mono text-xs muted">([A-Za-z0-9]+)</.exec(html.slice(rowStart, rowStart + 400));
  return idMatch ? idMatch[1] : null;
}
function statusOfRow(html, publicId) {
  const idx = html.indexOf(publicId);
  if (idx === -1) return null;
  const badges = [...html.slice(idx, idx + 700).matchAll(/badge-default">([A-Z]+)</g)];
  // Row order: [Type badge, Status badge]
  return badges.length >= 2 ? badges[1][1] : null;
}
function isFeaturedRow(html, title) {
  // The Featured badge sits inside the same <strong>title</strong> block,
  // right after the title and before the listing's public id — not before
  // the title, so the window must look forward from the title match.
  const rowStart = html.indexOf(`<strong>${title}</strong>`);
  if (rowStart === -1) return false;
  return html.slice(rowStart, rowStart + 200).includes('Featured');
}

async function createListing(title, extra = {}) {
  const form = await a.get('/admin/marketplace/listings/new');
  await a.postForm('/admin/marketplace/listings/save', {
    title, category: 'DIGITAL_GOODS',
    description: 'A listing created for the bulk-actions end-to-end test.',
    price: '1500', stock: '', delivery_days: '1', product_type: 'DIGITAL',
    ...extra,
  }, { fromHtml: form.text });
  const listPage = await a.get('/admin/marketplace?tab=listings');
  return { id: listingIdByTitle(listPage.text, title), page: listPage };
}

console.log('\n── Feature toggle: a single listing can be featured/unfeatured after creation');
const t1 = `Bulk A ${stamp}`;
let { id: id1 } = await createListing(t1);
check('listing A created', !!id1);

let listPage = await a.get('/admin/marketplace?tab=listings');
check('not featured yet', !isFeaturedRow(listPage.text, t1));

// use the row-level feature form directly from the listings table
const featureOn = await a.postForm(`/admin/marketplace/listings/${id1}/feature`, { featured: '1' },
  { fromHtml: listPage.text });
check('feature toggle succeeds', featureOn.status === 200);
listPage = await a.get('/admin/marketplace?tab=listings');
check('listing now shows the Featured badge', isFeaturedRow(listPage.text, t1));

const featureOff = await a.postForm(`/admin/marketplace/listings/${id1}/feature`, { featured: '0' },
  { fromHtml: listPage.text });
check('unfeature toggle succeeds', featureOff.status === 200);
listPage = await a.get('/admin/marketplace?tab=listings');
check('listing no longer shows the Featured badge', !isFeaturedRow(listPage.text, t1));

console.log('\n── Bulk actions: publish/unpublish/archive/feature apply to every checked listing');
const t2 = `Bulk B ${stamp}`;
const t3 = `Bulk C ${stamp}`;
const { id: id2 } = await createListing(t2);
const { id: id3 } = await createListing(t3);
check('listing B created', !!id2);
check('listing C created', !!id3);

listPage = await a.get('/admin/marketplace?tab=listings');
check('both start ACTIVE', statusOfRow(listPage.text, id2) === 'ACTIVE' && statusOfRow(listPage.text, id3) === 'ACTIVE');

let bulkUnpublish = await a.postForm('/admin/marketplace/listings/bulk',
  { bulk_action: 'unpublish', 'listing_ids[]': [id2, id3] }, { fromHtml: listPage.text });
check('bulk unpublish request succeeds', bulkUnpublish.status === 200);
// Flash data is one-time — read it off the page that followed the redirect,
// before a later separate GET consumes and clears it.
check('a summary flash message is shown', /2 listing\(s\) updated/.test(bulkUnpublish.text));
listPage = await a.get('/admin/marketplace?tab=listings');
check('both are now PAUSED', statusOfRow(listPage.text, id2) === 'PAUSED' && statusOfRow(listPage.text, id3) === 'PAUSED');

let bulkFeature = await a.postForm('/admin/marketplace/listings/bulk',
  { bulk_action: 'feature', 'listing_ids[]': [id2, id3] }, { fromHtml: listPage.text });
check('bulk feature request succeeds', bulkFeature.status === 200);
listPage = await a.get('/admin/marketplace?tab=listings');
check('both listings are now featured', isFeaturedRow(listPage.text, t2) && isFeaturedRow(listPage.text, t3));

let bulkArchive = await a.postForm('/admin/marketplace/listings/bulk',
  { bulk_action: 'archive', 'listing_ids[]': [id2, id3] }, { fromHtml: listPage.text });
check('bulk archive request succeeds', bulkArchive.status === 200);
listPage = await a.get('/admin/marketplace?tab=listings');
check('both are now ARCHIVED', statusOfRow(listPage.text, id2) === 'ARCHIVED' && statusOfRow(listPage.text, id3) === 'ARCHIVED');

console.log('\n── Bulk actions: input validation');
const emptySelection = await a.postForm('/admin/marketplace/listings/bulk',
  { bulk_action: 'archive' }, { fromHtml: listPage.text });
check('selecting no listings is refused with a clear message',
  /Select at least one listing/.test(emptySelection.text));

const badAction = await a.postForm('/admin/marketplace/listings/bulk',
  { bulk_action: 'delete-everything', 'listing_ids[]': [id2] }, { fromHtml: listPage.text });
check('an unsupported bulk action is refused, not silently ignored',
  /Unsupported bulk action/.test(badAction.text));

console.log('\n── Every bulk-applied change is individually audited');
const auditPage = await a.get('/admin/audit-logs');
check('the audit log records the featured toggles (not a single opaque "bulk" entry)',
  (auditPage.text.match(/marketplace\.listing\.feature_toggled/g) || []).length >= 2);
check('the audit log records the moderation status changes from the bulk run',
  (auditPage.text.match(/marketplace\.listing\.moderate/g) || []).length >= 2);

const passed = results.filter(r => r.ok).length;
console.log(`\n${passed}/${results.length} checks passed`);
if (passed !== results.length) {
  console.log('\nFailures:');
  for (const r of results) if (!r.ok) console.log(`  ${r.label} — ${r.detail}`);
  process.exit(1);
}
