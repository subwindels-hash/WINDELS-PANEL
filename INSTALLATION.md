# MarvySocials — Step-by-Step Installation Guide

This is the complete, click-by-click installation walkthrough for a **cPanel
shared host** — the platform's primary deployment target. It assumes **no
Terminal, no SSH, no Composer, no Node.js**. Everything happens in the cPanel
web interface and your browser.

> Running Docker instead? See the README ("First boot with Docker").
> Migrating an existing panel to a new host? See
> [docs/cpanel-deployment.md](docs/cpanel-deployment.md) — the same five core
> steps, plus carrying two values over.

---

## What you need before you start

| Requirement | Where to check / get it |
| --- | --- |
| cPanel hosting with **PHP 8.1+** | cPanel → *Select PHP Version* (or *MultiPHP Manager*) |
| PHP extensions: `mysqli`, `mbstring`, `curl`, `openssl`, `bcmath`, `json` | cPanel → *Select PHP Version → Extensions* (tick them) |
| MySQL 5.7+ / MariaDB 10.4+ | Included with any cPanel account |
| The package **`application-deployment.zip`** | GitHub → **Releases** → `application-deployment.zip` (built by the packaging workflow). No release yet? Build it once on any machine with PHP: `bash tools/build_deployment_package.sh` |
| A domain pointing at the hosting account | Your DNS provider |

The zip already contains everything: `index.php`, `application/`, the
CodeIgniter framework as real files at `system/` **and**
`vendor/codeigniter/framework/system` (no symlinks, no `composer install`),
pre-built CSS in `assets/`, `storage/`, `cron/`, `database/marvysocials.sql`
(the complete initialised database) and `.env.example`.

---

## Step 1 — Upload the package

1. Log in to **cPanel → File Manager**.
2. Open the directory your domain serves — usually `public_html`
   (or `public_html/subdomain` for an addon/subdomain).
3. **Upload** → choose `application-deployment.zip` → wait for 100%.
4. Back in File Manager, select the zip → **Extract** → *Extract File(s)*.
5. **Important check:** `index.php` must sit *directly* in the web directory,
   next to `application/`, `system/` and `assets/`. If extraction created an
   extra folder (e.g. `public_html/application-deployment/index.php`), open
   that folder, **Select All** → **Move** → remove the last path segment →
   *Move File(s)*.
6. Delete the zip when done.

## Step 2 — Create the database

1. **cPanel → MySQL® Database Wizard** (or *MySQL® Databases*).
2. **Create Database**: enter e.g. `panel`. cPanel adds your account prefix —
   the real name becomes `yourcpuser_panel`.
3. **Create User**: e.g. `panel`, with a long random password (use the cPanel
   *Password Generator*). Real name: `yourcpuser_panel`.
4. **Add User to Database** → tick **ALL PRIVILEGES** → *Make Changes*.
5. Write down the exact three values — you need them in Step 4:
   **database name**, **username**, **password** (all with the prefix).

## Step 3 — Import the database

1. **cPanel → phpMyAdmin**.
2. In the left sidebar, click the database you just created.
3. Open the **Import** tab → **Choose File** → select
   `database/marvysocials.sql`.
   *Tip: in File Manager you can right-click the file → Download to get a
   local copy, or compress it to `marvysocials.sql.zip` — phpMyAdmin imports
   zipped SQL directly (useful if your host's upload limit is small).*
4. Leave all settings at their defaults → press **Go**.
5. Wait for the green success banner: *"Import has been successfully
   finished…"* (the exact query count varies — the table count
   must be **94**).

That one file creates the entire schema (tables, columns, indexes, 111
foreign keys) **and** all required data: roles, permissions, settings,
feature flags, payment methods, email templates, currencies, catalogues, the
migration bookkeeping row — and the **first-login accounts** (SUPER_ADMIN, customer, staff).

**There is no migrate step and no seed step afterwards.** Re-importing is
safe if an import is interrupted (`CREATE TABLE IF NOT EXISTS` — it just
repairs the partial run).

## Step 4 — Create `.env`

1. In **File Manager**, in the web directory, find `.env.example`.
   *Hidden files: File Manager → Settings (top right) → tick "Show Hidden
   Files (dotfiles)".*
2. Select `.env.example` → **Copy** → name the copy **`.env`**.
3. Select `.env` → **Edit** → fill in the required values:

```ini
CI_ENV=production
VP_BASE_URL=https://yourdomain.com

VP_DB_HOST=localhost
VP_DB_PORT=3306
VP_DB_NAME=yourcpuser_panel
VP_DB_USER=yourcpuser_panel
VP_DB_PASS=the-password-from-step-2

VP_ENCRYPTION_KEY=
VP_AUTH_SECRET=
```

| Key | What to put |
| --- | --- |
| `VP_BASE_URL` | Your full site URL **with `https://`**, no trailing slash. |
| `VP_DB_NAME` / `VP_DB_USER` | The **prefixed** names from Step 2. |
| `VP_DB_PASS` | The database user's password. |
| `VP_ENCRYPTION_KEY` | **64 random hex characters** — encrypts API keys & sensitive data at rest. Generate with a password manager (hex mode) or any "random hex generator". Never reuse the example value. |
| `VP_AUTH_SECRET` | **32+ random characters** — signs password-reset / email-verification tokens. |

Everything else has working defaults for cPanel (file-based sessions, local
upload storage, `mysqli`, `utf8mb4`, UTC timezone). Optional integrations
(SMTP, VTpass, 5sim, Dojah, Reloadly, payment-gateway secrets) are annotated
inside the file — add them when you enable those services; see Step 9.

*Plain names also work (`DB_PASSWORD`, `APP_URL`, `ENCRYPTION_KEY`, `APP_KEY`)
— the `VP_` prefix is just the canonical convention; a plain name always wins
if both are set.*

## Step 5 — Verify the installation

Open in your browser:

```
https://yourdomain.com/deploy-verify.php
```

You get one page that checks, in sections:

- ✅ **Package** integrity and the **CodeIgniter framework path**
- ✅ **PHP version** (8.1+) and **required extensions**
- ✅ **Composer autoloader** (real or shipped fallback — either passes)
- ✅ **Writable directories**: `storage/logs`, `storage/cache/sessions`,
  `storage/cache/ratelimit`, `application/cache`, `assets/uploads`
- ✅ **`.env`** parses and every required value is set (and not a placeholder)
- ✅ **Live database connection** and — with the DB reachable — the **schema
  audit**: all 94 tables, every column with its type, indexes, foreign keys

Any red row prints **what is missing and the exact cPanel click-path that
fixes it** (e.g. "enable mysqli: cPanel → Select PHP Version → Extensions").
Fix up, refresh, repeat until everything is green.

*(If you have CLI access you can run the same battery with
`php tools/check_installation.php`, and audit the imported database any time
with `php database/schema_verification.php` — both are optional replicas of
the browser check.)*

## Step 6 — First login & secure the administrator

The SQL seeds three first-login accounts (also printed at the top of
`marvysocials.sql`):

```
Staff admin — full control of the site
  URL:      https://yourdomain.com/admin/login
  Username: admin
  Password: ChangeMe!Admin2026

Customer dashboard
  URL:      https://yourdomain.com/login
  Username: demo
  Password: MarvyDemo#2026!

Support staff
  URL:      https://yourdomain.com/admin/login
  Username: staff
  Password: MarvyStaff#2026!
```

**Do one of the following immediately:**

- **Simple:** log in at `https://yourdomain.com` and change the password
  (Dashboard → **Security** → change password), and set your real email
  address (Dashboard → **Profile**).
- **Better (never expose the seeded password):** before first login, add a
  line to `.env`:
  ```ini
  VP_SETUP_TOKEN=pick-a-long-random-string
  ```
  then open `https://yourdomain.com/setup?token=pick-a-long-random-string`.
  The page only exists while the token is set (otherwise it is a plain 404).
  It lets you set the administrator's **own** email + password directly
  (minimum 12 chars), shows the same health checks, and writes the change to
  `audit_logs`. **Delete the `VP_SETUP_TOKEN` line from `.env` when done.**

## Step 7 — Lock down the verification page

Once `deploy-verify.php` is green, take it off the internet — either:

- add `VP_VERIFY_DISABLE=1` to `.env` (the file stays but answers nothing), or
- delete `deploy-verify.php` in File Manager.

Keep `database/schema_verification.php` and `database/marvysocials.sql`
(they are inert server-side), or delete them too if you prefer a minimal docroot.

## Step 8 — Cron jobs (background workers)

Order fulfilment, status chasing, the email queue, payment reconciliation and
subscriptions all run as cron jobs. On cPanel this is **Cron Jobs** in the
web UI — no Terminal needed. Jobs are **CLI-only** (`php index.php cron
<job>`); there are deliberately no web-cron URLs.

**cPanel → Cron Jobs → Add New Cron Job**, one entry per job. Command pattern
(replace `yourcpuser`; use the docroot path from Step 1):

```bash
cd /home/yourcpuser/public_html && php index.php cron order_status >> storage/logs/cron.log 2>&1
```

| Schedule | Job | What it does |
| --- | --- | --- |
| `* * * * *` | `dripfeed` | Drip-feed order batches |
| `*/2 * * * *` | `order_status` | Polls providers for order progress |
| `*/2 * * * *` | `vtu_status` | VTU/data transaction status |
| `*/2 * * * *` | `giftcard_codes` | Chases paid-but-undelivered gift-card codes |
| `* * * * *` | `numbers_status` | Virtual-number reservations (expire in minutes) |
| `*/5 * * * *` | `marketplace_release` | Auto-releases delivered marketplace orders |
| `*/5 * * * *` | `subscriptions` | Recurring subscription renewals |
| `*/5 * * * *` | `provider_health` | Provider availability/balance snapshots |
| `*/5 * * * *` | `refill_status` | Refill request tracking |
| `*/5 * * * *` | `payment_reconciliation` | Gateway ↔ ledger reconciliation |
| `*/5 * * * *` | `email_queue` | Sends queued mail |
| `*/60 * * * *` | `provider_sync` | Pulls provider service catalogues/prices |
| `0 * * * *` | `analytics` | Hourly rollup |
| `*/10 * * * *` | `affiliate_payouts` | Approved affiliate commissions |
| `30 3 * * *` | `identity_purge` | Deletes identity results past retention |

Notes:

- `php` must be the **8.1+** CLI binary. On most cPanel hosts it is; if not,
  use the full path shown under *Select PHP Version* (e.g.
  `/opt/alt/php81/usr/bin/php`) or `/usr/local/bin/ea-php81`.
- Every job runs inside a lock (`JobRunner`): a slow run makes the next tick
  *skip*, never pile up; runs are recorded in the `job_runs` table.
- Minimum viable subset if you want to start small: `order_status`,
  `email_queue`, `provider_sync`, `payment_reconciliation` — but the full
  list above is the supported configuration.

## Step 9 — Configure the panel

Log in as admin and finish configuration — most of it is in the dashboard,
secrets live in `.env`:

- **Dashboard → Settings**: site name, logo, currency, feature flags.
  Timezone is `APP_TIMEZONE` in `.env` (UTC by default).
- **Mail**: set `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASSWORD` /
  `SMTP_CRYPTO` (+ optional `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`) in `.env`,
  or the same values under Settings — then send a test email.
- **Payment gateways** (Dashboard → Payment Methods, keys in `.env` as
  documented per provider): Paystack (`PAYSTACK_SECRET_KEY`,
  `PAYSTACK_PUBLIC_KEY`), Flutterwave, Stripe, PayPal, Razorpay,
  CoinPayments — plus the `MARVYSOCIALS_<GATEWAY>_WEBHOOK_SECRET` values for
  signed webhooks. Manual funding works out of the box.
- **SMM/VTU providers** (Dashboard → Providers): VTpass, 5sim, Dojah,
  Reloadly — paste API credentials; their `.env` counterparts are annotated
  in `.env.example`.
- **Webhook URLs** to give gateways/payment providers:
  `https://yourdomain.com/webhook/<gateway>` (see Dashboard → Payment
  Methods for the exact per-gateway URL).

## Step 10 — You're live 🎉

Open `https://yourdomain.com`. Register a test user, fund the wallet with
the manual method, place a small test order, and confirm `order_status`
moves it (the cron log at `storage/logs/cron.log` shows each run).

---

## Troubleshooting

| Symptom | Cause → fix |
| --- | --- |
| "The panel is not configured yet" page | This is the panel naming what is missing — follow the `.env` keys it lists (copied from `.env.example`) and reload. It appears on purpose before `.env` is written or while `VP_ENCRYPTION_KEY` is unset/placeholder. |
| Blank page / HTTP 500 | Open `deploy-verify.php` — it names the failing layer. Most often: wrong PHP version (`Select PHP Version` → 8.1+) or a mistyped `.env` line. |
| "Database connection failed" | `VP_DB_*` values don't match Step 2 (prefix missing?), or user not added to DB with ALL PRIVILEGES. |
| Login works but sessions drop | `storage/cache/sessions` not writable (File Manager → select → *Permissions* → `755`, or `775`/`777` if the host runs PHP as another user) — or switch to database sessions: `VP_SESSION_DRIVER=database` (uses the shipped `user_sessions` table). |
| phpMyAdmin: file too large | Upload `marvysocials.sql.zip` instead; phpMyAdmin decompresses it. |
| Emails never arrive | SMTP values missing — the `email_queue` cron row in `storage/logs/cron.log` shows the SMTP error. |
| Orders stuck on PENDING | Cron not installed or running an old PHP — check `storage/logs/cron.log` timestamps and `/setup` (while token is set) → job health. |
| Re-import to repair | The SQL is idempotent: importing again fixes partial imports/corruption without duplicating rows. |

## Re-deploying / updating later

Upload and extract the new `application-deployment.zip` the same way
(overwrite), **keeping** your `.env` and `assets/uploads/` (they are not in
the zip). If the release notes mention schema changes, import the new
`marvysocials.sql` (idempotent) or activate the CI workflow that applies
migrations. Your encryption key must stay the same forever — data encrypted
with it is unreadable otherwise (the migration guide in
`docs/cpanel-deployment.md` explains how to carry it between hosts).

---

**Reference cards:** [.env.example](.env.example) — every variable annotated ·
[database/README.md](database/README.md) — the SQL file ·
[docs/cpanel-deployment.md](docs/cpanel-deployment.md) — migration variant ·
[README.md](README.md) — architecture, testing, Docker, module map.
