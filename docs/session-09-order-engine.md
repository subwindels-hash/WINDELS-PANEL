# WINDELS PANEL — Session 09: Order Engine

> End-to-end order placement: validation → pricing → wallet charge → order
> persistence → state history → provider submission, with idempotency and
> automatic refund on failure. All money goes through `LedgerService`.

## What shipped

| Area | Files |
|---|---|
| Order engine | `application/libraries/OrderService.php` |
| Customer placement/cancel | `controllers/dashboard/Orders.php`, `views/dashboard/orders/new_order.php`, `views/dashboard/orders/detail.php` (cancel action) |
| Routes | `dashboard/orders/create`, `dashboard/orders/:id/cancel` |
| Tests | `tests/unit/OrderServiceTest.php` |

## Create flow (`OrderService::place`)

1. **Resolve user & service.** The service may be passed by internal id, public
   ULID, or slug. Unknown/inactive services are rejected.
2. **Idempotency.** A caller-supplied `idempotency_key` is normalised and stored
   on the order; a repeated request returns the original order without charging.
   The customer form generates one per page load so back-button resubmits are safe.
3. **Validate quantity** against `min_quantity`, `max_quantity`, and
   `increment_step`, and the target link (must be `http(s)`, not localhost, and
   must not match a blacklisted pattern).
4. **Price** through `PricingService::price_for()` (user-specific → price group
   → service default) and `charge_for_quantity()` — all bcmath, no floats. The
   resolved rate is frozen as `rate_at_order` and the provider cost as
   `provider_charge` (§56) so margin is auditable.
5. **Charge** the wallet through `LedgerService::charge()` (double-entry,
   `SELECT … FOR UPDATE`, its own idempotency). Insufficient balance returns
   `INSUFFICIENT_BALANCE` before any order row is written.
6. **Persist** the order in a transaction with its initial `PENDING` row in
   `order_status_history` (source `SYSTEM`).
7. **Submit** to the provider via the existing adapter (`MockProviderAdapter` in
   demo/test, `StandardSmmAdapter` over TLS-verified `SecureHttpClient`):
   * success → `PENDING → PROCESSING`, store `provider_order_id` and
     `submitted_at`;
   * failure → `PENDING → FAILED`, record the reason, and **immediately refund**
     the charge;
   * no active provider configured → the order stays `PENDING` (routed later by
     a worker/admin), no transition to `PROCESSING`.
8. **Notify** the customer with an in-app notification.

## Other actions

* **`cancel`** — only from a cancelable state (`PENDING`/`PROCESSING`/
  `IN_PROGRESS`) and only when the service supports cancellation; calls
  `requestCancel()` on the adapter when a provider order exists and records the
  `CUSTOMER`-sourced `→ CANCELED` history entry.
* **`apply_status` / `apply_partial`** — used by future cron/webhooks to apply
  external status changes. `PARTIAL` automatically refunds the undelivered share
  proportionally (`remains / quantity * charge`) via `LedgerService::refund()`
  and records `refunded_amount`. Illegal transitions throw via
  `OrderStateMachine::assert()`.

## Controller / form

* `GET /dashboard/new-order` renders a service picker (all active services with
  embedded `data-rate/min/max/step`), link and quantity fields, a live client-side
  total, wallet balance, and the resolved user price badge when applicable.
* `POST /dashboard/orders` is CSRF-protected and POST-only; it validates with
  `form_validation`, calls `OrderService::place`, and redirects to the new order
  on success or back to the form with a flash error on failure.
* The order detail page now shows a real, CSRF-protected **Request
  cancellation** button when the order is active and the service allows it.

## Safety rules

* Only `OrderService` writes orders; controllers never call
  `insert('orders')`, `update('wallets')`, or `insert('wallet_transactions')`
  directly (verified by a test).
* The wallet is the only source of truth for balance; the order's `charge` is a
  frozen snapshot, not a mutable value.
* Provider API keys stay encrypted and are only decrypted inside the adapter;
  every outbound call enforces TLS verification.
* All links are validated server-side and checked against the blacklist before
  the wallet is charged.
* Every status change appends an immutable `order_status_history` row with its
  source (`SYSTEM|ADMIN|PROVIDER|CUSTOMER|CRON|WORKER`).

## Follow-ups (later sessions)

* **Session 10** — mass orders, drip-feed (child orders), subscriptions, and the
  refill request flow on top of this engine.
* **Session 16** — a queue/worker replaces the inline provider submission and
  drives `apply_status()` from `getMultipleOrderStatus()`; the cron
  `order_status` job already exists as a stub.
