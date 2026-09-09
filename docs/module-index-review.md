# Index review under load — the queries the schema must serve

*Item 15 of [unfinished.md](unfinished.md): "SQLite's planner is not
MySQL's, so no EXPLAIN work has been done on the real engine." This is the
static half of closing it: every heavy read path matched against the schema's
indexes, the gaps found, the fix (migration 038), and the tool that proves
the result on MySQL 8 (`tools/mysql_explain_check.php`).*

## Method

1. Extracted the full table → index map from the applied schema (92 tables,
   107 primary keys, ~180 named indexes).
2. Swept the codebase for the query shapes that grow with trading volume —
   the ones with a `created_at` range, an `ORDER BY created_at`, a
   `GROUP BY`, or an unfiltered paginated list — in `AdminStats`, the admin
   search methods, the dashboard, the cron workers and the models.
3. For each shape: can an existing index answer it (right leading column,
   usable range), or does MySQL have to scan the table?
4. Gaps became migration 038; the result is checked on the real engine by
   `tools/mysql_explain_check.php`, which EXPLAINs the same query shapes and
   fails on any full scan of a table too big to scan.

SQLite cannot be asked. Its planner has no statistics, no cost model and no
`EXPLAIN FORMAT=JSON`, and a scan it shrugs off at 12,000 rows (the
`perf_check` fixture) is a dashboard-timeout at the row counts a busy panel
reaches. The perf harness still measures *query count* (N+1s, module 20's
territory); this review and the EXPLAIN harness cover *plan quality*.

## What is already covered (spot-checked, not a census)

| Read path | Where | Served by |
|---|---|---|
| Customer transaction history | `Service_transaction_model::history_for_user` | `idx_stx_user_created (user_id, created_at)` |
| Stuck-transaction sweep (cron) | `Service_transaction_model::stuck` | `idx_stx_status_created (status, created_at)` per status |
| Revenue by domain / daily series | `AdminStats::revenue_by_domain`, `revenue_series` | same — `status IN (…)` + `created_at >=` is a range per status |
| Order history / admin order list by user | `Order_model` search paths | `idx_ord_user_status_created`, `idx_ord_status_created` |
| Wallet statement | `wallet_transactions` | `idx_wt_wallet_created (wallet_id, created_at)` |
| Ticket queue | `tickets` | `idx_t_status_prio_created (status, priority, created_at)`, `idx_t_assigned` |
| Auth lookups | `users` | unique `email`, `username`, `user_code` |
| Rate limiting | `login_attempts` | `idx_la_scope_email_created`, `idx_la_scope_ip_created` |
| Notification inbox | `notifications` | `idx_n_user_read_created (user_id, is_read, created_at)` |
| API usage per key | `api_usage_logs` | `idx_aul_key_created`, `idx_aul_created` |
| Coupon / redemption / idempotency checks | `coupons`, `coupon_redemptions`, `idempotency_keys` | unique `code`, `uq_couponredeem_slot`, `idem_key` |
| Webhook dedupe | `payment_webhooks` | `uq_gateway_event (gateway_type, event_id)` |

The pattern across the schema is consistent: every *user-scoped* or
*status-scoped* list leads with the scoping column and ends with
`created_at`, which is the right shape for a list the operator or customer
actually runs.

## The gaps — queries that filter by time alone

A `created_at` range with no column before it in the WHERE cannot use any
`(x, created_at)` index; the leading column has to be in the predicate.
Three hot paths do exactly that:

### 1. Dashboard revenue windows — `AdminStats::windowed_totals()`

```sql
SELECT …CASE WHEN created_at >= … AND status IN (…)…
  FROM service_transactions            -- and orders
 WHERE created_at >= <oldest window edge>
```

The status split is in the `CASE`, not the `WHERE` — one bounded scan per
table feeds all the windows (the module-20 design, "one pass per table").
But the predicate is `created_at` alone, and neither `service_transactions`
nor `orders` had a `created_at`-leading index. On MySQL 8 that is a **full
table scan of the busiest table on the panel, on the first screen staff
open**. At the 12,000-row fixture SQLite does not care; at real volume it is
the single most expensive query in the panel.

### 2. Unfiltered admin lists — `ORDER BY created_at DESC LIMIT 25`

The default transaction/order admin lists apply no filter. Unfiltered,
MySQL reads the whole table and sorts it to show 25 rows. With a
`created_at`-leading index it walks the index backwards and stops after 25
rows.

### 3. Provider performance — `AdminStats::provider_performance()`

```sql
SELECT … FROM provider_transactions
 WHERE created_at >= <7 days>
 GROUP BY provider_id
```

`provider_transactions` had `(provider_id, created_at)` — right for
*per-provider* log views, useless for a time-only range — and nothing else
time-based. Every vendor call appends a row, so this table is the
fastest-growing in the panel; the 7-day review of all of it was a scan.

## The fix — migration 038

Three covering indexes (the aggregates' columns ride along so MySQL 8 does
the windowed totals as an **index-only** scan, no heap lookups):

```sql
CREATE INDEX idx_stx_created ON service_transactions
       (created_at, status, amount, refunded_amount);
CREATE INDEX idx_ord_created ON orders
       (created_at, status, charge, refunded_amount);
CREATE INDEX idx_ptx_created ON provider_transactions
       (created_at, provider_id, status, latency_ms);
```

Deliberate trade: the covering width adds two small columns per insert on
the busiest tables. That is accepted because these are the panel's hottest
reads and the scans they remove are the ones that grow linearly with the
operator's success.

Also noted, not fixed: the `COUNT(*) … CASE WHEN created_at >= …` figures
(new orders today, new customers this month) scan the table by design —
they count *all* rows and only split by date in the aggregate. A covering
index would halve the IO; at current scale that is noise, and the review
flags it rather than widens the schema for it.

## The proof leg — `tools/mysql_explain_check.php`

The static review can only argue. The tool closes the loop on the real
engine: it EXPLAINs the same query shapes (bound to representative values)
and fails with a named table on any `type=ALL` plan against a table too big
to scan (small reference tables are allowlisted — scanning them is the
*right* plan). Run it against MySQL 8, ideally after a year of seeded
trading so the row counts are real:

```bash
php tools/mysql_explain_check.php --host=… --port=3306 --db=marvysocials --user=… --pass=…
```

Exit code = number of violations, so it can gate a release. This is the
step that belongs in the MySQL 8 verification pass (unfinished.md item 19)
alongside `deploy-verify.php` and a run of `tools/verify_all.sh`.
