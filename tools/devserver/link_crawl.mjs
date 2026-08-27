/**
 * Link crawl — signs in and follows every internal link reachable from the
 * dashboard (or the admin console), reporting dead links and pages that
 * render a PHP/CI error.
 *
 * DEV TOOLING ONLY.
 *
 *   node tools/devserver/link_crawl.mjs --password <pw> [--admin]
 */
import { Client, errorMarkersIn } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (n, d = null) => (argv.indexOf(n) === -1 ? d : argv[argv.indexOf(n) + 1]);
const base = arg('--base', 'http://127.0.0.1:8080');
const password = arg('--password', 'Demo!bc7f5590');
const asAdmin = argv.includes('--admin');
const maxPages = parseInt(arg('--max', '160'), 10);

// Links that log the crawler out or leave the app.
const SKIP = /^(mailto:|tel:|javascript:|#)|\/(logout|impersonation)/i;

function linksIn(html) {
  const out = new Set();
  for (const tag of html.match(/<a\s[^>]*href="[^"]*"/gi) || []) {
    const href = (/href="([^"]*)"/i.exec(tag) || [])[1];
    if (!href || SKIP.test(href)) continue;
    let url;
    try { url = new URL(href, base); } catch { continue; }
    if (url.origin !== new URL(base).origin && !/localhost:8080/.test(url.host)) continue;
    out.add(url.pathname + url.search);
  }
  return out;
}

const c = new Client(base);
await c.get(asAdmin ? '/admin/login' : '/login');
const login = await c.postForm(asAdmin ? '/admin/login' : '/login', {
  identifier: asAdmin ? 'admin@marvy.local' : 'demo@marvy.local',
  password,
});
if (/login/.test(login.url)) {
  console.log('login FAILED', login.status, login.url);
  process.exit(2);
}

const start = asAdmin ? '/admin' : '/dashboard';
const queue = [start];
const seen = new Set([start]);
const failures = [];
let visited = 0;

while (queue.length && visited < maxPages) {
  const route = queue.shift();
  visited++;
  let r;
  try {
    r = await c.get(route);
  } catch (e) {
    failures.push(`${route} — request failed: ${e.message}`);
    continue;
  }
  const markers = errorMarkersIn(r.text);
  const loggedOut = /\/login/.test(r.url);
  if (r.status >= 400) failures.push(`${route} — HTTP ${r.status}`);
  else if (markers.length) failures.push(`${route} — ${markers.join(', ')}`);
  else if (loggedOut) failures.push(`${route} — bounced to login`);
  const flag = r.status >= 400 || markers.length || loggedOut ? 'FAIL' : 'ok  ';
  console.log(`  ${flag} ${r.status}  ${route}`);
  if (r.status >= 400 || loggedOut) continue;
  for (const link of linksIn(r.text)) {
    if (seen.has(link)) continue;
    // Stay inside the area we are auditing.
    const inScope = asAdmin ? link.startsWith('/admin') : !link.startsWith('/admin');
    if (!inScope) continue;
    seen.add(link);
    queue.push(link);
  }
}

console.log(`\nvisited ${visited} page(s), ${failures.length} problem(s)`);
for (const f of failures) console.log('  - ' + f);
process.exit(failures.length ? 1 : 0);
