# Module 7 — analytics

*Branch `arena/01a04558-windels-panel`. Follows module 6 (refunds and refills).*

The analytics and dashboard screens are read-only, so nothing about them can be
proved by "the page loads". The only question worth asking is whether the
numbers printed on them are true. Four of them were not.

---

## 1. What was broken

### 1.1 Revenue counted attempts, not income

`AdminStats::$earned` — the map of "statuses in which money has actually been
earned" — was declared on day one and **used by no query**. Every figure on the
analytics page and the admin landing page counted *every row created in the
window*:

- an order cancelled before it was ever submitted,
- a failed VTU top-up that was refunded in full seconds later,
- an order still PENDING manual review, charged but undelivered.

All of them were reported as revenue. The headline number on the first screen
an operator sees was the *volume of attempts*, and it was always too high — by
exactly the amount of everything that went wrong, which is the worst possible
correlation for a number people plan with.

### 1.2 The chart could disagree with the cards above it

`revenue_series()` applied no status filter either, and it built the daily
buckets by `SELECT`ing **every order and every service transaction** in the
window and adding them up in PHP. Two problems in one method:

- the sparkline and the summary cards were different definitions of revenue on
  the same screen;
- the admin **landing page draws the same chart**, so every admin page load
  materialised a fortnight of sales rows into PHP memory. At a few thousand
  sales a day that is tens of thousands of objects per page view.

### 1.3 Delivery health had no row for the panel's biggest domain

`domain_health()` read only `service_transactions`, so VTU, numbers, identity
and gift cards each had a row and **SMM had none** — even though the same page
lists SMM in its revenue table and links to the SMM queue. An SMM backlog was
the one thing the delivery-health widget could not show.

### 1.4 "Total spent" was a column nothing ever wrote

`wallets.total_spent` and `wallets.total_deposited` have existed since
migration 002. Nothing has ever written `total_spent`, and only the demo seeder
ever wrote `total_deposited` — once, at seed time, so it was stale the moment a
customer deposited again.

Three admin screens report them as fact: the customers list ("Spent" column),
the customer detail page ("Total spent") and the wallets summary (platform
deposited/spent). Every customer on every install showed **₦0.00 spent** no
matter how much they had bought.

### 1.5 The customer's own "spent" figure was wrong in the other direction

`DashboardStats::totals()` computed `spent` as the sum of charges on
COMPLETED/PARTIAL orders only. A customer whose order was half delivered and
half refunded was shown the **full** price as spent, and money currently held
against orders still in progress was shown as not spent at all. Neither figure
agreed with the wallet balance the customer can see for themselves.

### 1.6 (Dev harness) every money aggregate lost its decimals

Not shipped code, but it made all of the above impossible to verify: the
MySQL-protocol dev database advertised **every** JS number as `LONGLONG`.
SQLite returns `SUM()` over a DECIMAL-as-TEXT column as a float, so the client
cast the perfectly good text `16215.60500004` to the integer `16215`. Every
money aggregate read in development lost its fraction — which makes correct
code look wrong, and could just as easily make wrong code look right.

---

## 2. What the code does now

### Revenue is income, and attempts are reported separately

`$earned` is now applied by `revenue()`, `revenue_by_domain()` and
`revenue_series()`:

| Table | Counted as revenue | Not counted |
|---|---|---|
| `orders` | COMPLETED, PARTIAL, IN_PROGRESS, PROCESSING, REFUNDED | PENDING, FAILED, ERROR, CANCELED, EXPIRED |
| `service_transactions` | SUCCESSFUL, PROCESSING, REFUNDED | PENDING, FAILED, CANCELLED, EXPIRED |

REFUNDED is deliberately **in**: the sale happened and the money was given
back, so it belongs in gross *and* in refunded, or a goodwill refund would be
invisible in the reporting. A failed or cancelled sale is a wash — charged,
then refunded — and belongs in neither.

`revenue()` also returns `unearned`, the number of attempts excluded, and the
Net revenue card prints "*N* not counted". Stating it matters: an operator who
sees a smaller total than last month needs to know it is honesty, not data loss.

### The chart is one grouped query per table, with the same filter

`revenue_series()` now groups in SQL on `SUBSTR(updated_at, 1, 10)` — identical
in MySQL and in the SQLite-backed dev database, and UTC in both, matching the
keys the series is built from. At most one row per day per table comes back
instead of every sale.

The window column is deliberately `updated_at`, not `created_at`. A pending
order created ten days ago is not revenue; when it is delivered (or refunded)
today the sale has to land in *today's* report. Using the creation date would
page a genuinely fresh update onto an old bar (or, once the creation date
falls outside the range, drop it from the report entirely). `revenue()` and
`revenue_by_domain()` window on the same column, so the cards, the breakdown
and the chart cannot disagree about what "updated in this range" means; the
summary also exposes `report_freshness()`, the newest `updated_at` across both
revenue tables, so the page itself says how current the figures are.

### Delivery health covers SMM

`domain_health($stuck_after_minutes = 30, $smm_stuck_after_minutes = 1440)`
adds an SMM row computed from `orders`, in the same shape as the service
domains: in-flight, successful, failed, refunded, success rate over settled
rows only, and stuck. SMM's stuck window is a full day on purpose — an SMM
order in flight for half an hour is ordinary, while a gift card in that state
means a customer has paid and has no code. The page hint now says so.

### The wallet lifetime counters are maintained by the ledger

`LedgerService::move()` updates them in the same locked transaction that moves
the balance — the only place allowed to touch a wallet:

- `DEBIT` → `total_spent += amount`
- `CREDIT` of type `DEPOSIT` → `total_deposited += amount`
- `CREDIT` of type `REFUND` → `total_spent -= amount`, floored at zero

They are kept as counters rather than recomputed per page because the admin
customer list would otherwise need an aggregate over the whole movement history
for every row on the page.

**Migration 027** backfills both from `wallet_transactions` for existing
installs. It recomputes rather than increments, so it is re-runnable and
doubles as the repair for any future drift. On the dev database it turned a
demo wallet's `total_deposited` from a stale `250.00` into the true `60,450.00`.

### The customer's "spent" is what left the wallet and stayed gone

`DashboardStats::totals()` now returns `spent = SUM(charge) − SUM(refunded_amount)`
across all of a customer's orders (subtracted with bcmath, never through a
float), plus a `refunded` figure. That is exactly the net outflow the wallet
ledger shows.

---

## 3. How it was verified

### Unit / integration

`tests/unit/AnalyticsTest.php` (+8): failed and cancelled attempts are not
revenue and are counted as `unearned`; a pending sale is not revenue yet; a
refunded sale stays visible in gross and refunded; the domain breakdown ignores
the vendor cost of attempts that earned nothing; the series applies the same
filter as the summary and equals it; the series is **≤ 2 queries** for 40 sales
(the row-scan regression); delivery health includes SMM with the right
in-flight/success figures; SMM's stuck window is wider and parameterised.

`tests/unit/IntegrationTest.php` (+2), over the real ledger: the lifetime
counters follow real movements (deposit → order → cancellation), and they agree
with the movement history they summarise.

`tests/unit/DashboardTest.php`: updated for the corrected `spent` definition,
with the reason recorded in the test.

Test doubles extended (never weakened): `FakeDb` gained `where_not_in()` and
`SUBSTR(col, start, len) AS alias` as a computed, groupable column;
`IntegrationHarness` now writes the wallet counter defaults MySQL would apply.

**Suite: 1355 tests, 0 failures, 1 skipped.**

### End-to-end — `tools/devserver/analytics_check.mjs` (19 checks)

Every check computes the expected figure from the database and compares it with
what the **rendered page** says:

- net revenue and gross on `/admin/analytics` equal earned sales minus refunds;
- after inserting a ₦777,777 cancelled order, a ₦888,888 failed one and a
  ₦999,999 pending one, none of those amounts appears anywhere on the page, the
  ₦123,456 delivered one does, and the "not counted" figure matches;
- today's chart bar carries today's earned total and sale count (the chart and
  the cards agree);
- delivery health lists SMM with the real in-flight count;
- the customer dashboard's "spent" equals charges minus refunds for that user;
- `total_spent` / `total_deposited` match the movements, and the wallets screen
  no longer shows a dead zero.

```
node tools/devserver/analytics_check.mjs --admin-password '…'
19/19 checks passed
```

Regressions all green: `smoke` 24/24, `journey` 38/38, `admin_check` 18/18,
`commerce_check` 24/24, `refunds_check` 32/32, `currency_check` 28/28,
`earnings_check` 24/24, `shop_check` 45/45, `ux_separation_check` 58/58,
`api_check` 31/31, `notifications_check` 22/22, `reconciliation_check` 10/10,
`page_audit` (0 failing pages), `npm run test:js` 13/13.

---

## 4. Still not proven here

- The `analytics` cron job does no analytics: it prunes `api_usage_logs`,
  `provider_health_logs`, `provider_sync_logs` and `job_runs` on a retention
  window and never touches `audit_logs`. That is correct housekeeping and the
  reporting is computed live, so nothing depends on the job running — but the
  name is misleading and is worth renaming the next time the cron table is
  changed (renaming it now would break every deployed crontab).
- Day boundaries are UTC everywhere ("today", the chart buckets). For an
  operator in Lagos or Whitehorse "today" therefore starts at a local hour that
  is not midnight. Fixing it properly needs a site timezone setting, which the
  panel does not have; it is recorded here rather than half-done.
- All figures assume one base currency. Orders carry a `currency` column and
  every row in this build is NGN; a genuinely multi-currency install would need
  the sums converted before they are added.
