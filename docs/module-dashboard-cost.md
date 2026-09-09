# Module 20 — the admin dashboard's query cost

*Branch `arena/01a04558-windels-panel`. Follows module 19 (naming the operator).*

Item 14 of [unfinished.md](unfinished.md), closed. **31 queries → 20** on the
first screen every staff member opens, with every reported figure unchanged
and pinned by tests.

---

## 1. The same questions, asked twice

Module 12 measured this page and deliberately left it alone: six aggregate
widgets plus a chart, "worth about four queries". Measured properly, it was
worth eleven — because the widgets did not know about each other, and three of
them were literally duplicate work.

| What | Before | After |
|---|---|---|
| `order_status_counts()` | **twice** — the controller asked, and `platform_overview()` asked again | once, memoised |
| "orders today" | its own `COUNT(*)` over `orders` | a conditional sum on the status GROUP BY |
| "stuck orders" | its own `COUNT(*)` over `orders` | the same scan again |
| `revenue(1)` + `revenue(30)` | **8** — a sum and an unearned count, per table, per window | **2**, one pass per table |
| `platform_overview()` + `customers()` | two full scans of `users` | one, with conditional sums |
| open vs unassigned tickets | two counts over `tickets` | one |

Nobody notices on a seeded dev database. On a panel with a year of trading —
which is what `seed_load.mjs` builds — this is the difference between a
dashboard that opens and one staff stop opening, and it is paid on every load
by every member of staff, all day.

The revenue duplication was the worst of it: today's window is *inside* the
month's, so eight queries were being spent to scan the two largest tables in
the panel four times for figures one scan can produce.

## 2. What replaced it

`AdminStats::revenue_windows(array(1, 30))` answers every window in one query
per table, with conditional sums for sales, gross, refunds and unearned
attempts, bounded by the widest window so the index still does the work.
`revenue($days)` now delegates to it, so `admin/analytics` and every test keep
their old call, and the dashboard asks for both windows at once.

`order_counts()`, `user_totals()` and `ticket_queue()` are private, memoised
single-pass aggregates behind the existing public widget methods. The widgets
stay independent — `customers()` still just returns customer figures — but
asking twice within one request now costs once. `flush()` drops the memo,
because a long-running cron process must not report the figures it saw when it
booted.

Statuses are interpolated into those CASE expressions, so `escape_status()`
constrains them to `[A-Z_]` rather than trusting them because they came from a
constant. They are our own values today; the query does not depend on that
staying true.

## 3. Correctness first

A performance change that quietly alters a figure is worse than the slow
version, so `tests/unit/DashboardQueryCostTest.php` (8 tests) pins both halves:

- **the batched windows equal the single-window answers**, field for field,
  and the figures themselves are right — 2,000 + 500 today, a ten-day-old
  3,000 joining in the month, a FAILED sale earning nothing but still counted
  as an unearned attempt;
- the overview and the customer widget still disagree correctly (a staff
  account is a user and never a customer);
- "orders today" excludes a three-day-old order and "stuck" catches it;
- open tickets exclude CLOSED, and unassigned excludes the one with an owner;
- **the whole widget set is bounded** — the number is asserted, so
  reintroducing a per-widget query fails here rather than on a busy panel
  months later;
- asking the same question twice within a request costs **zero** the second
  time, and `flush()` genuinely re-reads.

`FakeDb` had to learn one thing to make this testable: `A AND B` inside a
`CASE WHEN`, plus `NOT IN`. Every unique key and every conditional sum in this
codebase had been single-condition until now; "sales in the last day" is a
window *and* a status in one pass. A double that could not express that would
have forced the production code back into a query per widget — exactly the
cost this module removes. *The double was corrected; no assertion was
weakened.*

One stale source-shape assertion in `AnalyticsTest` was updated, with the rule
intact: it checked that `revenue()`'s body names both money tables, which is
now true of `revenue()` + `revenue_windows()` together. Reading only `orders`
still reports every service domain as zero, and that is still asserted.

---

## 4. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php   # 1474 tests, 17018 assertions, 0 failures
bash tools/verify_all.sh --admin-password '…' --with-load  # 48 passed, 0 failed
```

Measured over HTTP against the running panel, via the dev database's stats
channel:

| Page | Before | After |
|---|---|---|
| `/admin` | 31 queries | **20** |
| `/admin/analytics?days=30` | 24 budget | **22** budget, passing |

`perf_check.mjs` holds the dashboard to **22** — just above the measured cost —
and passes at 40/40 **under the 12,000-order load fixture**, which is the run
that matters: the consolidation has to hold when the tables are big, not just
when they are empty.

---

## 5. Still open

- **`revenue_series(14)` and `revenue_by_domain(30)` are still their own
  queries** (two each). They could ride along on the same scans, but a daily
  series is a different GROUP BY from a rolling window, and merging them would
  mean deriving rolling totals from day buckets — subtly different numbers at
  the boundary. Not worth the ambiguity for two queries.
- **The remaining 20 include the request's own fixed cost**: settings, feature
  flags, the session's user row, the notification badge. Those are shared by
  every authenticated page and were addressed in module 12.
- **No index review under load** — unchanged, item 15. SQLite's planner is not
  MySQL's, so the *shape* is proved here and the plans are not.
