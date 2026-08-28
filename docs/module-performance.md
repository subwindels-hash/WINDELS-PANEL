# Module 12 — performance under real volume

*Branch `arena/01a04558-windels-panel`. Follows module 11 (marketplace fulfilment).*

Every performance claim in this project had been made against a database with
a dozen orders in it, where an N+1 costs twelve queries and nobody notices.
`PerformanceTest` pins the *shape* of some queries by reading source, which is
useful and not the same as knowing what a page costs.

This module built the instrument, filled the database, measured, and fixed the
two things the measurements found.

---

## 1. The instrument

**`tools/devdb/server.js --stats-port 3400`** — the dev database now counts
every statement it executes and exposes them over a tiny HTTP side-channel:
totals, per-table counts, a sample statement per table and the slowest one.
`POST /reset` before a request, `GET /` after, and the cost of a page is a
number rather than an opinion. Dev-only and opt-in.

**`tools/devserver/seed_load.mjs`** — fills the dev database with a year of
trading: 400 customers with wallets, 12,000 orders across every status, 4,000
service transactions, 20,000 wallet movements, 800 tickets with messages, 3,000
notifications, all dated over the last year. Written in one SQLite transaction
(tens of thousands of HTTP purchases would take hours and prove nothing extra),
every row tagged so `--clean` removes exactly what it created.

**`tools/devserver/perf_check.mjs`** — loads sixteen screens as a real signed-in
admin and a real signed-in customer, prints what each cost, and asserts a
per-page query ceiling. It also asks the question that actually distinguishes
an N+1 from a fat page: **does the cost grow with the number of rows?** —
comparing `per_page=5` against `per_page=100` on three list screens.

---

## 2. What the measurements found

### Every formatted price re-read its currency — with a join to `users`

`CurrencyService::format()` and `display_code()` both called
`Currency_model::find()`, and `find()` ran

```sql
SELECT *, (SELECT username FROM users WHERE users.id = currencies.rate_updated_by)
       AS rate_updated_by_username
  FROM currencies WHERE code = 'NGN'
```

— a correlated subquery over the `users` table, for a column only the admin
currencies screen renders — **once per price on the page**. The public services
catalogue showed 24 prices and issued **24 of these**, on every anonymous page
view. A hundred-row catalogue would have issued hundreds.

Fixed: `find()` memoises per request and no longer carries the admin-only
subquery (which stays on `all_rows()`, the query that actually renders it);
`display_code()` memoises too; both memos are dropped on a write.

> `/services`: **31 queries → 8**.

### Feature flags were nine point queries per page

`marvy_feature_enabled()` issued its own `SELECT … WHERE flag_key = ?` for
every check, and the navigation alone asks about one flag per module it might
render. Nine queries per authenticated page load before the page did any of its
own work.

Fixed: `Feature_flag_model::all_flags()` reads the whole (tiny, dozen-row)
table once per request and every check answers from it; the memo is dropped on
a write, and a database with no flags table yet still falls back to the
caller's default.

> customer dashboard **21 → 13**, customer orders **17 → 9**, admin orders
> **12 → 9**, admin dashboard **35 → 32**.

### What was *not* wrong

The list screens do not N+1: admin orders, admin customers and customer orders
each cost the same for 100 rows as for 5 (`0` extra queries), and page 20 of
12,000 orders costs exactly what page 1 does. The batched lookups and projected
picker queries that `PerformanceTest` has been asserting for several sessions
are real. The analytics screens cost 19 queries against 12,000 orders and
4,000 service transactions, which is the shape of six aggregates and a chart —
not a scan.

---

## 3. Where it landed

Measured against 12,023 orders / 20,117 wallet movements / 438 customers:

| Screen | Before | After |
|---|---|---|
| public services | 31 | **8** |
| customer dashboard | 21 | **13** |
| customer orders | 17 | **9** |
| customer transactions | 18 | **10** |
| customer history | 19 | **11** |
| admin orders (page 1 and page 20) | 12 | **9** |
| admin customers | 16 | **13** |
| admin analytics (30d and 90d) | 22 | **19** |
| admin dashboard | 35 | **32** |

`perf_check.mjs` now asserts a ceiling just above each of those, so a
regression that reintroduces a per-row query fails the check instead of being
discovered by an operator whose panel got slower.

---

## 4. How it was verified

- `tests/unit/PerformanceTest.php` (+3): twelve feature-flag checks cost one
  query; twenty-four currency reads cost one; and the admin-only username
  subquery appears exactly once in the model, on the admin-only query.
  **Suite: 1388 tests, 16546 assertions, 0 failures, 1 skipped.**
- `tools/devserver/perf_check.mjs` — **40/40** under load.
- Regressions after the change, with the load data removed again: `smoke`
  24/24, `journey` 38/38, `feature_flags_check` 32/32, `currency_check` 28/28,
  `commerce_check` 24/24, `admin_check` 18/18, `shop_check` 45/45,
  `security_check` 31/31, `analytics_check` 20/20, `page_audit` (0 failing
  pages).

One check was corrected rather than the code: `analytics_check` asserted
`wallets.total_spent == debits − refunds`. That is the figure migration 027
*recomputes*, but the runtime counter is a running total floored at zero on
each refund, so in a sandbox where fixtures write wallet rows directly and
refunds outnumber charges the two legitimately differ. The check now asserts
the contract the code actually offers: never negative, and exact whenever
refunds have not overtaken debits.

---

## 5. Still open

- **Absolute timings here are not production numbers.** This is PHP compiled to
  wasm talking to SQLite through a MySQL-protocol translator; the query *count*
  is the meaningful measurement, and the millisecond column is a smell test.
- **The admin dashboard's 32 queries** are six aggregate widgets plus a chart,
  several of which recompute the same order-status counts. Consolidating them
  behind one memoised call would save perhaps four queries — worth doing, not
  worth risking a subtle change to the numbers on the operator's landing page
  in the same pass that changed how currencies are read.
- **No index review was done under load.** SQLite's planner is not MySQL's, so
  index choices verified here would prove little; `PerformanceTest` continues to
  assert index *existence and column order* from the migration DDL, which is
  the part that transfers.
