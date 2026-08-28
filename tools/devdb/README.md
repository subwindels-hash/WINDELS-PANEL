# Dev database and dev application server

**These tools are for local development and automated testing only.** They are
not part of a deployment, are never loaded by the application, and are excluded
from the production package. Production runs PHP-FPM behind Nginx against MySQL
8 / MariaDB, exactly as `docker/` and `INSTALLATION.md` describe.

## Why they exist

MarvySocials targets MySQL, and the application, migrations and mysqli/PDO
drivers are written for it. In an environment where no MySQL server and no PHP
runtime can be installed, the alternatives are to test nothing or to test
something that is not the real application. Neither is acceptable for a payment
system, so these tools let the **unmodified** application run end to end:

| Tool | What it is |
| --- | --- |
| `tools/devdb/server.js` | A MySQL wire-protocol server backed by SQLite. Real handshake, real COM_QUERY, real result sets and error codes, so CodeIgniter's driver connects to it without knowing the difference. |
| `tools/devdb/translate.js` | MySQL → SQLite SQL translation for the dialect this repository actually uses. |
| `tools/devdb/protocol.js` | Wire-protocol codec (packets, capability flags, status flags). |
| `tools/devserver/server.mjs` | Serves the real CodeIgniter application over HTTP. |
| `tools/devserver/*_check.mjs` | End-to-end tests driven over real HTTP with cookies and CSRF. |

## Running it

```bash
node tools/devdb/server.js --port 3399 --db storage/devdb/marvy.sqlite --fresh
node tools/devserver/server.mjs --port 8080
```

Point `.env` at the dev database:

```
VP_DB_DSN=mysql:host=127.0.0.1;port=3399;dbname=marvysocials;charset=utf8mb4
VP_DB_DRIVER=pdo
VP_DB_STRICT=inherit
```

Then migrate and seed exactly as production would:

```bash
php index.php migrate
php index.php seed core
php index.php seed demo
```

## Editing PHP while the dev server runs

The wasm runtime keeps compiled PHP per worker, so **restart
`tools/devserver/server.mjs` after changing any `.php` file** — otherwise the
running workers keep serving the previous version and you will debug a file
that is not the one being executed.

## The test scripts

```bash
node tools/devserver/smoke.mjs                                  # every public route
node tools/devserver/journey.mjs      --admin-password <pw>     # customer + admin walkthrough
node tools/devserver/admin_check.mjs  --admin-password <pw>     # settings, secrets, impersonation
node tools/devserver/commerce_check.mjs --admin-password <pw>   # deposit, approval, order, ticket
node tools/devserver/content_check.mjs  --admin-password <pw>   # CMS round trip + XSS sanitising
node tools/devserver/pin_check.mjs                              # security PIN lifecycle
node tools/devserver/blockonomics_check.mjs                     # BTC callback handling
node tools/devserver/responsive_check.mjs                       # layout audit
node tools/devserver/gateway_check.mjs        --admin-password <pw>   # hosted gateway config + signed webhook
node tools/devserver/reconciliation_check.mjs                   # deposits whose callback never arrived
node tools/devserver/notifications_check.mjs  --admin-password <pw>   # inbox, email queue, preferences
node tools/devserver/smm_provider_check.mjs                     # real SMM adapter against a fake panel
node tools/devserver/page_audit.mjs           --password <pw>   # every dashboard/admin page
node tools/devserver/link_crawl.mjs           --password <pw>   # follow every internal link
node tools/devserver/image_audit.mjs          --password <pw>   # every <img> resolves
node tools/devserver/api_check.mjs            --password <pw>   # reseller API: envelope, scopes, idempotency
```

## What this does and does not prove

**Does prove:** the application's own logic — routing, auth, sessions, CSRF,
RBAC, the order state machine, the double-entry ledger, webhook verification
and idempotency, admin controls, and that content saved in the back office
reaches the public site.

**Does not prove:** that MySQL behaves identically to the SQLite translation in
every edge case, or that any third-party API (providers, gateways, SMTP) responds
as documented. Those need a real MySQL instance and real credentials. CI runs the
PHP suite against native PHP and MySQL for exactly this reason.

## Known differences from MySQL

- `DECIMAL` is stored as `TEXT` to preserve exact money values; SQLite has no
  fixed-point type and `REAL` would introduce float error.
- `SELECT ... FOR UPDATE` is accepted and ignored — SQLite serialises writes.
- `ALTER TABLE ... ADD CONSTRAINT FOREIGN KEY` is recorded but not enforced;
  SQLite cannot add a foreign key to an existing table.
- Multi-statement `COM_QUERY` is supported; prepared-statement protocol
  (`COM_STMT_PREPARE`) is not — the CI3 drivers do not use it.
