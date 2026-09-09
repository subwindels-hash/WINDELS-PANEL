# Module 8 — service purchases under provider failure

*Branch `arena/01a04558-windels-panel`. Follows module 7 (analytics).*

VTU, virtual numbers, identity checks and gift cards all run through
`TransactionEngine`, and all four are sold the same way: charge the customer,
call a vendor, settle later. The engine's money rules were sound. What was
missing was what happens when the vendor accepts a purchase and then never
becomes answerable about it.

---

## 1. What was broken

### 1.1 A purchase with nothing to poll stayed in flight for ever

`Service_transaction_model::pending_provider_sync()` — the query behind every
settlement worker — selects rows that are `PROCESSING` **and have a
`provider_reference`**. That is correct for polling and fatal as a whole
strategy, because it makes one shape of row invisible to every worker in the
panel:

> in flight, charged, with no reference to poll.

It happens for real reasons: a vendor accepts a top-up and answers without a
reference; a response is lost; the process dies between the charge and the
reply. VTU had **no give-up rule of any kind**, so such a row sat at PROCESSING
indefinitely. The money was gone from the customer's wallet, the airtime never
arrived, and no worker, screen or alert would ever mention it again. The only
way out was an operator noticing it by hand in the admin queue.

Gift cards already had this discipline (`giftcard_give_up_minutes`, then
`abandon()`); nothing else did.

### 1.2 A PENDING record was never closed either

`execute()` creates the row, then charges, then moves to PROCESSING. A row left
at PENDING therefore means the charge never completed. No money was taken — but
the row still counted in the admin dashboard's "stuck purchases" figure, for
ever, so the number staff are asked to act on drifted permanently upward.

### 1.3 An adapter that threw a PHP Error kept the customer's money

The dispatch call was wrapped in `catch (Exception $e)`. In PHP 7+, a
`TypeError` — an array where an object was expected, a method called on null
after a vendor changed a field, the single most common shape of adapter bug —
is an `Error`, not an `Exception`. It escaped the handler entirely: the
customer had already been charged, the transaction stayed PROCESSING, **no
refund was made**, and the request died as a 500.

### 1.4 Money came back in silence

Every refund in these four domains — automatic on provider failure, or a member
of staff pressing Refund — moved the money and told the customer nothing. They
saw a purchase vanish and a balance change with no explanation.

---

## 2. What the code does now

### `TransactionEngine`

- `catch (Throwable)` around the dispatch, so an adapter crash refunds exactly
  like a rejection.
- A refund is the last step before returning and now notifies the customer:
  `purchase.refunded` (in-app), worded per domain — "Top-up STX… was not
  completed (…). ₦500.00 has been returned to your wallet." Wrapped in its own
  try/catch: the money has already moved and a mail or inbox problem must never
  undo it.
- The refund guard is explicit that a row with no `wallet_transaction_id` never
  moves money, because nothing was ever charged.

### `CronWorkers::service_recovery()` — every 10 minutes, all domains

| Row | Window | Outcome |
|---|---|---|
| PROCESSING, no provider reference | `service_stuck_minutes` (default 60) | FAILED + full refund — "The provider accepted nothing we can check on" |
| PENDING (charge never completed) | `service_stuck_minutes` | FAILED, **no** money moves — "Abandoned before payment completed" |
| Any in-flight row, reference or not | `service_abandon_hours` (default 24) | FAILED + refund — "The provider never settled this purchase" |
| SUCCESSFUL, however old | — | untouched |

The two-tier window is the point: a purchase with a reference belongs to its own
settlement worker for the first day, and only becomes this job's problem when
that worker has plainly stopped making progress. Refunds go through
`TransactionEngine::transition()`, so they are capped at what was charged,
idempotent, written to the append-only status history, and announced.

`Service_transaction_model::stuck()` is deliberately two plain queries merged by
id rather than one clause of nested OR groups — each is index-friendly and
obvious at a glance, and money queries are not the place to be clever.

Registered in `Cron::$jobs`, `config/marvy.php` (`*/10 * * * *`) and
`cron/crontab.example`, with a test asserting all three, because a worker that
exists and is never scheduled is worse than no worker at all.

### Settings

| Key | Default | What it does |
|---|---|---|
| `service_stuck_minutes` | 60 | When an unpollable purchase is written off and refunded |
| `service_abandon_hours` | 24 | Backstop for purchases whose vendor stopped settling them |

---

## 3. How it was verified

### Unit / integration — `tests/unit/ServiceRecoveryTest.php` (9 tests)

Real engine, real ledger, real schema; only the vendor call is a double:

- a purchase the vendor left unpollable is refunded in full once hopeless, with
  the reason recorded;
- the customer is told why their balance changed;
- a fresh purchase is left alone;
- a *pollable* purchase is only closed by the hard backstop (3 hours: still the
  poller's; 30 hours: written off);
- an unpaid PENDING record is closed without moving money;
- three sweeps in a row refund once, notify once, and leave the ledger balanced;
- a SUCCESSFUL purchase is never swept away, however old;
- an adapter throwing a PHP `Error` still refunds and still notifies;
- the job is registered in the controller, the schedule and the crontab.

**Suite: 1364 tests, 16388 assertions, 0 failures, 1 skipped.**

### End-to-end — `tools/devserver/service_recovery_check.mjs` (17 checks)

A real ₦500 airtime purchase is made **over HTTP** (real charge, real ledger,
real wallet), then rewound into the unpollable state, and the sweep is run the
way cron runs it (`php index.php cron service_recovery` via the PHP CLI):

- the purchase is closed rather than waiting for ever, with an actionable
  reason;
- the charge is returned in full and the wallet actually receives it;
- the customer gets the inbox entry naming the reference;
- the status trail records SYSTEM as the actor;
- running the sweep twice more returns nothing extra and notifies nobody twice;
- a purchase minutes old is left to its poller;
- the admin purchase page shows the failure and the refund, and the failed
  purchase is findable in the queue.

```
node tools/devserver/service_recovery_check.mjs --admin-password '…'
17/17 checks passed
```

Regressions all green: `smoke` 24/24, `journey` 38/38, `commerce_check` 24/24,
`admin_check` 18/18, `notifications_check` 22/22, `refunds_check` 32/32,
`analytics_check` 19/19, `ux_separation_check` 58/58, `shop_check` 45/45,
`api_check` 31/31, `currency_check` 28/28, `settings_validation_check` 20/20,
`feature_flags_check` 32/32, `page_audit` (0 failing pages), `npm run test:js`
13/13.

---

## 4. Still not proven here

- No live VTPass, Reloadly, 5sim or Dojah account exists in this environment,
  so the adapters' own request/response shapes remain verified against their
  documentation and unit-level doubles rather than a sandbox. The recovery path
  above is deliberately independent of any of them: it exists precisely for the
  case where the vendor's answer is unusable or absent.
- `identity` has no settlement worker of its own because `IdentityService`
  resolves synchronously in this build. If a future provider returns
  PROCESSING, this sweep is what closes it — a backstop, not a substitute for
  the poller that domain would then need.
