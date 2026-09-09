/**
 * PHP syntax check without a PHP runtime.
 *
 * This sandbox has no php binary, so we use glayzzle/php-parser to parse the
 * PHP source and report syntax errors. Run with:
 *
 *   node tools/php_syntax_check.mjs [path ...]
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, extname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import Parser from 'php-parser';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const targets = args.length ? args.map((p) => resolve(root, p)) : [resolve(root, 'application'), resolve(root, 'index.php'), resolve(root, 'cron')];

const parser = new Parser({ parser: { php7: true, extractDoc: false } });

function walk(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    const st = statSync(full);
    if (st.isDirectory()) {
      if (name === '.git' || name === 'vendor' || name === 'node_modules') continue;
      out.push(...walk(full));
    } else if (extname(full) === '.php') {
      out.push(full);
    }
  }
  return out;
}

let files = [];
for (const t of targets) {
  try {
    if (statSync(t).isDirectory()) files.push(...walk(t));
    else if (extname(t) === '.php') files.push(t);
  } catch (e) {
    // ignore
  }
}

let failures = 0;
const sources = [...new Set(files)];
for (const file of sources) {
  try {
    parser.parseCode(readFileSync(file, 'utf8'), file);
  } catch (e) {
    failures++;
    console.error(`PHP SYNTAX ERROR: ${file}\n  ${e.message}`);
  }
}

if (failures) {
  console.error(`PHP syntax check failed: ${failures} file(s)`);
  process.exit(1);
}
console.log(`PHP syntax check passed: ${sources.length} file(s)`);
