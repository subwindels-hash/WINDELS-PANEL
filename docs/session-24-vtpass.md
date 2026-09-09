# Session 24 — Live VTU vendor integration (VTpass)

Follow-up item 3. Until now the VTU engine had only ever spoken to
`MockVtuAdapter`: `StandardVtuAdapter` existed but was written to a
"VTpass-style" API from memory, no real vendor was configured, and meter
verification — wired end to end since session 21 — had never actually resolved
a meter. This session adds a real integration and exercises it.

## What shipped

| Area | Change |
| --- | --- |
| `application/libraries/VtpassAdapter.php` | **New.** Full VTpass integration: airtime, data, cable, electricity, exam PINs, merchant-verify, requery, balance, service-variations. |
| `application/libraries/Provider_manager.php` | Registered `VTPASS` under `FAMILY_VTU`. |
| `application/libraries/ProviderSyncService.php` | Family-aware: VTU providers test/sync through `Provider_manager`; api_type whitelist now derived from the registry; VTpass three-key credential handling. |
| `application/models/Vtu_product_model.php` | `upsert_from_provider()` + `paginated_for_provider()`/`count_for_provider()` for the catalogue sync. |
| `application/controllers/admin/Providers.php` | Offers every registered adapter; VTU providers show their VTU catalogue instead of an empty SMM service list. |
| `application/views/admin/providers/{index,detail}.php` | Registry-driven type select, VTpass key fields, VTU catalogue table. |
| `application/libraries/VtuService.php` | Supplies the `phone` VTpass requires on cable/electricity/exam purchases. |
| `application/seeds/Demo_seeder.php` | Seeds an **inactive** VTpass provider, only when `VTPASS_*` credentials are in the environment. |
| `.env.example` | Documented `VTPASS_BASE_URL` / `VTPASS_API_KEY` / `VTPASS_PUBLIC_KEY` / `VTPASS_SECRET_KEY`. |
| `tests/fixtures/vtpass/*` | 14 captured VTpass response shapes. |
| `tests/unit/VtpassTest.php` | 38 tests, 132 assertions. |

Suite: **518 tests, 5341 assertions, 0 failures** (was 480/5199).

## Why a new adapter instead of fixing `StandardVtuAdapter`

The existing adapter is close enough to VTpass to look interchangeable and
different enough to lose money on every purchase. Three defects, each of which
is a financial bug rather than a cosmetic one:

**1. Authentication.** It sends `Authorization: Bearer <key>`. VTpass does not
read `Authorization` at all. It wants `api-key` on every request, plus
`public-key` on GETs and `secret-key` on POSTs. The old adapter authenticates as
nobody, so nothing works — the cheap failure, because it fails immediately.

**2. The requery key.** It returns the provider's `transactionId` as the
reference, and `TransactionEngine` stores that in `provider_reference`. But
VTpass `/requery` takes **the `request_id` we sent**. A purchase that settles
asynchronously (electricity and cable routinely sit pending for minutes) would
be requeried with an identifier VTpass has never seen, so it would never leave
`PROCESSING`: the customer has paid, received nothing, and no cron run can ever
resolve it. `VtpassAdapter` returns the `request_id` and keeps the
`transactionId` in `detail` for support to quote.

**3. Indeterminate outcomes.** The old adapter treats any non-200 or unparseable
body as a rejection, which `TransactionEngine` refunds in full. A timeout on
`/pay` is *not* evidence the purchase failed — VTpass documents exactly this and
says to requery. Refunding there pays the customer back for airtime they
received, and the panel absorbs it.

## The pending-by-default rule

The single most consequential decision here. `VtpassAdapter::pay()` returns a
terminal failure **only** for a documented terminal code. Everything else —
timeouts, HTTP 0/5xx, a 200 carrying an HTML maintenance page, code `099`, and
any code VTpass invents after this was written — comes back as
`ok:true, status:PROCESSING` with a reference.

That leaves the customer charged and the transaction in flight, and hands the
decision to `CronWorkers::vtu_status()`, which requeries until VTpass gives a
terminal answer. If VTpass never received the request it answers `015`, the
adapter maps that to `FAILED`, and the engine refunds. The asymmetry is
deliberate: a wrongly-pending transaction is resolved by the next cron run a
minute later, while a wrongly-failed one silently pays for goods that were
delivered.

`status()` is the mirror image. When the provider cannot be reached it returns
`ok:false` **with no `status` key at all**, because `CronWorkers` skips those —
saying nothing is safer than guessing.

## Code → serviceID mapping

`Core_seeder::vtu_catalogue()` names networks for humans: `IKEDC`, `9MOBILE`,
`MTN-DATA`. VTpass names them for itself: `ikeja-electric`, `etisalat`,
`mtn-data`. `VtuService` passes our code straight through as
`network_code`/`disco_code`/`exam_code`, so without a translation table every
live call returns `012 — product does not exist`.

The table lives in `VtpassAdapter::$service_ids` and covers the four networks,
the data variants, three cable providers, ten discos and three exam bodies.
Unmapped codes fall back to `strtolower($code)`, which is correct for most
VTpass ids. A vendor account with bespoke ids overrides per provider via
`providers.retry_policy → vtpass.service_ids`, without a code change.

## Credentials

VTpass issues three secrets, and `providers` has one `api_key_encrypted`
column. Rather than add two columns for one integration, all three are stored
as a JSON blob inside that column, encrypted as a unit:

```json
{"api_key":"…","public_key":"PK_…","secret_key":"SK_…"}
```

`VtpassAdapter` accepts either shape — a bare string is read as the api-key
alone, which is what the shared admin form produces for single-key vendors.
`create_provider()` refuses to store a half-set: VTpass with only a public key
is a provider that can look up meters but cannot buy anything, which would be
discovered at the worst possible moment.

## Catalogue sync

`provider_services` is an SMM shape — rate per 1000, min/max quantity — and
means nothing for a data bundle, so a VTU "sync services" writes to
`vtu_products` instead, via `Vtu_product_model::upsert_from_provider()`. Two
rules keep it safe to run against a live panel:

- **It never overwrites a price we set.** The vendor's amount becomes
  `provider_cost`; `price` is only filled in when the row has none. A sync must
  not be able to move a product onto a losing margin, and must not undo an
  admin's pricing.
- **New rows arrive `is_active = 0`.** A synced product is unreviewed and
  priced at cost; putting it in front of customers automatically would sell at
  zero margin. The admin flash message says so explicitly.

A network the vendor does not carry is skipped and reported, not fatal — one
unsupported disco should not abort the other twenty. A sync that returns
nothing at all *and* collected errors is recorded as `FAILED`.

## Testing without credentials

`tests/fixtures/vtpass/` holds 14 captured response shapes (success, `099`,
`018`, reversal, unknown request id, meter verify, wrong meter, smartcard,
electricity token, WAEC `cards[]`, DSTV variations, balance, and an HTML
maintenance page). `VtpassFakeHttp` is a hand-rolled scripted stand-in for
`SecureHttpClient` that records exactly what went on the wire — half the
assertions are about the request, not the response — and **throws** on an
unscripted call, so a test making an unexpected request fails loudly instead of
passing on a default.

Five tests run a real VTpass payload all the way through
`VtuService → TransactionEngine → LedgerService` against the integration
harness, including the two that matter most: a timeout leaves the wallet
charged and the transaction `PROCESSING` with a usable reference, and the
settlement cron then refunds it in full once VTpass answers `015`. Meter
verification is exercised against a real payload for the first time.

No network access and no live credentials are needed to run any of it.

## Sandbox trigger values

VTpass sandbox drives outcomes off the recipient value, which is how to
exercise the paths above against the real endpoint:

| Value | Outcome |
| --- | --- |
| `08011111111` | success |
| `201000000000` | pending → requery |
| `500000000000` | unexpected response |
| `400000000000` | no response |
| `300000000000` | timeout |
| `1111111111111` | prepaid meter, verifies |
| `1010101010101` | postpaid meter, verifies |

## Going live

1. Put sandbox keys in `.env` (`VTPASS_*`), or add the provider by hand in
   **Admin → Providers → Add provider**, type `VTPASS`, API URL
   `https://sandbox.vtpass.com/api`.
2. **Test connection** — this calls `/balance` with the public key and proves
   the key pair is the right way round.
3. **Sync services** — pulls bundles, packages and PIN types into
   `vtu_products`, inactive and priced at cost.
4. Price and activate the products you intend to sell.
5. Activate the provider. `VtuService::provider_for()` picks the product's
   `provider_id` first, then the first ACTIVE provider with a VTU api_type — so
   leaving VTpass INACTIVE keeps the panel on MOCK.
6. For live, swap the API URL to `https://vtpass.com/api` and the keys to the
   live pair. Confirm the server's IP is whitelisted with VTpass first: an
   un-whitelisted server gets code `027` on every purchase.

## Not done

- **Airtime is not in the catalogue sync.** VTpass exposes variations for
  fixed-price products; variable-amount airtime and electricity are configured
  by the panel, not the vendor. Those rows stay hand-seeded.
- **No webhook.** Settlement is poll-only via `CronWorkers::vtu_status()`
  (every 2 minutes). VTpass does offer callbacks; a webhook would cut the
  worst-case settlement delay but adds a signature-verification surface, and
  polling is already correct.
- **`retry_policy` still has no admin editor.** Per-provider `service_ids` and
  path overrides have to be written into the JSON column directly. Worth a
  field on the provider detail page when a second VTU vendor arrives.
- **`config/providers.php` is now explicitly marked deprecated** rather than
  deleted — nothing loads it, and `Provider_manager` is the single registry.
