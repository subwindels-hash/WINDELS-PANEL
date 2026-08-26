# Session 19 — Integration & end-to-end testing

The 23 unit-test files that existed before this session covered every service in
the codebase, and all 364 of their tests passed. They also missed a bug that
made it impossible to place an order at all.

That is not an accident, and it is the reason this session exists.

## The blind spot

Every unit test in this repo builds its own doubles. `OrderServiceTest` stubs
the state machine, the wallet, the provider adapter; `DripfeedServiceTest` stubs
the order service. A double always agrees with the test that wrote it, so a
whole class of defect is invisible by construction:

- A service writes `remains` while its caller reads `remaining`. Both tests pass.
- A class is referenced but never loaded. Every test stubs it, so no test notices.
- A query selects a column set that omits a field the consumer reads.

These are integration defects. No amount of unit testing finds them, because
the thing that is wrong is precisely the seam that the unit test replaced.

## The approach

`tests/_support/IntegrationHarness.php` inverts the usual arrangement. It wires
up the **real** models, the **real** libraries and a schema derived from the
**real** migrations, and fakes only genuine edges:

| Faked | Why |
| --- | --- |
| `HarnessLoader`, `HarnessConfig`, `HarnessSession`, `HarnessInput` | CI framework glue, not our code |
| `HarnessProviderSync` / `HarnessAdapter` | third-party HTTP |
| `HarnessMailService` | SMTP |

Everything else — `OrderService`, `LedgerService`, `DripfeedService`,
`SubscriptionService`, `AffiliateService`, `CronWorkers`, all 39 models — is the
production class, running production code paths against a schema-validating
in-memory database that rejects unknown tables, unknown columns, NOT NULL
violations and UNIQUE collisions.

The harness exposes the small vocabulary the tests need: `seed_minimal()`,
`register()`, `credit()`, `balance()`, `rows()`, `ledger_is_balanced()`,
`library()`, plus `$app->provider_calls`, `$app->sent_mail` and
`$app->db->raw_updates`.

## The bug it found immediately

`OrderStateMachine` is a plain static utility, not a CI library. It is used at
four points in `OrderService` (`:182`, `:215`, `:284`, `:445`) — and it was
never loaded. Not in `autoload.php`, not via `load->library()`, not by
Composer's PSR-4 map (which is namespaced `Marvy\`, and this class is global),
not by a `require` anywhere in `application/`.

Every single order placement fataled with `Class "OrderStateMachine" not found`
the moment it tried to move `PENDING → PROCESSING`.

The fix is one line in the constructor:

```php
require_once __DIR__.'/OrderStateMachine.php';
```

Six integration tests fail without it. Zero unit tests do, because each one
stubs the state machine it needs.

`testOrderStateMachineIsAvailableWhereverItIsUsed` pins it from both directions:
it scans the source for every `OrderStateMachine::` reference and asserts the
class is loadable, *and* it drives a real `place()` to completion.

## A false positive worth recording

The harness also flagged `$service->provider_rate` at `OrderService.php:113` as
an undefined property, and it looked like a second bug: the column is nullable
and `Service_model::PICKER_COLUMNS` omits it entirely.

It was not a bug. `place()` re-resolves the service itself through
`resolve_service()`, which uses `find_by_id`/`find_by_public_id`/`find_by_slug`
— all plain `SELECT *`. A picker-sourced object never reaches that line. The
warning came from the harness's own `FakeDb` returning only the columns a row
was inserted with, where real MySQL returns every column with `NULL` for unset
ones.

The correct fix was to the test double, not the production code, and the
speculative `isset()` guard was reverted. Two changes to `FakeDb` close the
fidelity gap:

- **`hydrate()`** — every returned row is filled out against the table's
  declared column set, so unset nullable columns come back as `null`.
- **A real `select()`** — previously a no-op that returned `$this`, meaning
  projections were silently ignored and every query got a full row. It now
  records the column list (handling `t.col` and `col AS alias`) and `get()`
  projects to it.

A test double that is more permissive than the real database manufactures
phantom bugs; one that is stricter hides real ones. Both changes push `FakeDb`
toward what MySQL actually does.

## A test-ordering landmine

`AuthRbacTest` declared stub classes named `User_model` and `Role_model` via
`eval()`. Once integration tests began loading the real models in the same
process, whichever file ran first won and the other fataled with
`Cannot redeclare class User_model`.

The stubs were reachable — `AuthRbacFakeLoader::model()` picked them up through
`class_exists($name)` — so deleting them broke two tests. They are now named
`AuthRbacFakeUserModel` / `AuthRbacFakeRoleModel` and mapped explicitly in the
loader, the same way `Permission_model` already was. No global name is claimed,
and the suite is order-independent again.

`IntegrationHarness::model()` additionally refuses to silently accept a stub
that has taken a production class name, and says so in a way a human can act on.

## What the 16 tests actually assert

**The journey.** Signup → wallet credit → order → provider dispatch → status
progression → refund, asserting the full status history
(`PENDING → PROCESSING → IN_PROGRESS → COMPLETED → REFUNDED`), the balance after
each step, and a balanced ledger throughout.

**Money integrity.** These matter most, because the failure mode is silent:

- a refund happens exactly once — a second terminal transition is a no-op;
- an unaffordable order leaves zero orders *and* zero provider calls;
- a provider rejection refunds in full;
- a provider *exception* also refunds in full, ledger still balanced;
- a duplicate submit with the same `idempotency_key` resolves to one order,
  charged once;
- a `PARTIAL` with `remains = 250` of 1000 refunds exactly `0.50000000`.

**Cross-component.** The cron worker drives a real order to `COMPLETED`; a
drip-feed child order is prepaid (wallet untouched, parent linkage recorded); a
subscription charges per cycle; affiliate commission accrues without
double-charging the buyer or unbalancing the ledger.

**Invariants.** `LedgerService` is the only writer of wallet balances (any
direct `wallets.balance` write is recorded in `raw_updates` and asserted empty),
and every service writes only columns that exist in the migrations.

## Result

380 tests, 3575 assertions, 0 failures, ~14s.

The suite grew by 16 tests and found one production bug that 364 passing tests
could not see.

## Deferred

- The harness models joins as a flat column merge, not a SQL engine. Anything
  needing real join semantics still wants a live MySQL.
- No HTTP-level tests: controllers are exercised through their services, not
  through routing, CSRF and session middleware.
- `EXPLAIN` review against realistic data volumes remains a Session 20 item.
