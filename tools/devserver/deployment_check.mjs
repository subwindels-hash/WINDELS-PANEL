/**
 * Deployment check — boot the shipped package as a new cPanel account would.
 *
 * DEV TOOLING ONLY. `verify_deployment_package.sh` already proves the archive
 * is *shaped* right (framework as real files, no composer, .env alone
 * configures it) by booting the configuration. What nobody had ever done is
 * the thing an operator actually does:
 *
 *   1. extract application-deployment.zip into an empty account,
 *   2. import database/marvysocials.sql into a brand new, empty database,
 *   3. write .env,
 *   4. open the site and sign in with the credentials in the file's header.
 *
 * This does exactly that, against the extracted tree — never the working copy
 * — with its own database and its own web server, and then asks for pages.
 * If the shipped SQL is incomplete, or a file the site needs was left out of
 * the archive, or the documented password does not work, it fails here rather
 * than on somebody's live domain.
 *
 *   node tools/devserver/deployment_check.mjs
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { execFileSync, spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { Client } from './client.mjs';

const argv = process.argv.slice(2);
const arg = (name, def) => { const i = argv.indexOf(name); return i === -1 ? def : argv[i + 1]; };
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const WEB_PORT = parseInt(arg('--port', '8090'), 10);
const DB_PORT = parseInt(arg('--db-port', '3410'), 10);
const KEEP = argv.includes('--keep');

const ADMIN_PASSWORD = 'ChangeMe!Admin2026';   // documented in the SQL header
const DEMO_PASSWORD = 'MarvyDemo#2026!';

const results = [];
function check(label, ok, detail = '') {
  results.push({ label, ok: !!ok, detail });
  console.log(`   ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n       ${detail}`}`);
}
const children = [];
function cleanup() {
  for (const c of children) { try { c.kill('SIGKILL'); } catch { /* gone */ } }
}
process.on('exit', cleanup);

/* ------------------------ 1 · extract the package ------------------------- */

console.log('\n── Extracting the package into an empty account');
const account = fs.mkdtempSync(path.join(os.tmpdir(), 'marvy-deploy-'));
const zip = path.join(ROOT, 'application-deployment.zip');
check('the package exists', fs.existsSync(zip), zip);
execFileSync('unzip', ['-q', zip, '-d', account]);

const shipped = (rel) => fs.existsSync(path.join(account, rel));
check('index.php is at the account root', shipped('index.php'));
check('the framework ships as real files', shipped('system/core/CodeIgniter.php'));
check('the production SQL ships with it', shipped('database/marvysocials.sql'));
check('deploy-verify.php ships with it', shipped('deploy-verify.php'));
check('no composer.json to satisfy on the host', !shipped('composer.json'));
check('the uploads directory is guarded', shipped('assets/uploads/.htaccess'));

/* --------------------- 2 · import the SQL into a new DB ------------------- */

console.log('\n── Importing database/marvysocials.sql into an empty database');
const dbFile = path.join(account, 'fresh.sqlite');
const devdb = spawn('node', [path.join(ROOT, 'tools/devdb/server.js'),
  '--port', String(DB_PORT), '--db', dbFile, '--fresh'],
  { cwd: ROOT, stdio: 'ignore' });
children.push(devdb);

async function waitForPort(port, tries = 60) {
  for (let i = 0; i < tries; i++) {
    const open = await new Promise((resolve) => {
      const s = net.connect(port, '127.0.0.1');
      s.on('connect', () => { s.destroy(); resolve(true); });
      s.on('error', () => resolve(false));
    });
    if (open) return true;
    await new Promise((r) => setTimeout(r, 200));
  }
  return false;
}
check('the empty database is listening', await waitForPort(DB_PORT));

// Import through the MySQL protocol, exactly as phpMyAdmin would — the dump
// has to be valid to something other than the tool that wrote it.
const importOut = execFileSync('node', [path.join(ROOT, 'tools/devdb/import_sql.cjs'),
  '--port', String(DB_PORT), '--file', path.join(account, 'database/marvysocials.sql')],
  { cwd: ROOT, encoding: 'utf8', timeout: 300000 });
const imported = /statements:\s*(\d+).*?failed:\s*(\d+)/s.exec(importOut) || [];
check('every statement in the dump applies',
  imported[2] === '0', importOut.trim().split('\n').slice(-6).join(' | '));

const { DatabaseSync } = await import('node:sqlite');
const fresh = new DatabaseSync(dbFile);
const rows = (t) => { try { return fresh.prepare(`SELECT COUNT(*) n FROM ${t}`).get().n; } catch { return -1; } };
check('the schema is there', rows('users') >= 3 && rows('settings') > 20,
  `users=${rows('users')} settings=${rows('settings')}`);
check('migration bookkeeping is recorded, so nothing replays',
  rows('migrations') >= 1, `migrations=${rows('migrations')}`);
check('roles, permissions and payment methods are seeded',
  rows('roles') === 4 && rows('permissions') > 40 && rows('payment_methods') > 0,
  `roles=${rows('roles')} perms=${rows('permissions')} methods=${rows('payment_methods')}`);
fresh.close();

/* ------------- 2b · first visit BEFORE .env exists (regression) ---------- */

// The most common fresh-upload state is "extracted, not configured yet".
// That first visit used to be a zero-byte HTTP 500 — a blank white page with
// no hint — because the encryption-key refusal threw through CodeIgniter's
// own exception handler (installed before Config parses, and with
// display_errors off it only logs and exits). An operator who saw it assumed
// the upload itself had failed. The panel must instead answer with its
// configuration page that names the .env keys to set.
console.log('\n── Opening the site before .env is written (the very first visit)');
const RAW_PORT = WEB_PORT + 1;
const rawWeb = spawn('node', [path.join(ROOT, 'tools/devserver/server.mjs'),
  '--port', String(RAW_PORT), '--host', '127.0.0.1', '--root', account, '--workers', '1'],
  { cwd: ROOT, stdio: ['ignore', 'pipe', 'pipe'] });
children.push(rawWeb);
let rawLog = '';
rawWeb.stdout.on('data', (d) => { rawLog += d; });
rawWeb.stderr.on('data', (d) => { rawLog += d; });
check('the unconfigured site is listening', await waitForPort(RAW_PORT), rawLog.slice(-400));
await new Promise((r) => setTimeout(r, 2000));

let first;
try {
  first = await new Client(`http://127.0.0.1:${RAW_PORT}`).get('/');
} catch (e) {
  console.log('   note    the unconfigured request failed: ' + e.message + '\n' + rawLog.slice(-600));
  first = { status: 0, text: '' };
}
check('the first visit is not a blank page',
  first.text.trim().length > 200,
  `status=${first.status} bytes=${first.text.length}`);
check('it explains that .env must be written',
  first.status === 503 && /panel is not configured/i.test(first.text) && /\.env|ENCRYPTION_KEY/i.test(first.text),
  `status=${first.status} ${first.text.slice(0, 120)}`);
try { rawWeb.kill('SIGKILL'); } catch { /* gone */ }

/* ----------------------------- 3 · write .env ---------------------------- */

console.log('\n── Writing .env the way an operator would in File Manager');
const env = [
  'CI_ENV=production',
  `VP_BASE_URL=http://127.0.0.1:${WEB_PORT}`,
  'VP_DB_DRIVER=pdo',
  'VP_DB_HOST=127.0.0.1',
  `VP_DB_PORT=${DB_PORT}`,
  'VP_DB_NAME=marvysocials',
  // The dev database stands in for MySQL and accepts any user with an empty
  // password; on a real host these are the cPanel credentials.
  'VP_DB_USER=root',
  'VP_DB_PASS=',
  `VP_DB_DSN=mysql:host=127.0.0.1;port=${DB_PORT};dbname=marvysocials;charset=utf8mb4`,
  'VP_ENCRYPTION_KEY=' + 'a1b2c3d4'.repeat(8),
  'VP_AUTH_SECRET=' + 'deploysecret'.repeat(3),
  'VP_DEBUG=false',
  // Production sends `SET sql_mode=STRICT_ALL_TABLES` as a connection init
  // command, which real MySQL answers and this sandbox's dev database cannot.
  // Inheriting the server's mode is the documented escape hatch and is the one
  // line of this .env that a real cPanel account would not need.
  'VP_DB_STRICT=inherit',
].join('\n') + '\n';
fs.writeFileSync(path.join(account, '.env'), env);
check('.env is the only configuration the operator writes', fs.existsSync(path.join(account, '.env')));

/* -------------------------- 4 · boot and browse -------------------------- */

console.log('\n── Booting the extracted account and opening the site');
const web = spawn('node', [path.join(ROOT, 'tools/devserver/server.mjs'),
  '--port', String(WEB_PORT), '--host', '127.0.0.1', '--root', account, '--workers', '2'],
  { cwd: ROOT, stdio: ['ignore', 'pipe', 'pipe'] });
children.push(web);
let webLog = '';
web.stdout.on('data', (d) => { webLog += d; });
web.stderr.on('data', (d) => { webLog += d; });
check('the site is listening', await waitForPort(WEB_PORT), webLog.slice(-400));
// The port opens before the PHP workers finish booting; give them a moment.
await new Promise((r) => setTimeout(r, 2500));

const site = new Client(`http://127.0.0.1:${WEB_PORT}`);
let home;
try {
  home = await site.get('/');
} catch (e) {
  console.log('   note    the first request failed: ' + e.message + '\n' + webLog.slice(-600));
  home = { status: 0, text: '' };
}
check('the homepage renders', home.status === 200 && /<\/html>/i.test(home.text),
  `status=${home.status} ${home.text.slice(0, 120)}`);
check('and it is the real site, not an error page',
  !/A Database Error|Unable to (load|connect)|Fatal error/i.test(home.text),
  (/(A Database Error[\s\S]{0,200})/.exec(home.text) || [, ''])[1]);

const services = await site.get('/services');
check('the catalogue renders from the shipped seed data',
  services.status === 200 && !/Fatal error|Database Error/i.test(services.text),
  `status=${services.status}`);

const verify = await site.get('/deploy-verify.php');
check('deploy-verify.php answers', verify.status === 200, `status=${verify.status}`);

// Its own summary line is the assertion. Two checks cannot pass in this
// sandbox and would on any MySQL host: the dev database is SQLite behind a
// protocol translator, so it reports every column as bigint(20)/varchar(255)
// and creates no separate index or foreign-key objects. Everything else must
// pass, including the writability, extension, .env and connection checks that
// are the actual point of the page.
const summary = (/(\d+)\s*passed,\s*(\d+)\s*warnings?,\s*(\d+)\s*failed/s
  .exec(verify.text.replace(/<[^>]*>/g, ' ')) || []);
const [, passed, warned, failedCount] = summary.map(Number);
console.log(`   note    deploy-verify: ${passed} passed, ${warned} warnings, ${failedCount} failed`);
check('deploy-verify passes everything the sandbox can answer',
  passed >= 35, `passed=${passed}`);
// Each FAIL must be one of the two schema-shape checks; anything else is a
// real deployment defect, whatever the count.
const flat = verify.text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
const failReasons = [...flat.matchAll(/FAIL\s+([^.]{0,90})/g)].map((m) => m[1].trim());
const schemaShape = failReasons.every((r) => /type mismatch|missing index|missing column|foreign key/i.test(r));
check('the only failures are the schema-shape checks SQLite cannot satisfy',
  failedCount === 0 || schemaShape, `failed=${failedCount}: ${failReasons.join(' | ')}`);
check('the writable, extension and connection checks all pass',
  !/FAIL[\s\S]{0,80}(writable|extension|connection|\.env)/i.test(verify.text.replace(/<[^>]*>/g, ' ')));

console.log('\n── Signing in with the credentials printed in the SQL file');
const admin = new Client(`http://127.0.0.1:${WEB_PORT}`);
await admin.get('/admin/login');
const alogin = await admin.postForm('/admin/login',
  { identifier: 'admin', password: ADMIN_PASSWORD });
check('the documented administrator password works',
  /\/admin/.test(alogin.url) && !/login/.test(alogin.url), alogin.url);
const dash = await admin.get('/admin');
check('the admin dashboard renders on a fresh install',
  dash.status === 200 && !/Fatal error|Database Error/i.test(dash.text), `status=${dash.status}`);
for (const url of ['/admin/orders', '/admin/settings', '/admin/customers', '/admin/services']) {
  const page = await admin.get(url);
  check(`${url} renders`, page.status === 200 && !/Fatal error|Database Error/i.test(page.text),
    `status=${page.status}`);
}

const cust = new Client(`http://127.0.0.1:${WEB_PORT}`);
await cust.get('/login');
const clogin = await cust.postForm('/login', { identifier: 'demo', password: DEMO_PASSWORD });
check('the documented customer password works', /\/dashboard/.test(clogin.url), clogin.url);
const cdash = await cust.get('/dashboard');
check('the customer dashboard renders', cdash.status === 200
  && !/Fatal error|Database Error/i.test(cdash.text), `status=${cdash.status}`);

console.log('\n── The install surface is closed on a fresh deployment');
const setup = await site.get('/setup');
check('the setup wizard is not open to the public',
  [403, 404].includes(setup.status) || /token/i.test(setup.text), `status=${setup.status}`);
// Two questions, because they have two different answers. What the HOST does
// is decided by the shipped .htaccess, which is what a cPanel account obeys;
// what this sandbox does is decided by the dev server. Both must refuse.
const htaccess = fs.readFileSync(path.join(account, '.htaccess'), 'utf8');
check('the shipped .htaccess denies dotfiles even without mod_rewrite',
  /FilesMatch\s+"\^\\\./.test(htaccess) && /Require all denied|Deny from all/.test(htaccess));
check('and denies the database dump', /\\\.sql/.test(htaccess));

const envLeak = await site.get('/.env');
check('.env is not served over HTTP', envLeak.status !== 200 && !/VP_DB_PASS/.test(envLeak.text),
  `status=${envLeak.status}`);
const dumpLeak = await site.get('/database/marvysocials.sql');
check('the database dump is not downloadable',
  dumpLeak.status !== 200 && !/CREATE TABLE/i.test(dumpLeak.text), `status=${dumpLeak.status}`);
const storageLeak = await site.get('/storage/logs/');
check('storage is not browsable', storageLeak.status !== 200 || !/log/i.test(storageLeak.text),
  `status=${storageLeak.status}`);

/* -------------------------------- results -------------------------------- */

cleanup();
if (!KEEP) fs.rmSync(account, { recursive: true, force: true });
else console.log(`\nkept the account at ${account}`);

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('\nFailures:');
  for (const f of failed) console.log(`  ${f.label} — ${f.detail}`);
  process.exit(1);
}
