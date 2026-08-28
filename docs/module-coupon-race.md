# Module 18 — the coupon redemption race

*Branch `arena/01a04558-windels-panel`. Follows module 17 (private attachments).*

Item 13 of [unfinished.md](unfinished.md), closed. One defect, one database
constraint, and one piece of test tooling that was hiding failures.

---

## 1. Check-then-act, at real money

Module 14 made `usage_limit_per_user` real. It enforced it like this:

```php
$used = COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?
if ($used < $limit) { INSERT … }
```

Read, decide, write — with no lock and no constraint between the three. Two
requests from the same customer that overlap by a few milliseconds both read
zero, both decide they are inside the limit, and both insert. A code created as
*one per customer* is redeemed twice.

A few milliseconds is not exotic. It is a double-clicked **Pay** button, a
retried POST on a flaky mobile connection, or two tabs. And because the panel
never notices, nothing looks wrong afterwards — two redemption rows is a
perfectly consistent state, indistinguishable from two legitimate uses.

Worse, the redemption was recorded **after** the first line was charged. So the
losing request had already moved money at the discounted price by the time
anything could refuse it. Whatever the resolution, it would have been a manual
correction against a customer who had done nothing obviously wrong.

## 2. Only the database can settle it

Migration **030** adds `coupon_redemptions.redemption_slot` — which redemption
*number* this is for this customer on this coupon — and a UNIQUE index on
`(coupon_id, user_id, redemption_slot)`. Existing rows are numbered per
(coupon, user) in id order so the historical data satisfies the index and the
sequence continues cleanly.

Now the two overlapping requests both compute slot 1, both try to insert it,
and **the database refuses the second**. There is no window, because the
decision and the write are the same operation.

`Coupon_model` gained the three verbs this needs:

| Method | When | Why |
|---|---|---|
| `reserve_redemption($coupon, $user_id)` | before any charge | takes the slot, bumps `times_used`; returns `PER_USER_LIMIT` when the cap is already spent |
| `attach_redemption($id, $order_id, $discount)` | after the charge lands | fills in the order and the amount |
| `release_redemption($id, $coupon_id)` | when checkout charges nothing | gives the slot *and* the counter back |

`ShopCheckoutService::checkout()` now reserves **before** the purchase loop and
attaches after the first order. That ordering is the point: the loser of the
race is told *"You have already used this coupon"* while nothing has been
charged, instead of being charged and reconciled later.

`release_redemption()` matters as much. Without it, a checkout that failed on
its first line — sold out, insufficient balance — would have burned the
customer's single use of a launch code on an order that never existed. That is
the most annoying possible way to lose a sale.

Two details worth stating: the reservation increments `times_used`, so a coupon
capped at 100 uses cannot be handed to 120 people who are all mid-checkout; and
the release decrements it only `WHERE times_used > 0`, so a double release
cannot make a spent coupon look fresh.

`usage_limit_per_user` of `NULL` **or** `0` still means unlimited — an empty box
in the admin form must never make a live coupon unusable. Unlimited coupons
still take a distinct slot each time, so `reserve_redemption()` retries up to
three times, walking to the next free slot rather than failing on a collision.

## 3. Two pieces of test tooling that were lying

**`FakeDb` ignored `CREATE UNIQUE INDEX`.** Every unique key in this schema had
previously been declared inline in `CREATE TABLE`, so the double only parsed
those. Migration 030 adds one afterwards — and it *is* the mechanism under
test. A double that does not model the constraint cannot test the race it
closes, so the parser now registers standalone unique indexes. It also learned
`col = col - 1`: it understood the increment form and would have stored the
literal string `"times_used - 1"` in the column for a decrement.

**`phpunit_lite.php` skipped `tearDown()` on failure.** Cleanup ran only on the
success paths, so one failing test left its fixtures behind and *later,
unrelated* classes failed for reasons that had nothing to do with them — a
leaked `STORAGE_PATH` from the module-17 tests made `ProductionReadinessTest`
report a missing storage directory. A cascade like that hides the real failure
and sends you debugging the wrong file. `tearDown()` now runs in a `finally`.

*Both doubles were corrected; no assertion was weakened.*

---

## 4. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php  # 1455 tests, 16928 assertions, 0 failures
node tools/devserver/pricing_check.mjs --admin-password '…'   # 18/18
bash tools/verify_all.sh --admin-password '…'                 # 44 passed, 0 failed
```

`tests/unit/CouponRedemptionRaceTest.php` (12 tests) writes the race the way it
actually happens — both callers read the world *before* either writes — and
asserts that the first succeeds, the second is refused with `PER_USER_LIMIT`,
exactly one row exists and `times_used` is 1. Plus: slots number 1, 2, 3; the
limit is per customer, not global; unlimited and zero-per-user keep working; a
reservation is empty until attached; a released slot is reusable; a double
release cannot go negative; checkout reserves before it charges (asserted by
source order); and the index is in both the migration and the shipped SQL.

`pricing_check.mjs` proves it against the running panel:

- the **live schema** refuses a second row in the same slot (two direct
  inserts) — without this, the reservation logic is only a slower count;
- two signed-in tabs POST `/checkout/place` **simultaneously** with the same
  one-per-customer code, and **exactly one** redemption exists afterwards —
  "exactly", not "at most", because if both requests had failed for some
  unrelated reason the invariant would hold vacuously;
- a real order came out of the winner, `times_used` agrees with the row count,
  and every redemption carries a distinct slot.

---

## 5. Still open

- **The global `usage_limit` is protected by an atomic increment, not a
  constraint.** `times_used` moves with `SET times_used = times_used + 1`,
  which cannot lose a count, but the *comparison* against `usage_limit` is
  still read separately. Closing that the same way would need a slot-style
  index over the coupon as a whole, and the exposure is one extra discount at
  the boundary rather than an unlimited leak.
- **Reservations are not expired.** A slot released only when checkout
  explicitly fails is the right behaviour for a synchronous wallet charge,
  where the request always finishes. It would need a sweeper if coupons ever
  reach an asynchronous payment flow where a customer can walk away
  mid-checkout.
- **Coupons remain shop-only** (item 1 of the unfinished list). Nothing here
  changes that.
