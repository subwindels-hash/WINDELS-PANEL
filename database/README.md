# `database/` — production schema and safe upgrades

| File | What it is |
| --- | --- |
| **`marvysocials.sql`** | The **complete database for a new installation**: full schema plus required roles, permissions, settings, payment methods, catalogues, migration bookkeeping, and first-login accounts. It is derived from `application/migrations/*.php` and `application/seeds/Core_seeder.php` by `php tools/build_production_sql.php`; never edit it by hand. |
| **`upgrade-042-deposit-currency.sql`** | A small, repeatable upgrade for an **existing installation** missing migration 042’s four payment settlement columns. It preserves users, balances, payments, settings, credentials, and all other live data. |
| **`schema_verification.php`** | Read-only comparison of a live database with `marvysocials.sql`: every table, column/type, index, and foreign key. The same checks run in the browser at `/deploy-verify.php`. |
| `README.md` | This file. |

## New installation on cPanel

1. Create a database and database user with **ALL PRIVILEGES** under cPanel → MySQL Databases.
2. In phpMyAdmin, select the empty database and import `marvysocials.sql`.
3. Configure `.env` and open `/deploy-verify.php`.
4. When every check passes, delete `deploy-verify.php`.

First-login credentials are printed at the top of `marvysocials.sql`. Change
them immediately, or use `/setup` with `VP_SETUP_TOKEN` as described in
`docs/cpanel-deployment.md`.

## Existing installation: upgrade to migration 042

Do **not** import the full `marvysocials.sql` over a live database. It is a
new-install database and includes seed records; it is not a general-purpose
upgrade script.

1. Export a complete backup in phpMyAdmin.
2. Upload/extract the updated application package, retaining the existing
   `.env`, user uploads, and storage data.
3. Select the existing database in phpMyAdmin and import
   `upgrade-042-deposit-currency.sql`.
4. Open `/deploy-verify.php`. Confirm the schema is healthy, then delete the
   verifier.

The upgrade file can be run more than once. It adds each missing column only
when necessary, backfills old payments without changing what customers paid,
and advances the migration version without moving a database already beyond
version 42 backwards.

## Development / CI

```bash
php tools/build_production_sql.php           # regenerate the new-install SQL
php tools/build_production_sql.php --check   # fail if it is stale
php tools/verify_database.php                # static code/schema audit
python3 tools/validate_production_sql.py     # deep SQL lint (sqlglot required)
bash tools/build_deployment_package.sh       # rebuild deployment artifact
```
