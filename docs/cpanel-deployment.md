# Deploying MarvySocials on cPanel

**Upload the files → create the database → import one SQL file → edit `.env` →
open the domain.** That is the whole process. No Terminal, no SSH, no Composer,
no Node.js, no migration command, no seed command, no installer script.

This guide covers a new install and a migration between hosting accounts. Both
are the same five steps; a migration just reuses two values from the old
server.

---

## What you need

| | |
| --- | --- |
| Hosting | cPanel with PHP **8.1+** and MySQL 5.7+ / MariaDB 10.4+ |
| PHP extensions | `mysqli`, `mbstring`, `openssl`, `curl`, `json`, `bcmath` (cPanel → Select PHP Version → Extensions) |
| The package | `application-deployment.zip` — published as a **release artifact** (GitHub → Releases), or built locally with `bash tools/build_deployment_package.sh`. It is a build artifact and is not committed to git. Contains index.php, application/, system/, assets/, storage/, cron/, database/marvysocials.sql and .env.example. |

Nothing else. The framework is inside the package, so `composer install` is
never required; the CSS is pre-built, so `npm install` is never required.

---

## 1. Upload

1. **cPanel → File Manager**.
2. Open the directory your domain serves — usually `public_html`, or
   `public_html/subdomain` for an addon/subdomain.
3. **Upload** `application-deployment.zip`.
4. Select it → **Extract**.
5. Check that `index.php` sits *directly* in that directory, next to
   `application/`, `system/` and `assets/`. If the extract created an extra
   folder level, select everything inside it and **Move** it up one level.

Delete the zip when you are done.

---

## 2. Create the database

**cPanel → MySQL® Databases**:

1. **Create New Database** — e.g. `panel`. cPanel prefixes it with your account
   name, so the real name becomes something like `myaccount_panel`.
2. **Add New User** — e.g. `panel`, with a long random password. Real name:
   `myaccount_panel`.
3. **Add User To Database** → tick **ALL PRIVILEGES** → *Make Changes*.

Write down the three prefixed values: **database name**, **user name**,
**password**. They go into `.env` in step 4.

---

## 3. Import the database

**cPanel → phpMyAdmin**:

1. Select the database you just created in the left-hand list.
2. Open the **Import** tab.
3. **Choose file** → `database/marvysocials.sql` from the extracted package
   (download it from File Manager first if your browser needs a local copy).
4. Press **Go**.

That single file creates everything: all tables, columns, indexes and foreign
keys, the migration bookkeeping, roles, permissions, application settings,
feature flags, payment methods, email templates, FAQs, currencies, the VTU /
numbers / identity / gift card catalogues, marketplace categories — and the
first-login accounts (SUPER_ADMIN, customer, staff).

**There is no migration step and no seed step afterwards.** The database is
finished when the import finishes.

> If phpMyAdmin refuses the upload because the file is larger than the host's
> limit, compress it first (`marvysocials.sql.zip`) — phpMyAdmin imports zipped
> SQL directly.

---

## 4. Configure `.env`

In **File Manager**, copy `.env.example` to `.env` (right-click → Copy, then
Rename), then right-click `.env` → **Edit** and set:

```ini
CI_ENV=production

VP_BASE_URL=https://yourdomain.com

VP_DB_HOST=localhost
VP_DB_PORT=3306
VP_DB_NAME=myaccount_panel
VP_DB_USER=myaccount_panel
VP_DB_PASS=the-password-you-set

VP_ENCRYPTION_KEY=32-or-more-random-characters
VP_AUTH_SECRET=another-32-or-more-random-characters
```

Save.

* `VP_ENCRYPTION_KEY` protects provider API keys, TOTP secrets and gift card
  codes stored in the database. The panel refuses to start in production while
  it is empty, shorter than 32 characters, or still an example value.
* `VP_AUTH_SECRET` signs password-reset and email-verification links and
  session tokens.
* Generate them anywhere that produces long random strings — a password
  manager is fine. If you have a shell handy, `openssl rand -base64 32`.

Optional, and all documented inline in `.env.example`: mail transport
(`VP_MAIL_*`), payment gateway keys, fulfilment provider credentials, Redis,
S3 storage, session/cache tuning.

---

## 5. Verify the deployment (one page, in the browser)

Visit `https://yourdomain.com/deploy-verify.php`.

It checks, and names the exact fix for anything red:

- PHP version and required extensions (`mysqli`, `mbstring`, `curl`,
  `openssl`, `bcmath`, `json` + recommended ones)
- the CodeIgniter system path — resolved automatically from `system/` or
  `vendor/codeigniter/framework/system`, whichever the upload produced
- `vendor/autoload.php` (bundled fallback or full composer install)
- writable runtime directories (with real write probes, not guesses)
- `.env` — required values present, secrets non-default, https base URL
- database credentials **and a live connection**, including whether the
  `marvysocials.sql` import actually ran (table count)

**Delete `deploy-verify.php` when everything is green** — File Manager →
right-click → Delete. (Or set `VP_VERIFY_DISABLE=1` in `.env` to disable it
without deleting.) It can also run from a shell: `php deploy-verify.php`
exits 0/1, so monitoring can gate on it.

---

## 6. Open the site

Visit `https://yourdomain.com`.

The homepage should render. Sign in with the credentials printed in the header
of `database/marvysocials.sql`:

```
Staff admin (full control of the site)
  URL:      https://yourdomain.com/admin/login
  username: admin
  password: ChangeMe!Admin2026

Customer dashboard
  URL:      https://yourdomain.com/login
  username: demo
  password: MarvyDemo#2026!

Support staff
  URL:      https://yourdomain.com/admin/login
  username: staff
  password: MarvyStaff#2026!
```

The SUPER_ADMIN account (`admin`) bypasses every permission check, so the
admin dashboard can manage users, services, orders, payments, settings and
the rest of the site. Customer credentials are refused at `/admin/login`.

**Change those passwords immediately** (Dashboard → Account → Password), or set
your own administrator *before* the first login using the setup page below.

If the database was imported earlier and has no `demo`/`staff` rows, import
`database/first_login_accounts.sql` in phpMyAdmin — it inserts missing
accounts and does not overwrite existing passwords.

---

## Optional: the browser setup page

Instead of logging in with a documented password, you can set the
administrator's own username, email and password from the browser.

1. In `.env`, set `VP_SETUP_TOKEN` to 32 random characters.
2. Visit `https://yourdomain.com/setup?token=THOSE-32-CHARACTERS`.
3. The page lists every deployment check — PHP version and extensions, writable
   directories, base URL, encryption key, database connection, whether the
   import ran, whether an administrator exists — and offers a form to set the
   administrator credentials.
4. **Delete the `VP_SETUP_TOKEN` line from `.env` when you are done.** Without
   it the route returns 404 to everyone.

The same page is the fastest way to diagnose a panel that will not come up: it
renders even when the database is unreachable, and tells you which value in
`.env` is wrong.

---

## Folder permissions

The application creates its own runtime directories on the first request, and
the package already contains them. You only need this section if something is
not writable — the setup page and the error log will say so.

**cPanel → File Manager → select the folder → Permissions:**

| Folder | What is in it | Permissions |
| --- | --- | --- |
| `storage/logs/` | application log | 755 (or 775) |
| `storage/cache/` | cached settings and templates | 755 (or 775) |
| `storage/cache/sessions/` | logged-in sessions | 755 (or 775) |
| `assets/uploads/` | uploaded images and PDFs | 755 (or 775) |
| `application/cache/` | CodeIgniter cache | 755 (or 775) |

Files 644, directories 755. Some hosts run PHP as a different user than the one
that owns the files; those need 775 on the five folders above. Nothing needs
777, and nothing needs `chmod`, `chown` or `find` from a shell.

Each of those directories ships with its own `.htaccess`, so logs, caches and
sessions are not readable over HTTP even though they sit inside the document
root. `assets/uploads/` is deliberately different: it is served to browsers,
but with PHP execution switched off.

---

## Migrating an existing panel to a new cPanel account

Same five steps, with two differences.

**Export from the old account:**

1. **File Manager** → select the application folder → **Compress** → download
   the zip. (Or reuse `application-deployment.zip` — the files are the same;
   only `.env`, `assets/uploads/` and the database carry your data.)
2. **phpMyAdmin** → select the old database → **Export** → *Quick*, format
   **SQL** → Go. Save the `.sql` file.
3. Open the old `.env` and copy the values of `VP_ENCRYPTION_KEY` (or
   `ENCRYPTION_KEY`) and `VP_AUTH_SECRET` (or `APP_KEY`).

**On the new account:**

4. Upload and extract the files (step 1), and upload the contents of
   `assets/uploads/` if you compressed the app without them.
5. Create the new database and user (step 2).
6. Import **your exported dump**, not `database/marvysocials.sql` — your dump
   already contains the schema *and* your live data.
7. Edit `.env` (step 4) with the **new** database name/user/password and the
   new domain, and paste the **old** `VP_ENCRYPTION_KEY` and `VP_AUTH_SECRET`
   back in unchanged.
8. Open the domain. Existing users, including the administrator, log in with
   the passwords they already had.

> **The secrets must travel with the database.** Provider API keys, TOTP
> secrets and gift card codes are encrypted at rest with `VP_ENCRYPTION_KEY`.
> Generate a new key on the new server and those rows become undecryptable —
> the panel will run, but every stored provider credential has to be re-entered
> and every user with two-factor authentication has to re-enrol.

Nothing about a migration requires new secrets, a new installer run, or a
terminal.

---

## Background jobs (recommended)

Drip-feed orders, subscription renewals, provider status polling, payment
reconciliation and the email queue run from cron. Without them the panel works,
but nothing progresses on its own.

**cPanel → Cron Jobs** → *Add New Cron Job*. Each job is one entry; these five
cover everything a typical panel needs (adjust the PHP path and the application
path to your account — cPanel shows the correct `php` binary under *Select PHP
Version*):

| Common settings | Command |
| --- | --- |
| Once per minute | `cd /home/myaccount/public_html && /usr/local/bin/php index.php cron dripfeed >/dev/null 2>&1` |
| Every 2 minutes | `cd /home/myaccount/public_html && /usr/local/bin/php index.php cron order_status >/dev/null 2>&1` |
| Every 5 minutes | `cd /home/myaccount/public_html && /usr/local/bin/php index.php cron email_queue >/dev/null 2>&1` |
| Every 5 minutes | `cd /home/myaccount/public_html && /usr/local/bin/php index.php cron payment_reconciliation >/dev/null 2>&1` |
| Every 5 minutes | `cd /home/myaccount/public_html && /usr/local/bin/php index.php cron subscriptions >/dev/null 2>&1` |

`cron/crontab.example` in the package lists every available job with its
recommended schedule, and `php index.php cron` prints the same list.

This is the only place a PHP command line appears anywhere in this document,
it is entered through a cPanel form rather than a terminal, and the panel
serves traffic correctly without it.

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| “CodeIgniter framework files are missing” / “system folder path does not appear to be set correctly” | The upload was cut short — `system/` (and/or `vendor/`) is incomplete | Re-upload the zip and re-extract. The package ships the framework as ordinary files in **both** auto-detected locations, so no symlink or `composer install` is ever needed. `/deploy-verify.php` confirms the fix. |
| “The panel is not configured yet” | `.env` missing a required value | The page names it. Edit `.env` in File Manager. |
| Blank page / HTTP 500 | Usually a permissions problem | Set the five folders above to 755/775; check `storage/logs/log-*.php`. |
| “Unable to connect to your database server” | Wrong `VP_DB_*` values, or the user is not assigned to the database | cPanel → MySQL Databases → *Add User To Database* → ALL PRIVILEGES. |
| Homepage loads but every link 404s | `.htaccess` was not extracted (File Manager hides dotfiles by default) | File Manager → Settings → *Show Hidden Files*, confirm `.htaccess` exists next to `index.php`. |
| Login form reloads without an error | Session cookies are `Secure` but the site is on plain http | Install the free certificate in cPanel → SSL/TLS Status, then set `VP_BASE_URL=https://…`. |
| Admin login works but every `/admin` page bounces to Security | `admin_mfa_required` is on and the account has no authenticator yet | Import `database/first_login_accounts.sql`, or turn the setting off in Admin → Settings after enabling MFA. |
| Provider keys stopped working after a move | `VP_ENCRYPTION_KEY` changed | Restore the old key in `.env`. |
| Uploaded images 404 | `assets/uploads/` missing or not writable | Create it in File Manager and set 755/775. |

The application log is `storage/logs/log-YYYY-MM-DD.php`, readable from File
Manager.

---

## What each file in the package is for

```
application-deployment.zip
├── index.php                 front controller — reads .env, boots the app
├── .htaccess                 clean URLs + denies application/, system/, database/, dotfiles
├── .env.example              copy to .env and edit; the only configuration
├── deploy-verify.php         browser diagnostics — delete after a green report
├── README-DEPLOYMENT.txt     this guide, condensed to one screen
├── application/              controllers, models, views, libraries, config
├── system/                   CodeIgniter 3.1.13, REAL files (no symlink — works everywhere)
├── vendor/                   codeigniter/framework + fallback autoloader;
│   │                         full composer dependencies when built after composer install
│   └── codeigniter/framework/system/   ← the second path index.php auto-detects
├── assets/                   css, js, images, uploads/ (writable)
├── storage/                  logs/ and cache/ (writable, pre-guarded)
├── cron/                     crontab example for cPanel → Cron Jobs
└── database/
    ├── marvysocials.sql           the complete database: schema + data + accounts
    └── first_login_accounts.sql   phpMyAdmin paste for an existing live database
```

`index.php` auto-detects the framework in `system/` first and
`vendor/codeigniter/framework/system` second — both always exist in the
package, as ordinary files, so no composer install and no symlink support is
needed at the destination. The remaining composer packages (S3 storage,
Redis, payment SDKs) stay optional feature flags: the request path never
requires them.

---

## For maintainers: rebuilding the package

The zip is a build artifact (GitHub Actions → Artifacts, or GitHub Releases
when a `v*` tag is pushed). After changing application code, a migration or
the core seed, rebuild it:

```bash
php tools/build_production_sql.php          # regenerate database/marvysocials.sql
bash tools/build_deployment_package.sh      # regenerate application-deployment.zip
bash tools/verify_deployment_package.sh     # extract it into a scratch account and prove it boots
```

`CpanelDeploymentTest` fails when the committed zip no longer matches the tree:
its staleness check compares every packaged application file (views, layouts,
assets, config, controllers, models, libraries, database dump, runtime
guards) hash-for-hash against the working tree, not just `marvysocials.sql` and
`index.php`. CI runs all three of the above plus a real MySQL import of
`database/marvysocials.sql`.
