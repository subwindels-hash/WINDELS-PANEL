# Session 27 — Gift cards (phase F)

Phase F of the [rebuild-spec build order](rebuild-spec-audit.md#7-proposed-build-order):

> **F** — Gift cards + marketplace — *inventory and trading semantics*

Two halves. This session ships the first one — **the panel sells gift card
codes** — and deliberately defers the second. See [Marketplace, deferred](#marketplace-deferred)
for why, and what the schema does to keep that door open.

Every previous domain was interesting because of its lifecycle or its payload.
This one is the first that is both:

- **The lifecycle has a gap in the middle.** A gift card is ordered in one call
  and *issued* in another. Between them the vendor has our money and the
  customer has nothing.
- **The payload is a bearer instrument.** A gift card code is money to whoever
  reads it. Not sensitive like a NIN is sensitive — spendable.

Almost every decision below follows from one of those two facts.

## The gap in the middle

`POST /orders` returns a `transactionId`; the card numbers come from
`GET /orders/transactions/{id}/cards`, which can 404 for a while afterwards
while the vendor mints the card. That produces a state the order engine had not
needed before: **paid, accepted, undelivered**.

It maps onto `TransactionEngine` as the same "charge → in flight → settle or
refund" shape a virtual number uses:

| Step | Engine | Order state |
| --- | --- | --- |
| `purchase()` | `execute()`, dispatch returns **PROCESSING** | `PLACED` |
| `collect()` — codes arrive | `transition(SUCCESSFUL)` | `DELIVERED` |
| `abandon()` — they never do | `transition(FAILED)` → refunds in full | `FAILED` |

**A purchase is never SUCCESSFUL on the order call**, even when the codes turn
up microseconds later — `purchase()` calls `collect()` inline, so the common
case still completes in one request. Settling early would close the transaction
against the refund path that undelivered orders need: the engine can refund a
PROCESSING transaction, but a settled one has to be clawed back through an admin
reversal.

`giftcard_orders.status` is kept separate from the transaction status for the
same reason `virtual_numbers.status` is: they answer different questions —
*"has the vendor issued the card?"* versus *"where is the money?"* — and keeping
them apart is what lets the sweep be a two-column query instead of re-deriving
the refund rules.

### When to stop waiting

An order the vendor accepted and never filled has to be given up on. Two
conditions, both required:

- `code_attempts >= 6` — we have actually asked, repeatedly;
- `placed_at` older than `giftcard_give_up_minutes` (default 60).

Either alone is wrong. Age alone would write off an order nobody ever chased
because the worker was stopped. Attempts alone would write off an order retried
six times in ninety seconds during a vendor blip.

Giving up **refunds the customer, and we are still billed**. That is a real
loss, so the attempt count lives on the row where an operator can see the
pattern, and the admin queue leads with the undelivered backlog rather than
burying it in a log.

The sweep collects before it writes off, so a card issued in the last seconds
before the deadline still counts.

## The payload is spendable

The controls look like the identity domain's, with one deliberate inversion.

| | Identity result (§22) | Gift card code (§23) |
| --- | --- | --- |
| Encrypted at rest | yes | yes |
| One audited path to plaintext | `IdentityService::reveal()` | `GiftcardService::reveal()` |
| Separate permission to read | `identity.reveal` | `giftcards.reveal` |
| Kept in the clear for display | last 4 of the identifier | last 4 of the card number |
| **Retention sweep** | **deletes after 30 days** | **never deletes** |

That last row is the whole difference. An identity result is a liability we hold
on the customer's behalf and scrub on a timer. A gift card code *is the product
they bought* — a sweep that helpfully tidied away an unspent ₦40,000 Amazon card
would be indistinguishable from theft. So `giftcard_codes` has no `purged_at`
column, `Giftcard_code_model` has no delete method, and
`GiftcardsTest::testNothingEverPurgesAGiftCardCode` asserts all three, because
"we forgot to add retention here" is exactly the kind of consistency a future
tidy-up would introduce.

Two further storage decisions:

- **No masked tail beyond four digits, and no plaintext anywhere.** Unlike a
  NIN, a partial card number tells support nothing useful, so the convenience
  column would be pure downside.
- **`quantity` is real, so codes are a child table.** One order can be five
  cards; each is independently revealable, because a customer handing four of
  them out needs to know which one is left.

`testThePlainCardNumberIsNowhereInTheDatabase` runs a complete purchase and then
greps *every column of every row* of `giftcard_codes`, `giftcard_orders`,
`service_transactions`, `wallet_transactions`, `ledger_entries`, `audit_logs`,
`provider_transactions` and the status history for the plaintext. Two new
`SecurityHardeningTest` gates cover the rest of the codebase: codes never reach
`log_message` or the session store, and nothing outside `GiftcardService`
decrypts one.

## Reloadly

Real vendor, pinned by 20 captured fixtures in `tests/fixtures/reloadly/`. The
sandbox is unreachable from the build box (same TLS failure as VTpass and
Dojah), so the contract is verified against recorded responses rather than a
live handshake — see [the caveat](#verified-against-fixtures-not-a-live-sandbox).

Five properties shaped the adapter:

| Property | What it forces |
| --- | --- |
| **OAuth2, ~60-day token** | Every other vendor here signs each call with a static key. Fetching a token per call would double the latency of every order, so it is cached on the provider row (`retry_policy → reloadly`) and refetched on expiry, on a 401, or a day early — a token that dies mid-order dies on a call that moves money |
| **Tokens are scoped per product *and* environment** | The audience is derived from the configured base URL. A sandbox row can never hold a production token, which would authenticate fine and then spend real money on test clicks |
| **`Accept: application/com.reloadly.giftcards-v1+json`** | Plain `application/json` is rejected. One constant, used by every call |
| **404 on the codes endpoint means "not yet"** | The single most consequential mapping in this session. Read as an error it refunds a customer whose card is seconds away, while the vendor keeps both our money and the card. Mapped to `ok:true, ready:false` |
| **Account currency ≠ card currency** | A product has a `recipientCurrencyCode` (what the card is worth: USD, EUR) and a `senderCurrencyCode` (what our wallet is billed in). Costs are reported **only** when the vendor bills in our base currency; otherwise no cost at all, rather than a dollar figure in a naira column reading as a 99% margin |

`customIdentifier` is set to the transaction's `public_id`, exactly as VTpass's
`request_id` is, so a timeout on the way out cannot become two purchases.

The error envelope (`{timeStamp, message, path, errorCode}`) carries `path`,
which echoes our request — only `message` and `errorCode` are surfaced, and
`errorCode` distinguishes permanent rejections (`PRODUCT_NOT_FOUND`) from
transient ones (a rate limit) so a retry loop can tell them apart.

## Catalogue

`giftcard_products` follows the rule the VTU, number and identity catalogues
already follow — **a sync imports availability and cost, never a selling
price** — and it matters more here than anywhere else in the panel, because a
gift card's vendor cost moves with the FX rate. A nightly sync that could write
`price` would have the shop chasing the naira and would silently erase an
operator's considered margin.

Three further catalogue rules:

- **New rows land inactive and unpriced.** `Giftcard_product_model::active()`
  hides unpriced rows and `GiftcardService` re-checks (`NO_PRICE`).
- **One vendor product becomes several of ours.** Reloadly gives a $25 and a $50
  Amazon card the same `productId`; each denomination is its own buyable row.
  `fixedRecipientToSenderDenominationsMap` is the vendor's own already-converted
  price table, which beats applying an FX rate ourselves — and it arrives as an
  object on some endpoints and a list of single-key objects on others, so both
  are parsed. The difference is invisible until a sync imports zero prices.
- **RANGE products import but do not sell.** A custom-amount card has no
  denomination until the customer names one, and there is no form for that yet.
  `GiftcardService` refuses it explicitly (`NOT_FIXED`) rather than charging the
  price of a card whose face value is NULL.

The sync is scoped to `GIFTCARD_COUNTRIES` (default `US,GB,NG`). Reloadly lists
thousands of products across 140 countries; importing all of them buries the
twenty an operator will actually price.

Brands are seeded (eight, with redeem instructions) but denominations are not,
for the same reason `number_products` are not: a price nobody has agreed to is
either invented or below cost.

### `recipient_currency` has no DEFAULT

Every other currency column in the panel defaults to the base currency, because
each records money the panel itself holds. This one records what a *card* is
worth to the person redeeming it, which is genuinely foreign. Defaulting it to
`'USD'` would mean a vendor that omitted the field silently produced dollar
cards — a €50 card sold as "$50". A row whose currency the vendor did not state
is **not imported at all**, in the adapter and again in the model.

`CurrencyTest` caught this: two `'USD'` fallbacks were flagged by the gate
written in session 22. The gate was right and the code was wrong.

## Marketplace, deferred

The audit asks for "gift cards + marketplace" and this session ships gift cards.
Peer-to-peer trading is a materially different product — escrow, dispute
resolution, two-sided KYC, seller payouts, and a fraud surface where the
counterparty is the attacker — and none of it shares the "panel buys from vendor,
customer buys from panel" shape everything else in this codebase has.

Nothing here blocks it. `service_transactions.service_domain` already includes
`MARKETPLACE`, `TransactionEngine` needs no schema change to carry a new domain,
and these four tables are all *panel-sells-codes* tables that a marketplace would
sit beside rather than inside. It is a new phase, not a refactor of this one.

**Still open** after this session: marketplace, phase G (admin analytics for the
new domains), and per-domain catalogue CRUD screens — gift card prices are set
directly in the database, as with VTU, numbers and identity.

## What shipped

**New:** migration `014_giftcards.php` (4 tables);
`GiftcardProviderInterface`, `ReloadlyAdapter`, `MockGiftcardAdapter`,
`GiftcardService`; `Giftcard_brand_model`, `Giftcard_product_model`,
`Giftcard_order_model`, `Giftcard_code_model`;
`controllers/{dashboard,admin}/Giftcards.php`; seven views;
`tests/unit/GiftcardsTest.php` (90 tests); `tests/fixtures/reloadly/` (20 fixtures).

**Modified:** `Provider_manager` (GIFTCARD family), `ProviderSyncService`
(gift card adapter/test/sync, Reloadly credential blob),
`Service_transaction_model::admin_projection()`, `CronWorkers::giftcard_codes()`,
`controllers/Cron.php`, both seeders, `config/{routes,migration,marvy}.php`,
nav + `gift-card` icon, `.env.example`, `cron/crontab.example`,
`views/admin/providers/index.php` (client-secret field),
`IntegrationHarness::seed_giftcards()`, `SecurityHardeningTest` (two new
bearer-instrument gates), `FakeDb` (see below).

Suite: **745 tests, 7429 assertions, 0 failures** (was 653/6549).
Schema regenerated (109 statements, 14 migrations).

## Two things worth flagging

**`FakeDb` filtered before it joined.** Two harness bugs surfaced while writing
`Giftcard_brand_model::sellable()`, and both would have looked like bugs in the
model: `WHERE` was evaluated against the base row *before* joins were applied,
so a predicate on a joined column matched nothing; and `IS NOT NULL` only parsed
unqualified column names, though a joined query has to qualify them or MySQL
rejects the ambiguity. Both are now fixed in the harness — filtering happens
after the join, as SQL does — rather than contorting the model into queries a
real database would refuse. This changes behaviour for every test in the suite,
which is why the full run matters: 745 green.

**A cache write could have failed an order.** `ReloadlyAdapter` persists its
access token through `Provider_model`, and the first draft caught `Exception`.
In PHP 8 a model that will not load raises `Error`, which would have escaped and
turned a failed *cache write* into a failed *purchase* — with the customer's
money already moved. Now `Throwable`, and the in-memory token still serves the
current request.

## Verified against fixtures, not a live sandbox

`giftcards-sandbox.reloadly.com` is unreachable from this build environment, as
`sandbox.vtpass.com` and `sandbox.dojah.io` were before it. The adapter is
pinned by responses captured from Reloadly's published reference — request
shapes, success and error envelopes, both spellings of the denomination map, and
the 404-means-not-yet case — but **it has not exchanged a packet with the real
vendor**. Before taking live orders: set `RELOADLY_CLIENT_ID` /
`RELOADLY_CLIENT_SECRET`, run `php index.php seed demo` to create the INACTIVE
provider, press **Test** in Admin → Providers (proves the OAuth handshake and
shows the wallet float), sync the catalogue, price the denominations, and buy one
card end to end against the sandbox before activating.
