# WINDELS PANEL — Session 15: Admin panel (operational core)

> The back office staff actually work in: the order queue, the manual-deposit
> approval queue, and the staff ticket queue — plus a real admin landing page.
> No new migration; `tickets.assigned_to_id` and `ticket_messages.is_internal_note`
> already existed from migration 008.

## What shipped

| Area | Files |
|---|---|
| Order queue | `controllers/admin/Orders.php`, `views/admin/orders/{index,detail}.php` |
| Deposit approval | `controllers/admin/Payments.php`, `views/admin/payments/{index,detail}.php` |
| Staff ticket queue | `controllers/admin/Tickets.php`, `views/admin/tickets/{index,detail}.php` |
| Admin landing | `libraries/AdminStats.php`, `controllers/admin/Dashboard.php`, `views/admin/dashboard.php` |
| Staff ticket API | `libraries/TicketService.php` — `staff_reply()`, `assign()`, `set_status()` |
| Admin queries | `models/{Order,Ticket,Payment_transaction,User}_model.php` |
| Tests | `tests/unit/AdminPanelTest.php` (25 tests) |

## Order queue

* **List** filters by status, source and a search across public id, provider
  order id and link, with per-status counts in the header.
* **Detail** shows the customer, service, link, money and the full append-only
  status history.
* **Actions** — status change, cancel and refund. The status dropdown only
  offers transitions `OrderStateMachine` will accept, and choosing `PARTIAL`
  reveals a required `remains` field (validated as a whole number no greater
  than the ordered quantity).
* Every action delegates to `OrderService::apply_status()`, so the state
  machine, the history log and the refund rules are identical to the customer
  and cron paths. The controller never writes `orders.status` itself.

## Deposit approval

* Defaults to the `PENDING` queue — the deposits a human has to decide on.
* **Approve** calls `PaymentService::confirm()`, the only path that credits a
  wallet (through `LedgerService`) and records `wallet_transaction_id` on the
  transaction. An optional bank reference is stored as `provider_tx_id`.
* **Reject** marks the row `FAILED` and credits nothing.
* An already-`SUCCESS` deposit can be neither re-approved nor rejected; the UI
  says so and points at a wallet adjustment instead.

## Staff ticket queue

* Filters: status, priority, department, search, **assigned to me** and
  **unassigned**. Sorting puts `OPEN` before `CLOSED` and `URGENT` before `LOW`.
* Staff see the whole conversation **including internal notes**; the customer
  view filters `is_internal_note` out.
* **Reply** posts a visible answer and moves the ticket to `ANSWERED`. Ticking
  *internal note* stores a staff-only message and deliberately leaves the status
  alone — a note is bookkeeping, not an answer.
* A customer reply can never become an internal note: `add_message()` forces the
  flag off unless the author is staff.
* **Assign** validates that the assignee is actually staff (`BAD_ASSIGNEE`
  otherwise), and status/priority changes are whitelisted.

## Permissions

Read and write are separate throughout, and `Admin_Controller::require_perm()`
enforces each one:

| Action | Permission |
|---|---|
| View orders / detail | `orders.view` |
| Change order status | `orders.edit` |
| Cancel an order | `orders.cancel` |
| Refund an order | `orders.refund` |
| View payments | `payments.view` |
| Approve / reject a deposit | `payments.manage` |
| View the ticket queue | `tickets.view` |
| Reply / add a note | `tickets.reply` |
| Assign, status, priority | `tickets.manage` |
| Admin landing page | `reports.view` |

`STAFF` holds the view permissions plus `orders.edit` and `tickets.reply`, so a
support agent can work the queues but cannot refund money or reassign work.

## Safety

* Every mutation is POST-only (`show_404()` on GET), CSRF-protected and
  audit-logged with before/after state through `Audit_log_model`.
* No admin controller calls `LedgerService` or writes `wallets` /
  `wallet_transactions` — asserted by a test that scans the whole directory.
* Action routes are declared before the `(:any)` detail route, or CI3 would
  swallow them; a test enforces the ordering.
* The `admin_*` model queries are deliberately unscoped, which is safe only
  because a permission gate sits in front of them. The customer-facing
  `find_public_for_user()` / `for_user()` lookups stay scoped, and a test pins
  both facts.

## Fixes made along the way

* **`OrderService` never refunded a canceled or refunded order.** Only the
  `PARTIAL` path returned money; `apply_status()` moved an order to `CANCELED`,
  `REFUNDED` or `FAILED` and left the customer's charge with the panel. Added
  `refund_charge()`, which returns `charge - refunded_amount` under a
  deterministic idempotency key, so a partial refund followed by a full refund
  returns exactly one charge and a repeated cancel returns nothing extra.
* **A direct move to `PARTIAL` was ignored.** `apply_status()` only routed to
  `apply_partial()` when the target was `COMPLETED`, so an admin marking an
  order partial recorded no `remains` and refunded nothing. Both entry points
  now reach the same path.
* **`Payment_event_model::for_transaction()` called `get()` with no table**,
  which builds `SELECT * FROM ()` in CI3 — the payment event log could never
  have rendered.

## Follow-ups

Still open from the Session 15 roadmap, deliberately deferred to keep this
reviewable:

* Service / category / provider CRUD and the customer manager.
* FAQ, blog and announcement CRUD (the models and public pages already exist).
* Staff manager (create staff, assign roles) and the audit-log viewer.
* Commission clawback on an already-paid referral (Session 14 follow-up).
* Wallet adjustments, which is the documented way to reverse a credited deposit.
