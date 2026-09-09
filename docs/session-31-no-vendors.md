# Session 31 — Marketplace goes single-seller (no vendors, anywhere)

Supersedes the multi-vendor remnants of the marketplace design. The platform
is the ONLY seller; there is no vendor/seller feature at any layer.

## What was removed (real enforcement, not hidden buttons)

| Layer | Before | Now |
|---|---|---|
| Schema (fresh installs) | `015_marketplace` created `marketplace_sellers`, `listings.seller_id`, `orders.seller_id + fee_amount + seller_amount + payout_wallet_transaction_id` | Edited `015` never creates any of it; validator: 83 tables, 0 warnings |
| Schema (existing installs) | vendors table + vendor columns | **`019_remove_marketplace_vendors`** — information_schema-resolved FK detachment, then column drops, then `DROP TABLE IF EXISTS marketplace_sellers`, plus permission/settings cleanup; rehearsed against legacy AND fresh shapes in `MarketplaceTest` |
| Service | `apply_seller`, `moderate_seller`, fee split, `MARKETPLACE_PAYOUT` ledger credit on release | Gone. `release()` moves **no money** — the gross was platform revenue at purchase; refunds still go through TransactionEngine |
| Admin console | "Seller profiles" tab, `moderate_seller`, `ensure_platform_seller`, seller columns in order views | Removed. Tabs: Escrow orders / Listings / Analytics. Only listing moderation remains |
| Routes | `admin/marketplace/sellers/(:any)/moderate` | Removed — no wildcard catches it, so it 404s |
| Permissions | `marketplace.moderate_sellers` seeded + granted | Unseeded; 019 deletes it from `permissions`/`role_permissions` on upgrades |
| Settings | `marketplace_fee_percent` | Unmanaged (schema-less writes to it change nothing); 019 deletes the row on upgrades |
| Models | `Marketplace_seller_model`, seller joins/projections everywhere | File deleted; listing/order models carry no seller side |
| Ledger types | `MARKETPLACE_PAYOUT` created at release | No code path can create one; historical rows keep humanizing via the generic label fallback |

## What was deliberately kept

- Buyer escrow UX end to end: purchase → staff fulfilment (encrypted, reveal
  audited) → buyer accept / auto-release window / dispute → admin resolve
  (complete the sale, or refund exactly once). The compare-and-set claim that
  prevented double-resolution still prevents double-resolution — it now guards
  COMPLETED vs REFUNDED, not payout vs refund.
- Historical rows written while vendors existed: 019 never `DELETE`s orders
  or listings history, and the audit trail is untouched.
- All upstream-supplier vocabulary (`vendor cost`, provider adapters) — that
  is how ADMIN sources stock, not a storefront feature.

## Tests

`MarketplaceTest` (21 tests, 263 assertions) rewrites the vertical slice to
the single-seller shape and adds two source-level sweep tests
(`testThereIsNoVendorFeatureAnywhere`, `testMigrationHasNoVendorShape`), the
upgrade rehearsal (`testMigration019RetiresVendorDataOnUpgrades` +
`...RehearsesAgainstLegacyAndFreshShapes`), and a no-payout-ever pin on
completion. CI adds a reintroduction guard step ("Marketplace stays
single-seller") beside the withdrawal guard.
