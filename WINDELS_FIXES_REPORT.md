# Fundsvera card payment + 5sim key entry — fixes report

**Branch:** `arena/01a05c0b-windels-panel`

Two operator complaints, both reproduced locally against the running panel and
fixed in code.

---

## #1 — Fundsvera: "no card payment" and blank Bank / Account number / Account name

### What was happening

A Fundsvera deposit is one secured checkout with **two payment routes**: the
hosted `checkout_url` (Fundsvera's secure checkout page, which takes **card**
as well as transfer) and the account details (the **transfer** route).

The gateway read the provider's answer the way the *docs* spell it — flat
fields at the top level. The live answer was not coming through in that shape,
so every field landed `NULL`: no `checkout_url` (customer never reached the
card page) and no bank details (the deposit page rendered three empty fields,
exactly the screenshot). One missing shape broke **both** routes at once.

### Fixes

- **`FundsveraGateway::checkout_fields()`** (new) — normalises the checkout
  details out of the provider's body, scanning the documented flat shape, a
  `data`/`result`/`payload`/`virtual_account` wrapper, and common field
  aliases (`account_no`, `bank`, …). `initiate()` and
  `create_virtual_account()` both use it, so whichever shape the provider
  answers with, the account details and the `checkout_url` survive.
- **Deposit page** (`dashboard/wallet/deposits.php`) — the Fundsvera pending
  block now leads with the card route:
  - **"Pay now — open secure checkout"** button (the hosted page accepts
    credit/debit cards and bank transfer);
  - bank-transfer details shown **only when present** (merged from the
    checkout row *and* the transaction metadata, so a partial response still
    leaves the customer a way to pay);
  - when the provider gave **neither** link nor details, the page says so
    plainly ("We could not fetch the payment details… contact support with
    reference …") instead of rendering three blank fields.
- **Post-payment redirect** — the customer now lands back on *this* deposit's
  page (`/dashboard/wallet/deposits/{id}`, a path segment — their API forbids
  query strings on `redirect_url`) instead of the deposits list.
- **Deposit list ordering** — `for_user()` and the add-funds "Recent deposits"
  table now break `created_at` ties by `id DESC`; two deposits started in the
  same second used to order arbitrarily.
- The fake Fundsvera dev server gained `nested` / `nested-no-details` behavior
  modes so the wrapped shape is covered by the e2e, and `fundsvera_check.mjs`
  asserts the new resume CTA.

### Verified (real panel over HTTP, local fakes)

- flat, wrapped and empty provider responses → details shown / details shown /
  clear message (never blank fields);
- customer is redirected to the hosted checkout (card page) and can resume from
  the deposit page;
- `fundsvera_check.mjs` **55/55** (signature, amount, idempotency, referral,
  initiation, failure matrix);
- `reconciliation_check.mjs` 10/10 (with any gateway configured, as intended);
- full PHP suite **1670 tests / 0 failures**.

> Note: Fundsvera's public API documents only the secured checkout (card +
> transfer on the hosted page) and webhooks — there is no separate "card"
> endpoint to call. Card payment is delivered through the `checkout_url`,
> which is now extracted reliably and surfaced as the primary action.

---

## #2 — 5sim: "enter the key → 404 Page Not Found"

### What was happening

- The **Update credentials** dialog (where an operator pastes a new 5sim key)
  was the only POST form on the panel **without a CSRF token**. In a browser,
  saving the key hit the CSRF guard and died on an error page instead of
  saving.
- The 404 body the operator saw is CodeIgniter's stock 404, which means the
  request hit `show_404()` — i.e. the **production server is still running the
  old build** (the previous session's report ends exactly here: the fix was
  committed but the deployment package was never re-deployed). This session
  verified the current code end to end and re-built the deployment package,
  and the repo's own `CpanelDeploymentTest::testTheCommittedPackageIsNotStale`
  now passes — the zip in the repo carries these fixes.

### Fixes

- CSRF token added to the credentials form (`admin/providers/detail.php`).
- `application-deployment.zip` refreshed with all changed files (the stale
  package is what a cPanel extract would keep serving — and the unit suite
  now refuses a stale package).

### Verified (real panel over HTTP, fake current-protocol 5sim)

- **Add provider** with the 5sim JWT key → created, detail page renders, key
  never echoed back;
- **Update credentials** (the "enter the key" flow) → saves **and** probes the
  vendor in one action: "Credentials saved and verified — the vendor answered
  with balance …";
- **Test connection / Sync balance / Sync services** all answer with real
  data (77 products synced);
- deprecated `handler_api.php` URL still refused at save time;
- `fivesim_check.mjs` **40/40** (rent → number → OTP → release, wallet charged
  exactly once, full vendor failure matrix, protocol hygiene).

---

## Required production actions

1. **Deploy the new `application-deployment.zip`** from this branch (extract
   over `public_html` as before). The old build is what is 404-ing.
2. **Revoke the 5sim JWT that was pasted in chat** — it is now exposed. Issue
   a new key in the 5sim dashboard, then Admin → Providers → *Update
   credentials* → paste → Save. The page answers "Credentials saved and
   verified — … balance …" when the key is good.
3. Confirm **Test connection** shows the real 5sim balance, then **Sync
   services** and price/activate the products.
4. For Fundsvera: start a deposit and you should now be taken straight to the
   Fundsvera secure checkout (card or transfer); the deposit page keeps the
   "Pay now — open secure checkout" button and the bank details alongside.
   Set the method's name in Admin → Payments → Methods if you want it
   labelled "Fundsvera (Card & Bank Transfer)".
5. If any 404 recurs after deployment, `storage/logs/` names method + URI +
   referer for every 404 (instrumentation added in the previous session).
