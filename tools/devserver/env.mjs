/**
 * .env bootstrap for the dev tooling.
 *
 * DEV TOOLING ONLY. The repository-root `.env` is the single source of truth
 * for local credentials (the demo password in particular): the PHP seeder
 * pins demo logins with `getenv('DEMO_PASSWORD')`, and the end-to-end check
 * scripts read `process.env.DEMO_PASSWORD`. Neither sees the other unless
 * something puts the value in the OS environment — a fresh sandbox rebuild
 * of `.env` with a newly generated password has otherwise left every check
 * logging in with a stale hardcoded fallback and "failing" for no reason.
 *
 * `loadDotEnv()` fills any missing `process.env` keys from `.env`. Explicit
 * OS environment always wins (you can still override a single run with
 * `DEMO_PASSWORD=... node tools/devserver/…`), and nothing already set is
 * ever clobbered.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ENV_PATH = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..', '.env');
let loaded = false;

export function loadDotEnv() {
  if (loaded || !fs.existsSync(ENV_PATH)) return;
  loaded = true;
  for (const line of fs.readFileSync(ENV_PATH, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (key && !(key in process.env)) process.env[key] = value;
  }
}
