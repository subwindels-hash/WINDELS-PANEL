# Master Rebuild Specification — Audit & Gap Analysis

Audit of the existing codebase against the WINDELS PANEL Master Rebuild
Specification (PHP / CodeIgniter 3.x Edition), performed before any code was
written, as §36 directs.

**Headline:** the architectural correction in §1 and §33 requires no work — this
repository is already a CodeIgniter 3.x + MySQL/MariaDB application and was never
Node/React. What the spec actually asks for is a **large scope expansion**: eight
service domains that do not exist yet.

---

## 1. Stack compliance (§2, §33) — already satisfied

| Requirement | Status |
| --- | --- |
| PHP 8.1, CodeIgniter 3.x | Present |
| MySQL / MariaDB | Present (9 migrations, 61 tables) |
| Server-rendered PHP views | Present (`application/views/`) |
| Controller → Model → View | Present |
| Session auth, cron, REST provider integrations | Present |
| No Next.js / React / NestJS / Express / PostgreSQL / Supabase / Prisma | Verified absent from all application code |

`package.json` exists but is **Tailwind CSS build tooling only** (`tailwindcss`,
`lucide`) — it is a frontend asset pipeline, explicitly allowed by §2, not a Node
backend. Views render from `assets/css/design-system.css` without a build step.

`docs/checkpoint-01/` contains superseded Node-era planning artifacts
(`02-prisma-schema.md`). They are already marked superseded by
`docs/checkpoint-01-php/` and referenced by no code. **Recommend deleting** them
to remove ambiguity about the intended stack.

## 2. Prohibitions (§5, §12, §32) — already satisfied

- **License / activation:** no `license_keys`, `purchase_codes`, `domain_locks`
  tables; no `license_valid()` gate; no activation server; installer has no
  license step. Every grep match is either a prohibition comment or a test
  asserting absence (`SchemaTest.php:257`, `SanityTest.php:7`,
  `AuthRbacTest.php:127`, `HomepageTest.php:112`).
- **Buy logs:** zero matches for buy-logs / stolen-credential functionality.

These are enforced by tests, so they cannot regress silently.

## 3. What already exists and satisfies the spec

| Spec section | Status |
| --- | --- |
| §4 MVC structure | Present, with `libraries/` used for the service layer |
| §7 Central wallet | Present. **No `users.balance` column** — ledger is the source of truth, exactly as §7 requires |
| §8 Financial ledger | Present, double-entry, `LedgerService` is the sole writer |
| §13 SMM module | Complete: categories, services, orders, refill, cancel, drip-feed, subscriptions, provider sync |
| §14 Provider engine | Adapter pattern (`ProviderAdapterInterface`), no provider logic in controllers |
| §15 Pricing engine | `PricingService` + `price_groups` + `service_prices` + `user_service_price` |
| §24 Referrals | Present, commissions recorded in the ledger |
| §25 Admin panel | Dashboard, orders, payments, providers, tickets, affiliates |
| §27 Three homepages | **AURORA, NEXUS, PULSE all present** as distinct layouts |
| §29 API | `/api/v1` with API keys, rate limiting, logging |
| §30 Cron | 10 jobs, locking, `job_runs` history, no Node dependency |
| §31 Security | CSRF, XSS escaping, Query Builder, RBAC, audit logs, idempotency, `FOR UPDATE` locking, secrets in env |
| §32 No license dependency | Satisfied |

Current suite: **408 tests, 3661 assertions, 0 failures.**

## 4. The actual gap — eight missing service domains

Controller / library / model audit found **nothing** for:

| §34 module | Controllers | Libraries/Models | Tables |
| --- | --- | --- | --- |
| VTU (airtime, data, cable, electricity, education) | 0 | 0 | 0 of 6 |
| Virtual Numbers | 0 → 2 | 0 → 6 | 0 of 2 → 4 (session 25) |
| OTP | 0 | 0 → 1 | 0 → 1 (session 25) |
| Identity (NIN/BVN) | 0 | 0 | 0 |
| Gift Cards | 0 | 0 | 0 of 2 |
| Marketplace | 0 | 0 | 0 |
| Deposits / Withdrawals (as distinct tables) | — | — | 0 of 2 |
| Provider transactions | — | — | 0 |

Sixteen of the §6 tables are missing. This is roughly the same volume of work as
everything built in sessions 01–20 combined.

## 5. Key architectural finding — the core generalises cleanly

The most important question for planning was whether the existing wallet/ledger
core is SMM-specific. **It is not.**

```php
LedgerService::charge($wallet_id, $amount, $reference_type, $reference_id,
                      $idempotency_key = null, $metadata = null);
```

`wallet_transactions.reference_type` is `VARCHAR(64)` — free text, not an enum
(`'Order|PaymentTransaction|...'`), indexed as `idx_wt_ref (reference_type,
reference_id)`. A VTU airtime purchase, a virtual-number reservation and a gift
card order can all post to the ledger today with **no schema change** to the
money tables.

Consequences for the build plan:

- §18's "every service uses the same transaction lifecycle" is achievable by
  reusing `LedgerService` + `idempotency_keys`, not by rewriting them.
- §19's universal transaction record is best served by a new
  `service_transactions` table carrying `service_domain` / `service_type`, which
  *references* the existing wallet transaction rather than duplicating money
  columns.
- The `ProviderAdapterInterface` is SMM-shaped (`createOrder`, `requestRefill`,
  `getRefillStatus`). VTU and number providers need **sibling interfaces**
  (`VtuProviderInterface`, `NumberProviderInterface`), not changes to the
  existing one — §14's "Provider Manager" becomes a registry over several
  interfaces.

## 6. Conflicts requiring a decision

Three points where the spec collides with a committed decision in this
repository. None should be resolved unilaterally.

### 6.1 Migration count is test-locked at 9

`tests/unit/SchemaTest.php:74` asserts **exactly 9 migrations** ("Checkpoint 01
specifies exactly 9 migrations"). Sixteen new tables cannot land without either
adding migrations 010+ (and amending that assertion) or editing existing
migrations (unsafe — they have run in real deployments).

**Recommendation:** add new migrations 010+ and relax the assertion to "sequential
from 001, matching `migration_version`", keeping the ordering guarantee that
gives the test its value.

### 6.2 Base currency is USD; the spec shows ₦ — **RESOLVED (session 22)**

`config/windels.php` set `base_currency = 'USD'` and both money tables defaulted
`currency CHAR(3) NOT NULL DEFAULT 'USD'`. §16 shows balances as `₦XX,XXX.XX`,
and VTU/NIN/BVN/exam-PIN services are Nigeria-specific.

**Resolved:** the panel is now denominated in Naira. See
`docs/session-22-currency.md`. Migration `011_base_currency_ngn` moves the column
defaults and relabels existing rows; `currencies` is rebased so NGN sits at 1.0;
`windels_base_currency()` is now the single source of truth and the ~20 hardcoded
`'USD'` fallbacks scattered through libraries, controllers, models and views were
replaced with calls to it.

### 6.3 Session numbering has diverged

The spec's §35 lists 30 sessions with different content than the 20-session
roadmap this repo has been following (`docs/checkpoint-01-php/03-module-dependency-map.md:180`).
Sessions 01–20 are complete under the **old** numbering. Under the new numbering,
completed work maps to roughly: 01–11, 14–15, 21–30 — while 12, 13, 16–20 (the
VTU, numbers, OTP, identity, gift card, marketplace domains) are untouched.

**Recommendation:** keep the completed work as-is and renumber forward, tracking
new sessions by module name rather than number to avoid ambiguity.

## 7. Proposed build order

Each phase ends with tests, per §36. Ordered by dependency, not by spec order —
shared infrastructure first so the domains do not each invent their own.

| Phase | Content | Why here |
| --- | --- | --- |
| **A** | Migrations 010+ for all new tables; `service_transactions` universal record; `Provider_manager` registry; `VtuProviderInterface` / `NumberProviderInterface` | Everything downstream depends on it; doing it once prevents six inconsistent variants |
| **B** | VTU: airtime + data | Highest-volume domain; proves the universal transaction engine end to end |
| **C** | VTU: cable + electricity (incl. meter verification) + education | Same engine, more products |
| **D** | Virtual numbers + OTP sessions/messages | New lifecycle (reservation, expiry) the order engine does not yet model |
| **E** | Identity (NIN/BVN) | Needs the §22 sensitive-data controls: encryption, access control, log minimisation |
| **F** | Gift cards + marketplace | Inventory and trading semantics |
| **G** | Unified history (§20), admin sections + analytics (§25/§26) for all new domains | Cross-cutting; cheapest once the domains exist |

Progress: **A** and **B** landed in session 21; **C** is implemented in
`VtuService` and, since [session 24](session-24-vtpass.md), exercised against a
real vendor — `VtpassAdapter` covers all five VTU types plus meter/smartcard
verification, requery settlement and catalogue sync, with the whole contract
pinned by captured fixtures rather than live credentials. The **G** slice for
VTU — the admin queue, detail, refund and manual re-check — landed in
[session 23](session-23-admin-vtu.md), which sets the pattern D–F should copy.
**D** landed in [session 25](session-25-numbers.md): migration 012 adds the
reservation lifecycle this table flagged as missing — `virtual_numbers` carries
a vendor-set `expires_at`, and a deadline that passes without an OTP refunds
through `TransactionEngine` exactly as a provider failure does. It is not
mock-only from birth: `FiveSimAdapter` implements the 5sim contract, pinned by
captured fixtures, alongside an offline `MockNumberAdapter`.

Phase A is the one that determines whether this stays coherent. If each domain is
built without it, the result is six parallel half-copies of the order engine.

## 8. Recommended immediate next step

Phase A, starting with the universal transaction engine (§18/§19) and the
provider registry (§14) — the two pieces every later domain depends on.

Blocking questions, from §6 above: the migration-count assertion, the base
currency, and confirmation that phase order is acceptable.
