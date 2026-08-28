# Module 37 — wallets that hold a foreign currency

*Branch `arena/01a04991-windels-panel`. Follows module 36 (coupons on every
purchase surface).*

Item 2 of [unfinished.md](unfinished.md) ("A. Features that are incomplete by
decision"), closed — the item that named exactly what was missing: *"Currency
is display-only; charging in a second currency needs conversion at the ledger
boundary and a refund-rate policy."* Both of those now exist, in the one
place all money already flows through.

---

## 1. What "display-only" meant in practice

Since session 22 a customer could browse a catalogue converted into USD, and
since module 24 an admin could manage exchange rates with full provenance —
but every one of those dollars was a costume. `wallets.currency` has existed
since migration 002 and said `NGN` on every row, because nothing could set it
to anything else, and nothing that charged a wallet knew what to do if it
had been. A diaspora customer who wanted to hold dollars was not refused;
the option simply did not exist. The CurrencyService header said why, and
said it honestly: making a second settlement currency real is "a large,
high-risk change to core money-movement code."

The risk lives in one question: *where does the conversion happen?* If every
engine (OrderService, TransactionEngine, ShopCheckoutService, PayoutService,
AffiliateService, PaymentService…) converts for itself, there are six
definitions of the rate, six rounding policies and six refund behaviours —
and they will drift. This module's answer is that the conversion happens
**nowhere except LedgerService**, the only writer to wallets,
wallet_transactions and ledger_entries. Every amount passed to
`charge()`/`credit()`/`refund()` remains denominated in the base currency —
the convention every caller already used — and the ledger converts it if,
and only if, the wallet it is moving holds something else.

That single decision is why **no engine was rewired**. An SMM order, a VTU
purchase, a marketplace checkout, a deposit, a commission credit and a
payout settled as wallet credit all support a foreign wallet through the
boundary they already charge through. The engines stay currency-blind; a
source-gate test fails if any of them reads an exchange rate itself.

## 2. Choosing the currency

A wallet's currency is a **one-time choice, available only while the wallet
is empty and has never moved money** — offered to the customer on the
add-funds page and to staff on the admin customer file, enforced by the same
rule for both. After the first movement the choice is frozen for everyone,
because re-labelling a wallet with history re-denominates every balance and
movement on it: the exact silent-devaluation failure migration 011 documents
for the base currency, which this panel does not intend to commit twice.

Foreign currencies are opt-in per currency by the operator: the same
enable/disable switch the admin currencies screen already manages. A wallet
may only take a currency that is enabled; a currency a wallet already holds
keeps working even if the operator later disables it for new wallets (its
rate row is still there — the disable switch stops new choices, it does not
strand existing holders).

Staff adjustments (`adjust()`) are deliberately different from programmatic
movements: a human types the number against the balance on the screen they
are looking at, so the amount is **in the wallet's own currency** and no
conversion happens. The admin adjust form's label follows the wallet.

## 3. The conversion, and the books

A charge of ₦980 into a wallet holding USD at 0.00064516 USD/₦ debits
$0.63225680 and writes a **four-legged ledger entry** — the plain two-legged
pair this ledger has always written is in one currency, and a movement
between currencies must not pair a dollar leg against a naira leg directly:

```
wallet:7   DEBIT    0.63225680  USD      fx:USD  CREDIT  0.63225680  USD
fx:USD     DEBIT  980.00000000  NGN      revenue CREDIT 980.00000000  NGN
```

Each currency's books balance on their own (and the existing global
debits-equal-credits invariant holds too). `fx:USD` is the translation
account — the platform's currency position: its dollar sub-balance is the
foreign currency taken in, its naira sub-balance the value handed out, and
their difference at today's rate is the unrealised FX position, as an
auditable account rather than a hope. The counter account keeps the meaning
it always had (revenue on a charge, liability on a credit).

Migration **035** records the conversion where it happened, on the movement:
`wallet_transactions.fx_rate` (the pinned rate) and `base_amount` (what the
movement was worth in base at that rate), both NULL when nothing was
converted. A transaction list can now show "$0.63 · ≈ ₦980" without
re-deriving anything from today's rate — the customer's transactions page
does exactly that.

## 4. The refund-rate policy

**A refund converts at the rate pinned on the charge it reverses — never the
day's rate.** The customer gets back exactly the wallet currency that was
taken (proportionally, for a partial refund), so FX drift can never make a
refund create or destroy money. The e2e check proves it live: charge at
0.001, move the rate to 0.002, cancel the order — $0.0005 comes back, not
$0.001.

Enforcement lives in the ledger, not in callers' discipline:

- `LedgerService::refund()` locates the original converted DEBIT by
  reference and replays its pinned rate. Order charges are now **stamped**
  with the order's `public_id` after the order row is created (they were
  previously reference-less, which also made "which wallet movement paid for
  order X?" unanswerable — an auditability gap this fixes on its own);
  dripfeed charges get the same stamp; TransactionEngine already charged and
  refunded against the same service-transaction reference.
- A caller holding the original movement (a same-request rollback: persist
  failure, submit failure) passes the rate explicitly.
- Marketplace refunds look up the pinned rate from the service-transaction
  charge their order was paid through and pass it — their refund reference
  (the marketplace order) deliberately differs from the charge's.
- A goodwill refund with no prior charge converts at the current rate and
  **pins it on its own row** — the answer is always recorded, never guessed
  twice.
- A wallet whose currency row has vanished is refused outright
  (`CURRENCY_UNAVAILABLE`) rather than moved at an invented rate.

A base-currency wallet takes exactly the path it always took: two ledger
legs, no fx columns, byte-identical rows. Nothing about existing money
changed.

## 5. The pre-check that had to learn

`TransactionEngine` compares the wallet balance against the charge before
calling the ledger, for a friendly error. Comparing a dollar balance against
a naira amount would refuse every foreign-wallet purchase that could afford
it — so the pre-check now asks the boundary:
`LedgerService::covers($wallet, $base_amount)` values a foreign balance at
the current rate (the same rate the charge would use), with a one-unit
tolerance so a balance that is exactly enough is never refused here and
then accepted by the authoritative `FOR UPDATE` check inside `move()`.

## 6. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php   # 1597 tests, 18052 assertions, 0 failures
node tools/devserver/currency_wallet_check.mjs            # 28/28
node tools/devserver/coupon_domains_check.mjs             # 29/29 (module 36 still green)
```

`tests/unit/ForeignWalletTest.php` (17 tests) drives the boundary directly
and through the real services: the choice (virgin yes, used never, unknown
and disabled refused); a base wallet's charge byte-identical to before; a
converted charge with the exact wallet amount, pinned rate, base amount and
four balanced legs; insufficient balance judged in the wallet's own
currency; a missing rate row refused; an adjustment typed in wallet
currency with a plain two-legged entry; the refund-rate policy (full and
partial refunds replaying the pinned rate after the rate moved, an explicit
rate for same-request rollbacks, a goodwill refund pinning today's); an SMM
order and a VTU purchase from a USD wallet with the charge stamped and the
order still priced in naira; admin totals reported per currency and never
added together; and source gates (the adjust form labels the wallet's
currency, every customer-facing balance formats in it, the engines read no
exchange rates themselves).

`currency_wallet_check.mjs` proves it against the running panel end to end:
the admin fixes a rate; a customer picks USD on the add-funds page; the
admin file labels the adjust form in dollars and funds $100; an SMM order
debits the converted amount with the rate pinned and the charge stamped;
the rate moves; a staff cancel-with-refund returns **exactly** what was
taken; a VTU purchase converts at the current rate; the frozen choice, the
per-currency ledger balance, the per-currency admin wallets summary, the ≈₦
annotation — and a naira customer whose experience is unchanged to the
kobo.

`migration_version` → 35; `marvysocials.sql` and `application-deployment.zip`
regenerated and re-verified.

*One dev-tooling note for whoever runs this later: the php-wasm dev database
serialises every session over a single shared SQLite handle, so concurrent
PHP workers can interleave `BEGIN`/`COMMIT` across sessions and wedge a
worker at 0% CPU. Run the dev server with `--workers 1` for e2e scripts
(`node tools/devserver/server.mjs --host 0.0.0.0 --port 8080 --workers 1`);
production MySQL gives every connection its own transaction scope and is
unaffected.*

## 7. Still open

- **Gateway deposits in a foreign currency** remain display-side only:
  `payment_methods.currencies` is a ₦ list today, and the mock/manual
  deposit path credits the base amount which the boundary then converts. A
  gateway that actually charges in USD (Stripe, PayPal) needs its
  `currencies` list extended and its amount passed through — C-category
  work that needs live credentials to prove.
- **The `fx:{CODE}` position is not revalued anywhere.** The account
  accumulates the taken-in and handed-out sides at historical rates; an
  operator who wants a marked-to-market FX gain/loss figure still computes
  it from the account plus today's rate.
- **Rounding is truncation at 8dp** (`bcmul`), the same convention
  `CurrencyService::convert()` already used for display. A charge and its
  full refund replay the same truncation, so a full refund always returns
  exactly what was taken.
- **The global coupon `usage_limit`** (module 18's open edge) and the other
  unfinished items are unchanged by this module.
