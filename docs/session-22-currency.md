# Session 22 — Base currency: USD → NGN (₦)

Resolves open conflict §6.2 of `docs/rebuild-spec-audit.md`.

The panel was built with `base_currency = 'USD'` — a Checkpoint-01 default that
nobody revisited — while the Master Rebuild Spec prices everything in Naira and
every domain the panel is growing into (VTU, NIN/BVN identity, exam PINs,
Nigerian virtual numbers) is settled in Naira by the vendors themselves. The two
were already contradicting each other: the VTU catalogue seeded in Session 21
quotes ₦300 for 1GB and ₦10,500 for DSTV Compact against a dollar base.

Changed now, before real priced rows accumulate.

## What "changing the base currency" actually meant

Four separate layers, each of which would have kept showing dollars on its own.

### 1. Column defaults — migration `011_base_currency_ngn`

Six tables carried `currency CHAR(3) NOT NULL DEFAULT 'USD'`: `wallets`,
`wallet_transactions`, `providers`, `orders`, `dripfeed_orders`,
`service_transactions`. Three more (`ledger_entries`, `payment_transactions`,
`referral_commissions`) are `NOT NULL` with no default and take their value from
the caller.

The in-place `CREATE TABLE` defaults were edited **and** a migration 011 was
added. Both, deliberately:

- editing the `CREATE TABLE` statements keeps a **fresh install** correct, and
  `docs/database.sql` (generated from migration `statements()`) matches it;
- migration 011 keeps an **existing deployment** correct, since a database that
  already ran 002–010 will never re-execute them.

011 creates no tables, so its `tables()` returns `array()` — which is what
`SchemaTest` asserts against the tables its statements actually `CREATE`.

### 2. It relabels; it does not convert

A row holding `100.00000000` stays `100.00000000` and starts meaning ₦100 rather
than $100. That is correct **here** because the catalogue was authored in naira
figures all along and the panel has not taken real money yet.

This is called out at the top of the migration, because it is exactly the wrong
behaviour for a deployment that *has* taken deposits — relabelling would silently
devalue every wallet by the USD/NGN rate. Such a deployment must convert balances
first, then run 011 to move the labels. `down()` reverses the labelling only.

`providers.currency` is deliberately **not** mass-updated: that column records
what a given provider bills in, and an SMM vendor invoicing in dollars must keep
saying USD. Only its column default moves.

`ledger_entries` is append-only accounting history, so it is relabelled only in
lockstep with the wallets it belongs to — no posted entry is otherwise rewritten.

### 3. `currencies` is rebased

`exchange_rate` means "units of this currency per 1 unit of base", so rebasing
from USD to NGN inverts every rate through the old NGN rate of 1550:

| code | was | now |
|------|-----|-----|
| NGN | 1550.00000000 | **1.00000000 (base)** |
| USD | 1.00000000 (base) | 0.00064516 |
| EUR | 0.92000000 | 0.00059355 |
| GBP | 0.79000000 | 0.00050968 |
| INR | 83.00000000 | 0.05354839 |
| BRL | 5.40000000 | 0.00348387 |

Foreign currencies remain available for display; none may claim to be base.

### 4. Naira-shaped operational defaults

`$5` / `$10,000` deposit bounds are meaningless in naira — left alone they would
have capped deposits at ₦10,000. Re-priced in `Core_seeder` and in 011:

| setting | was | now |
|---------|-----|-----|
| `min_deposit` | 5.00000000 | 500.00000000 |
| `max_deposit` | 10000.00000000 | 5000000.00000000 |
| `referral_min_payout` | 0.01000000 | 100.00000000 |
| `payment_methods.min_amount` / `max_amount` | 5 / 10000 | 500 / 5000000 |
| `payment_methods.currencies` | `["USD"]` | `["NGN"]` |

## One source of truth: `marvy_base_currency()`

The literal `'USD'` appeared as a fallback in ~20 places across libraries,
controllers, models, seeds and views (`$wallet->currency ?? 'USD'`,
`'currency' => 'USD'`, …). Swapping each for `'NGN'` would have reproduced the
same problem one redenomination later.

Instead `marvy_base_currency()` was added to `marvy_helper.php`. It reads
`config/marvy.php`, memoises, and falls back to `NGN` when there is no CI
instance (CLI tools, seeders, early bootstrap) rather than guessing a foreign
currency. Every one of those fallbacks now calls it, and `marvy_money()`
defaults its `$currency` argument to it.

`marvy_money()` also gained `INR` and `BRL` symbols — it previously fell
through to the bare code for two currencies it was already seeding.

## Bugs found and fixed on the way

- **`add_funds.php` hardcoded `$min = 5.0; $max = 10000.0` in the view**, ignoring
  the `min_deposit`/`max_deposit` settings rows entirely. The controller now reads
  the settings and passes them in, so the form, the hint text and the HTML `min`/
  `max` attributes finally agree with the configured policy.
- **Three live price calculators concatenated `'$'` in JavaScript** — the order
  form, the public service detail page and the Pulse homepage. These are the
  parts a customer watches update as they type, and they would have survived any
  amount of server-side currency work. All three now take the symbol from the
  server and format via `toLocaleString`. The wallet add-funds summary had the
  same bug, including a `$25.00` literal in the markup.
- The suggested deposit amount was `25.00`; now ₦5,000, clamped to the configured
  bounds.

## Tests

`tests/unit/CurrencyTest.php` (new, 12 tests) pins the contract at each layer so
it cannot quietly rot: the config value, the helper's default/override/unknown-code
behaviour and formatting, that no migration still defaults a currency column to
USD and that all six defaulted columns say NGN, that no application file
reintroduces a hardcoded USD fallback, that no view concatenates a `$` into a
live total, and that migration 011 creates no tables and never arithmetically
rewrites a money column.

Updated existing assertions: `SeedTest` (base currency + deposit bounds),
`SeedRunTest` (NGN is the single base at exactly 1.0; USD survives as a display
currency at a sub-1 rate), the `IntegrationHarness` seeds, and ~26 incidental
`'currency'=>'USD'` fixtures across seven test files.

**Suite: 453 tests, 5058 assertions, 0 failures** (from 441/4835 with 1 failure).

## Unrelated fix folded in

The one pre-existing failure, `ProductionReadinessTest::testPreflightChecksThe
RuntimeDirectories`, was a fresh-clone gap: `Preflight` requires
`application/cache/` to be writable but the directory had no tracked contents, so
a clean checkout never created it. Added `application/cache/.gitignore`
(`*` + `!.gitignore`), mirroring `storage/.gitignore`.

## Follow-ups

- Demo/seed **service rates** are still small numbers that read as dollar prices
  (e.g. 1.20 per 1000). They are illustrative placeholders an operator overwrites,
  but they look odd in naira and should be re-scaled when the demo seeder is next
  touched.
- The `currencies` rates are static placeholders. Nothing consumes them for
  conversion yet; a rate-refresh job belongs with any future multi-currency
  display work.
