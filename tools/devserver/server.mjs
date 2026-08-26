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
const ROOT = path.resolve(__dirname, '../..');

function parseArgs(argv) {
  const out = { port: 8080, host: '0.0.0.0', workers: 3 };
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--port') out.port = parseInt(argv[++i], 10);
    else if (argv[i] === '--host') out.host = argv[++i];
    else if (argv[i] === '--workers') out.workers = parseInt(argv[++i], 10);
  }
  return out;
}
const args = parseArgs(process.argv);

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

const handler = new PHPRequestHandler({
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

  try {
    const headers = {};
    for (const [k, v] of Object.entries(req.headers)) {
      headers[k] = Array.isArray(v) ? v.join(', ') : String(v);
    }

    cookiePassthrough.current = headers.cookie || '';
    const response = await handler.request({
      url: req.url,
      method: req.method,
      headers,
      body: body.length ? new Uint8Array(body) : undefined,
    });

    const outHeaders = {};
    for (const [k, v] of Object.entries(response.headers || {})) {
      outHeaders[k] = Array.isArray(v) && v.length === 1 ? v[0] : v;
    }
    res.writeHead(response.httpStatusCode || 200, outHeaders);
    res.end(Buffer.from(response.bytes));

    if (response.errors) process.stderr.write(String(response.errors));
  } catch (err) {
    console.error('[devserver]', req.method, req.url, err?.message || err);
    const detail = err?.response
      ? new TextDecoder().decode(err.response.bytes)
      : String(err?.stack || err?.message || err);
    res.writeHead(500, { 'content-type': 'text/html; charset=utf-8' });
    res.end(detail);
  }
});

server.listen(args.port, args.host, () => {
  console.log(
    `[devserver] MarvySocials on http://${args.host}:${args.port} (root ${ROOT}, ${args.workers} workers)`
  );
});
