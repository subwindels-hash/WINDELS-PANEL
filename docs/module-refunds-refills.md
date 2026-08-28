# Module 6 — refunds and refills

*Branch `arena/01a04558-windels-panel`. Follows module 5 (reseller API v1).*

This module covers every path where the panel gives something back: a refill
(the provider re-delivers a drop), a partial refund (the provider delivered
less than was paid for) and a cancellation (the order is stopped and the charge
returned). All three existed. None of them was honest about failure, and two of
them could move the wrong amount of money.

---

## 1. What was broken

### 1.1 A refused refill was reported as a successful request

`RefillService::request()` called the provider and then returned
`array('ok' => true)` **regardless of the answer**. A provider refusal —
"Incorrect order ID", "Refill not available for this service", "Order is too
old", all of which arrive as HTTP 200 with an error envelope — was written into
`refills.error`, the row was left in `PENDING`, and the customer was shown
*"Refill requested."*

Nothing ever looked at that row again. The status poller
(`Refill_model::pending_provider_sync()`) only selects refills that already
carry a `provider_refill_id`, and a refused refill has none. So the row sat in
`PENDING` for ever, the admin Refills queue grew a permanent tail of "waiting
more than 24 hours" items no worker would ever touch, and the customer waited
for a top-up that had never been requested.

A **timeout** produced exactly the same row. The panel could not tell "the
provider said no" from "the provider never answered", which are opposite
situations: one must be closed, the other must be re-sent.

### 1.2 Nothing ever re-sent a refill

The comment said *"otherwise create the row in PENDING so a worker can submit
it"*. No worker did. `refill_status()` only polled.

### 1.3 The refill poller spoke the wrong vocabulary — and could starve itself

`CronWorkers::refill_status()` mapped provider words through `$status_map`, the
**order** status map. A refill does not use order words: panels answer
`Rejected`, `Refused`, `Not found`, `Error`. None of those existed in the map,
so `$new` came back `null` and the loop did `continue` — **without updating
`last_checked_at`**.

Rows are selected `ORDER BY last_checked_at ASC`. A handful of refills whose
status could not be mapped therefore sat permanently at the head of the queue
and were re-selected on every run, so on a busy panel the poller could stop
updating everything else. Worse, those refills never reached a terminal state
at all.

### 1.4 A partial delivery that delivered nothing refunded nothing

`OrderService::apply_partial()` guarded the refund with
`$remains > 0 && $remains < (int)$order->quantity`. When a provider reports
`remains == quantity` — nothing was delivered, the single most common way an
SMM order fails — the refund was **zero**. The customer paid in full for an
empty delivery, and the order looked correctly handled. `quantity <= 0` did the
same thing.

### 1.5 A partial refund could be recorded without being paid, or paid twice

- The ledger result was never checked: `refunded_amount` was written whether or
  not the credit succeeded. A failed refund became a refund that the books said
  had happened and nobody would ever look for again.
- `refunded_amount` was **overwritten**, not accumulated, and the idempotency
  key (`order:partial:<public_id>`) never varied. A drop reported in stages
  (200 missing, then 500 missing) credited the first amount, was deduplicated
  on the second, and then recorded the larger figure anyway — a refund the
  customer never received. The later full refund computes
  `charge − refunded_amount`, so the phantom amount was silently subtracted
  from what the customer eventually got back.
- `$this->ci->Wallet_model->for_user(...)` was dereferenced without a null
  check.

### 1.6 A cancellation the provider refused still refunded the customer

`OrderService::cancel()` called `requestCancel()` inside a `try` and threw the
result away. `admin/Orders::cancel` and `admin/Operations::cancel` did not call
the provider **at all** — they went through `apply_status('CANCELED')`.

A provider refuses a cancellation when delivery has already started, which is
precisely when customers ask to cancel. The panel refunded the customer in full
and kept paying the provider for a delivery that carried on. Every one of those
was a straight loss that looked, in the books, like a clean cancellation.

### 1.7 Nobody was told how a refill ended

`NotificationService` had no refill events. A refill that completed, was
refused, or was quietly abandoned produced no inbox row and no email.

---

## 2. What the code does now

### RefillService (`application/libraries/RefillService.php`)

| Provider answer | Refill row | What the customer sees |
|---|---|---|
| accepted, refill id returned | `PROCESSING`, id stored | "The provider accepted the refill." |
| refusal (HTTP 200 error envelope, per-order refusal, no refill id) | `FAILED`, `completed_at` set, provider's words in `error` | the refusal itself, plus an inbox notification |
| transport failure (timeout, 5xx, 429, HTML maintenance page) | `PENDING`, retried by the worker | "The provider could not be reached; the refill is queued and will be re-sent automatically." |
| no usable provider reference (never submitted, provider disabled) | `PENDING`, `metadata.manual = true` | "Refill logged for a member of staff to handle: …" |

Retries are counted in `refills.metadata.submit_attempts` and bounded by
`RefillService::MAX_SUBMIT_ATTEMPTS` (5); after that the refill is closed as
`FAILED` with *"Given up after N attempts"*. Rows flagged `manual` are never
closed by the worker — a human still has to act, and closing it behind their
back would hide the work.

`apply()` is the single place a refill changes state: it writes
`refill_status_history`, stamps `completed_at` on a terminal status, and
notifies the customer. `touch()` records that we looked without pretending
anything changed.

A refill outside the guarantee window (`refill_window_days`, default 30, 0 =
no limit) is refused **before** calling the provider — the provider would
refuse it anyway, and the customer gets a straight answer instead of a queue
item.

### The adapter (`StandardSmmAdapter`)

Every failure envelope now carries **`retryable`**:

- `true` — connection failure, HTTP 429, HTTP 5xx, a body that is not JSON
  (a maintenance page is the panel having a bad minute, not a decision);
- `false` — any answer the panel actually gave, including per-order refusals
  buried in a list response (`[{"order":1,"refill":{"error":"…"}}]`).

Re-sending a refusal gets an account rate-limited; closing a timeout throws
away the customer's only remedy. The distinction is the whole point.

### The worker (`CronWorkers::refill_status`, every 5 minutes)

Three passes, in the order a refill meets them:

1. **submit** — `Refill_model::pending_submission()` finds refills with no
   provider reference and re-sends them through `RefillService::retry()`;
2. **poll** — accepted refills are asked about, through a **refill-specific**
   status map (`completed/success/done`, `in progress/processing/running`,
   `pending/queued/awaiting`, `rejected/refused/declined/error/failed/expired/not found`).
   Every branch — success, refusal, unreachable provider, unmappable word —
   updates `last_checked_at`, so the queue always rotates;
3. **close** — anything still open after `refill_abandon_hours` (default 168 =
   one week) is closed as `FAILED` and the customer told. "Still waiting" stops
   being true at some point, and an item that can never be cleared is not a
   queue, it is a graveyard.

### Partial refunds (`OrderService::apply_partial`)

```
remains  = clamp(remains, 0, quantity)
target   = quantity > 0 ? charge × remains / quantity : charge
delta    = target − refunded_amount        // only ever the difference
credit(delta) with key order:partial:<public_id>:<target>
refunded_amount = target                   // only if the ledger actually paid
```

Nothing delivered now refunds the whole charge; a remainder larger than the
order is clamped rather than multiplying the refund; a staged drop credits only
the difference; a repeated report moves nothing; and a ledger refusal leaves
`refunded_amount` alone and writes a note that says staff attention is needed.

### Cancellation (`OrderService::cancel`)

- Asks the provider first and **believes the answer**. A refusal — or silence —
  fails the cancellation with `code = PROVIDER_REFUSED` and the provider's own
  message. No refund is paid on an order that is still being delivered.
- `options['force']` overrides it. The admin order screen exposes this as a
  *"cancel anyway"* checkbox that spells out the cost.
- `options['source'] = 'ADMIN'` skips the customer-facing `cancel_supported`
  promise (staff must be able to cancel an order stuck at a dead provider) and
  records the transition against `ADMIN`.
- Cancelling now fires the reseller webhook and the customer notification,
  which only `apply_status()` used to do.
- `admin/Orders::cancel` and `admin/Operations::cancel` both route through this
  service instead of `apply_status()`.

### Notifications

`NotificationService::EVENTS` gains `refill.completed` and `refill.failed`
(in-app; no email template, so nothing to seed). Both appear automatically on
the customer's notification-preferences form, which renders from that map.

### Settings

| Key | Default | What it does |
|---|---|---|
| `refill_window_days` | 30 | How long after completion a refill may be asked for. 0 = no limit. |
| `refill_abandon_hours` | 168 | When an unsettled refill is closed and the customer told. |

### Admin surfaces

- **Refills queue** shows `submit_attempts` and a **Needs staff** badge for
  refills that no machine can send, so "the provider is down" is
  distinguishable from "nobody can ever send this".
- **Order detail** carries the *cancel anyway* override with its warning.
- **Cancellations queue** explains that a refusal blocks the refund and where
  to override it.

---

## 3. How it was verified

### Unit / integration (`tests/unit/RefundsRefillsTest.php`, 21 tests)

Runs the real models, the real ledger and the real schema through
`IntegrationHarness`; only the provider HTTP call is a double. Covers: refusal
closes and notifies; refusal is never re-sent; a timeout is kept and re-sent;
retries are bounded; a manual refill is never auto-closed; the poller settles
and notifies; `Rejected` closes the refill; an unmappable status still records
the check (the starvation bug); a never-settled refill is closed; the window is
enforced without calling the provider; nothing-delivered refunds the full
charge; an oversized remainder is clamped; a worsening partial credits only the
difference; a repeated report moves nothing; partial-then-full returns exactly
the charge; a failed ledger credit is never recorded as paid; a refused
cancellation refunds nothing; `force` cancels and refunds; a cancellation
notifies.

`tests/unit/SmmAdapterTest.php` gains the `retryable` classification tests
(refusal vs timeout vs 502 vs 429 vs maintenance page, and the per-order refill
refusal shape).

The harness's provider double was answering `createRefill`/`cancelOrder` —
method names no adapter implements — so every refill in the older tests fell
through to "no refill id" and looked refused. It now speaks
`ProviderAdapterInterface`. One assertion in `AdminOperationsTest` was updated
for that, with the reason recorded in the test.

**Suite: 1347 tests, 16286 assertions, 0 failures, 1 skipped.**

### End-to-end (`tools/devserver/refunds_check.mjs`, 32 checks)

Real HTTP against the running panel (customer browser and admin browser), the
real cron worker through the PHP CLI, and `fake_smm_panel.mjs` — extended so a
refill/cancel can be scripted to refuse, and the panel can be put into
maintenance so a request genuinely goes unanswered.

Proves, end to end: a refusal reaches the customer's screen and closes the
refill; it is never re-sent; an unanswered refill is kept, re-sent by the
worker, and then settled from the provider's own status; a never-settled refill
is closed and announced; a nothing-delivered partial refunds ₦2,000 of a ₦2,000
charge to the real wallet and re-reporting it moves nothing; a refused
cancellation leaves the order running and the wallet untouched; *cancel anyway*
cancels and refunds exactly once; and the admin screens carry the error, the
override and the two new settings (which save and persist).

```
node tools/devserver/refunds_check.mjs --admin-password '…'
32/32 checks passed
```

Regression runs, all green: `smoke` 24/24, `journey` 38/38, `commerce_check`
24/24, `admin_check` 18/18, `smm_provider_check` 13/13, `api_check` 31/31,
`notifications_check` 22/22, `reconciliation_check` 10/10, `gateway_check`
20/20, `ux_separation_check` 58/58, `shop_check` 45/45, `feature_flags_check`
32/32, `settings_validation_check` 20/20, `currency_check` 28/28, `page_audit`
(0 failing pages), `link_crawl` (160 pages, 0 problems), `npm run test:js`
13/13.

---

## 4. Still not proven here

No live provider account exists in this environment, so the refill lifecycle
has been driven against a faithful fake panel rather than a real one. The
shapes it answers with are the documented "SMM panel API v2" shapes and the
refusal styles seen in the wild, but a real panel with its own vocabulary may
still return a status word outside the map — which is now logged
(`refill_status: unknown provider status '…'`) instead of silently jamming the
queue.
