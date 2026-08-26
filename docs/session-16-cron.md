# Session 16 — Cron workers

The panel had nine scheduled jobs declared in `config/marvy.php` and listed in
`cron/crontab.example`, but eight of them were empty stubs in
`application/controllers/Cron.php`. Drip-feed schedules never fired, order
statuses never came back from providers, and queued email never left the table.
This session makes all nine real.

## Shape

Three layers, so the logic is testable without a request:

| Layer | File | Job |
|---|---|---|
| CLI entry point | `application/controllers/Cron.php` | argument parsing, one-line summary, exit code |
| Harness | `application/libraries/JobRunner.php` | locking, `job_runs` records, failure containment |
| Work | `application/libraries/CronWorkers.php` | the actual body of each job |

`Cron` still extends `Cron_Controller`, so every job remains CLI-only — there
are no web cron URLs (§66). Each method is three lines:

```php
public function dripfeed() {
    $this->execute('dripfeed', function () {
        return $this->cronworkers->dripfeed();
    });
}
```

### JobRunner

`run($job, callable $work)` gives every job three guarantees:

1. **A job never overlaps itself.** Cron fires on a fixed schedule whether or
   not the previous run finished. A slow provider sync would otherwise stack up
   and double-submit orders. The lock is a non-blocking `flock()` on a file in
   `$config['cron_lock_dir']` (default `sys_get_temp_dir().'/marvy-locks'`);
   a run that cannot take the lock is *skipped*, not queued. The OS releases the
   handle even if the process is killed, so a crash cannot wedge a job
   permanently. §66 wants Redis `SET NX` for multi-host deployments — swap
   `acquire()` when that infrastructure exists; the interface does not change.
2. **Every execution is recorded.** A `job_runs` row goes in as `RUNNING` and is
   closed out as `SUCCESS` or `FAILED` with duration, processed/failed counts
   and any message. `php index.php cron status` prints the last 20, which is
   what makes "did the cron actually run last night?" answerable.
3. **A throwing worker is contained.** The exception is caught, logged, recorded
   as `FAILED`, and the lock is released in a `finally`. The CLI exits non-zero
   so `MAILTO`/monitoring notices.

Workers return `array('processed'=>int, 'failed'=>int, 'message'=>string)`.

## The nine jobs

| Job | Schedule | What it does |
|---|---|---|
| `dripfeed` | `* * * * *` | places the next child order for due schedules |
| `order_status` | `*/2` | polls providers, applies status changes |
| `subscriptions` | `*/5` | places the next order for due subscriptions |
| `provider_health` | `*/5` | pings active providers, records health |
| `refill_status` | `*/5` | polls providers for in-flight refill status |
| `payment_reconciliation` | `*/5` | expires deposits that were never paid |
| `email_queue` | `*/5` | delivers queued email with backoff |
| `analytics` | `0 * * * *` | prunes high-volume logs |
| `provider_sync` | `*/60` | refreshes provider service catalogues |
| `affiliate_payouts` | `*/10` | pays cleared commissions (Session 14) |

Two source-level tests keep this table honest: every key in `$config['cron']`
must have a controller method wired to `execute()`, **and** a line in
`crontab.example`. Adding a job to one place and forgetting the other now fails
the suite.

Each worker loads only the models and libraries it needs (`need()`), since one
invocation runs exactly one job.

## The money decisions

Scheduled orders are the highest-risk code in the panel: they place orders with
nobody watching, so a bug silently double-charges a customer or double-submits
to a provider. Three decisions fell out of that.

### Drip-feed is prepaid, subscriptions are not

A drip-feed schedule reserves **the entire charge when it is created**. If each
child order then charged the wallet again the customer would pay N+1 times. So
child orders go through a new `OrderService::place_prepaid()`, a thin wrapper
that sets `__prepaid` and the parentage context and delegates to `place()`.
Inside `place()` a `$prepaid` flag now guards **three** money paths — the wallet
charge, the `PERSIST_FAILED` rollback refund, and the `SUBMIT_FAILED` refund.
All three matter: refunding a charge that was never taken is as wrong as taking
it twice. A test asserts all three sites respect the flag.

Subscriptions are the opposite — they are billed per run at execution time — so
`SubscriptionService::execute_due()` deliberately uses the ordinary `place()`. A
test asserts `place_prepaid` does *not* appear in that file.

`persist_order()` now also writes `dripfeed_order_id`, `dripfeed_run_number` and
`subscription_id`, so every child order traces back to the schedule that made it.

### Deterministic idempotency keys

Scheduled orders key off the schedule and the run number:

```
dripfeed:{drip.public_id}:run:{n}
subscription:{sub.public_id}:run:{n}
```

If the process dies between submitting to the provider and recording the result,
the retry resolves to the order that already exists instead of placing a second
one.

### Claim before ordering

Both engines claim their unit of work with a compare-and-set *before* spending
money, so two overlapping workers cannot both act:

- Drip-feed: `UPDATE dripfeed_runs SET status='RUNNING' WHERE id=? AND status='PENDING'`, then check `affected_rows() === 1`.
- Subscriptions: advance `next_execution_at` conditional on its old value.

The loser returns `array('ok'=>true, 'skipped'=>true, 'reason'=>'claimed by another worker')` and places nothing.

### Failure policy

- A failed drip-feed run is marked `FAILED` with the error; **the schedule stays
  `ACTIVE`** so one bad run does not kill the remaining ones.
- A subscription hitting `INSUFFICIENT_BALANCE` is **paused**, not failed — that
  is recoverable, and burning the remaining runs because a wallet was briefly
  empty would be the wrong call. Every other error logs a `failed` event and
  leaves the plan alone.

## Other worker notes

**`order_status`** groups orders by provider so one HTTP call covers many, then
routes every change through `OrderService::apply_status()`, which owns the state
machine, the history log and refunds. Two refusals are deliberate: an unknown
provider status string is logged and skipped rather than guessed, and a
`PARTIAL` **without** a `remains` value is left for a human, because the refund
amount cannot be computed without it. A provider that throws fails only its own
batch.

**`email_queue`** claims each row `QUEUED → SENDING` before sending, so
overlapping runs cannot deliver twice. Failures back off exponentially
(2^attempts minutes) and are abandoned as `FAILED` after five attempts.
`MailService::deliver()` was added with two transports selected by the
`mail_transport` setting: `log` (default — this is where verify/reset links are
read locally) and `smtp` via CI3's email library.

**`payment_reconciliation`** credits nothing. Deposits are credited by the
gateway webhook or by an admin approving a manual transfer; this job only ages
out rows that have sat in `CREATED`/`PENDING` past seven days so the pending
queue reflects reality. A test asserts it performs no inserts.

**`analytics`** prunes `api_usage_logs` and `provider_health_logs` (30 days),
`provider_sync_logs` and `job_runs` (90 days), 5000 rows at a time.
`audit_logs` are **never** touched — that is the compliance trail. A test
asserts it.

## Bug found: eleven queries that would have failed at runtime

Reviewing the models the workers call turned up `->get()` called with neither a
table argument nor a preceding `->from()`. CodeIgniter builds `SELECT * FROM ()`
from that — a SQL syntax error every time. Eleven methods across six models were
affected, including `Dripfeed_order_model::due_runs()` and
`Subscription_model::due()`: the two queries the drip-feed and subscription
workers open with. Every scheduled order path was dead on arrival.

Fixed to `->get($this->table)`, and `CronWorkersTest::testModelQueriesNameTheirTable`
now scans every model method for the pattern so it cannot come back. The scan
follows `$this->foo()` calls into sibling methods, so `admin_search()` delegating
its `from()` to `admin_filters()` is correctly not flagged.

## Tests

Two new files, 39 tests:

- **`tests/unit/CronWorkersTest.php`** (24) — JobRunner recording, overlap
  prevention, lock release after a crash; order-status mapping including the two
  refusals; email claim/backoff/abandon; reconciliation and pruning; plus
  source-level guarantees (CLI-only, config↔crontab↔controller agreement, no
  worker writes wallet tables, prepaid flag honoured in all three places).
- **`tests/unit/ScheduledOrdersTest.php`** (15) — drip-feed and subscription
  execution against fakes whose compare-and-set only succeeds once, so the
  "lost the race" path is genuinely exercised.

Suite: **311 tests, 3040 assertions, 0 failures** (was 272/2904).
`tools/export_schema.php --check` clean. No new migration — everything used here
already exists in `job_runs`, `email_queue`, `dripfeed_runs` and `subscriptions`.

## Deferred

- Redis `SET NX` locking for multi-host deployments (flock is single-host).
- Cancellation requests have no worker yet; `cancellation_requests` is still
  admin-driven.
- `analytics` only prunes. Rollup tables for the admin dashboard (which still
  computes its figures live) would be the next step.
- Provider balance polling — `providers.balance` is written on sync but there is
  no low-balance alert.
