# Phase A + B — Universal transaction engine and VTU

First build phase against the Master Rebuild Specification, following the plan
in `docs/rebuild-spec-audit.md`. Covers §14 (provider engine), §18 (transaction
engine), §19 (transaction model) and §9 (VTU).

## Why the engine came first

The audit found eight missing service domains. Building them one at a time,
each with its own purchase flow, would have produced six near-copies of the
order engine — and money bugs multiply per copy. So Phase A built the shared
machinery, and Phase B (VTU) is the first consumer proving it works.

The load-bearing finding from the audit: `wallet_transactions.reference_type`
is a free-text `VARCHAR(64)`, not an enum. Every new domain can post to the
existing double-entry ledger with **no change to the money tables**.
`LedgerService` remains the only writer of wallet balances.

## Migration 010

Six tables, added as 010 rather than by editing already-deployed migrations.

| Table | Purpose |
| --- | --- |
| `service_transactions` | The universal record (§19). Carries `service_domain`/`service_type`, the money, and a FK to the `wallet_transactions` row that moved it |
| `service_transaction_status_history` | Append-only status trail with a source |
| `provider_transactions` | Provider call log shared by every domain |
| `vtu_networks` | MTN/Glo/DSTV/IKEDC/WAEC — one row per network per service type |
| `vtu_products` | One products table for all VTU types, not a table per bundle shape |
| `vtu_transactions` | VTU-specific detail only: recipient, token, units. Never duplicates money |

`SchemaTest` previously asserted *exactly* 9 migrations. That assertion was
relaxed to "at least the original 9, sequential, matching `migration_version`" —
the ordering guarantee is what gave the test its value, and the fixed count
would have blocked every future domain.

## TransactionEngine

One lifecycle every domain uses:

```
validate → price → check wallet → create record → debit
   → call provider → update record → finalise ledger
```

A domain supplies only what differs, through `execute()`: the amount, a
`detail` callback that writes its own row, and a `dispatch` callback that calls
its provider. Everything money-critical is owned here:

- **Refund on failure.** A provider rejection *or* a thrown exception refunds in
  full. A purchase that fails must never quietly keep the customer's money.
- **Refund exactly once.** Guarded by `refunded_amount` and by refusing to
  transition out of a terminal state.
- **Idempotency.** A repeat with the same key resolves to the original
  transaction rather than charging twice.
- **The record exists before the provider is called**, so a failed purchase
  still shows what was attempted.

### A design bug the tests caught

I initially put `SUCCESSFUL` in the terminal-state list. That made the money
guarantees look right — and made it **impossible for an admin to refund a
completed purchase**, which §25 requires. Fixed by separating *terminal*
(`FAILED`, `CANCELLED`, `REFUNDED` — nothing further happens) from *settled*
(`SUCCESSFUL` — may still be refunded or cancelled, nothing else).

A second, subtler one: re-requesting `REFUNDED` on an already-refunded
transaction returned `ok = true` with `unchanged`, because the equality check
ran before the terminal check. A caller reading `ok` as "the refund happened"
would report a second refund that never occurred. The terminal check now runs
first and returns an explicit `TERMINAL` rejection.

Both were found by writing the money tests before wiring up the UI.

## Provider_manager

`ProviderAdapterInterface` is SMM-shaped — `createOrder`, `requestRefill`,
`getRefillStatus`. None of those verbs mean anything for an airtime top-up, so
VTU got a **sibling** interface rather than a widened one.

`Provider_manager` maps `(family, api_type) → adapter class`:

| Family | api_types |
| --- | --- |
| `SMM` | `STANDARD_SMM`, `MOCK` |
| `VTU` | `STANDARD_VTU`, `MOCK` |

An unknown `api_type` throws with the list of known types rather than returning
something unusable. Nothing outside the registry may construct an adapter.

`StandardVtuAdapter` covers the common Nigerian VTU API shape (VTpass-style:
`request_id`, `code` `000` for success). Endpoint paths come from the provider
row rather than being hardcoded, since vendors differ. All traffic goes through
`SecureHttpClient`, so the Session 17 SSRF protections apply.

## VtuService

Thin by design — validation, pricing and adapter selection only.

| Service | Pricing |
| --- | --- |
| Airtime | Variable amount, face value less `discount_percent` (₦1,000 at 2% costs ₦980) |
| Data / Cable / Exam PIN | Fixed `vtu_products.price` |
| Electricity | Variable amount with disco discount; token and units stored on the receipt |

Validation that actually matters: MSISDNs are normalised (`+2348031234567` and
`08031234567` both store as `08031234567`) and rejected if malformed; meter and
smartcard numbers are format-checked; amounts are bounded by the product; a
product cannot be bought under the wrong network; exam PIN quantity is capped.

`provider_cost` is frozen on the transaction at purchase time, so margin stays
auditable (§15).

## Async settlement

Airtime and data usually settle instantly; electricity and cable can sit in
`PROCESSING`. Until a purchase is terminal the customer has paid and received
nothing, so `cron vtu_status` (every 2 minutes) re-checks them. A provider-side
`FAILED` refunds automatically through the engine.

## Customer UI

`dashboard/vtu` with five tabs, a receipt page (showing electricity tokens and
exam PINs) and filterable history. Server-rendered PHP views using the existing
design system — no React, per §33. Forms are CSRF-protected and idempotency-
keyed so a double-click cannot double-charge.

## Result

**433 tests, 3968 assertions, 0 failures.** 25 new tests in `VtuTest.php`, run
against the real service, engine, ledger, models and migration-derived schema —
only the provider HTTP call is a double.

Three money guards were mutation-verified: removing the refund-on-failure,
dropping the idempotency check, and allowing a terminal transaction to
transition again each fail their tests.

Two pre-existing guard tests also caught problems in this work — an unpaginated
list query and a view missing design-system classes — which is the suite doing
its job.

Schema: 67 tables, 84 foreign keys, `docs/database.sql` regenerated and
validated.

## Still to build

Phases C–G from the audit, unchanged:

- **C** — cable/electricity/education are implemented in the service, but only
  airtime and data have had real provider integration exercised; meter
  verification is wired but unproven against a live vendor.
- **D** — virtual numbers + OTP (new lifecycle: reservation, expiry, messages)
- **E** — identity NIN/BVN (needs the §22 sensitive-data controls)
- **F** — gift cards + marketplace
- **G** — admin sections and analytics for every new domain (§25/§26)

Also deferred: the base currency is still `USD` while the spec shows ₦ — that
decision is still open, and changing it affects existing rows and every seeded
price.
