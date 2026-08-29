/**
 * MarvySocials dev application server.
 *
 * DEV TOOLING ONLY. Production runs PHP-FPM behind Nginx (see docker/), and
 * nothing in application/ knows this file exists.
 *
 * It boots the *real* CodeIgniter application through a PHP runtime and serves
 * it over HTTP, so the whole stack — routing, sessions, auth, the database,
 * order and payment flows, the admin panel — can be exercised end to end on a
 * machine where PHP-FPM cannot be installed.
 *
 *   node tools/devserver/server.mjs --port 8080
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadNodeRuntime, createNodeFsMountHandler, withNetworking } from '@php-wasm/node';
import { PHPRequestHandler } from '@php-wasm/universal';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function parseArgs(argv) {
  const out = { port: 8080, host: '0.0.0.0', workers: 1, root: null, maxRequests: 400 };
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--port') out.port = parseInt(argv[++i], 10);
    else if (argv[i] === '--host') out.host = argv[++i];
    else if (argv[i] === '--workers') out.workers = parseInt(argv[++i], 10);
    // Serve a different document root — used to boot the *extracted
    // deployment package* as a brand new cPanel account would, rather than
    // the working tree it was built from.
    else if (argv[i] === '--root') out.root = argv[++i];
    // Recycle the PHP pool after this many requests (0 = never), the same
    // discipline PHP-FPM's pm.max_requests exists for.
    else if (argv[i] === '--max-requests') out.maxRequests = parseInt(argv[++i], 10);
  }
  return out;
}
const args = parseArgs(process.argv);
const ROOT = args.root ? path.resolve(args.root) : path.resolve(__dirname, '../..');

const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.map': 'application/json',
  '.pdf': 'application/pdf',
};

let processIdSeq = 100;

/**
 * The paths a correctly configured host never serves.
 *
 * Mirrors the shipped `.htaccess` (dotfiles, *.sql, *.zip) and the nginx
 * configs (application/, system/, vendor/, storage/). Kept as one function so
 * the rule is stated once and can be read next to the config it mirrors.
 */
function isDenied(pathname) {
  const clean = pathname.replace(/\\/g, '/');
  if (/(^|\/)\.[^/]/.test(clean)) return true;               // .env, .git, .htpasswd
  if (/\.(sql|sqlite|zip|gz|log)$/i.test(clean)) return true;  // dumps and archives
  // Same list as the shipped .htaccess and the nginx configs — docs/ holds
  // the security audit trail, database/ the schema, cron/ the worker entry
  // points; none of them is a document the panel should publish.
  if (/^\/(application|system|vendor|storage|tools|tests|docs|database|cron)(\/|$)/i.test(clean)) return true;
  return false;
}

/**
 * Per-request cookie passthrough.
 *
 * PHPRequestHandler ships a single shared HttpCookieStore intended for a
 * one-user playground: it remembers every Set-Cookie and replays it on the
 * next request, whoever makes it. In a server that is exactly a
 * session-fixation bug — visitor B inherits visitor A's session cookie and
 * lands in A's account.
 *
 * The handler also *overwrites* the request's `cookie` header with whatever
 * this store returns, so it cannot simply be disabled: returning '' would
 * strip the browser's real cookies and no one could ever stay logged in.
 * Instead the true Cookie header for the request in flight is stashed here
 * and handed straight back. Requests are serialised through the handler's
 * semaphore, so the value is always the one being served.
 */
const cookiePassthrough = {
  cookies: {},
  current: '',
  rememberCookiesFromResponseHeaders() {
    /* the real browser owns the jar */
  },
  getCookieRequestHeader() {
    return this.current;
  },
};

function createHandler() {
  return new PHPRequestHandler({
    cookieStore: cookiePassthrough,
    documentRoot: '/app',
    absoluteUrl: process.env.DEV_ABSOLUTE_URL || `http://localhost:${args.port}`,
    maxPhpInstances: args.workers,
    phpFactory: async () => {
      const php = await (async () => {
        const { PHP } = await import('@php-wasm/universal');
        const runtime = await loadNodeRuntime(
          '8.2',
          await withNetworking({ emscriptenOptions: { processId: ++processIdSeq } })
        );
        return new PHP(runtime);
      })();
      php.mkdir('/app');
      await php.mount('/app', createNodeFsMountHandler(ROOT));
      php.chdir('/app');
      return php;
    },
    // Mirror .htaccess: an existing file is served as-is, everything else is a
    // front-controller route handled by index.php.
  getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
  });
}

/**
 * The PHP pool, recycled after a bounded number of requests.
 *
 * Two failure modes, two mitigations:
 *
 * 1. Leaked file descriptors. Each wasm runtime holds open fds, and this
 *    build leaks a few per request. After a few hundred — one long end-to-end
 *    run — the process hits "No file descriptors available" and every route
 *    starts answering 500, which looks exactly like the application breaking
 *    and has cost several debugging sessions. PHP-FPM solves the same class
 *    of problem with pm.max_requests; so does this (below). The old handler
 *    stays alive until its in-flight requests finish, then goes away with its
 *    descriptors.
 *
 * 2. Session-lock self-deadlock. Every wasm request shares the main thread
 *    (asyncify). CI's file session handler takes flock(LOCK_EX) on the
 *    session file as a synchronous syscall; if two requests ever overlap on
 *    one session, the second flock blocks the main thread while the first
 *    request (holding the lock) can never resume — an unrecoverable
 *    self-deadlock that turns the server into a black hole (observed in
 *    production of the dev box: main thread stuck in locks_lock_inode_wait
 *    with two open fds on one session file). The default is therefore ONE
 *    PHP instance: requests are strictly serialised, so a lock is always
 *    released before the next request starts. `--workers N` (N > 1) is
 *    available for people who accept that risk; it is a dev speed dial, not
 *    a production claim.
 */
let handler = createHandler();
let servedSinceRecycle = 0;
function noteRequestServed() {
  if (args.maxRequests <= 0) return;
  if (++servedSinceRecycle < args.maxRequests) return;
  servedSinceRecycle = 0;
  handler = createHandler();
  console.log(`[devserver] recycled the PHP pool after ${args.maxRequests} requests`);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const pathname = decodeURIComponent(url.pathname);

  // Paths the shipped .htaccess and nginx configs refuse, refused here too.
  //
  // This server reads files straight from disk and knows nothing about
  // .htaccess, so until now it happily served `/.env` — every credential on
  // the panel — plus `database/marvysocials.sql` (schema and the seeded
  // administrator hash) and anything under storage/. On a laptop that is
  // careless; in a sandbox whose port is published as a preview URL it is a
  // credential leak. Production denies these in Apache and nginx; the dev
  // server now denies the same list, so a deployment check that asks "is .env
  // reachable?" gets a truthful answer here as well.
  if (isDenied(pathname)) {
    res.writeHead(403, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Forbidden');
    return;
  }

  // Static assets straight from disk — faster, and keeps binary files intact.
  if (pathname !== '/' && !pathname.endsWith('/')) {
    const candidate = path.join(ROOT, pathname);
    if (
      candidate.startsWith(ROOT) &&
      fs.existsSync(candidate) &&
      fs.statSync(candidate).isFile() &&
      path.extname(candidate).toLowerCase() !== '.php'
    ) {
      const ext = path.extname(candidate).toLowerCase();
      res.writeHead(200, {
        'content-type': MIME[ext] || 'application/octet-stream',
        'cache-control': 'no-cache',
      });
      fs.createReadStream(candidate).pipe(res);
      return;
    }
  }

  const body = await readBody(req);

  // Watchdog: a wasm request that never completes wedges its PHP instance
  // forever — the pool serialises through one semaphore per instance, so a
  // few such requests brick the whole server into a black hole (every later
  // request times out; only a process restart recovers it). Time each
  // request, 504 the client, and rebuild the pool so development continues.
  const REQUEST_TIMEOUT_MS = 120000;
  let watchdog = null;
  try {
    const headers = {};
    for (const [k, v] of Object.entries(req.headers)) {
      headers[k] = Array.isArray(v) ? v.join(', ') : String(v);
    }

    cookiePassthrough.current = headers.cookie || '';
    const pool = handler;                 // the instance this request belongs to
    const response = await Promise.race([
      pool.request({
        url: req.url,
        method: req.method,
        headers,
        body: body.length ? new Uint8Array(body) : undefined,
      }),
      new Promise((_, reject) => {
        watchdog = setTimeout(
          () => reject(new Error('php request never completed — wasm worker wedged, recycling pool')),
          REQUEST_TIMEOUT_MS
        );
      }),
    ]);
    if (watchdog) clearTimeout(watchdog);

    const outHeaders = {};
    for (const [k, v] of Object.entries(response.headers || {})) {
      outHeaders[k] = Array.isArray(v) && v.length === 1 ? v[0] : v;
    }
    res.writeHead(response.httpStatusCode || 200, outHeaders);
    res.end(Buffer.from(response.bytes));

    if (response.errors) process.stderr.write(String(response.errors));
    noteRequestServed();
  } catch (err) {
    const wedged = /wasm worker wedged/.test(String(err?.message || err));
    console.error('[devserver]', req.method, req.url, err?.message || err);
    if (wedged) {
      // The pool this request was in may hold a wedged instance; a few of
      // these and the old pool serves nothing but 504s. Rebuild it so the
      // next request lands on a healthy worker. The ghost request keeps
      // consuming the discarded pool's one instance, which is now orphaned.
      handler = createHandler();
      servedSinceRecycle = 0;
      console.log('[devserver] pool recycled after a wedged wasm request');
    }
    const detail = wedged
      ? '504 — the PHP worker wedged on this request; the dev server recycled its pool. Retry the action.'
      : (err?.response
        ? new TextDecoder().decode(err.response.bytes)
        : String(err?.stack || err?.message || err));
    if (!res.headersSent) {
      res.writeHead(wedged ? 504 : 500, { 'content-type': wedged ? 'text/plain; charset=utf-8' : 'text/html; charset=utf-8' });
    }
    res.end(detail);
  }
});

server.listen(args.port, args.host, () => {
  console.log(
    `[devserver] MarvySocials on http://${args.host}:${args.port} (root ${ROOT}, ${args.workers} workers)`
  );
});
