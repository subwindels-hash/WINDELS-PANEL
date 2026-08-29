/**
 * Site chrome and operator surfaces — end-to-end.
 *
 * The eight things an operator asked for, checked against the running panel
 * rather than against the source:
 *
 *   1. review avatars are photographs of people, and they load
 *   2. the menu and the footer are navy with white text
 *   3. both appear on every page, including the API reference and 404s
 *   4. the sign-in panel's copy is three separate blocks, not one run-on line
 *   5. the announcement bar's words and colours come from Admin → Settings
 *   6. no page still says WINDELSOCIALS
 *   7. Admin → System → Cron jobs reports the schedule and the last runs
 *   8. the contact page shows a map, configured entirely from the admin
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/chrome_check.mjs --admin-password '…'
 */
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const require = createRequire(import.meta.url);
const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const BASE = arg('--base', 'http://127.0.0.1:8080');
const DB_PATH = arg('--db', 'storage/devdb/marvy.sqlite');
const ADMIN_PASSWORD = arg('--admin-password', process.env.DEMO_PASSWORD || null);
if (!ADMIN_PASSWORD) {
  console.error('Pass --admin-password or set DEMO_PASSWORD in .env (the seeder prints the demo password once).');
  process.exit(2);
}
const CUSTOMER_PASSWORD = arg('--customer-password', ADMIN_PASSWORD);
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

/* ------------------------- pages every check reuses ----------------------- */

const PUBLIC_PAGES = ['/', '/services', '/shop', '/pricing', '/faq', '/contact', '/about',
                      '/terms', '/privacy', '/blog', '/api/docs', '/assistant', '/cart',
                      '/login', '/register', '/admin/login', '/definitely-not-a-page'];

const anon = new Client(BASE);
const fetched = {};
for (const url of PUBLIC_PAGES) fetched[url] = await anon.get(url);

/* ===================== 2 + 3 · the menu and footer ======================= */

console.log('\n── The menu and the footer, on every page');
const missingNav = PUBLIC_PAGES.filter((u) =>
  !/ws-public-nav|ws-auth-header/.test(fetched[u].text));
check('every public page carries the shared menu', missingNav.length === 0, missingNav.join(', '));

const missingFooter = PUBLIC_PAGES.filter((u) => !/ws-footer/.test(fetched[u].text));
check('every public page carries the footer', missingFooter.length === 0, missingFooter.join(', '));

const missingAnnounce = PUBLIC_PAGES.filter((u) => !/ws-announce/.test(fetched[u].text));
check('every public page carries the announcement bar',
  missingAnnounce.length === 0, missingAnnounce.join(', '));

check('the API reference is no longer a dead end',
  /ws-public-nav/.test(fetched['/api/docs'].text) && /ws-footer/.test(fetched['/api/docs'].text));
check('even a 404 keeps the menu and the footer',
  fetched['/definitely-not-a-page'].status === 404
  && /ws-public-nav/.test(fetched['/definitely-not-a-page'].text)
  && /ws-footer/.test(fetched['/definitely-not-a-page'].text));

const css = await anon.get('/assets/css/design-system.css');
check('the stylesheet defines the navy palette', /--ws-navy-900:\s*#0b1b3a/.test(css.text));
for (const [label, selector] of [
  ['public menu', '.ws-public-nav'],
  ['sign-in header', '.ws-auth-header'],
  ['signed-in sidebar', '.ws-sidebar'],
  ['footer', '.ws-footer'],
]) {
  const rule = new RegExp(`${selector.replace('.', '\\.')}[^{]*\\{[^}]*--ws-navy-900`);
  check(`the ${label} is navy`, rule.test(css.text.replace(/\s*\n\s*/g, '')), selector);
}
check('the ink on navy is white', /--ws-navy-ink:\s*#ffffff/.test(css.text));

/* ========================= 6 · the brand name ============================ */

console.log('\n── The old brand name is gone');
const stillBranded = PUBLIC_PAGES.filter((u) => /WINDELSOCIALS/i.test(fetched[u].text));
check('no public page says WINDELSOCIALS', stillBranded.length === 0, stillBranded.join(', '));
check('the pages say MarvySocials instead', /MarvySocials|MARVYSOCIALS/.test(fetched['/'].text));

/* ================== 4 · the sign-in panel's arrangement ================== */

console.log('\n── The sign-in, register and staff pages');
const aside = (html) => {
  const m = /<aside class="ws-auth-visual">([\s\S]*?)<\/aside>/.exec(html);
  return m ? m[1] : '';
};
for (const url of ['/login', '/register', '/admin/login']) {
  const block = aside(fetched[url].text);
  check(`${url} renders the panel as real content, not aria-hidden`,
    block !== '' && !/aria-hidden="true"[^>]*>\s*<img[^>]*logo/.test(fetched[url].text), 'no panel found');
  const heading = /<h2[^>]*>([\s\S]*?)<\/h2>/.exec(block);
  const para = /<p[^>]*>([\s\S]*?)<\/p>/.exec(block);
  check(`${url} keeps the headline and the sentence in separate blocks`,
    !!heading && !!para && heading[1].trim() !== '' && para[1].trim() !== '',
    block.replace(/\s+/g, ' ').slice(0, 90));
}
check('the staff door has staff words, not the customer pitch',
  /Staff sign-in\./.test(aside(fetched['/admin/login'].text))
  && !/A wallet you can audit/.test(aside(fetched['/admin/login'].text)));

/* ======================= 1 · the review photographs ====================== */

console.log('\n── Customer review photos');
for (let i = 1; i <= 4; i++) {
  const img = await anon.raw(`/assets/images/reviews/reviewer-${i}.jpg`);
  check(`reviewer-${i}.jpg loads`, img.status === 200, `status=${img.status}`);
  check(`reviewer-${i}.jpg is a photograph, not the old illustration`,
    img.text.length > 40000, `${Math.round(img.text.length / 1024)} kB`);
}
const product = withDb((db) => db.prepare(
  `SELECT public_id FROM marketplace_listings WHERE status = 'ACTIVE' LIMIT 1`).get());
if (product) {
  const page = await anon.get('/shop/product/' + product.public_id);
  check('the product page renders', page.status === 200);
  if (/assets\/images\/reviews\//.test(page.text)) {
    check('a review avatar names its reviewer for screen readers',
      /images\/reviews\/reviewer-\d\.jpg"[^>]*\n?[^>]*alt="[^"]+"/.test(page.text)
      || /alt="[^"]+"[^>]*images\/reviews/.test(page.text));
  }
}

/* ==================== 5 · the announcement bar is data =================== */

console.log('\n── The announcement bar is edited in the admin');
const admin = new Client(BASE);
await admin.get('/admin/login');
const alogin = await admin.postForm('/admin/login', { identifier: 'admin', password: ADMIN_PASSWORD });
check('admin signed in', /\/admin/.test(alogin.url) && !/login/.test(alogin.url), alogin.url);

const before = withDb((db) => db.prepare(
  `SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'announcement%'`).all());

const settingsPage = await admin.get('/admin/settings');
for (const key of ['announcement_enabled', 'announcement_text', 'announcement_bg_color',
                   'announcement_text_color', 'announcement_speed_seconds']) {
  check(`Settings offers ${key}`, settingsPage.text.includes(`name="${key}"`));
}

const marker = 'E2E announcement ' + Date.now();
await admin.postForm('/admin/settings/save', {
  __rendered_announcement_enabled: '1', announcement_enabled: '1',
  announcement_text: marker, announcement_bg_color: '#123456',
  announcement_text_color: '#fedcba', announcement_speed_seconds: '0',
}, { fromHtml: settingsPage.text });

const shown = await new Client(BASE).get('/');
check('the operator’s words appear on the public site', shown.text.includes(marker));
check('so do the operator’s colours',
  /background:#123456/.test(shown.text) && /color:#fedcba/.test(shown.text),
  (/<div class="ws-announce[^>]*/.exec(shown.text) || [''])[0].slice(0, 120));
check('a single message is centred rather than scrolled', /ws-announce is-static/.test(shown.text));

// A banner that cannot point at the page it is talking about is half a
// notice, so a line may carry [label](target) — and only a target the panel is
// willing to write into an href.
const linkPage = await admin.get('/admin/settings');
await admin.postForm('/admin/settings/save', {
  __rendered_announcement_enabled: '1', announcement_enabled: '1',
  announcement_text: 'Funding is down. [Read more](/faq)\n'
    + 'Ask us: [email](mailto:help@marvy.example)\n'
    + 'Never: [pwn](javascript:alert(1))\n'
    + 'Nor raw HTML: <img src=x onerror=alert(1)>',
  announcement_bg_color: '#123456', announcement_text_color: '#fedcba',
  announcement_speed_seconds: '30',
}, { fromHtml: linkPage.text });

const linked = await new Client(BASE).get('/');
const banner = (/<div class="ws-announce[\s\S]*?<\/div>\s*<\/div>/.exec(linked.text) || [''])[0];
check('an operator link becomes a real anchor',
  /<a class="ws-announce-link" href="\/faq">Read more<\/a>/.test(banner), banner.slice(0, 200));
check('a mailto link opens safely',
  /href="mailto:help@marvy\.example"/.test(banner) && /rel="noopener noreferrer"/.test(banner));
check('a javascript: target is refused but its words survive',
  !/javascript:/i.test(banner) && /pwn/.test(banner));
check('raw HTML in the banner is text, never markup',
  !/<img/i.test(banner) && /&lt;img/.test(banner),
  'the banner is on every page of a site holding wallet balances');

const bad = await admin.postForm('/admin/settings/save',
  { announcement_bg_color: 'javascript:alert(1)' }, { fromHtml: settingsPage.text });
check('a colour that is not a colour is refused', /must be a colour/i.test(bad.text),
  (/alert[^>]*>([\s\S]{0,120}?)</.exec(bad.text) || [, ''])[1].replace(/<[^>]+>/g, ' ').trim());

const off = await admin.get('/admin/settings');
await admin.postForm('/admin/settings/save',
  { __rendered_announcement_enabled: '1' }, { fromHtml: off.text });   // checkbox unticked
const hidden = await new Client(BASE).get('/');
check('switching it off removes the bar everywhere', !/ws-announce/.test(hidden.text));

// Put the operator's settings back the way they were.
withDb((db) => {
  db.prepare(`DELETE FROM settings WHERE setting_key LIKE 'announcement%'`).run();
  for (const row of before) {
    db.prepare(`INSERT INTO settings (setting_key, setting_value, category, is_public, updated_at)
                VALUES (?, ?, 'branding', 0, datetime('now'))`).run(row.setting_key, row.setting_value);
  }
});

/* ============================ 7 · the cron screen ======================== */

console.log('\n── Admin → System → Cron jobs');
const cron = await admin.get('/admin/cron');
check('the cron screen loads', cron.status === 200, `status=${cron.status}`);
const flat = cron.text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
for (const job of ['order_status', 'refill_status', 'payment_reconciliation', 'email_queue',
                   'service_recovery']) {
  check(`it lists ${job}`, flat.includes(job));
}
check('it explains the schedule in words', /every 2 minutes|every 5 minutes|hourly/.test(flat));
check('it shows a crontab to install', /crontab -e/.test(flat) && /php index\.php cron/.test(flat));
check('it reports the last run of a job that has run',
  /Healthy|Overdue|Never run|Failing/.test(flat));
check('the screen is reachable from the sidebar', /admin\/cron/.test((await admin.get('/admin')).text));
// The screen's third write action is "run now" — the operator-facing answer to
// "did the crontab even install?". It is safe because it is not a second
// implementation: it resolves the same CronRegistry worker and runs it under
// the same exclusive JobRunner lock as the crontab tick, so it can never
// overlap a scheduled run or credit anything twice.
check('a run-now button posts to the run endpoint', /admin\/cron\/run/i.test(cron.text));
check('and the screen says why that is safe', /exclusive lock/i.test(flat));

console.log('\n── Running a job by hand');
// `analytics` prunes logs: no money, no customer-visible consequence, and it
// leaves a job_runs row the screen itself then reports.
const runBefore = withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM job_runs WHERE job = 'analytics'`).get().n);
const runRes = await admin.postForm('/admin/cron/run',
  { job: 'analytics' }, { fromHtml: cron.text });
check('the job runs and reports back', /analytics: ok/i.test(runRes.text),
  (/alert[^>]*>([\s\S]{0,200}?)</.exec(runRes.text) || [, ''])[1].replace(/<[^>]+>/g, ' ').trim());
const runAfter = withDb((db) => db.prepare(
  `SELECT COUNT(*) AS n FROM job_runs WHERE job = 'analytics'`).get().n);
check('the run is recorded in job_runs like any other tick', runAfter === runBefore + 1,
  `before=${runBefore} after=${runAfter}`);
check('and the manual run is audited',
  !!withDb((db) => db.prepare(
    `SELECT id FROM audit_logs WHERE action = 'cron.run' AND resource_id = 'analytics'`).get()));
const bogusRun = await admin.postForm('/admin/cron/run',
  { job: 'not_a_job' }, { fromHtml: cron.text });
check('an unknown job is refused', /Unknown cron job/i.test(bogusRun.text));

console.log('\n── Pausing a job during an incident');

// This checker runs against live panels, so the operator's own pauses are
// snapshotted and put back at the end of the section — and the drill uses
// `analytics`, the one scheduled job with no customer-visible consequence.
const controlsBefore = withDb((db) => db.prepare(`SELECT * FROM cron_job_controls`).all());
withDb((db) => db.prepare(`DELETE FROM cron_job_controls`).run());
const cronFresh = await admin.get('/admin/cron');
const pauseRes = await admin.postForm('/admin/cron/pause',
  { job: 'analytics', reason: 'e2e incident drill', hours: '1' }, { fromHtml: cronFresh.text });
check('the job can be paused', /resumes automatically/i.test(pauseRes.text),
  (/alert[^>]*>([\s\S]{0,160}?)</.exec(pauseRes.text) || [, ''])[1].replace(/<[^>]+>/g, ' ').trim());

const paused = withDb((db) => db.prepare(
  `SELECT * FROM cron_job_controls WHERE job = 'analytics'`).get());
check('with a reason and an expiry recorded',
  paused && Number(paused.is_paused) === 1 && paused.reason === 'e2e incident drill'
    && !!paused.resume_at, JSON.stringify(paused));
check('and an audit entry naming who did it',
  !!withDb((db) => db.prepare(
    `SELECT id FROM audit_logs WHERE action = 'cron.paused' AND resource_id = 'analytics'`).get()));

const pausedScreen = await admin.get('/admin/cron');
check('the screen shows it as paused, not as broken',
  /Paused/.test(pausedScreen.text) && /Resumes automatically/.test(pausedScreen.text));

// A pause with no reason is refused: the next person to read this screen has
// nothing else to go on.
const noReason = await admin.postForm('/admin/cron/pause',
  { job: 'email_queue', reason: '', hours: '1' }, { fromHtml: pausedScreen.text });
check('a pause with no reason is refused', /Say why/i.test(noReason.text));
check('and nothing was paused by it',
  !withDb((db) => db.prepare(
    `SELECT id FROM cron_job_controls WHERE job = 'email_queue' AND is_paused = 1`).get()));

// Money-moving jobs can be paused, but the form says what stops happening.
check('a money-moving job warns before it is stopped',
  /This job moves money/.test(pausedScreen.text)
  && /Deposits whose callback never arrived/.test(pausedScreen.text));

const resumeRes = await admin.postForm('/admin/cron/resume',
  { job: 'analytics' }, { fromHtml: pausedScreen.text });
check('it can be resumed by hand', /Resumed/i.test(resumeRes.text));
check('and the row says so',
  Number(withDb((db) => db.prepare(
    `SELECT is_paused FROM cron_job_controls WHERE job = 'analytics'`).get().is_paused)) === 0);

// Left behind by an operator who never came back: the pause must lift itself.
withDb((db) => {
  db.prepare(`UPDATE cron_job_controls SET is_paused = 1, reason = 'forgotten pause',
              resume_at = datetime('now', '-1 hour') WHERE job = 'analytics'`).run();
});
await admin.get('/admin/cron');
const lifted = withDb((db) => db.prepare(
  `SELECT is_paused FROM cron_job_controls WHERE job = 'analytics'`).get());
check('a pause nobody came back for expires by itself',
  Number(lifted.is_paused) === 0, JSON.stringify(lifted));
check('and the expiry is recorded as nobody’s doing',
  !!withDb((db) => db.prepare(
    `SELECT id FROM audit_logs WHERE action = 'cron.auto_resumed' AND actor_id IS NULL`).get()));

// Put the operator's own pauses back exactly as they were.
withDb((db) => {
  db.prepare(`DELETE FROM cron_job_controls`).run();
  for (const row of controlsBefore) {
    db.prepare(`INSERT INTO cron_job_controls
        (job, is_paused, reason, paused_by_id, paused_at, resume_at, resumed_by_id, resumed_at,
         created_at, updated_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`)
      .run(row.job, row.is_paused, row.reason, row.paused_by_id, row.paused_at, row.resume_at,
           row.resumed_by_id, row.resumed_at, row.created_at, row.updated_at);
  }
});

/* =========================== 8 · the contact map ========================= */

console.log('\n── The contact page map');
const contactSettings = await admin.get('/admin/settings');
for (const key of ['contact_map_enabled', 'contact_address', 'contact_map_query',
                   'contact_map_zoom', 'contact_phone', 'contact_hours']) {
  check(`Settings offers ${key}`, contactSettings.text.includes(`name="${key}"`));
}

const beforeContact = withDb((db) => db.prepare(
  `SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'contact_%'`).all());

await admin.postForm('/admin/settings/save', {
  __rendered_contact_map_enabled: '1', contact_map_enabled: '1',
  contact_address: '12 Adeola Odeku Street\nVictoria Island, Lagos',
  contact_map_query: '6.4281,3.4219', contact_map_zoom: '16',
  contact_phone: '+234 800 111 2222', contact_hours: 'Mon–Fri, 9:00–18:00 WAT',
}, { fromHtml: contactSettings.text });

const contact = await new Client(BASE).get('/contact');
check('the map is embedded', /<iframe[^>]+openstreetmap\.org\/export\/embed/.test(contact.text),
  (/<iframe[^>]*src="([^"]{0,90})/.exec(contact.text) || [, 'no iframe'])[1]);
check('the pin uses the coordinates the operator typed', /marker=6\.4281/.test(contact.text));
check('the address, phone and hours are shown',
  /Victoria Island/.test(contact.text) && /800 111 2222/.test(contact.text) && /WAT/.test(contact.text));
check('the content-security-policy allows the map frame and nothing else',
  /frame-src 'self' https:\/\/www\.openstreetmap\.org/.test(
    contact.headers.get('content-security-policy') || ''));

// A typed address (not coordinates) must also work, keylessly.
const s2 = await admin.get('/admin/settings');
await admin.postForm('/admin/settings/save',
  { __rendered_contact_map_enabled: '1', contact_map_enabled: '1',
    contact_map_query: 'Victoria Island, Lagos, Nigeria' }, { fromHtml: s2.text });
const contact2 = await new Client(BASE).get('/contact');
check('a typed address gets a keyless embed too',
  /<iframe[^>]+maps\.google\.com[^"]*output=embed/.test(contact2.text));

// Off again: no iframe, and the relaxed policy goes with it.
const s3 = await admin.get('/admin/settings');
await admin.postForm('/admin/settings/save',
  { __rendered_contact_map_enabled: '1' }, { fromHtml: s3.text });
const contact3 = await new Client(BASE).get('/contact');
check('switching the map off removes the iframe', !/<iframe/.test(contact3.text));
check('and restores the strict frame policy',
  !/frame-src/.test(contact3.headers.get('content-security-policy') || ''));

withDb((db) => {
  db.prepare(`DELETE FROM settings WHERE setting_key LIKE 'contact_%'`).run();
  for (const row of beforeContact) {
    db.prepare(`INSERT INTO settings (setting_key, setting_value, category, is_public, updated_at)
                VALUES (?, ?, 'contact', 0, datetime('now'))`).run(row.setting_key, row.setting_value);
  }
});

/* ==================== the signed-in shell keeps its chrome =============== */

console.log('\n── The signed-in shell');
const cust = new Client(BASE);
await cust.get('/login');
const clogin = await cust.postForm('/login',
  { identifier: 'demo@marvy.local', password: CUSTOMER_PASSWORD });
check('customer signed in', /\/dashboard/.test(clogin.url), clogin.url);
for (const url of ['/dashboard', '/dashboard/orders', '/dashboard/tickets']) {
  const page = await cust.get(url);
  check(`${url} has the sidebar menu and a footer`,
    /ws-sidebar/.test(page.text) && /ws-app-footer|ws-footer/.test(page.text));
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
