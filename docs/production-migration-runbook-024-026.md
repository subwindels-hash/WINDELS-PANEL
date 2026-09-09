# Production migration runbook — migrations 024, 025, 026

Applies to a **real production deployment with live customer data**: real
wallet balances, real orders, real referral earnings, real gift cards. This
is the runbook the platform-fixes spec explicitly asked for, distinct from
`docs/deployment.md`'s generic "Upgrades" section — those steps are correct
but generic (`git pull && migrate`); this document is what to actually check
*before*, *during* and *after* running them for these three specific
migrations, because they touch money-adjacent tables.

Read `docs/session-22-currency.md` first if you have not — it is the
precedent this runbook follows (relabel, never reinterpret) and the reason
`base_currency` is read-only in the admin UI.

---

## 1. What these three migrations actually do

| Migration | Tables touched | Risk class |
|---|---|---|
| `024_currency_management` | `ALTER TABLE currencies` — adds 4 nullable metadata columns (`rate_source`, `rate_updated_by`, `rate_updated_at`, `rate_effective_at`). Backfills existing rows with `rate_source='SEED'`. | **Low.** Purely additive metadata. Does not touch `exchange_rate` or `is_base` on any row. |
| `025_shop` | Creates 11 new tables (`shopping_carts`, `cart_items`, `coupons`, `coupon_redemptions`, `digital_products`, `digital_deliveries`, `shipping_methods`, `shipping_addresses`, `physical_products`, `shop_order_shipments`, `product_reviews`). Adds `currency CHAR(3) DEFAULT 'NGN'` to `marketplace_listings` and `marketplace_orders`, then **backfills every existing row** to `marvy_base_currency()`. Also adds two unused nullable FK columns (`giftcard_product_id`, `giftcard_order_id`) — informational only, not read by any purchase path. | **Medium.** The backfill UPDATE touches every existing marketplace row, but only to *label* it with the currency it was always implicitly priced in — no monetary column changes value. |
| `026_coupon_discovery` | `ALTER TABLE coupons ADD COLUMN is_public TINYINT(1) DEFAULT 0`. | **Low.** Purely additive; every existing coupon defaults to not-publicly-listed, i.e. behaves exactly as before. |

None of the three converts a stored amount, changes what currency an
existing balance means, or reinterprets a historical row's value. The
highest-risk operation here is the `marketplace_listings`/`marketplace_orders`
currency backfill in 025 — read section 3 before running it on a database
with real marketplace sales.

## 2. Preconditions — do these before touching production

1. **Freeze marketplace/shop writes.** Put the app in maintenance mode
   first (`Admin → Settings → Maintenance mode`, or `maintenance_mode=1`
   directly in `settings` if the app is already unreachable). This runbook
   assumes no marketplace order is being placed while 025 backfills
   `marketplace_orders.currency`.
2. **Take a full logical backup**, not just a snapshot of the tables being
   touched — a migration failure partway through requires restoring the
   whole schema-and-bookkeeping state together, not just the new tables:
   ```bash
   mysqldump --single-transaction --quick --routines --triggers --events \
     -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
     | gzip > "pre-024-026-$(date -u +%F-%H%M).sql.gz"
   ```
   Confirm the file is non-trivially sized and `gunzip -t` passes before
   continuing.
3. **Record the current base currency and its rate table**, so post-migration
   verification (§4) has something to diff against:
   ```bash
   mysql ... -e "SELECT code, is_base, exchange_rate, is_active FROM currencies ORDER BY code;" > pre-migration-currencies.txt
   mysql ... -e "SELECT COUNT(*), SUM(gross_amount) FROM marketplace_orders;" > pre-migration-marketplace-totals.txt
   mysql ... -e "SELECT id, currency, price FROM marketplace_listings LIMIT 20;" > pre-migration-listings-sample.txt
   ```
4. **Confirm what `marvy_base_currency()` will resolve to** on this
   deployment before running 025 — it reads `application/config/marvy.php`
   `$config['base_currency']`, not the `currencies` table, and its value is
   exactly what every existing `marketplace_listings`/`marketplace_orders`
   row is about to be labelled with:
   ```bash
   php index.php eval 'echo marvy_base_currency();'   # or grep config/marvy.php directly
   ```
   If this deployment migrated its base currency before (per
   `docs/session-22-currency.md`, e.g. USD→NGN), the value here **must**
   match what those existing rows were actually priced in. If it does not —
   if, say, some marketplace listings were genuinely priced in USD before a
   later base-currency change relabelled everything else to NGN but the
   marketplace tables were never audited — stop here and reconcile that
   first. Migration 025's backfill has no way to know a row's *true*
   historical currency; it only knows the current config value.
5. **Bump `application/config/migration.php`'s `$config['migration_version']`**
   to `26` in the deployed code *before* running `migrate` — CI3's migration
   library refuses to run past the configured target, and `/health/ready`
   reports the schema as unhealthy until the code and DB versions agree.
6. **Dry-run against a restored copy first**, not production directly:
   ```bash
   # On a scratch host/container, restore the backup from step 2, then:
   php index.php migrate
   php index.php deploy check
   ```
   Confirm no error, and spot-check the queries in §4 against the scratch
   copy before touching the real database.

## 3. Running it

```bash
php index.php deploy check      # confirm current state is sane before starting
git pull                        # or extract the new application-deployment.zip
composer install --no-dev --optimize-autoloader   # Docker/VPS path only — cPanel ships vendor/ in the zip
php index.php migrate           # forward-only; applies 024, 025, 026 in order
php index.php deploy check      # confirm the schema now reports version 26
```

If `migrate` fails partway through (e.g. a lock timeout on a busy
`marketplace_orders` table during the 025 backfill): **stop, do not retry
blindly.** CI3's migration runner is not transactional across multiple
`statements()` entries — check `schema_migrations` (or the CI3 migration
bookkeeping table) to see which of 024/025/026 actually recorded as applied,
restore from the step-2 backup if the state is ambiguous, and re-run from a
known-clean point rather than re-running `migrate` against a half-applied 025.

## 4. Post-migration verification — do not skip

Run every one of these against the real database before lifting maintenance
mode. Each has an expected answer; treat any deviation as a stop-ship issue,
not a note-and-continue.

```sql
-- 1. Schema version matches the deployed code (26).
SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1;

-- 2. No currency row's exchange_rate or is_base moved. Diff this against
--    pre-migration-currencies.txt from step 3 above — it must be identical
--    except for the new rate_source/rate_updated_*/rate_effective_at columns.
SELECT code, is_base, exchange_rate, is_active FROM currencies ORDER BY code;

-- 3. The base currency is still marked exactly once, at rate 1.0.
SELECT code, exchange_rate FROM currencies WHERE is_base = 1;
--   Expect exactly one row, exchange_rate = 1.00000000.

-- 4. Every marketplace_orders/marketplace_listings row now has a currency,
--    and it is the single expected base-currency code — not a mix, which
--    would mean the backfill ran against an inconsistent config value
--    mid-flight (should be impossible in one migration run, but confirm).
SELECT currency, COUNT(*) FROM marketplace_orders GROUP BY currency;
SELECT currency, COUNT(*) FROM marketplace_listings GROUP BY currency;

-- 5. Gross order totals are byte-for-byte unchanged from
--    pre-migration-marketplace-totals.txt — 025 must never have touched
--    gross_amount, price or promo_price, only the new currency column.
SELECT COUNT(*), SUM(gross_amount) FROM marketplace_orders;

-- 6. No wallet balance moved. This migration set never touches wallets or
--    wallet_transactions at all, so this must be an exact match against
--    whatever your last pre-migration wallet snapshot showed.
SELECT COUNT(*), SUM(CAST(balance AS DECIMAL(24,8))) FROM wallets;

-- 7. Every existing coupon defaults to not-publicly-listed (026's whole
--    point — a coupon nobody opted into discovery must not suddenly show
--    up on the /cart page).
SELECT COUNT(*) FROM coupons WHERE is_public = 1;
--   Expect 0 immediately after migrating, before any admin opts one in.

-- 8. New shop tables exist and are empty (nothing should have back-filled
--    into them — they are brand new).
SELECT COUNT(*) FROM shopping_carts;
SELECT COUNT(*) FROM digital_products;
SELECT COUNT(*) FROM shop_order_shipments;
```

Then, at the application layer:

- `GET /health/ready` returns 200 with the schema at version 26.
- `Admin → Currencies` loads, shows the base currency locked/undisable-able,
  and every existing exchange rate matches what you recorded in step 3.
- `Admin → Settings → Feature flags` still shows every flag with its
  pre-migration on/off value (this migration set does not touch
  `feature_flags`, but confirming nothing else in the deploy silently reset
  it is cheap insurance).
- Open one real pre-existing marketplace listing in `Admin → Marketplace →
  Edit` and confirm its price displays exactly as it did before (same
  number, now with an explicit currency label matching what it always
  implicitly was).
- Place one small **test** purchase end-to-end (ideally on a staging
  deployment, not production) and confirm the wallet debit matches the
  displayed price exactly — the new `currency` column must never change
  what a customer is actually charged.

## 5. Rollback

Each migration has a `down()`:

```bash
php index.php migrate 23    # rolls back 026, then 025, then 024, in that order
```

`026::down()` drops `coupons.is_public` — safe, no data loss (nothing had
been marked public yet if you are rolling back shortly after applying it;
if coupons have since been marked public, that flag is lost, which only
means those coupons silently go back to code-only, not a financial issue).

`025::down()` drops all 11 new tables and the `currency`/informational FK
columns it added — **this destroys any cart, coupon redemption, digital
delivery record, or shipment created after 025 was applied.** Only roll
back 025 before any real shop traffic has occurred. If shop orders have
already been placed and paid for, rolling back is a data-loss event for
those specific rows and should be treated as an incident, not a routine
revert — the underlying `marketplace_orders` rows and their wallet debits
are unaffected (`ShopCheckoutService` calls the same
`MarketplaceService::purchase()`/`TransactionEngine` every other purchase
uses), only the shop-specific metadata (shipping address on file, digital
download access, coupon redemption record) disappears.

`024::down()` drops the four rate-provenance columns — safe, no data loss
beyond "who set this rate and when" audit metadata, which is also
independently visible in `audit_logs` (`currency.rate_changed` entries)
going forward.

## 6. What this runbook deliberately does not cover

- **Changing the base currency itself.** That is `docs/session-22-currency.md`'s
  territory (a much bigger, one-way operation), not these three migrations.
  024–026 do not change what currency anything is settled in.
- **Multi-currency settlement/checkout.** Not built yet (see
  `docs/settings-audit-inventory.md`, "What is intentionally not a
  setting"). These migrations only add the admin currency *display*
  control panel and shop scaffolding; a customer still pays in the base
  currency only.
- **A generic "how to deploy at all" guide.** That is `docs/deployment.md`
  (Docker/VPS) and `docs/cpanel-deployment.md` (shared hosting, full
  reimport of `database/marvysocials.sql` — cPanel deployments do not run
  `migrate` at all, they import a fresh dump each time, so this runbook's
  migration-specific risks do not apply to a cPanel deploy the same way;
  the equivalent cPanel risk is instead "does the freshly generated
  `marvysocials.sql` match what you expect", covered by
  `tools/build_production_sql.php --check`).
