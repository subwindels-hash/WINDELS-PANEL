# Module 24 — the commission that outlived the sale

*Branch `arena/01a04558-windels-panel`. Follows module 23 (partial refunds).*

The open item module 23 left behind, closed: *"partial refunds do not adjust
affiliate commission … a known, bounded overpayment rather than a silent one."*
It is neither now.

---

## 1. Priced once, never revisited

`AffiliateService::record_for_order()` prices a referral commission from the
order's **net** charge — right at the moment it accrues, and never looked at
again. The method is idempotent by design: it finds the existing commission
row and returns it. That is correct for its own job and wrong for what happens
next.

```
order completes at ₦2,000 → commission accrues at 10% = ₦200
an hour later the provider admits 500 of the 1,000 never landed
₦1,000 goes back to the customer
the commission is still ₦200 — on a sale now worth ₦1,000
```

The referrer keeps a commission on money the platform gave back. Nothing in
the panel notices, because after the fact the data is perfectly consistent:
one order, one commission, both real. It is exactly the shape of every defect
in this project — *a rule that is applied once and never re-applied.*

The reachable path is not exotic. `apply_partial()` recomputes the refund and
pays the difference every time a provider re-reports a shortfall, and
providers routinely report twice: "200 short", then "actually 500 short". The
first report accrues the commission; the second grows the refund underneath
it.

## 2. Commission follows the money

`resync_for_order()` re-prices the commission against the order's current net
charge, and `OrderService::sync_affiliate()` calls it on every qualifying
transition instead of the accrue-only path:

| Situation | What happens |
|---|---|
| No commission yet | ordinary accrual, unchanged |
| PENDING, sale shrank | **re-priced down** to the new net |
| PENDING, whole sale refunded | **reversed** — there is nothing to take a share of |
| PENDING, unchanged | left alone, no write |
| **PAID** | not touched; the overpayment is calculated, logged and audited |

`Referral_commission_model::reprice()` updates `PENDING` rows only, with a
compare-and-set on the status, so a re-price and a payout racing each other
cannot both win.

The PAID case is the interesting one. Clawing money out of a referrer's wallet
because a customer got a refund is a decision with a human in it — an operator
may decide the referrer did nothing wrong. What is **not** acceptable is
absorbing it silently, so the exact overpayment is written to the audit trail
as `affiliate.commission.overpaid` with the amount paid, the amount actually
earned and the difference. The rule module 8 established for refunds applies
here too: *money moving without anybody being told is the defect.*

---

## 3. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php  # 1532 tests, 17329 assertions, 0 failures
node tools/devserver/refunds_check.mjs --admin-password '…'  # 36/36
bash tools/verify_all.sh --admin-password '…'                # 45 passed, 0 failed
```

Seven new tests in `AffiliateTest`: a refund after accrual re-prices the
pending commission (5% of the 6,000 actually paid, not of the 10,000 charged);
resync does not accrue twice; resync accrues when nothing exists yet; a full
refund reverses it; **a paid commission is reported, not quietly edited**, with
the overpayment named; an unchanged order writes nothing; and the order
lifecycle actually calls the resync.

`refunds_check.mjs` proves it over HTTP with the real two-report sequence: the
provider says 200 short (₦400 back, commission accrues at ₦160 on the 1,600
paid), then says 500 short (₦1,000 back) — and the pending commission follows
the money down to **₦100**, with no second commission created for the order.
`FakeDb`'s commission double gained `reprice()` mirroring the model's
PENDING-only rule.

### Environment note

The sandbox was rebuilt mid-module (a reset wiped `node_modules`, `vendor`,
`system` and the dev database). Two failures that appeared afterwards were
proved **not** to be this change by stashing it and re-running: a
`migrations.version` of 16 on a database built by importing the schema dump
(so `/health/ready` reported `schema: fail`), and leftover `E2E…` fixture
services from an interrupted checker run, whose provider pointed at a fake
panel that was no longer listening — every later checker that picked "the
first orderable service" got *"Empty reply from server"*. Both cleaned; the
full sweep is green again.

---

## 4. Still open

- **No automatic clawback of a paid commission.** By design. The audit entry
  is the handle; an operator uses a wallet adjustment if they decide to
  recover it. A screen listing overpaid commissions would be the natural next
  step.
- **Marketplace partial refunds do not touch commissions** because marketplace
  sales do not accrue percentage commissions at all — referral rewards there
  are fixed per-signup amounts (`EarningsService`), which a later refund does
  not proportionally overpay.
- **`total_earned` on the referral account is not re-summed** when a
  commission is re-priced. It is a display counter fed by payouts; the payable
  figures come from the commission rows themselves.
