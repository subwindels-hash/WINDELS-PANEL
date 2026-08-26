# Session 26 — Identity verification, NIN/BVN (phase E)

Phase E of the [rebuild-spec build order](rebuild-spec-audit.md#7-proposed-build-order):

> **E** — Identity verification (NIN/BVN) — *§22; first domain where the payload
> is more sensitive than the money*

Every domain shipped so far is interesting because of its **lifecycle**. Airtime
settles or fails. A virtual number is a reservation racing a deadline. This one
has the simplest lifecycle in the panel — one request, one answer, no settlement
window — and is by far the most dangerous thing in it, because of what the
request and the answer *contain*.

A NIN or BVN identifies a real person to their bank and to their government. The
response carries their name, date of birth, phone number and, if you ask for it,
their photograph. A leak here is not a chargeback; it is an identity theft kit.
So almost every design decision in this session gives up a convenience in
exchange for a smaller blast radius, and this document is mostly a record of
which convenience and why.

## Five decisions

### 1. The identifier is never stored — not even encrypted

`identity_checks` has no column that can hold a NIN. What it holds is:

| Column | What it is | What it answers |
| --- | --- | --- |
| `identifier_hash` | HMAC-SHA256 blind index, keyed | "is this the number on that receipt?" |
| `identifier_last4` | last four digits | "which of my checks was this?" |

Encrypting the identifier instead was the obvious alternative and it is worse.
It would keep a recoverable copy of the single most sensitive field in the
system, in exchange for one feature: re-running a lookup without asking the
customer to retype eleven digits. That is not a trade worth making. The blind
index answers every question support is actually asked — you hash what the
customer quotes on the phone and compare — while making "dump every customer's
NIN" impossible, because the column does not contain them.

The index is **scoped to the id type**, so a NIN and a BVN that happen to share
digits do not collide, and inputs are normalised first, so `7012 3456 781` and
`70123456781` are one identifier rather than two.

`IdentityTest::testTheRawIdentifierIsNowhereInTheDatabase` runs a complete
successful lookup and then greps *every column of every row* of
`identity_checks`, `service_transactions`, `provider_transactions`, `audit_logs`,
`wallet_transactions` and `ledger_entries` for the raw number. A future
convenience column, a metadata blob, a `failure_reason` built from the request —
each would fail that test rather than ship quietly.

### 2. "No record found" is refunded

Dojah bills us whether or not the identity exists. A not-found lookup is
therefore a real cost to the business, and the tempting move is to charge for it
anyway: the vendor answered, we paid, why should we eat it?

Because the customer did not get what they bought. Charging for nothing is how
identity resellers earn their reputation, and it quietly creates an incentive to
keep a *broken* vendor connected — if empty answers are billable, an outage that
returns "not found" looks like revenue. The panel eats it.

That makes the status vocabulary matter:

| Outcome | `identity_checks.status` | Transaction | Money |
| --- | --- | --- | --- |
| Record returned | `VERIFIED` | `SUCCESSFUL` | charged |
| Vendor answered "nobody" | `NOT_FOUND` | `FAILED` + `refunded_amount` | **refunded** |
| We never got an answer | `FAILED` | `FAILED` + `refunded_amount` | **refunded** |

`NOT_FOUND` and `FAILED` refund identically but are deliberately *different
records*, because they are different signals: a rising NOT_FOUND rate is fraud
or a broken form, a rising FAILED rate is an outage. Collapsing them would make
both unanswerable. The admin queue surfaces exactly this — it raises a banner
when a quarter or more of completed lookups found nobody, because that costs
real money on the vendor invoice while earning none.

Mechanically this reuses the engine unchanged: the dispatch callback returns
`ok:false` for a not-found, and `TransactionEngine` refunds in full. No new
refund path exists in this domain.

### 3. Reading a result is an event, not a read

`reveal()` is the **only** route to a plaintext identity record anywhere in the
codebase. It decrypts, increments `reveal_count` on the row, stamps
`last_revealed_at` / `last_revealed_by`, and writes an `identity.result.reveal`
audit entry — one that carries the id type, the last four and who looked, and
none of the record itself, because `audit_logs` is stored in clear.

Consequences that fall out of that, all of them tested:

- The admin detail page shows the outcome, the masked tail and the money by
  default. The record is behind a separate POST button and a **separate
  permission** (`identity.reveal`), which the STAFF role does not get. Support
  does not need a stranger's date of birth to answer "did my check work?".
- A revealed record is rendered into that one response — never flashed (that
  would write an identity record to the session store), never redirected to
  (that would make it re-viewable by refresh without a second audit entry).
- Even the customer viewing their own receipt goes through `reveal()`, so a
  bookmark, a refresh or a back button does not silently become an access.
- Decryption uses `EncryptionService::open()`, not `decrypt()`. `decrypt()`
  returns its input when the GCM tag does not verify — a legacy fallback for
  plaintext provider keys — which here would render a base64 blob into the page
  as if it were the customer's record. `open()` returns NULL and the screen says
  "no result".

`SecurityHardeningTest` now enforces the single-entry-point rule at the source
level: any file other than `IdentityService` that mentions `result_encrypted`
*and* calls the decryptor fails the suite.

### 4. The result expires, the evidence does not

`result_encrypted` is AES-256-GCM over an **allow-listed** entity — keys are
fixed in code, so a new field in a vendor response cannot silently become a new
stored field. `CronWorkers::identity_purge` runs nightly at 03:30 and, after
`identity_retention_days` (default 30), empties the blob and stamps `purged_at`.

The row survives. The money, the audit trail, the consent record and the blind
index are not the sensitive part, and accounting and dispute handling both need
the check to have existed. There is also a manual purge button for a subject who
asks for erasure today rather than in three weeks.

There is deliberately **no cleartext PII column** — no `full_name`, no
`date_of_birth` beside the encrypted blob, however much easier an admin list
would be. A convenience column defeats both the encryption and the retention
sweep on day one; that is the usual way this control fails in practice, and
there is a schema test asserting those columns do not exist.

### 5. The photograph is dropped twice

Both Dojah lookups return a base64 portrait. The panel sells "does this identity
check out", not a face database, so the photo is stripped in `DojahAdapter`
before the result leaves the adapter, **and** again by `IdentityService`'s
allow-list on the way into storage and on the way out of `reveal()`. Belt and
braces on purpose: the second filter means a photo that somehow reached storage
before this rule existed still never reaches a screen.

`MockIdentityAdapter` emits a `photo` key deliberately, so the stripping is
exercised by every test that runs a successful lookup rather than only by the
adapter unit tests.

## Consent

Running a government identity check on someone who has not agreed to it is the
illegal version of this product. The service refuses to dispatch without it
(`NO_CONSENT`, checked before anything is charged and before the vendor is
called), and `consent_at` / `consent_ip` are recorded on the row that proves the
lookup happened.

## Pre-charge validation is a money control

An 11-digit check, a `22` prefix check for BVNs and a Nigerian-phone pattern for
phone lookups all run **before** the wallet is touched. This is not UX polish:
the vendor bills us for a lookup it was always going to reject, so a 10-digit
NIN caught locally is money nobody has to get back. The commonest real error —
pasting a NIN into the BVN form — is caught by the prefix rule.

## The Dojah integration

Live sandbox is unreachable from this environment (the same TLS failure as
VTpass), so verification is fixture-based: `tests/fixtures/dojah/` holds twelve
recorded response bodies including the awkward ones.

Four properties of that API shaped the adapter, each a bug if got wrong:

| Property | What it forces |
| --- | --- |
| Auth is `Authorization: <key>` with **no `Bearer`**, plus a separate `AppId` header | Both credentials live in one encrypted JSON blob, as VTpass established; the create form validates both, because one alone 401s on first use |
| `404` means "no such person", not "call failed" | Mapped to `ok:true, found:false` — an answer, refunded, not an outage |
| `402` (our wallet) and `424` (NIMC/NIBSS down) say nothing about the customer's data | Hard failures, never billable answers — the phase-E analogue of VTpass's "still processing" trap |
| The identifier travels in the **query string** | `SecureHttpClient` logs the URL it fetches, so identity calls pass `request_id => 'identity-redacted'`; error messages are built from the status code, and a vendor message echoing digits back is discarded rather than repeated |

Response shapes vary by endpoint — `first_name` vs `firstname` vs `surname`,
and the basic BVN lookup answers `{value, status}` per field rather than bare
strings — so the adapter maps a handful of vendor spellings onto one stable
entity shape and tidies shouted names. Endpoints are overridable per provider
under `retry_policy → dojah.endpoints`, so a vendor renaming a path is a config
change rather than a release.

Dojah bills its own prepaid wallet and returns no per-call figure, so `cost` is
`null` rather than invented — an invented cost would corrupt every margin report
in the panel.

## Catalogue

`identity_products` seeds **inactive with a NULL price**. Every other reference
table in the panel seeds a working price, but a KYC lookup has a real per-query
cost that depends on the contract you signed, and a guessed default would either
sell below cost or look considered. `Identity_product_model::active()` hides
unpriced rows and `IdentityService` re-checks (`NO_PRICE`), so the storefront
stays empty until an operator sets a price they have actually agreed. The test
harness seeds a `NIN_UNPRICED` row specifically to prove both halves.

Identity vendors publish no catalogue to mirror, so `sync_services()` says so
plainly instead of falling through to the SMM path and calling `getServices()`
on an adapter that has no such method.

## What shipped

**New:** migration `013_identity_verification.php`; `IdentityProviderInterface`,
`DojahAdapter`, `MockIdentityAdapter`, `IdentityService`; `Identity_product_model`,
`Identity_check_model`; `controllers/{dashboard,admin}/Identity.php`; six views;
`tests/unit/IdentityTest.php` (82 tests); `tests/fixtures/dojah/` (12 fixtures).

**Modified:** `EncryptionService` (`open()`, `blind_index()`), `Provider_manager`
(IDENTITY family), `ProviderSyncService` (identity adapter/test/sync, Dojah
credential blob), `Service_transaction_model::admin_projection()`,
`CronWorkers::identity_purge()`, `controllers/Cron.php`, both seeders,
`config/{routes,migration,marvy,providers}.php`, nav + icon, `.env.example`,
`cron/crontab.example`, `IntegrationHarness::seed_identity()`,
`SecurityHardeningTest` (three new identity-data gates).

Suite: **653 tests, 6549 assertions, 0 failures**. Schema regenerated
(105 statements, 13 migrations).

## Two things worth flagging

**The engine hides the transaction on failure.** `TransactionEngine`'s failure
result is deliberately minimal — an error and a code, no transaction — because
most callers only need "that did not work". This domain needs more: a NOT_FOUND
lookup is a completed, refunded purchase with a receipt the customer is entitled
to see, and the dashboard redirects to it. `IdentityService` captures the id in
the `detail` callback and re-reads the transaction on the failure path rather
than changing the engine's contract for every other domain.

**`PerformanceTest` flagged `dashboard/Identity::index()`** as an unpaginated
list query. It was a false positive — `Wallet_model::for_user()` is a single-row
accessor — but the gate identifies those by an assignment to a local, and the
call was inline in an array literal. Fixed the controller to match the
convention rather than loosening the gate: the next unpaginated `for_user()`
someone writes should still fail.

## Not done here

Phases F (gift cards + marketplace) and G (admin analytics for the new domains)
remain. Identity products have no admin CRUD screen yet — prices are set
directly, as with the other catalogues — and `identity.manage` currently gates
only the manual purge.
