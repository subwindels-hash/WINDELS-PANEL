# MarvySocials — Session 10: Advanced Orders

> Refills, drip-feed schedules and subscriptions on top of the Session 09
> order engine. All money movement stays in `LedgerService`; every state change
> is append-only and every action is POST/CSRF protected.

## What shipped

| Capability | Library | Controller |
|---|---|---|
| Refills | `libraries/RefillService.php`, `models/Refill_model.php`, `Refill_status_history_model.php` | `dashboard/Orders::refill` |
| Drip-feed | `libraries/DripfeedService.php`, `models/Dripfeed_order_model.php`, `Dripfeed_run_model.php` | `dashboard/Dripfeed.php` |
| Subscriptions | `libraries/SubscriptionService.php`, `models/Subscription_model.php`, `Subscription_event_model.php` | `dashboard/Subscriptions.php` |
| Models | `Refill_model`, `Refill_status_history_model`, `Dripfeed_order_model`, `Dripfeed_run_model`, `Subscription_model`, `Subscription_event_model` | — |
| Views | dripfeed index/detail, subscriptions index, refill button on order detail, sidebar entries | — |
| Tests | `tests/unit/AdvancedOrdersTest.php` | — |

## Refills

* Allowed only when the service has `refill_supported` and the order is
  `COMPLETED` or `PARTIAL`. A second active refill for the same order is
  rejected (`DUPLICATE`).
* When a provider is configured and the order has a `provider_order_id`, the
  adapter's `requestRefill()` is called immediately; success stores
  `provider_refill_id` and moves the refill to `PROCESSING`. Without a
  provider the row is created in `PENDING` for a worker to submit.
* Every status change writes a `refill_status_history` row with source
  `CUSTOMER`/`SYSTEM`/`PROVIDER`.

## Drip-feed

* Validates the service (`dripfeed_supported`), quantity bounds, run count
  (2–100), interval (≥ 5 minutes) and that `total = quantity_per_run × runs`.
* The **total charge is reserved up-front** via `LedgerService::charge()`.
  `dripfeed_orders` holds the reserve and `dripfeed_runs` one row per run.
  `next_run_at` is set from the start time; the cron worker (Session 16) calls
  `OrderService::place()` for each due run and marks the run `COMPLETED`.
* **Pause/resume** flips the schedule status; **cancel** refunds the unspent
  reserve (total charge minus sum of completed child-order charges) and cancels
  pending runs. Cancellation is idempotent.

## Subscriptions

* Validates the service (`subscription_supported`), quantity and interval
  (`daily`/`weekly`/`monthly`, or a custom `interval_minutes` ≥ 60).
* A subscription does **not** reserve funds — each run is charged at execution
  time by the worker, matching the per-interval model of most SMM panels.
* Creates the `subscriptions` row and a `created` event in
  `subscription_events`. Pause/resume/cancel are provided; `EXPIRED` is set by
  the worker when `posts`/`runs` is exhausted or `expires_at` passes.

## Safety

* Only the services write to `refills`, `dripfeed_orders/runs`,
  `subscriptions/events`; controllers never insert wallet/order rows directly
  (verified by tests).
* Refill/dripfeed/subscription public IDs are ULIDs; lookups are always scoped
  to the authenticated user.
* All state transitions go through the libraries and write history rows;
  illegal states (pausing a `CANCELED` schedule, refilling an in-progress order)
  return typed error codes.
* Dripfeed cancellation computes the refund with bcmath and never refunds more
  than was reserved.

## Follow-ups

* **Session 16** — the cron workers (`dripfeed`, `subscriptions`,
  `refill_status`) execute due runs, poll refill status, and expire
  subscriptions, reusing `OrderService` and the provider adapters.
* Mass/bulk order entry is intentionally deferred; the new-order form remains
  the single-order path.
