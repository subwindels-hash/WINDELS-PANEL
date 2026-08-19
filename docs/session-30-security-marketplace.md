# Session 30 — Security hardening, withdrawal removal, admin-controlled marketplace

This session delivers the required update:

> **Final architecture:** Admin → creates/manages/sells products. Customer →
> browses/buys/receives products. Customer → cannot become a seller. Customer →
> cannot withdraw/cash out wallet funds.

The wallet itself is untouched: deposits, the double-entry ledger and every
purchase flow (services, VTU, numbers, gift cards, identity, marketplace,
subscriptions, drip-feed, affiliates) keep working.

## 1. Security

| Area | Change |
|---|---|
| Logout CSRF | `Auth::logout()` is POST-only (GET redirects to the dashboard). All five rendered logout controls (admin/customer layout, auth layout, public nav, MFA page, account security page) are now POST forms carrying the CI CSRF token. |
| Session lifecycle | Sessions are regenerated at every privilege transition: login (post-auth), MFA-completed login, password change, impersonation enter, impersonation exit. Pinned by tests. |
| Webhook retry taxonomy | Invalid signatures → 401. Processed duplicates → 200 idempotent. **Transient processing failures (e.g. ledger rollback) now leave the `payment_webhooks` row unprocessed, mark the event `retryable`, and the controller answers 503** so the gateway retries; a retried delivery re-runs `confirm()` (itself idempotent) instead of being swallowed as a duplicate. |
| Encryption taxonomy | MFA TOTP secrets moved from `decrypt()` (plaintext fallback) to authenticated `open()` with fail-closed `MFA_SECRET_UNREADABLE` at all three call sites (verify/confirm/disable). `->decrypt(` is now confined to the six provider adapter files where the legacy plaintext-key shim is required; identity results and gift-card codes already used `open()`. A test sweeps the tree to keep it that way. |
| Preflight | Added four production gate checks: real database probe (`SELECT 1`), hardened session-cookie flags (httponly/secure/SameSite from CI config), required secrets presence (APP_KEY or ENCRYPTION_KEY for token signing, DB_NAME/DB_USER), CI_ENV vs APP_ENV consistency. All carry actionable hints. |
| IDOR / mass assignment | Verified: every customer dashboard record access is bound to `current_user->id` via `*_for_user` accessors or `owned()`/buyer-id guards that 404; no controller binds records by a submitted numeric id. Registration inserts an explicit allowlist with `role => CUSTOMER`; profile update is a five-field allowlist; role changes go through `UserAdminService::set_role` guarded by `staff.manage` + super-admin-only-mints-super-admin; balances change only inside `LedgerService` (pin-down sweep test added for all of these). |

## 2. Withdrawals removed completely

The feature is excised at every level — not hidden:

- **Routes**: dashboard + admin withdrawal route blocks deleted.
- **Controllers**: `dashboard/Withdrawals.php`, `admin/Withdrawals.php` deleted.
- **Service/Model**: `WithdrawalService.php`, `Withdrawal_model.php` deleted.
- **Views**: `dashboard/withdrawals/*`, `admin/withdrawals/*` deleted.
- **Migration**: `016_withdrawals.php` deleted; mass orders renumbered to `016`; the drop-down tables (`withdrawals`, `withdrawal_requests`) never reappear in the generated schema.
- **Ledger**: `reserve_withdrawal` / `refund_withdrawal` and the `withdrawal_payable` counter-account removed from `LedgerService`.
- **Settings/Permissions**: five `withdrawal_*` settings + cross-field validation removed from `SettingsService`; `withdrawals` permission group and all role-matrix grants removed from `Core_seeder`.
- **UI/notifications**: both nav entries and the WITHDRAWAL label maps removed.
- **Tests**: `WithdrawalTest.php` deleted; new `WithdrawalRemovalTest.php` guards the removal and proves the wallet/purchase plumbing is intact.

`grep -rli withdraw application/` → **0 matches** (the only exception is the
intentional retrofit `018_remove_withdrawals.php`, which exists precisely to
erase the feature from upgraded databases).

### Upgrade path for existing installations (migration 018)

Deleting `016_withdrawals.php` covers fresh installs but not databases that
already ran it. `018_remove_withdrawals.php` closes that gap (migration
chain target is now 18):

- Drops `withdrawal_events` then `withdrawal_requests` with `IF EXISTS`
  (child first — both tables only ever pointed INTO users +
  wallet_transactions, so wallet, ledger and every other financial table are
  structurally untouched);
- Deletes the three RBAC keys (`withdrawals.view/process/reveal`) from
  `permissions` and `role_permissions`;
- Deletes the five `withdrawal_*` rows from `settings`;
- Keeps historical `wallet_transactions` (type WITHDRAWAL), ledger entries,
  audit logs and notifications intact — the audited record of money that
  already moved;
- Keeps `statements()` empty so the generated fresh-install dump carries no
  drop-retrofit SQL, and has no `down()` — the feature is removed, not
  versioned.

Fresh installs run 001–018 with the migration as a harmless no-op; installs at
the old v17 upgrade through 018 only; installs at v≤15 pull in 016 (mass
orders), 017 (marketplace catalogue) and 018. Rehearsed for both shapes in
`WithdrawalRemovalTest::testMigration018ActuallyRunsAgainstARealDatabaseShape`.

### UX wording

The wallet is presented as a platform spending balance: the dashboard wallet
card and the transactions page carry the "pay for services, orders and other
supported purchases within the platform / the balance spends here; it does
not cash out" wording, and the add-funds page explains the balance is for
spending inside the platform.

### CI reintroduction guard

`WithdrawalRemovalTest` IS the guard, running with the standard suite in CI:
it fails the build if any withdrawal route, controller, service, model, view,
permission, setting, API endpoint, asset reference or dedicated migration
reappears, while explicitly tolerating the sanctioned 018 retrofit and
historical docs/audit data. Old URLs have no route and no controller, so CI3
serves its normal 404 for them.


## 3. Marketplace is admin-controlled (the platform is the only seller)

- **Seller policy**: `MarketplaceService::apply_seller` refuses non-staff with `CUSTOMERS_CANNOT_SELL` (customers cannot mint sellers even calling the service directly); staff profiles are auto-`APPROVED` and stamped with the acting operator. The `marketplace_require_verified_identity` setting is gone — no policy can soften the rule.
- **Customer surface**: `dashboard/Marketplace` is buyer-only — browse/search/filter by managed category, listing detail with promo display, wallet purchase (server-side price only; quantity is the single customer-chosen number), own orders, reveal/accept/dispute. Seller workspace view, apply/save/status/deliver methods and their routes are deleted.
- **Admin surface**: `admin/Marketplace` (permission-gated, POST-only mutations, audited) — listing create/edit (`marketplace.manage`), publish/unpublish/archive, moderation, managed categories CRUD, fulfil-as-platform (`deliver` with the explicit `as_admin` flag that is never inferred), reveal/resolve escrow, seller-profile moderation, analytics tab (orders by status, GMV, released, top listings, listings by status), product images through `MediaService` (sniffed/re-encoded), featured shelf.
- **Catalogue schema**: migration `017_marketplace_catalogue` adds `marketplace_categories` and `promo_price`, `image`, `is_featured`, `product_type` on listings. `docs/database.sql` regenerated (116 statements, 17 migrations).
- **Price integrity**: buyers always pay the server's price; a promo undercuts only when it is genuinely below list price (validated in `save_listing`, recomputed in `purchase`). Existing escrow invariants (idempotent purchase, optimistic stock CAS, atomic release/refund, dispute freeze) are covered by the integration suite.

## Validation evidence

| Check | Result |
|---|---|
| Full unit suite (48 classes, WASM PHP 8.2) | 47/48 classes green; only pre-existing `CronWorkersTest::testAJobCannotOverlapItself` WASM `flock()` artifact (correct on native kernels) |
| Parse lint, every PHP file fresh instance | 402/402 parsed, 0 parse failures |
| `docs/database.sql` vs migrations (`export_schema --check`) | up to date |
| `tools/validate_schema.py` (sqlglot static analysis) | OK · 119 statements · 84 tables · 117 FKs · 0 warnings |
| Asset build (`npm ci`, `npm run build:css`) | OK |
| Withdrawal sweep | 0 references in `application/` |
| Customer-seller sweep | 0 dashboard seller routes/controllers/view |
| `->decrypt(` outside provider adapters | 0 files |

New/updated tests: `WithdrawalRemovalTest` (new), `MarketplaceTest` (staff-only seller profile, listings/promos/categories, promo charge path, platform fulfilment, wiring gates), `PaymentsTest` (retryable webhook flow end-to-end), `ProductionReadinessTest` (four new preflight checks), `SecurityHardeningTest` (logout POST+CSRF, session regeneration pins, MFA open(), decrypt confinement, IDOR scope pins, mass-assignment pins), `CurrencyTest`/`DashboardTest` (post-removal expectations).
