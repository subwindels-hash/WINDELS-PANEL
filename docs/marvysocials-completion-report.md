# MarvySocials — completion report

Branch: `arena/01a03fe7-windels-panel`

This records what was changed, what was verified, and — importantly — what
could **not** be verified here and why. Nothing below is claimed as tested
unless it was actually executed.

---

## 1. How this was tested at all

The repository had never been run. There was no PHP binary, no MySQL server,
no package mirrors and no Composer access in this environment, so the previous
audit documents mark almost everything "BLOCKED — cannot execute".

Testing a payment system by reading it is not testing it, so the first job was
to make the real application runnable:

- **`tools/devdb/`** — a MySQL wire-protocol server backed by SQLite. It speaks
  the actual protocol (handshake, `COM_QUERY`, text result sets, MySQL error
  numbers, transaction status flags), so CodeIgniter's own mysqli/PDO driver
  connects to it unmodified.
- **`tools/devserver/`** — serves the real CodeIgniter application over HTTP,
  plus eight end-to-end test suites driven over real HTTP with cookies and CSRF.

Both are development-only, excluded from the deployment package, and pinned
there by `DevToolingIsolationTest`. Production is unchanged: PHP-FPM, Nginx,
MySQL.

**Result:** 19 → 21 migrations apply cleanly, core and demo seeds run, and the
whole panel is reachable. Everything below was then verified by execution.

---

## 2. Bugs found by running it

These were all real, and all would have hit a live deployment:

| Bug | Consequence |
| --- | --- |
| `index.php` never defined `FCPATH` | **The homepage was HTTP 500.** Every call site resolving a web-root file (brand logo, MediaService, favicon) raised "Undefined constant". |
| `Setting_model` queried `settings` unguarded | A not-yet-migrated database turned the *first page load* — and the `migrate` command itself — into a database-error page. |
| `migration.php` read via `config->item()` | It is loaded into the Migration library, not the config registry, so the version read `NULL`. `migrate status` reported version 0 and **deploy preflight compared the live schema against 0**. |
| `migrate status` read CI3's table cache | Reported "tables: 1" immediately after creating 84. |
| `Webhooks::index` was POST-only | Blockonomics' GET callback would have been rejected 405 — **crypto deposits would silently never have credited**. |
| `record_webhook()` resolution order | Matched `provider_tx_id` before an adapter-supplied id, so a callback could land on a *different, older* transaction and no-op because that one was already `SUCCESS`. |
| `OrderService` try/catch around a property read | An undefined property warns rather than throws, so the catch never fired and a PHP warning leaked into output on every order. |
| Homepages carried invented content | Hard-coded services, prices, provider balances, 4.6–4.9 star ratings, testimonials from people who do not exist, and `$` prices on an NGN panel. |
| PULSE "Quick order" was a dead form | `onsubmit="return false"` with a hard-coded option and a total pinned to a non-existent rate. |
| `VP_DB_STRICT` was boolean-only | CI3 sends `SET sql_mode` as a *connection init command*, so managed MySQL that refuses it failed the **connect**, not the query. Now tri-state with `inherit`. |

---

## 3. What was built

**Rebrand (full, including identifiers).** `windels_*` → `marvy_*` helpers,
`config/windels.php` → `config/marvy.php`, `Windels\` → `Marvy\`, database
dump, cookie names, cron lock dir. `VP_*` environment names are deliberately
unchanged so existing `.env` files and the Docker stack keep working.

**Six-digit account number.** Unique `users.user_code`, backfilled before the
unique index is added. Allocated randomly — a sequential code would leak the
customer count and allow enumeration. Accepted as a login identifier alongside
username and email, but only when the input actually looks like a code.

**Four-digit security PIN.** `password_hash` digest for verification, plus —
since the operator asked for readable PINs — an AES-256-GCM envelope
(`users.pin_cipher`, `EncryptionService`) so staff with `users.edit` can reveal
a PIN from the customer file; every reveal is POST-only and audited. PINs set
before the envelope existed are hash-only and reported unreadable rather than
guessed at. Weak PINs refused; failures counted **on the user row** with
escalating lockouts, because 10,000 possibilities means a per-request limit
that resets with a new session is not a limit. New accounts are issued a
starting PIN at sign-up (switchable in Admin → Settings) and told it once by
notification and email.

**Admin access, three ways.** Admin → Administrators can now *create*
administrator accounts (not just list them), with the same privilege rule as
promotion — only a SUPER_ADMIN may mint a SUPER_ADMIN — a complete account
shape (verified address the operator vouched for, zero-balance wallet, real
password hash) and an audit row naming the creator and never the password.
Administrators also get self-service profile editing like customers: the admin
sidebar links **My profile**, and email changes re-verify, avatars upload
through MediaService, passwords rotate from Account → Security. And
impersonation grew a second mode: alongside the original read-only lens,
staff with `users.impersonate` can start a **full-access** session from the
customer file and act on the customer's behalf — place orders, open tickets,
spend their wallet — with every request audited against the staff member,
the 30-minute hard expiry intact, the admin area still unreachable, and
credential screens (email, password, PIN, MFA, identity, API keys) writable
by nobody in either mode, because that would be account takeover, not
support. See `docs/customer-impersonation.md` and
`docs/module-admin-account-access.md`.

**Coupons beyond the shop.** A coupon used to be redeemable at exactly one
till — the marketplace basket — while the SMM order form, the five VTU
tabs, the number, identity and gift-card screens priced money out of the
wallet with no discount step and no field to type a code into. A code is
now site-wide by design: no domain column on `coupons`, one
`CouponService::quote()` with the cart's exact rules (validity window,
minimum spend, percent/fixed with cap, per-customer and global limits), and
migration 034 stamping each redemption with the `domain` it was spent on and
the order or transaction `public_id` as `reference`. The module-18 slot
reservation travels with it — reserved before any charge, released on every
failure path, and the per-customer limit now holds **across** domains: use a
code on an SMM order and the airtime form refuses it with "already used".
A 100% coupon is a real purchase that charges nothing and skips the ledger
line entirely while still counting the redemption. The reseller API
deliberately cannot redeem coupons — a recorded product decision enforced
by a source-gate test. See `docs/module-coupon-domains.md`.

**Blockonomics BTC.** A real adapter — address generation, live rate quoting,
callback verification, confirmation threshold, underpayment tolerance — and,
unlike the six pre-existing gateway scaffolds, actually routed by
`PaymentService`. No callback secret configured means "cannot verify", which
stores the event and moves no money.

**Admin-editable pages.** Terms, Privacy, Refund, Acceptable use and About were
PHP views. They are now an override layer: a row overrides the page, no row
renders the bundled copy, so a fresh install is never missing a policy and
"reset" is a real undo rather than a way to blank a legal page.

**Honest homepages.** All three variants now render the live catalogue with an
explicit empty state, and the fabricated statistics and testimonials are gone.

---

## 4. Verification

### Executed here

| Suite | Result |
| --- | --- |
| PHP unit/integration suite | **1,227 tests, 0 failures**, 1 documented platform skip |
| Public routes (`smoke`) | **24/24 healthy** |
| Customer + admin journey | **38/38** |
| Admin settings, secrets, impersonation | **18/18** |
| Security PIN lifecycle | **13/13** |
| Commerce: deposit → approval → order → ticket | **24/24** |
| Content management round trip + XSS | **18/18** |
| Blockonomics callback handling | **14/14** |
| Responsive layout audit | **16/16** |
| Migrations + seeds | 21 migrations, core + demo seeds, repeatable |
| Cron workers | `order_status` advanced real orders to COMPLETED |

Money handling was checked against the database, not just the UI: a deposit
credits the wallet against `liability`, an order debits it against `revenue`,
approving the same deposit twice credits once, and a replayed Bitcoin
confirmation credits once.

### Verified with sandbox/test credentials

None. No third-party sandbox credentials were available.

### Implemented but awaiting production credentials

- **Blockonomics live API** — every decision the panel makes is verified; no
  request has been made to the real service. Do one small real deposit before
  going live. See `docs/payments-blockonomics.md`.
- **Stripe / PayPal / Flutterwave / Razorpay / Paystack / CoinPayments** — these
  were already scaffolds and remain so. They are **not** routed by
  `PaymentService`; a deposit through one falls back to manual admin review
  rather than an untested integration. Their config entries stay `enabled=FALSE`.
- **SMTP** — `MailService` is implemented; no mail was sent from here.
- **SMM/VTU/5sim/Dojah/Reloadly providers** — exercised through the mock
  adapters, which is how the order lifecycle above was verified. Mock adapters
  are refused outright in production.
- **USDT** — the admin toggle exists and is off. The Blockonomics address flow
  is implemented for BTC only; the toggle is documented as a placeholder rather
  than presented as working.

### Not verified

- **MySQL parity.** The dev database translates to SQLite. The schema, the
  migrations and the SQL the app emits all run, but MySQL-specific edge cases
  are not proven here. CI runs the suite against native PHP and MySQL.
- **Real browsers.** The responsive work is a static audit of markup and CSS,
  not a pixel test.

---

## 5. Before going live

1. Run the migrations against real MySQL: `php index.php migrate` (adds 020 and 021).
2. Set `VP_ENCRYPTION_KEY` and `VP_AUTH_SECRET`, and run `php index.php deploy check`.
3. Configure Blockonomics (API key + callback secret) and make one small real
   deposit before enabling BTC for customers.
4. Leave the unwired gateways inactive until each is completed and tested.
5. `git mv ci.yml.workflow-ready .github/workflows/ci.yml` with a
   workflows-capable token — the pipeline is written but cannot be committed by
   an app token.
