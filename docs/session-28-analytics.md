# Session 28 — Unified history and cross-domain analytics (phase G)

Phase G of the [rebuild-spec build order](rebuild-spec-audit.md#7-proposed-build-order):

> **G** — Unified history (§20), admin sections + analytics (§25/§26) for all
> new domains — *cross-cutting; cheapest once the domains exist*

The only phase with no new domain in it, no migration and no vendor. Its whole
job is to make the six things this panel already sells visible in one place —
once for the customer, once for the operator.

It is also the phase that found a bug worth the whole session.

## The bug

`AdminStats::revenue()` read one table:

```php
->get('orders')->row();     // ...and nothing else
```

SMM orders live in `orders`. VTU, virtual numbers, identity checks and gift
cards live in `service_transactions` (§19's universal record). So from session
21 — when VTU shipped — to session 27, **the admin landing page reported every
service-domain sale as zero revenue**. "Net revenue today", "Net revenue · 30
days", and the recent-sales list all silently excluded four of the six things
the panel sells.

Nothing failed. Nothing logged. The first number an operator sees on the first
screen they open was simply wrong, and got wronger with every domain added.

This is the characteristic failure of a two-table design, and the two tables are
worth keeping: an SMM order genuinely has columns a NIN lookup does not —
quantity, remains, drip-feed schedule — and merging them would have meant a
dozen nullable columns and a status vocabulary meaning different things per row.
The cost of that decision is exactly this: **any figure claiming to be "revenue"
has to read both tables, or it silently under-reports.** That cost was never
paid until now.

Both halves are now pinned:

- `testRevenueCountsEveryDomain` sells one of each and asserts the total.
- `testEveryRevenueFigureReadsBothMoneyTables` greps each revenue method's body
  for both table names, so a refactor that drops one fails structurally.

Reintroducing the original bug fails **4 tests**, which is how I checked the
tests were worth writing rather than decoration.

## What the operator gets

`admin/analytics`, gated on `reports.view`, read-only — no forms, no actions, no
writes anywhere in the controller or the two libraries behind it. It answers the
slow question the overview cannot: *which of the six things we sell is worth
selling.*

| Panel | What it answers |
| --- | --- |
| Revenue by domain | Sales, gross, refunded, net and margin per domain, biggest earner first |
| 14-day sparkline | Whether the trend is up, with empty days rendered as zero bars rather than skipped |
| Delivery health | In-flight, stuck and success rate per domain |
| Vendor reliability | Success rate and latency per vendor, **worst first** |

Three decisions in there are load-bearing:

**Unknown margin is not zero margin.** Dojah bills its own prepaid wallet and
reports no per-lookup cost; a 5sim price that could not be converted to naira is
stored NULL rather than as a rouble figure. So `margin` is `null`, rendered as an
em dash, when nothing was costed — and where a domain is *partly* costed the
table shows the denominator ("13 of 26 costed"). A margin over a small costed
count is not the whole picture, and hiding that would make it look authoritative.

**Success rate excludes in-flight purchases.** Counting a purchase that is
legitimately still processing as a failure would make every busy minute read as
an outage. A domain where nothing has settled yet reports `null`, not 0%.

**Vendor reliability reads the call log, not our bookkeeping.**
`provider_transactions` records what happened on the wire — latency and error
included — so it answers "is this vendor healthy?" independently of whether we
managed to refund the customer afterwards. `providers.health_status` is the last
probe; this is the trend.

The overview page also changed: "Recent orders" is now "Recent sales" and spans
every domain, there is a 30-day revenue-by-domain summary, and a new
**Services stuck >30m** card. That window is far tighter than the SMM one (24h)
on purpose — these domains settle in seconds, so a gift card still processing
after half an hour is a customer who paid and has nothing.

## What the customer gets

`dashboard/history` — one list of everything they have bought, filterable by
domain and status.

Before this, "what have I bought?" had five answers on five pages
(`/orders`, `/vtu`, `/numbers`, `/identity`, `/giftcards`) and none of them was
the whole list. A customer who bought airtime on Monday and a gift card on
Tuesday had nowhere that showed both.

`ActivityFeed` does the merging and normalises both tables into one row shape,
so no caller has to know where a row came from. Three rules:

- **Read-only, never a second source of truth.** It re-reads the same rows the
  domain screens read; nothing is cached and nothing is written, so a feed row
  cannot drift from the transaction it describes.
- **Every action stays on the domain page.** The history page has no forms. One
  place can cancel a virtual number, and it is the one that knows a number with
  a code cannot be cancelled.
- **Pagination is stable.** Rows are sorted by time and then by `public_id`,
  because two purchases in the same second with an unstable sort is how a
  customer sees one purchase twice and another not at all.
  `testTheFeedPaginatesAcrossTheMergedList` asserts no row appears on two pages.

On the admin side the same feed shows **every** row but only links the ones the
viewer may open. A staff member without `giftcards.view` still needs to see that
gift cards are selling — hiding the row would under-report the business to
whoever happens to be logged in. What they lose is the link, not the fact.

## The harness had to learn arithmetic

`FakeDb` could compute `COUNT(*)` and nothing else. Every money figure in this
session is a `SUM`, so testing analytics against it would have meant asserting
almost nothing — which is precisely how a revenue query that omits a whole table
survives review.

Four fixes, each a real gap rather than a convenience:

| Gap | Why it mattered |
| --- | --- |
| No `SUM`, incl. `SUM(CASE WHEN … THEN … ELSE … END)` | Every total in this session |
| `select()` split on **all** commas | `COALESCE(SUM(x),0)` was cut in half, and the halves looked like two unknown columns — silent, and a revenue figure became an undefined property |
| Projection ran **before** aggregation | `SUM(amount)` summed a column the projection had already stripped: zero revenue, no error |
| `IS NOT NULL` unsupported inside a `CASE` | The "how many sales recorded a vendor cost?" count, which is what stops a margin from three rows being presented as covering four hundred |

Also: an aggregate with no `GROUP BY` now returns exactly one row even when
nothing matched, as SQL does. Returning an empty set would make every caller's
`??` fallback hide a broken query.

This changes behaviour for every test in the suite, which is why the full run
matters rather than the new file alone.

## What shipped

**New:** `application/libraries/ActivityFeed.php`;
`controllers/admin/Analytics.php`; `controllers/dashboard/History.php`;
`views/admin/analytics/index.php`; `views/dashboard/history/index.php`;
`tests/unit/AnalyticsTest.php` (35 tests).

**Modified:** `AdminStats` (revenue across both tables; new
`revenue_by_domain`, `domain_health`, `provider_performance`, `revenue_series`;
`action_queue` gains `stuck_services`), `controllers/admin/Dashboard.php`,
`views/admin/dashboard.php`, `config/routes.php`, nav + `chart` icon,
`FakeDb` (aggregates — see above), `docs/rebuild-spec-audit.md`.

**No migration.** Phase G is reporting over tables that already exist, so the
schema is untouched at 14 migrations.

Suite: **780 tests, 7612 assertions, 0 failures** (was 745/7429).

## Not done here

**The marketplace half of phase F** — deferred in session 27 and still the one
outstanding item in the build order. Escrow, dispute resolution, two-sided KYC
and seller payouts share none of the "vendor → panel → customer" shape the rest
of the panel has.

**Per-domain catalogue CRUD.** Prices for VTU, numbers, identity and gift cards
are still set directly in the database. Every catalogue deliberately imports
unpriced and inactive, so this is the one manual step between connecting a
vendor and selling — it is the most valuable screen left to build.

**No CSV export and no date-range picker** on analytics: three fixed windows
(7/30/90 days). Both are easy additions once someone says which they want; I did
not guess.
