# Audit — physical shipping against the module 11 escrow rules

*Branch `arena/01a04b8d-windels-panel`, 2026-08-29. Closes item 4 of
[unfinished.md](unfinished.md): the physical shipping flow (migration 036
onwards) "was never re-audited against the escrow rules in module 11".*

Module 11 ([module-marketplace-fulfilment.md](module-marketplace-fulfilment.md))
set the rules every escrow-bound domain has to keep:

1. **The hold is deliberate, and only the release worker ends it** — money sits
   in `PROCESSING` until `marketplace_release` settles it; a released order is
   never swept.
2. **The abandonment sweep is domain-aware** — it waits past the domain's own
   window (`max(service_abandon_hours, marketplace_auto_release_hours + 24h)`)
   and, when it acts, it goes through the service that owns escrow
   (`MarketplaceService::refund()`), never a bare ledger reversal.
3. **A refund takes the goods back with the money** — every refund path revokes
   what was granted; a physical refund cancels the carrier record.
4. **Release and refund are mutually exclusive** — one compare-and-set claim on
   the order row; both can never win.
5. **Escrow is all-or-nothing** (module 23 later carved out *part* refunds as
   compensation that does not reverse the sale).

## What the audit verified (physical paths, file and line)

- **Purchase** (`MarketplaceService::purchase`): the carrier quote and address
  are bound to the charge *before* the wallet moves; the shipping cost is part
  of `gross_amount` and the service transaction, so a refund returns exactly
  what was paid. The shipment row is created inside `dispatch`, and its
  failure cancels the order, restores stock and rolls the charge back — there
  is no paid order without a carrier record.
- **Shipment flow** (`ShopShippingService::update`): strict
  `TRANSITIONS` map, tracking number required for `SHIPPED`, `CANCELLED` is
  only reachable as the consequence of a refund (a generic status form can no
  longer strand a paid order as CANCELLED with its money still in escrow),
  resolved orders refuse further shipment state. `DELIVERED` moves the escrow
  order `PAID → DELIVERED` and sets `release_due_at` in the same transaction;
  `RETURNED` freezes escrow into `DISPUTED` and clears the due time, so a
  returned parcel can never pass the release worker.
- **Release** (`MarketplaceService::release`): a physical order cannot be
  released unless its shipment row is `DELIVERED` (re-checked at release time,
  not only at transition time), and the order can only be `DELIVERED` through
  the shipment flow — `deliver()` refuses physical orders outright
  (`USE_SHIPMENT_FLOW`).
- **Refund** (`MarketplaceService::refund` / `ShopShippingService::refund`):
  the CAS claim means release and refund can never both commit; stock is
  restored for the units still out; the carrier record is cancelled
  (`cancel_after_refund`) after the commit, defensively in *both* entry
  points, and a failure there logs a reconciliation signal rather than
  undoing the refund.
- **Sweep** (`CronWorkers::service_recovery` / `recovery_due` /
  `recover_marketplace`): marketplace purchases use their own window and are
  closed through `MarketplaceService::refund()`, so an abandoned physical
  purchase returns the buyer's money, the stock and the carrier record
  together.

## The defect the audit found

**A part refund of a physical order in transit left the order uncloseable.**

`refund_partial()` was reachable on a `PAID` physical order whose parcel was
still with the carrier. But only a *fully paid* order can be recorded
delivered (`ShopShippingService::update` refuses `DELIVERED` for any other
status), so once the order became `PARTIALLY_REFUNDED`:

- the shipment could never be marked delivered,
- `release()` (which needs `DELIVERED`) could never run, and
- the escrow remainder would ride the abandonment sweep back to the buyer —
  who still receives the parcel.

The buyer ended up with the goods **and** the full money, and the shipment row
sat `SHIPPED` forever. That contradicts the very rule module 23 wrote for part
refunds: *compensation, not a reversal* — the buyer keeps the goods the sale
stood for, and the platform keeps the rest. (The digital analogue has no such
stranding: the file is granted at purchase, so nothing is left to record as
delivered.)

### The fix

`MarketplaceService::refund_partial()` now refuses any shipment-bound order
whose shipment is not `DELIVERED`, with the two honest options on screen —
`SHIPMENT_IN_TRANSIT`: *"This physical order is still with the carrier —
refund it in full to cancel the shipment, or wait for delivery."* A full
refund stays available in transit (it cancels the shipment, which is the right
closure), and the same part refund succeeds after delivery, when the buyer
actually keeps the goods. A `RETURNED` parcel is refused for the same test —
it is with fulfilment staff, not the buyer.

## What was proven

- `tests/unit/PhysicalShippingTest.php` — new
  `testAnInTransitPhysicalOrderCannotBePartRefunded`: refused in transit (no
  money moves, order and shipment untouched), then allowed after `SHIPPED →
  DELIVERED`.
- `tests/unit/MarketplacePartialRefundTest.php` — the ten physical-fixture
  money tests now deliver the parcel before the part refund, which is what
  every scenario they describe actually is ("arrived scratched", "chipped
  handle" …); the in-transit refusal is pinned by `PhysicalShippingTest`
  instead.
- `tools/devserver/physical_order_refund_check.mjs` — 24 → 38 checks: a second
  physical order is bought, part-refunded in transit through the real admin
  screen (refused, guidance on screen, nothing moves), marked shipped then
  delivered, and the same part refund then succeeds for exactly the part.
- Regressions green on a fresh database: `physical_product_check` 21/21,
  `marketplace_fulfilment_check` 32/32, `shop_check` 47/47, unit suite
  1604 tests / 18446 assertions / 0 failures.

Two adjacent defects found along the way (same audit, same commit): four
checks (`marketplace_fulfilment_check`, `analytics_check`, `attachment_check`,
`chrome_check`) still hardcoded demo passwords instead of taking
`DEMO_PASSWORD` — the exact violation README §Local development forbids — and
`marketplace_fulfilment_check` only bought its ₦1,500 ebook because earlier
`verify_all.sh` stages happened to have topped the demo wallet; it now funds
the wallet itself.

## Still open (policy, not physical-flow defects)

- **In-transit abandonment window is operator-tuned.** A physical parcel still
  en route past `max(service_abandon_hours, marketplace_auto_release_hours +
  24h)` is refunded by the sweep. The setting allows up to 720 h (30 days) so
  the operator can cover their carriers' worst case; with the 72 h default a
  five-day parcel would be treated as abandoned.
- **The remainder of a *delivered* part-refunded order** (digital or
  physical) is not released to the platform — it waits for the sweep or a
  full refund. Module 11's "all-or-nothing" and module 23's "compensation,
  not reversal" leave that end-state deliberately ambiguous; settling it would
  be a module 23 decision, not a shipping one.
