# MarvySocials — Session 14: Affiliate / Referral

> First-touch referral attribution, a bcmath commission engine with a hold
> window, idempotent payouts through LedgerService, and the customer + admin
> surfaces for both. Backed by the migration-008 tables (`referral_accounts`,
> `referrals`, `referral_commissions`) — no new migration.

## What shipped

| Area | Files |
|---|---|
| Commission engine | `libraries/AffiliateService.php` |
| Models | `models/Referral_account_model.php`, `Referral_model.php`, `Referral_commission_model.php` |
| Customer surface | `controllers/dashboard/Referrals.php`, `views/dashboard/referrals/{index,commissions}.php` |
| Admin surface | `controllers/admin/Affiliates.php`, `views/admin/affiliates/index.php` |
| Attribution hook | `libraries/AuthService.php` (signup), `controllers/Auth.php` (`?ref=` capture) |
| Accrual / reversal hooks | `libraries/OrderService.php` (`sync_affiliate()`) |
| Reseller API | `controllers/Api_v1.php` → `GET /api/v1/referrals` |
| Payout worker | `controllers/Cron.php::affiliate_payouts()`, `cron/crontab.example` |
| Settings / flag / seeds | `seeds/Core_seeder.php`, `seeds/Demo_seeder.php`, `config/marvy.php` |
| Tests | `tests/unit/AffiliateTest.php` (32 tests) |

## Lifecycle

1. **Account** — every user can own one `referral_accounts` row, keyed to their
   stable `users.referral_code`. It is created lazily by `account_for()` so the
   dashboard can always render a share link, even for a user who has never
   referred anyone.
2. **Attribution** — `/register?ref=CODE` stashes the code and posts it as
   `referred_by_code`. `attribute()` links signup → referrer **once**
   (first-touch, permanent). Self-referral and referrer cycles are rejected, and
   `referrals.referred_id` is UNIQUE so a concurrent double-submit still cannot
   create a second edge.
3. **Accrual** — when an order reaches a qualifying status (`COMPLETED` or
   `PARTIAL`), `OrderService::sync_affiliate()` calls `record_for_order()`,
   which writes a `PENDING` `referral_commissions` row. The amount is computed
   with bcmath from the order charge and the account's `commission_percent`.
   Accrual is idempotent on `(referral_id, order_id)`.
4. **Payout** — `pay()` moves `PENDING → PAID` with a **compare-and-set**
   UPDATE, then credits the referrer's wallet via `LedgerService::credit()`
   under a deterministic idempotency key. Losing the CAS race means another
   worker already claimed the row, so nothing is credited twice.
5. **Reversal** — refunding or canceling an order calls `reverse_for_order()`,
   which voids commissions that have not been paid yet. Already-paid
   commissions are left alone (clawback is a deliberate admin action, not an
   automatic one).

## Settings

Seeded into `settings` under the `affiliate` category (public flag in brackets):

| Key | Default | |
|---|---|---|
| `referral_commission_percent` | `5.0000` | public |
| `referral_commission_scope` | `LIFETIME` | public — or `FIRST_ORDER` |
| `referral_hold_hours` | `24` | private |
| `referral_min_payout` | `0.01000000` | private |

The whole feature sits behind the `affiliate_program` feature flag; when it is
off, accrual, payout and the cron worker all short-circuit.

## Surfaces

* **`/dashboard/referrals`** — share link, lifetime totals (referred / earned /
  paid / pending) and the most recent referrals. `/dashboard/referrals/commissions`
  paginates the full commission ledger.
* **`/admin/affiliates`** — every account with its totals, a per-account
  commission-rate override, and a manual payout action. Both mutating routes
  are POST-only and gated on `affiliates.manage`; the list needs `affiliates.view`.
* **`GET /api/v1/referrals`** — the authenticated reseller's own account, totals
  and recent commissions.
* **Cron** — `php index.php cron affiliate_payouts` pays everything past the
  hold window (batched, 500 per run); wired into `cron/crontab.example` and the
  `config/marvy.php` cron map.

## Safety

* `AffiliateService` never writes `wallets` or `wallet_transactions` — every
  credit goes through `LedgerService` (§24/25/56).
* All money is DECIMAL-as-string with bcmath; percentages are `DECIMAL(10,4)`.
* Commission rows reference the order and the wallet transaction that paid
  them, so every payout is traceable in both directions.
* Payout is safe to run concurrently and safe to re-run: CAS on status plus an
  idempotency key on the ledger movement.
* A user can never see another user's commissions; all lookups are scoped by
  `user_id`.

## Fixes made along the way

Running the previously-unexecuted suites surfaced real production bugs, fixed
here rather than left in place:

* `OrderService::transition()` and `PaymentService::transition()` both appended
  a status-history row **without ever writing the new status** to the parent
  record — orders and payment transactions were frozen in their initial state.
* `PaymentService::record_webhook()` fell back to `ManualGateway` for every
  unknown gateway, which reports `verify_webhook() === false`. Non-manual
  callbacks now use a generic HMAC-SHA256 verifier plus a JSON envelope parser,
  and an event that cannot be verified (no secret configured) is stored but
  never processed.
* `Provider_service_model::upsert_service()` did a read-then-write against a
  UNIQUE key; two concurrent syncs of the same provider would collide. It is
  now a single `INSERT … ON DUPLICATE KEY UPDATE`.
* `ProviderSyncService::normalize_service()` only matched exact lower-case keys,
  so panels sending `ID`/`minimum`/`maximum` lost their data; key lookup is now
  case-insensitive with aliases, and `map_type()` accepts common spellings.
* `AuthService::hash_password()` dereferenced the argon2 constants
  unconditionally, fataling on builds without argon2 support.
* `dashboard/{Dripfeed,Orders,Subscriptions,Tickets,Wallet}.php` never passed
  `unread` to the app shell, silently blanking the notification badge.

## Follow-ups

* **Session 15 (Admin)** — commission clawback on an already-paid referral, and
  per-user rate overrides in the staff UI.
* Multi-tier referrals (a referrer earning on their referrals' referrals) are
  intentionally out of scope; the schema supports it via `referral_account_id`.
* Payout batching per referrer (one wallet transaction covering N commissions)
  once volume justifies it.
