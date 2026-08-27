# Delivery report — Fundsvera payments, referrals, earnings and payouts (§18–§35)

Branch: `arena/01a03fe7-windels-panel`

Built against the **actual** Fundsvera API documentation (fetched from
`fundsvera.co/docs`), not assumed behaviour.

---

## 1. Files changed

### New — Fundsvera
| File | Purpose |
| --- | --- |
| `application/libraries/FundsveraGateway.php` | API client: secured-checkout, virtual accounts, HMAC webhook verification, event parsing |
| `application/models/Fundsvera_checkout_model.php` | One row per checkout; holds the amount the webhook is validated against |
| `application/models/Fundsvera_virtual_account_model.php` | Persistent per-customer bank account |
| `application/controllers/Payments.php` | `initialize` / `history` / `:reference` JSON API |
| `application/migrations/022_fundsvera_payments.php` | Payment reference + lifecycle columns, two Fundsvera tables |

### New — referrals, earnings, payouts
| File | Purpose |
| --- | --- |
| `application/libraries/ReferralService.php` | Codes, attribution, qualification, fraud rules |
| `application/libraries/EarningsService.php` | The earnings ledger (credit, release, reverse) |
| `application/libraries/PayoutService.php` | Balance locking and the withdrawal workflow |
| `application/models/Referral_code_model.php` | Vanity codes (`JOHN8K24`) |
| `application/models/Referral_campaign_model.php` | Advertising codes + performance maths |
| `application/models/Referral_visit_model.php` | Click attribution (hashed, never raw IPs) |
| `application/models/Referral_signup_model.php` | The qualification state machine |
| `application/models/Earning_model.php` | Ledger reads; balances are SQL SUMs |
| `application/models/Payout_request_model.php` | Payout requests |
| `application/controllers/Referral_api.php` | Referral / earnings / withdrawal JSON APIs |
| `application/controllers/dashboard/Earnings.php` | Customer earnings wallet |
| `application/controllers/admin/Payouts.php` | Payout queue, ledger, referral review, campaigns |
| `application/migrations/023_referral_earnings_payouts.php` | Six new tables |
| `application/views/dashboard/earnings/*` | Customer wallet + history |
| `application/views/admin/payouts/*` | Queue, ledger, referrals/campaigns |

### Modified
`PaymentService.php` (Fundsvera routing, `UNDERPAID` handling, transaction resolution order, `FIRST_DEPOSIT` hook) · `OrderService.php` (`FIRST_ORDER` hook) · `Auth.php` (referral capture + attribution + `EMAIL_VERIFIED`) · `Payment_transaction_model.php` (`for_user_reference`) · `SettingsService.php` (11 settings) · `Core_seeder.php` (payment method + 3 permissions) · `routes.php` · `config.php` (CSRF exemptions) · `layouts/app.php` (nav) · `.env.example`

### Tests
`tests/unit/EarningsPayoutIsolationTest.php` (new, 7 tests) · `tools/devserver/fundsvera_check.mjs` (31 checks) · `tools/devserver/earnings_check.mjs` (24 checks) · updates to `WithdrawalRemovalTest`, `CurrencyTest`

---

## 2. Database migrations

**022** — `payment_transactions` gains `internal_reference` (UNIQUE), `provider`,
`payment_method`, `initiated_at`, `paid_at`, `failed_at` (existing rows
backfilled before the index is added); new `fundsvera_virtual_accounts`,
`fundsvera_checkouts`.

**023** — `referral_codes`, `referral_campaigns`, `referral_visits`,
`referral_signups`, `earnings`, `payout_requests`.

Both are additive and re-runnable. Applied cleanly to a populated database
during testing.

---

## 3. Fundsvera integration points

```
PaymentController → PaymentService → GatewayInterface → FundsveraGateway → Fundsvera API
```

- `POST /api/v1/secured-checkout` — a 30-minute account + signed checkout URL
- `POST /api/v1/create-virtual-account` — standing account, one per customer
- Auth: `Authorization: Bearer {secret}` + `Public-Key: {public}` headers
- `request_id` ≥ 20 chars, unique per business, derived from `internal_reference`

**Credentials never touch the frontend, the repo or a JS bundle.** They live in
`.env` or as `secret`-typed settings that render as a masked placeholder and are
never echoed back into HTML.

---

## 4. Webhook endpoint and security

`POST /api/payments/webhooks/fundsvera` (also `/webhook/fundsvera`)

All eleven required steps are implemented: raw body read → `X-FUNDSVERA-SIGNATURE`
read → HMAC-SHA256 recomputed → `hash_equals()` timing-safe compare → invalid
rejected 401 → duplicate check on `(gateway_type, event_id)` UNIQUE → reference
matched to `fundsvera_checkouts` → **amount compared against the quote stored at
initiation** → status checked → credited inside a DB transaction via
`LedgerService` with an idempotency key → provider reference recorded → 200.

Two failures found and fixed while testing: the webhook controller accepted POST
only (Fundsvera's callback would have been rejected), and the new path was not
CSRF-exempt (419). Either would have meant deposits silently never crediting.

**No credentials ⇒ no credit.** With no secret configured, verification returns
`NULL`, the event is stored for inspection, and no money moves.

---

## 5. Referral routes and APIs

```
GET  /api/referrals/my-code      GET  /api/earnings
POST /api/referrals/validate     GET  /api/earnings/history
GET  /api/referrals/dashboard    POST /api/withdrawals
GET  /api/referrals/history      GET  /api/withdrawals/history
POST /api/payments/fundsvera/initialize
GET  /api/payments/history       GET  /api/payments/:reference
```

Plus `/dashboard/earnings`, `/admin/payouts`, `/admin/earnings`,
`/admin/referrals`, `/admin/campaigns`.

Link format: `https://<your-site>/register?ref=JOHN8K24`, built from the
configured base URL. (Your example used `halykpetroleum-kz.com`, an unrelated
domain — I assumed that was a copy-paste.)

---

## 6. Earnings wallet

Five states, reported separately and never summed: `PENDING`, `AVAILABLE`,
`LOCKED`, `PAID`, `REVERSED`. Every figure is a `SUM` over the ledger — there is
no cached balance column that can drift. A reversal writes an offsetting entry;
nothing is edited or deleted.

---

## 7. Withdrawal workflow — status and a design decision

**Working:** request → validate available balance → check minimum → lock
specific earning rows → staff approve/reject → record reference → settle.

Two deliberate departures from the brief, both to avoid a real problem:

**(a) Earnings are a separate ledger from the deposit wallet.** Migration 018
removed wallet withdrawals as an AML control — a balance you can top up with a
card and then cash out makes the platform a money transmitter. Referral earnings
are money the platform *owes* users, which is ordinary commission settlement. So
they get their own payable balance, and `PayoutService` can only ever reserve
from `earnings`. `EarningsPayoutIsolationTest` fails the build if a payout can
ever reach a wallet.

**(b) Payouts settle manually.** Fundsvera's documented API is collections-only —
`create-virtual-account`, `secured-checkout`, inbound webhooks. There is no
disbursement endpoint, so earnings cannot be paid out *through Fundsvera* as
§29 assumed. Staff send the transfer through their own bank and record the
reference; `mark_paid()` refuses an empty one. Cash payouts default to **off**;
users can always convert earnings into spendable wallet credit instead.

---

## 8. Fraud prevention

| Rule | Handling |
| --- | --- |
| Self-referral | Rejected outright |
| Same email identity (`+tag`, gmail dots) | Flagged for review |
| Referral loop (A→B→A) | Flagged |
| IP/device velocity | Flagged above the configured daily cap |
| Referrer cap reached | Flagged |
| Inactive referrer | Flagged |
| Duplicate attribution | **Impossible** — UNIQUE on `referred_user_id` |
| Duplicate earning | **Impossible** — UNIQUE idempotency key per event |
| Duplicate webhook | **Impossible** — UNIQUE `(gateway, event_id)` |
| Frontend deciding rewards | Amounts read from code/campaign config, never the request |
| Concurrent payouts | Compare-and-set row locking; one open request per user |

A click never earns. Qualification requires a configured event.

---

## 8b. Post-delivery completion pass

Four gaps found and closed after the initial delivery, by auditing the code
rather than trusting the report:

| # | Gap | Why it mattered |
| --- | --- | --- |
| 1 | No cron released held earnings | `release_due()` and `expire_stale()` existed but nothing called them, so an earning with a holding period sat PENDING **forever** — the hold was a life sentence, not a delay. Now `earnings_release` (10m) and `fundsvera_expire` (5m), in the schedule *and* in `crontab.example`. |
| 2 | No admin webhook viewer (§32) | A callback that arrived but could not be verified was invisible. Now Admin → Payments → Webhook events, with health counters and an idempotent Reprocess. |
| 3 | Bank details never shown to the customer | The deposits page rendered instructions only for `manual`, so a Fundsvera deposit was uncompletable — no account number, no amount. |
| 4 | `?ref=` captured only on `/register` | An advert pointing at the homepage lost attribution entirely; the campaign looked like it converted nobody. Now captured on every page, with the landing path recorded. |

Plus one pre-existing bug found while testing: `admin_search()` joined `users`
but `admin_count()` did not, so **any** payment search returned HTTP 500.

### Second completion pass

| # | Gap | Resolution |
| --- | --- | --- |
| 5 | `geo_allow` was stored but never checked | A campaign advertised as region-locked accepted anyone. Now enforced at resolve time from the CDN's country header, **failing open** when no country is known so a restriction cannot silently block the whole world. Admin shows which behaviour is active. |
| 6 | Dashboard endpoints undocumented | `/api/docs` covered only the key-authenticated reseller API. The session-authenticated payment/referral/earnings/withdrawal endpoints now have their own clearly separated section. |

Audited and found **already correct**, so deliberately left alone: campaign
`ends_at`/budget/`max_rewards` are enforced at resolve time; the RBAC editor is
database-driven and already lists `earnings.view`, `earnings.manage` and
`payouts.review` with none flagged unenforced.

## 9. Test results

**PHP suite: 1,234 tests, 0 failures**, 1 documented platform skip.
**227 end-to-end checks across 10 suites**, all passing and stable across
repeated runs (`fundsvera_check` now 38, covering geo restrictions).

**End-to-end, 220 checks across 10 suites, all passing:**

| Suite | Result |
| --- | --- |
| `fundsvera_check` | **31/31** — unsigned refused (401), forged signature refused, underpayment recorded but not credited, correct payment credited, replay does not double-credit, unknown reference ignored |
| `earnings_check` | **24/24** — order qualifies referral, exactly one earning, duplicate order does not pay twice, over-withdraw refused, below-minimum refused, locking works, second request refused, settlement requires a reference |
| `commerce_check` | 24/24 | `content_check` | 18/18 |
| `journey` | 38/38 | `admin_check` | 18/18 |
| `blockonomics_check` | 14/14 | `pin_check` | 13/13 |
| `responsive_check` | 16/16 | `smoke` | 24/24 routes |

Verified against the database, not the UI: the ledger sums to what was earned,
and paying earnings never touched the deposit wallet.

---

## 10. Still required before live activation

**Fundsvera**
1. **Credentials** — business onboarding, then `FUNDSVERA_PUBLIC_KEY` /
   `FUNDSVERA_SECRET_KEY` in `.env` (or Admin → Settings).
2. **Callback URL** in your Fundsvera profile:
   `https://yourdomain.com/api/payments/webhooks/fundsvera`
3. **One real test deposit.** Every decision the panel makes is verified, but no
   request has been made to the live Fundsvera service from here. Their
   integration team offers free assistance.
4. Activate the `fundsvera` payment method (ships inactive).
5. NGN only, minimum ₦100 — their documented constraints.

**Payouts — confirm before enabling `earnings_payouts_enabled`**
6. **Licensing** for paying commissions in your jurisdiction.
7. **KYC/identity verification** on payees (the panel has Dojah wired for this).
8. **Tax** — withholding and reporting obligations.
9. **A payout rail** — currently manual bank transfer. If you want automation,
   a disbursement provider is needed; Fundsvera does not document one.

**Referrals**
10. Set the reward, qualifying event and holding period in Admin → Settings.
    They default to a zero reward, so nothing pays until you configure it.

**Unverified here:** MySQL parity (the dev database translates to SQLite; CI runs
against real MySQL), and any live third-party API response.
