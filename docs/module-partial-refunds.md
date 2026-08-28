# Module 23 — refunding part of a marketplace order

*Branch `arena/01a04558-windels-panel`. Follows module 22 (cron control).*

Item 3 of [unfinished.md](unfinished.md), closed — plus an analytics check that
was asserting an invariant the ledger does not actually promise.

---

## 1. All-or-nothing stops working the moment a real dispute arrives

Escrow shipped all-or-nothing, and module 11 recorded that as a deliberate
default: for a single-seller platform, "the sale stands or it does not" is the
right first shape and the safest one to build.

Then the disputes arrive. Two dead keys in a five-licence bundle. A delivery
that turned up damaged but usable. An agreed discount after a shipment ran a
week late. Every one of those has an obvious answer, and the panel could not
express any of them. Staff had two options, both wrong:

- **refund the whole order** — and give away the three licences that worked;
- **pay the customer with a wallet adjustment** — which settles the dispute
  and leaves the order claiming it was paid in full.

The second is the dangerous one, and it is what actually happens, because it
is the one that keeps the customer happy. The money left the platform and
nothing on the order said so: `marketplace_orders` and `service_transactions`
both still read as fully paid, so **every revenue and margin figure overstated
by exactly the amount that was returned** — silently, for ever.

That is why this is a schema change and not a screen.

## 2. What a part refund means, decided explicitly

Migration 032 adds `refunded_amount` and `refunded_quantity` to
`marketplace_orders` (and backfills existing fully-refunded orders, which had
never recorded either), plus a `PARTIALLY_REFUNDED` status — because leaving
the order `DELIVERED` with a non-zero refund hides the event on every list
screen in the panel.

`MarketplaceService::refund_partial()` enforces five rules:

| Rule | Why |
|---|---|
| Never more than `gross - refunded` | refused, **not clamped**: "refund ₦5,000" and "refund the ₦2,000 that is left" are different decisions, and only a human should make the second one |
| Released escrow is out of reach | once the money is the platform's, returning it is a wallet adjustment with its own trail — and the error now says so instead of "this order cannot be refunded" |
| Stock moves only for units actually returned | a goodwill discount returns nothing to the shelf; `restock` is explicit and defaults to zero |
| A part refund does **not** revoke the goods | it is compensation, not a reversal — the buyer keeps what they part-paid for. Only refunding the last of the money revokes downloads and restocks the remainder |
| `service_transactions.refunded_amount` moves too | that is the row every revenue and margin figure reads; a refund invisible there is the wallet-adjustment bug again |

The idempotency key carries the **cumulative** refunded total, so a retried
request pays once while a genuine second refund of the same size still goes
through. Refunding the last of the money routes into the existing `refund()`,
so "this order is over" has one implementation — and a full refund after a
partial one returns only what is left, and restocks only the units still out.

---

## 3. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php   # 1525 tests, 17306 assertions, 0 failures
node tools/devserver/marketplace_fulfilment_check.mjs --admin-password '…'  # 32/32
bash tools/verify_all.sh --admin-password '…'             # 45 passed, 0 failed
```

`tests/unit/MarketplacePartialRefundTest.php` (15 tests): two of five licences
refunded with the other three still paid for; the refund visible to the revenue
tables; over-refund, zero, negative and over-restock all refused with nothing
moving; the ceiling is cumulative across two partials; released escrow refused
with a pointer to the right tool; a partial refund leaving the download intact;
the closing refund revoking it; **the buyer never paid more than they paid**;
stock restored once and only once; the audit entry and the buyer-visible
timeline note.

`marketplace_fulfilment_check.mjs` buys a five-unit bundle over HTTP, fails an
over-refund (asserting no money moved), refunds two-fifths with two units back
on the shelf, checks the order status, the `service_transactions` figure and
the timeline note, then refunds the remainder and asserts the buyer ends up
**exactly** where they started.

### An analytics check that was asserting the wrong invariant

Adding a real 5,000 charge to the sandbox tipped `debits - refunds` positive
and exposed this: `analytics_check` asserted `wallets.total_spent ==
debits - refunds` "whenever that is meaningful". The ledger does not promise
that. `total_spent` is a **running** counter floored at zero on each refund, so
once a decrement has stopped at zero the information is gone and the counter
sits permanently above the naive difference. On top of that, this dev database
contains `wallet_transactions` written **directly** by `seed_load.mjs`, which
never went through the ledger at all — so any reconstruction measures the
fixtures rather than the code.

The check now drives a real wallet adjustment through the admin form and
asserts the counter tracked it exactly: a debit of 250 moves `total_spent` by
250, and a goodwill credit moves it by nothing (only refunds reduce lifetime
spend). That is history-independent and tests the rule that actually exists.

---

## 4. Still open

- **No per-line refunds.** A cart that produced several orders is refunded per
  order; there is no "refund line 2 of this basket" view. Each order is
  already its own escrow, so this is a reporting convenience rather than a
  money problem.
- **The buyer cannot request a specific amount.** Disputes are still opened as
  free text and resolved by staff. A "I want ₦2,000 back for the two dead
  keys" field would need a negotiation state machine.
- **Partial refunds do not adjust affiliate commission.** A full refund
  reverses the commission through `sync_affiliate`; a partial one leaves it,
  which slightly overpays the referrer. Worth fixing the next time earnings
  are touched — it is a known, bounded overpayment rather than a silent one.
