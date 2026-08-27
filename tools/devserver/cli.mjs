/**
 * Run `php index.php <args>` through the WASM PHP runtime used by the dev
 * server, since this sandbox has no native `php` binary.
 *
 *   node tools/devserver/cli.mjs migrate
 *   node tools/devserver/cli.mjs seed core
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadNodeRuntime, createNodeFsMountHandler, withNetworking } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');

const args = process.argv.slice(2);

const runtime = await loadNodeRuntime('8.2', await withNetworking({ emscriptenOptions: { processId: 999 } }));
const php = new PHP(runtime);
php.mkdir('/app');
await php.mount('/app', createNodeFsMountHandler(ROOT));
php.chdir('/app');

php.setSapiName('cli');
const argv = ['php', 'index.php', ...args];
try {
  const result = await php.cli(argv);
  const out = await result.stdoutText;
  const err = await result.stderrText;
  if (out) process.stdout.write(out);
  if (err) process.stderr.write(err);
  const code = await result.exitCode;
  // The WASM runtime leaves background handles (workers/timers) open, so the
  // event loop never drains on its own — without an explicit exit, any
  // caller using execFileSync/spawnSync (no timeout) hangs forever even
  // though the PHP script itself has already finished and flushed output.
  process.exit(code);
} catch (e) {
  console.error(e);
  process.exit(1);
}
