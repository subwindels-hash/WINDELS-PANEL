# Session 18 — Performance

§18 asks for indexes, slow-query review, caching, pagination, and provider calls
off the page render. This session audited each and fixed what was actually
costing something.

As with the security audit, the honest headline is that much of it was already
fine: 85 indexes across the schema, pagination on every admin and dashboard
list, `DashboardStats::totals()` computing six figures in one aggregate query
instead of six round-trips, and no provider HTTP call on any customer-facing
page render. Five things were worth fixing.

## 1. Permission checks re-ran a three-table join every time

`Permission_model::keys_for_role()` joins `permissions → role_permissions →
roles`. `AuthService::can()` calls it through `role_has()`, and `require_perm()`
calls `can()`. An admin page does this four or five times before it renders a
row, then calls `permissions()` once more to build the view's `$permissions`
list. Every one of those was a fresh join.

The docblock on `permissions()` even said "cached". It wasn't.

Memoised per request, keyed by role name. The role/permission matrix cannot
change mid-request, so this is safe by construction; `flush_cache()` exists for
tests and for any code that edits the matrix. Six or so joins per admin page
become one.

## 2. `Setting_model::get()` issued a point query per key

`all()` was memoised. `get()` was not — it ran its own `WHERE setting_key = ?`
every call, across ~16 call sites. Sending one email reads five settings;
placing an order reads several more.

`get()` now reads through the same memo `all()` maintains. The settings table is
a handful of rows, so one cached full read is strictly cheaper than N point
queries, and it removes the possibility of `get()` and `all()` disagreeing
within a request. `set()` already invalidated the cache, and a test pins that a
written value is visible immediately — a stale cache after a write would be a
correctness bug, not just a slow one.

## 3. N+1 in the bulk order-status API

`POST /api/v1/orders/status` accepts up to 100 order ids and looped
`find_public_for_user()` over them — **up to 100 queries for one API call**, on
the endpoint resellers poll most often.

Replaced with `Order_model::find_public_many_for_user()`: one `WHERE IN`, results
keyed by public id. The `user_id` predicate is still in the query, so batching
did not turn into an IDOR — there is a test for exactly that, because "make it
one query" is precisely the change where that mistake gets made. Unknown ids
still return an explicit `null` so the response shape matches the request.

A scan now fails the build on any controller that issues a model read or
`db->get()` inside a loop.

## 4. Two composite indexes had their columns in the wrong order

```sql
INDEX idx_df_next_run   (next_run_at, status)        -- dripfeed_orders
INDEX idx_sub_next_exec (next_execution_at, status)  -- subscriptions
```

Both scheduler queries are `WHERE status = 'ACTIVE' AND next_run_at <= NOW()`.
A composite index is only usable left-to-right up to and including the first
**range** predicate — so leading with `next_run_at` means MySQL scans every row
dated at or before now and filters `status` afterwards. On a table with a long
history of completed schedules, that is most of the table, once a minute,
forever.

Swapped to `(status, next_run_at)` and `(status, next_execution_at)`: equality
first, range second. `refills` and `email_queue` already had it right, which is
what made the other two stand out.

These are edits to migration 006 rather than a migration 010, because the
project is pre-release and `SchemaTest` pins exactly nine migrations.
`docs/database.sql` regenerated.

## 5. Service pickers pulled a TEXT blob nobody rendered

Four pages call `Service_model::active()`, which is `SELECT *`. That includes
`description`, a TEXT column that also backs the FULLTEXT index. I checked what
the views actually read: the order, drip-feed and subscription pickers use name,
rate, quantity bounds and a few flags — none of them touch `description`.

Added `active_for_picker()` with an explicit 17-column projection and pointed
those three pages at it. On a catalogue of a few thousand synced services, the
description is the bulk of the payload. The services *catalogue* page still uses
the full select, since it renders more per row.

A test asserts every column named in the projection exists on the `services`
table — a typo there would be a runtime SQL error on a page that currently
works.

## What I looked at and left alone

- **Provider calls on page render.** Already clean. The only synchronous
  provider HTTP calls from a controller are in `admin/Providers.php`, behind
  explicit "test connection" / "sync now" buttons where the admin is waiting for
  that specific result. Order placement does call the provider inline, which is
  correct — the customer needs to know whether their order went through — and
  Session 15 already made sure a failed submit refunds them. A test now enforces
  that no non-admin controller acquires an adapter or an HTTP client.
- **Queries in views.** Three exist (`add_funds.php`, `deposits.php`,
  `public.php`). All are single-row or naturally-bounded lookups outside any
  loop. Worth moving to controllers on style grounds, but that is a refactor,
  not a performance fix, and this session was scoped to things that cost
  something.
- **Unbounded model reads.** 28 methods return `result()` with no `limit()`.
  Nearly all are over naturally small tables — settings, roles, currencies,
  providers, FAQ entries. The one that genuinely scales with user data is
  `Service_model::active()`, handled above.
- **Redis.** Still not wired up. Every cache added here is per-request memoisation,
  which needs no infrastructure and cannot go stale across a deploy. Cross-request
  caching of `provider:{id}:services` per §18 needs Redis and is deferred.

## Tests

`tests/unit/PerformanceTest.php` — 20 tests, 76 assertions.

Performance regressions are invisible to functional tests: the page still
renders, it just costs 40 queries instead of 4. So these **count round trips**
against a db double that records every query per table, rather than asserting on
output. The index tests parse the migration DDL directly, since composite column
order is the kind of thing that stays silently wrong for years.

I verified the suite actually catches regressions by reverting all three fixes —
the settings memo, the permission memo, and the index column order — and
confirming four tests failed before restoring them.

Suite: **364 tests, 3515 assertions, 0 failures** (was 344/3439).

## Deferred

- **Redis** for cross-request caching (`provider:{id}:services`), rate limiting,
  and session storage. Session 17 left the rate limiter table-backed behind an
  unchanged interface for exactly this.
- **`EXPLAIN` review against real data.** Everything here is reasoning from
  query shape and schema; the index column-order bug is the kind that shows up
  immediately in an `EXPLAIN` on a populated table. Worth doing in Session 20
  against production-shaped data.
- **Moving the three view-level queries into their controllers.**
- **Cursor pagination** for deep pages — `LIMIT ... OFFSET` degrades on large
  offsets, though at `PER_PAGE = 25` nobody is deep enough to notice yet.
