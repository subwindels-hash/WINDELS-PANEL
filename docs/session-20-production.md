# Session 20 — Production hardening

§20 covers migrations, workers, storage, webhooks, SSL, backups and health
checks. Most of the machinery already existed: migrations and seeds are
CLI-only, webhooks verify signatures and store events idempotently, health
endpoints were routed, and the cron jobs were written and documented.

What was missing was everything that connects those pieces to a real
deployment. Four of the findings would have broken or silently compromised a
production install.

## 1. `getenv()` returns strings, and "false" is truthy

```php
$config['http_allow_private_hosts'] = (bool)getenv('HTTP_ALLOW_PRIVATE_HOSTS');
```

`.env.example` ships `HTTP_ALLOW_PRIVATE_HOSTS=false`. `getenv()` hands back the
**string** `"false"`, and every non-empty string casts to `true` — so following
the shipped example switched **SSRF protection off**. The Session 17 hardening
that added `reject_url()` was disabled by its own documentation.

The same pattern appeared twice more:

- `log_threshold = getenv('APP_DEBUG') ? 2 : 1` — `APP_DEBUG=false` selected
  *debug* logging in production.
- `MAIL_LOG` in `MailService`, twice.

Fixed at the root with `env_bool()` and `env_str()` in the shared helper: only
`1/true/yes/on` are true. A source scan test fails if `(bool)getenv(...)` or a
bare `getenv()` condition reappears in config, libraries or controllers.

## 2. Production encrypted secrets with a key published in this repo

```php
$k = getenv('ENCRYPTION_KEY') ?: 'change-me-32-byte-key-replace!!';
```

`EncryptionService` protects **provider API keys** (`StandardSmmAdapter`) and
**TOTP MFA secrets** (`AuthService`). A deployment that forgot the variable
encrypted all of them with a constant visible in the source tree — functionally
plaintext, but with none of the visible signs of plaintext. `config.php` had a
second placeholder of its own.

Deferred from Session 17 as "must fail loudly in production", and now it does:
`EncryptionService::resolve_key()` throws in production when the key is unset,
under 32 characters, or one of the four known placeholders. Outside production
it returns a key literally named `insecure-development-key-do-not-use-in-production`,
so local setup stays a one-liner and nobody mistakes it for real.

The check is static so preflight can call it without constructing the service.

## 3. Nothing ran the cron jobs

Ten jobs, a documented crontab, `JobRunner` locking, `job_runs` history — and no
service in `docker-compose.yml` that ran any of it. `docker compose up` gave you
a panel where drip-feed orders never advance, subscriptions never renew, payouts
never pay and queued email never sends. Everything would look fine; it would
just quietly do nothing.

Added a `cron` container sharing the app image, which installs
`cron/crontab.example` and runs in the foreground.

## 4. The stack could not start from a clean clone

- `docker-compose.yml` mounted `./docker/mysql/init.sql`, which **did not
  exist**.
- `storage/logs/` and `storage/cache/sessions/` are gitignored (correctly — logs
  are not source), so a fresh clone had neither. CI3 discards log output
  silently when `log_path` is missing, and file sessions need the cache
  directory.

Both fixed: `init.sql` now creates the main and test databases, and the runtime
directories are tracked as empty dirs via nested `.gitignore` files.
`deploy storage` creates them on any host, and the Dockerfile creates them at
build time.

A test walks every `./x:/y` mount in the compose file and asserts the source
path exists, so this cannot regress.

## `deploy check` — the preflight command

The theme above is that a production misconfiguration looks exactly like a
working install. `Preflight` turns each of those invariants into a check with a
severity:

```
  [ok  ] encryption_key         set and non-placeholder
  [FAIL] https                  APP_URL is not https (http://panel.example.com)
         Session cookies are marked Secure in production and will not be
         sent over http, so logins will fail.
  [warn] debug                  APP_DEBUG is on in production
```

`FAIL` exits non-zero. It covers the encryption key, PHP version, required
extensions, writable directories, HTTPS, default database password, schema
version vs. code expectation, debug and demo mode.

It runs in three places: the app container's start command (so an unsafe
release never serves traffic), CI (which asserts a keyless production config is
*rejected* — a guard that never fires is not a guard), and by hand during
deploys. Checks are pure enough to drive directly from tests, no request needed.

Every failing check must carry an actionable hint; a test enforces that, because
a 3am `FAIL` with no remedy is barely better than silence.

## Health probes now mean different things

`ready` previously reported Redis as `ok` when the *config file* loaded — it
never connected, so a dead Redis looked healthy. It also returned raw exception
messages from an unauthenticated endpoint.

Now: `live` touches nothing (a liveness probe that checks the database turns a
brief blip into a restart loop — a test asserts it contains no `$this->db`,
`load->database` or `new Redis`); `ready` actually connects to Redis with a 1s
timeout, verifies the schema version matches the code, checks the log directory
is writable, and logs error detail rather than returning it. Redis stays
non-fatal because sessions and cache can run without it.

An instance whose schema does not match its code is **unready** — serving
requests against an unexpected schema corrupts data quietly.

## Test infrastructure

Two runner gaps surfaced while writing the tests:

- **No `expectException()`** in `phpunit_lite.php`. Implemented properly: it
  fails when nothing is thrown *and* when the wrong type is thrown, both
  verified with a throwaway probe test before being relied on.
- **No shared bootstrap.** `phpunit.xml` pointed at `vendor/autoload.php`, so
  `ENVIRONMENT` and `APPPATH` were never defined. Once `resolve_key()` started
  failing closed, the whole suite looked like production and 88 tests broke.
  `tests/bootstrap.php` now declares `ENVIRONMENT=testing`, `APPPATH`,
  `BASEPATH` and UTC for both runners.

That second one is worth stating plainly: failing closed is correct, and it
means the *test environment* has to declare itself rather than be inferred.

`Preflight` also degrades to a warning instead of fataling when the CI object is
half-built — it runs when things are broken, so it cannot assume a healthy app.

## Result

408 tests, 3661 assertions, 0 failures.

28 new tests in `ProductionReadinessTest`. The three highest-value ones were
mutation-verified: reintroducing `(bool)getenv`, deleting the cron service, and
removing `init.sql` each fail exactly one test.

Operational runbook: `docs/deployment.md` — first deploy, required settings,
workers, health checks, upgrades, backups, TLS and webhooks.

## Deferred

- **Backups are documented, not automated.** The `mysqldump` runbook is correct
  but nothing schedules or verifies it. An untested backup is a hypothesis.
- **No `ENCRYPTION_KEY` rotation path.** Rotating invalidates every provider
  credential and MFA secret; a re-encryption command would fix that.
- **Production nginx config.** The shipped one is HTTP-only for local
  development; certificates, redirect and `fastcgi_param HTTPS on` are
  documented but not provided.
- Still open from earlier sessions: Redis caching/sessions (S18), `EXPLAIN`
  review against realistic data, the S15 admin CRUD backlog, and the S17 items
  (`ApiAuthenticator ?key=`, TOTP replay, SRI, API-key CIDR).
