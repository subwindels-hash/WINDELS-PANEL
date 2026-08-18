# Session 25 — Virtual numbers + OTP (phase D)

Phase D of the [rebuild-spec build order](rebuild-spec-audit.md#7-proposed-build-order).
The audit picked this domain out for a specific reason:

> **D** — Virtual numbers + OTP sessions/messages — *New lifecycle (reservation,
> expiry) the order engine does not yet model*

Everything shipped so far sells a thing that is delivered. Airtime lands on a
phone, a data bundle activates, an SMM order progresses to a count. A virtual
number is different: the customer pays up front to **rent** a phone number for
about fifteen minutes, and the purchase is worth nothing unless a code arrives
before a deadline that the *vendor* sets. Doing nothing is the expensive option
— the reservation dies on its own, with the charge already taken.

So this session is mostly about one question: where does the deadline live, and
who is responsible for acting on it?

## The shape of the answer

Money did not need a new engine. `TransactionEngine` already models
"charge → in flight → settle or refund", which is exactly a reservation if you
map it honestly:

| Reservation event | Engine call | Money |
| --- | --- | --- |
| Number rented | `execute()`, dispatch returns `PROCESSING` | charged |
| A code arrives | `transition(SUCCESSFUL)` | kept — service rendered |
| Deadline passes, no code | `transition(FAILED)` | **refunded in full** |
| Customer cancels before a code | `transition(CANCELLED)` | **refunded in full** |
| Customer reports it unusable | `transition(FAILED)` | **refunded in full** |
| Customer finishes with a working number | none | nothing moves |

The one line worth arguing about is the first: **a reservation is never
`SUCCESSFUL` on reserve**. It would have been easy to settle it there, since
the vendor accepted and the number exists. But then an expiry would have to
claw money back out of a closed transaction rather than refund an open one, and
`TransactionEngine` deliberately treats a settled purchase as only refundable
by an admin. Leaving it `PROCESSING` means the automatic refund path is the
ordinary one, not a special case.

### Two state machines, kept apart

`virtual_numbers.status` is the *reservation's* state and has its own
vocabulary — `RESERVED`, `RECEIVED`, `COMPLETED`, `CANCELLED`, `EXPIRED`,
`BANNED`. It is not a copy of the transaction's status, because they answer
different questions: "does the vendor still hold this number for us?" versus
"where is the money?". A `RECEIVED` number sits against a `SUCCESSFUL`
transaction; an `EXPIRED` one against a `FAILED`, refunded transaction.

Keeping them separate is what lets the expiry sweep be a three-line query
(`status = RESERVED AND expires_at <= now`) instead of re-deriving the refund
rules from transaction state.

## Migration 012

Five tables, following migration 010's conventions exactly — reference tables
carry `public_id`/`code UNIQUE`/`is_active`/`sorting`, and the domain table
never duplicates a money column.

| Table | What it holds |
| --- | --- |
| `number_countries` | Where a number can be rented |
| `number_services` | What the OTP is for (WhatsApp, Telegram, …) |
| `number_products` | One buyable (country, service) pair from one vendor |
| `virtual_numbers` | The reservation: number, deadline, state |
| `otp_messages` | Every SMS delivered to a rented number, append-only |

Two columns carry the whole phase:

```sql
expires_at DATETIME NULL COMMENT 'vendor deadline; the expiry sweep reads this',
INDEX idx_vnum_status_expires (status, expires_at)
```

and one constraint prevents the most obvious bug:

```sql
UNIQUE KEY uq_otp_msg (virtual_number_id, provider_message_id)
```

A vendor returns its **whole inbox** on every poll. Without that key, a number
polled six times shows the customer six copies of one code and they cannot tell
which is current. `Otp_message_model::record()` returns whether a row was
actually written, and the service settles on *new* messages rather than on how
many the vendor sent.

## 5sim, not a mock-only domain

VTU shipped mock-only in session 21 and only met a real vendor in
[session 24](session-24-vtpass.md), which found three money bugs that a mock
could never have surfaced. Rather than repeat that, this domain was built
against a real vendor's contract from the start: **5sim** (`https://5sim.net/v1`).

`FiveSimAdapter` exists as its own class because four properties of that API
are each a money bug if you get them wrong:

1. **Errors are plain text, frequently with HTTP 200.** `no free phones`,
   `not enough user balance`, `order has sms` come back as bare strings. Code
   that `json_decode()`s and trusts the result reads every rejection as a
   successful reservation and charges for a number the customer never got. The
   adapter checks for a plain-text body *before* decoding.

2. **The vendor owns the deadline.** The buy response carries `expires`.
   Computing our own from "now + 15 minutes" drifts against the vendor and
   eventually either refunds a live reservation or holds a dead one open.
   `expires_at` is always the vendor's, normalised to UTC.

3. **Prices are in roubles, and this panel is in naira.** There is no
   defensible rate to hardcode, so a converted `cost` is only reported when the
   operator has set `providers.retry_policy → fivesim.rate_to_base`. Without
   it the vendor figure comes back as `cost_vendor` and `cost` is absent — the
   margin report says "unknown" rather than being wrong by a factor of twenty.
   `FIVESIM_RATE_TO_BASE` in `.env.example` is where an operator sets it.

4. **`finish`/`cancel`/`ban` are not interchangeable.** `cancel` is refused
   once an SMS has arrived, and `ban` costs vendor rating. `NumberService`
   picks; the adapter only reports what the vendor said.

As in session 24, **no live calls were made** — the sandbox is unreachable from
this environment. Every response in `tests/fixtures/fivesim/` is a captured
5sim shape, including the three plain-text error bodies, and the HTTP client is
a scripted fake that throws on an unscripted call.

`MockNumberAdapter` is the offline counterpart, following the
`…0000`/`…9999` convention MockVtuAdapter set but keyed on the service code:
`NOSTOCK` rejects, `SLOW` never delivers, everything else delivers a code **on
the second poll**. That last detail matters — a mock that answers instantly
would let code that never polls at all pass its tests, which is precisely the
bug this domain is prone to.

## The worker

`numbers_status` runs **every minute**, not every two like `vtu_status`. A
reservation lives about fifteen minutes and the customer is watching the screen
for their code; a missed tick is a customer charged for a number nobody checked
before it died.

It polls first and expires second, deliberately. A code that lands in the last
few seconds before the deadline still counts, and checking first means the
customer keeps a number that actually worked. There is a test for exactly that
(`testACodeArrivingJustBeforeTheDeadlineStillSettlesTheSale`).

Reservations the poll did not reach — no vendor reference, or the list
truncated by `$limit` — are swept separately, because otherwise a customer
stays charged for a number nobody will ever check again.

## Screens

**Customer** (`dashboard/numbers`): rent, then a detail page showing the
number, a countdown, the codes as they arrive, and four buttons whose
availability mirrors what `NumberService` will actually accept. A number that
received a code offers "I'm done with it"; one that has not offers "Cancel and
refund" and "Already registered". Offering a button the service will reject is
worse than not offering it.

This screen does something no other customer screen does: `check` makes a live
vendor call. That is not a page render — it is an explicit "has my code
arrived?" press, POST-only so a prefetch cannot trigger it, and the work still
happens inside `NumberService`. `PerformanceTest::testNoWebPageBlocksOnAProviderHttpCall`
scans page renders, which this is not.

**Admin** (`admin/numbers`): the queue built to the pattern
[session 23](session-23-admin-vtu.md) set, with one difference. A VTU purchase
is stuck when the provider has not answered; a reservation is stuck when the
deadline is near and no code has come. So the queue leads with the reservation
state and the expiry — overdue rows are flagged — rather than only the
transaction status.

Permissions `numbers.view` / `numbers.manage` / `numbers.refund` are seeded and
wired the same way VTU's are: read behind `view`, each mutation behind its own
key, POST-only, CSRF-protected, audit-logged, and every state change through
`NumberService` or `TransactionEngine::transition()`.

## Two rules worth stating plainly

**A number that received a code is not refundable by the customer.** `cancel`,
`expire` and `ban` all refuse once `sms_count > 0`. The service was rendered;
a refund from there lets a customer keep both the code and the money. An admin
can still refund it as goodwill through the engine — that is a deliberate
decision, not an automatic one.

**Releasing a number that never received a code still refunds.** Handing the
number back early does not turn an undelivered service into a delivered one.

## Also changed

- `Provider_manager` gains `FAMILY_NUMBER`; the MOCK-construction branch now
  matches any `MOCK*` type so `MOCK_NUMBER` builds through the same path.
- `ProviderSyncService::family()` is now registry-driven across all families
  rather than a VTU special case, and grows `test_number_connection()` and
  `sync_number_catalogue()`. As with VTU, a sync writes the vendor's cost and
  stock but **never our price**, and new rows land inactive and unpriced.
- `Service_transaction_model::admin_search()` picks its joins by domain instead
  of LEFT JOINing every domain table on every query — that would grow with the
  catalogue, and two domain tables both carrying `status` would make the result
  ambiguous.
- The customer history and "your live numbers" lists fetch their reservations
  in one query (`Virtual_number_model::for_transactions()`); at 15 rows a page
  that is two queries instead of sixteen. `PerformanceTest` caught the N+1.
- `Core_seeder` seeds countries and services but **no products** — a product
  needs a price, and nobody knows the price until a vendor is connected. The
  catalogue sync creates those rows, inactive, for an admin to price.
- `Demo_seeder` creates an INACTIVE 5sim provider only when `FIVESIM_API_KEY`
  is set, on the same terms as VTpass.

## Tests

`tests/unit/NumbersTest.php` — 50 tests, 190 assertions:

- **Behavioural** (real models, `NumberService`, `TransactionEngine`,
  `LedgerService`, migration-derived schema): charge-on-reserve, vendor
  rejection refunding in full, stock and price pre-checks, idempotent double
  clicks, code ingestion and settlement, repeated polling not duplicating a
  code, expiry refunding, a code-bearing number never being expired or
  cancelled, release with and without a code, the worker's two paths, and the
  ledger balancing after each.
- **5sim contract** (fixture-driven): bearer auth and slug mapping, the
  vendor-owned deadline, plain-text rejections not read as success, state
  mapping including `TIMEOUT → EXPIRED`, rouble prices not passed off as naira,
  hosting rows skipped by the catalogue sync, and a timeout leaving the
  reservation undecided rather than guessed.
- **Surface** (source-level, matching `AdminVtuTest`): POST-only guarded
  mutations, a permission per action, audit logging, no controller touching the
  ledger or a status column, customer lookups scoped to the signed-in user,
  bounded lists, CSRF in every admin POST form, and the worker being scheduled
  *and* wired — a registered worker that is never scheduled is the same as no
  worker at all.
- **Schema**: `expires_at` and its index present, one reservation per
  transaction, no money column on the domain table, and the OTP uniqueness key.

Suite after this session: **568 tests, 5,773 assertions, 0 failures**
(was 518 / 5,341).

## Next

Phases E–G remain:

- **E** — identity (NIN/BVN), which needs the §22 sensitive-data controls:
  encryption at rest, access control and log minimisation. Unlike this phase,
  the hard part is not the lifecycle but what may be stored and who may read it.
- **F** — gift cards + marketplace (inventory and trading semantics).
- **G** — unified history and cross-domain analytics.
