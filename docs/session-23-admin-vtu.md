# Session 23 — the admin VTU queue

Session 21 shipped the VTU domain, the universal transaction engine and the
`vtu.view` / `vtu.manage` / `vtu.refund` permissions. It did not ship anything
that rendered them. The result was a real operational hole:

- A purchase left `PROCESSING` (provider accepted, outcome unknown) could only
  be settled by waiting for the `vtu_status` cron. Support had no way to act
  while the customer was on the phone.
- `TransactionEngine::transition()` supported an admin refund from day one —
  §25 requires it, and Session 21 explicitly fixed the state machine so a
  `SUCCESSFUL` purchase stays refundable. There was no button anywhere.

This session builds that surface, following the `admin/Orders` template so the
back office stays consistent.

## What shipped

| File | What it is |
|---|---|
| `application/controllers/admin/Vtu.php` | The queue: `index`, `detail`, and two POST mutations |
| `application/views/admin/vtu/index.php` | Filterable, paginated queue with status cards |
| `application/views/admin/vtu/detail.php` | One purchase: facts, actions, status history, provider call log |
| `application/models/Service_transaction_model.php` | `admin_search`, `admin_count`, `status_counts`, `admin_find` |
| `application/config/routes.php` | `admin/vtu`, with action routes before the catch-all |
| `application/views/layouts/app.php` | VTU entry in the admin nav, gated on `vtu.view` |
| `application/views/partials/icon.php` | The missing `smartphone` glyph |
| `application/libraries/DashboardStats.php` | `SUCCESSFUL` badge mapping |
| `tests/unit/AdminVtuTest.php` | 27 tests |

## Design notes

### The controller owns no money logic

Both mutations delegate to `TransactionEngine::transition()`:

- **`refund`** (`vtu.refund`) → `transition($id, 'REFUNDED', 'ADMIN', $reason)`.
  The engine caps the refund at `amount - refunded_amount`, writes the ledger
  entry through `LedgerService`, and rejects a second attempt with
  `code = TERMINAL`. A double-clicked button cannot pay twice.
- **`recheck`** (`vtu.manage`) → the adapter's `status()`, then the same
  `transition()` the cron worker calls. A confirmed failure refunds
  automatically, because that path is shared rather than reimplemented.

So `AdminVtuTest` can assert `ledgerservice->` never appears in the controller,
and neither does `update('service_transactions'` — the status column belongs to
the engine.

### Re-check reuses the cron path deliberately

`VtuService` has no requery method; settlement lives in
`CronWorkers::vtu_status()`. Rather than add a second code path, `recheck()`
does what the worker does for exactly one transaction, and logs the call to
`provider_transactions` with `action = 'STATUS'` so the audit trail shows who
asked and what came back. It refuses anything that is not `PROCESSING` with a
provider reference, so it can never be used to "re-open" a settled purchase.

### Domain scoping

`admin_find($public_id, 'VTU')` takes the domain as a second argument and the
controller always passes its own. `service_transactions` is the universal table
— phases D–G will add `NUMBER`, `IDENTITY`, `GIFTCARD` rows to it — so without
that predicate `/admin/vtu/<a gift card id>` would happily render a gift card in
the VTU screen. `status_counts($domain)` is scoped for the same reason: a header
card counting other domains misreports the queue.

### Two gaps found while building

1. **`views/partials/icon.php` had no `smartphone` glyph.** Unknown names render
   nothing, silently. The customer nav has referenced `smartphone` since Session
   21 and has been showing a blank icon ever since. Added the glyph, and a test
   now walks the admin nav asserting every icon it names actually exists.
2. **`DashboardStats::status_badge()` had no `SUCCESSFUL` mapping.** Service
   transactions end `SUCCESSFUL`; orders end `COMPLETED`. Every delivered VTU
   purchase would have rendered in the neutral "unknown status" grey.

## Test-harness work

Three `FakeDb` gaps had to close before the new queries could be tested rather
than merely grepped. Each was a case where production code already used a
builder method the double ignored:

- **`like()` / `or_like()`** were no-ops, so `Order_model::admin_filters()`'s
  search branch had never actually been exercised. Now modelled as one OR group
  AND-ed with the rest of the predicate, which is what
  `group_start()->like()->or_like()->group_end()` compiles to.
- **`group_by()` + `COUNT(*) AS c`** did not exist, so `status_counts()` in both
  `Order_model` and `Ticket_model` was untestable.
- **`select('t.*, other.col AS alias')`** dropped both the base row and the
  alias. `t.*` now selects the whole row, and `AS alias` materialises the
  aliased value, so a test sees the same columns MySQL would return.

That last one is why `testTheQueueJoinsTheContextTheListNeeds` is meaningful: it
asserts every column the index view reads comes back from the single joined
query, which is what keeps the list off an N+1.

## Verification

- Suite: **480 tests, 5,199 assertions, 0 failures** (was 453 / 5,058).
- Both views were additionally rendered offline through the real
  `layouts/app` shell against harness-generated data — six purchases across two
  customers covering all five service types plus a refunded row — confirming the
  ₦ formatting, the CSRF fields, the read-only notice when permissions are
  missing, and that the re-check button appears only on a `PROCESSING` row.

## Still open

Unchanged from Session 21, minus item G-for-VTU:

- **C** — cable/electricity/education work against the `MOCK` adapter only;
  meter verification is wired but never exercised against a live vendor.
- **D** — virtual numbers + OTP
- **E** — identity NIN/BVN (needs the §22 sensitive-data controls)
- **F** — gift cards + marketplace
- **G** — admin sections for the domains above. This session establishes the
  pattern they should copy: a domain-scoped `admin_search`/`admin_find` on
  `Service_transaction_model` plus a thin controller that only ever calls
  `TransactionEngine::transition()`.
