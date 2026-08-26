/**
 * Route smoke test against a running dev server.
 *
 * DEV TOOLING ONLY. Walks a list of URLs, reports the status code and flags
 * anything that looks like a rendered CodeIgniter error page even when the
 * status is 200.
 *
 *   node tools/devserver/smoke.mjs [--base http://127.0.0.1:8080] [--json]
 */
const args = process.argv.slice(2);
const BASE = (() => {
  const i = args.indexOf('--base');
  return i === -1 ? 'http://127.0.0.1:8080' : args[i + 1];
})();
const asJson = args.includes('--json');

const PUBLIC_ROUTES = [
  '/',
  '/services',
  '/pricing',
  '/about',
  '/faq',
  '/contact',
  '/terms',
  '/privacy',
  '/refund-policy',
  '/acceptable-use',
  '/blog',
  '/login',
  '/register',
  '/forgot-password',
  '/verify-email',
  '/health',
  '/health/live',
  '/health/ready',
  '/sitemap.xml',
  '/robots.txt',
  '/admin/login',
  '/api/docs',
  '/assistant',
  '/csrf',
];

const ERROR_MARKERS = [
  'A Database Error Occurred',
  'An uncaught Exception',
  'A PHP Error was encountered',
  'Fatal error',
  'Parse error',
  'Undefined constant',
  'Call to undefined',
  'An unexpected error occurred',
  'Unable to locate the model',
  'Unable to load the requested file',
];

async function check(pathname) {
  const url = BASE + pathname;
  const started = Date.now();
  try {
    const res = await fetch(url, { redirect: 'manual' });
    const text = await res.text();
    const markers = ERROR_MARKERS.filter((m) => text.includes(m));
    return {
      path: pathname,
      status: res.status,
      ms: Date.now() - started,
      bytes: text.length,
      location: res.headers.get('location') || undefined,
      errors: markers,
      title: (/<title>([^<]*)<\/title>/i.exec(text) || [])[1],
    };
  } catch (err) {
    return { path: pathname, status: 0, ms: Date.now() - started, bytes: 0, errors: [String(err.message)] };
  }
}

const results = [];
for (const route of PUBLIC_ROUTES) results.push(await check(route));

if (asJson) {
  console.log(JSON.stringify(results, null, 2));
} else {
  let bad = 0;
  for (const r of results) {
    const ok = r.status > 0 && r.status < 500 && !r.errors.length;
    if (!ok) bad++;
    const flag = ok ? '  ok ' : 'FAIL ';
    console.log(
      `${flag} ${String(r.status).padEnd(3)} ${String(r.ms + 'ms').padStart(7)} ${String(r.bytes).padStart(7)}b  ${r.path}` +
        (r.location ? ` → ${r.location}` : '') +
        (r.errors.length ? `\n        ${r.errors.join(' | ')}` : '')
    );
  }
  console.log(`\n${results.length - bad}/${results.length} routes healthy`);
  process.exit(bad ? 1 : 0);
}
