# `database/` — the production database, importable anywhere

| File | What it is |
| --- | --- |
| **`marvysocials.sql`** | The **complete production database**: full schema (every table, column, index, foreign key) **plus** all required data — roles, permissions, settings, feature flags, payment methods, email templates, currencies, catalogues, the migration bookkeeping row and the first-login accounts (SUPER_ADMIN, customer, staff). Derived from `application/migrations/*.php` + `application/seeds/Core_seeder.php` by `php tools/build_production_sql.php`; never edited by hand. |
| **`schema_verification.php`** | Compares a **live database** against `marvysocials.sql` — every table, column (with type), index and foreign key. CLI: `php database/schema_verification.php`. Read-only. (The same checks run in the browser at `/deploy-verify.php`.) |
| `README.md` | This file. |

## Use on cPanel (no terminal)

1. **cPanel → MySQL Databases** — create the database and its user (ALL PRIVILEGES).
2. **cPanel → phpMyAdmin** → select the database → **Import** → choose
   `database/marvysocials.sql` → **Go**. The import is idempotent
   (`CREATE TABLE IF NOT EXISTS`), so re-importing repairs a partial run.
3. Fill in `.env` (`VP_DB_*` + the secrets + the domain) and open
   `https://yourdomain.com/deploy-verify.php` — it reports whether every
   table, column, index and foreign key actually landed.

First login credentials are printed at the top of `marvysocials.sql`
(admin at `/admin/login`, demo customer at `/login`, staff at `/admin/login`).
Change them immediately (Dashboard → Account → Password), or use the
`/setup` page with `VP_SETUP_TOKEN` — see `docs/cpanel-deployment.md`.

## Verify the codebase matches the schema (development / CI)

```bash
php tools/verify_database.php         # static audit: every table/column the
                                      # code touches vs marvysocials.sql
python3 tools/validate_production_sql.py   # deep SQL lint (sqlglot required)
```

Both must pass with **zero errors** before a release; CI enforces it.

## Regenerating after a migration change

```bash
php tools/build_production_sql.php           # rewrite database/marvysocials.sql
php tools/build_production_sql.php --check   # CI mode: fail if the committed
                                             # file is out of date
bash tools/build_deployment_package.sh       # rebuilds application-deployment.zip
```
