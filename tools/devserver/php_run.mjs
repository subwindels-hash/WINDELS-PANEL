/**
 * Run an arbitrary PHP script (not through index.php) via the same WASM PHP
 * runtime the dev server uses, since this sandbox has no native `php`
 * binary. Used for maintainer-only CLI tools such as
 * tools/build_production_sql.php that build a standalone file and never
 * touch a live request.
 *
 *   node tools/devserver/php_run.mjs tools/build_production_sql.php --check
 *   node tools/devserver/php_run.mjs tools/build_production_sql.php --admin-password=...
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadNodeRuntime, createNodeFsMountHandler, withNetworking } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');

const args = process.argv.slice(2);
if (args.length === 0) {
  console.error('Usage: node tools/devserver/php_run.mjs <script.php> [args...]');
  process.exit(2);
}
const script = args[0];
const scriptArgs = args.slice(1);

const runtime = await loadNodeRuntime('8.2', await withNetworking({ emscriptenOptions: { processId: 998 } }));
const php = new PHP(runtime);
php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(ROOT));
php.chdir('/app');

php.setSapiName('cli');
const scriptRel = script.startsWith('/') ? script : '/app/' + script.replace(/^\.\//, '');
const argv = ['php', scriptRel, ...scriptArgs];
try {
  const result = await php.cli(argv);
  const out = await result.stdoutText;
  const err = await result.stderrText;
  if (out) process.stdout.write(out);
  if (err) process.stderr.write(err);
  const code = await result.exitCode;
  process.exit(code);
} catch (e) {
  console.error(e);
  process.exit(1);
}
