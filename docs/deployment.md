# Deployment

Operational runbook for WINDELS PANEL. Everything here is CLI-only by design —
there are no web-triggered migrations, seeds or cron endpoints (§66).

> WINDELS-PANEL is a traditional PHP MVC application built on CodeIgniter 3.x
> with MySQL/MariaDB, served through PHP-FPM and Nginx. Redis provides
> supplementary caching/background processing where enabled. Node.js/npm is
> used only for optional frontend asset compilation (Tailwind CSS) and is not
> part of the application's backend architecture or runtime.

## Requirements

- PHP 8.1+ with `mysqli`, `mbstring`, `curl`, `openssl`, `bcmath`, `json`
  (plus `redis`, `gd`, `intl`, `zip` for the full feature set)
- Composer (application dependencies)
- PHP-FPM behind Nginx (see `docker/nginx.conf` for the reference config)
- MySQL 8.0 or MariaDB 10.6+
- Redis (optional — sessions and cache fall back to files/database)
- Node.js/npm only if you want to rebuild the Tailwind CSS bundle locally; the
  committed `assets/css/design-system.css` keeps the panel usable without it

## First deploy

```bash
git clone <repo> && cd windels-panel
composer install --no-dev --optimize-autoloader

cp .env.example .env
$EDITOR .env                       # see "Required settings" below

php index.php deploy storage       # create runtime directories
php index.php migrate              # apply the 18 migrations (001–018; 018 retires legacy withdrawal tables)
php index.php seed core            # roles, permissions, settings
php index.php deploy check         # verify before serving traffic
```

`deploy check` exits non-zero if anything is unsafe, so it can gate a release:

```bash
php index.php deploy check || exit 1
```

## Required settings

| Variable | Why it matters |
| --- | --- |
| `ENCRYPTION_KEY` | Encrypts provider API keys and TOTP secrets at rest. **The app refuses to boot in production** if this is unset, shorter than 32 characters, or still the `.env.example` placeholder. Generate with `openssl rand -base64 32`. |
| `APP_URL` | Must be `https://` in production — session cookies are `Secure`, so logins silently fail over plain http. |
| `DB_PASSWORD` | Preflight fails production on the shipped default. |
| `APP_ENV` | `production` enables the strict checks, HSTS and secure cookies. |

Rotating `ENCRYPTION_KEY` invalidates every stored provider key and MFA secret.
There is no re-encryption path yet — providers must be re-keyed and users must
re-enrol MFA.

### Booleans in `.env`

`1`, `true`, `yes`, `on` are true; **everything else is false**, including the
literal `false`. This is read through `env_bool()` rather than a `(bool)` cast,
because `getenv()` returns strings and every non-empty string is truthy in PHP
— `HTTP_ALLOW_PRIVATE_HOSTS=false` used to *disable* SSRF protection.

## Workers

Ten cron jobs do all the asynchronous work. Without them, drip-feed orders
never advance, subscriptions never renew, payouts never pay and queued email
never sends.

```bash
crontab cron/crontab.example       # bare metal
docker compose up -d cron          # containers (dedicated worker service)
```

Each job takes an exclusive `flock` so a slow run is skipped rather than
overlapped. Run history:

```bash
php index.php cron status          # last 20 job_runs
```

| Job | Schedule |
| --- | --- |
| `dripfeed` | every minute |
| `order_status` | every 2 minutes |
| `subscriptions`, `provider_health`, `refill_status`, `payment_reconciliation`, `email_queue` | every 5 minutes |
| `affiliate_payouts` | every 10 minutes |
| `analytics` | hourly |
| `provider_sync` | hourly |

## Health checks

| Endpoint | Meaning | Wire it to |
| --- | --- | --- |
| `GET /health/live` | the process is up; touches no dependency | container liveness probe / restart policy |
| `GET /health/ready` | database reachable, schema at the expected version, log directory writable, Redis reachable if present | load balancer / readiness probe |

Keep these distinct. A liveness probe that checks the database turns a brief
database blip into a restart loop. Readiness returns `200` or `503` and never
includes exception text — it is unauthenticated.

An instance whose schema version does not match the code reports **unready**:
serving requests against an unexpected schema corrupts data quietly.

## Storage

| Path | Contents |
| --- | --- |
| `storage/logs/` | application and cron logs |
| `storage/cache/sessions/` | file-based sessions (unused when `SESS_DRIVER=redis`) |
| `application/cache/` | CI cache |

Tracked as empty directories with `.gitignore` files, because CI3 silently
discards log output when `log_path` does not exist. They must be writable by
the PHP-FPM user:

```bash
chown -R www-data:www-data storage application/cache
```

## Upgrades

```bash
php index.php deploy check         # confirm current state is sane
git pull
composer install --no-dev --optimize-autoloader
php index.php migrate              # forward-only
php index.php deploy check
# restart php-fpm (opcache runs with validate_timestamps=0)
```

Migrations are forward-only in production. `migrate fresh` drops every table
and refuses to run under `APP_ENV=production` without `--force`.

## Backups

Nothing here is automated yet — wire these into whatever scheduler you use.

```bash
# Database (the only stateful component; --single-transaction avoids locking)
mysqldump --single-transaction --quick --routines \
  -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
  | gzip > "backup-$(date -u +%F-%H%M).sql.gz"

# Restore
gunzip -c backup-2026-08-17-0300.sql.gz | mysql -h "$DB_HOST" -u "$DB_USER" -p "$DB_NAME"
```

Also back up `.env` — specifically `ENCRYPTION_KEY`. A database backup restored
without the matching key leaves every provider credential and MFA secret
undecryptable.

Uploads live in S3-compatible storage (`STORAGE_*`); use the provider's own
versioning or replication. Verify restores periodically against a scratch
database — an untested backup is a hypothesis.

## TLS

Terminate TLS at the reverse proxy or load balancer. The application marks
cookies `Secure` and sends HSTS only when `APP_ENV=production` *and* the request
is genuinely HTTPS, so a misconfigured proxy shows up as failed logins rather
than as silent downgrade. If TLS terminates upstream, forward
`X-Forwarded-Proto`.

`docker/nginx/nginx.conf` is an HTTP-only development config. For production
use `docker/nginx/nginx.prod.conf` (wired by `docker-compose.production.yml`):
TLS 1.2/1.3, HSTS, HTTP→HTTPS redirect, certificate material bind-mounted from
`docker/nginx/certs/` (or terminate at an upstream load balancer and forward
`X-Forwarded-Proto`).

## Production compose

`docker-compose.production.yml` is the shipped production stack. It differs
from the development compose in that every credential is `${VAR:?}`-required
(compose refuses to boot with empty secrets), MailHog and MinIO are absent
(real SMTP and managed object storage instead), MySQL/Redis publish no host
ports, Redis enforces `--requirepass`, JSON logs rotate, and `APP_ENV` /
`CI_ENV` are pinned to `production` so `deploy check` applies its strictest
rules before php-fpm starts. Boot order:

```bash
docker compose -f docker-compose.production.yml up -d
docker compose -f docker-compose.production.yml exec app php index.php migrate
docker compose -f docker-compose.production.yml exec app php index.php seed core
curl -fsS https://<host>/health/ready    # must answer ready
```

Backups / PITR / restore rehearsal: **docs/backups.md**.

## Webhooks

Payment webhooks are excluded from CSRF (`webhook/.+`) because they arrive
server-to-server. Every handler verifies the provider signature and stores the
event idempotently, so replays are inert. Point each gateway at
`https://<host>/webhook/<gateway>` and set the corresponding `*_WEBHOOK_SECRET`.

## Preflight reference

`php index.php deploy check` reports `OK` / `WARN` / `FAIL`; only `FAIL` exits
non-zero.

| Check | Fails when |
| --- | --- |
| `encryption_key` | unset, too short, or a known placeholder (production only) |
| `php_version` | older than 8.1 |
| `ext:*` | a required extension is missing |
| `writable:*` | a runtime directory is missing or read-only |
| `https` | `APP_URL` is unset, or not https in production |
| `db_password` | a known default in production |
| `schema` | migrations table missing, or version ≠ code's expectation |
| `debug` | `APP_DEBUG` on in production (warning) |
| `demo_mode` | `DEMO_MODE` on in production (warning) |
| `mock_providers` | an ACTIVE provider row uses an offline MOCK adapter (production failure) |
| `db_connectivity` | MySQL does not answer `SELECT 1` |
| `secure_cookies` | session cookies are not `HttpOnly`/`Secure`/`SameSite` |
| `required_secrets` | `APP_KEY`/`ENCRYPTION_KEY`, `DB_NAME` or `DB_USER` missing |
| `environment_consistency` | `CI_ENV` and `APP_ENV` disagree |
