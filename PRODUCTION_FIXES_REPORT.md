# Production Fix Report — Fundsvera deposit, /admin/inbox 500, SMTP 503 HELO

Branch: `arena/01a05a46-windels-panel` · Commit: `fd03ae0`
Full unit suite: **1669 tests / 18804 assertions / 0 failures / 1 skipped**
E2E checks: `fundsvera_check.mjs` **55/55** (against a faithful fake of the
Fundsvera v1 API) · `/admin/inbox` probe **200** · SMTP conversation probes
against a scripted Exim-style server: greeting, EHLO→HELO fallback, and the
exact 503 failure all reproduce and behave as designed.

---

## 1. Fundsvera: "Processing…" that never reaches the card page

**What was wrong.** Two independent ways to get stranded on the form with the
button stuck on "Processing…":

- A provider response **without `checkout_url`** sent the customer to the
  deposits page with no explanation, while the page they came from kept its
  disabled button/spinner (the JS submit-guard only restores on a timer or
  `pageshow`, never after a plain redirected POST).
- A double-click or browser retry of the deposit POST opened a **second
  checkout at Fundsvera** (a fresh request_id every time), and provider
  errors reached the customer as a generic "payment provider rejected the
  request (HTTP …)" — the provider's own message was discarded because
  `SecureHttpClient` collapsed every HTTP 5xx into `http_code=0` (read as
  "unreachable"), and plain-text 401 bodies were never parsed.

**What changed** (`application/controllers/dashboard/Wallet.php`,
`application/views/dashboard/wallet/add_funds.php`,
`application/libraries/FundsveraGateway.php`,
`application/libraries/SecureHttpClient.php`):

- The add-funds form now carries a hidden one-shot `form_token`; the deposit
  POST scopes it into the idempotency key, so a double submit or retry of the
  same form resolves to the **same deposit** and redirects to its status page
  ("This deposit is already open") — never a second checkout.
- Success **without** a `checkout_url` lands on the deposit page with the
  account details and the "Open secure checkout page" resume link.
- Any initiation throw is caught in the controller: the customer gets a
  friendly flash error and is redirected back to the form — no exception
  page, no stuck spinner.
- **The "Processing…" button itself is fixed** (`assets/js/app.js`): the
  submit guard now restores the button (label, spinner, `disabled`) whenever
  the page comes back from the browser's back/forward cache — previously a
  customer who returned from the provider's page (or any redirect) found the
  deposit button permanently disabled with "Processing…" on it. A safety
  sweep also un-sticks any button found in the submitting state on page
  load.
- Provider failures now surface Fundsvera's **own message** ("System busy
  please try again later", "Duplicate request ID…", "amount greater than or
  equal to 100", "Unauthorized request please use valid keys"), and a
  provider hang fails fast in 12 seconds with "took too long to answer"
  (tunable via `FUNDSVERA_TIMEOUT_SECONDS=3..60`, default 12; connect 4s;
  zero internal retries — one slow request can no longer become minutes of
  silence).

**Verified** by the extended `fundsvera_check.mjs` initiation flow: deposit
POST → 302 to the provider's checkout URL → card page renders → checkout row
holds the account number → duplicate submit reuses the same transaction →
401/500/400/hang each produce an error flash and **no** checkout row → success
without `checkout_url` lands on the deposit page with details.

**If it still hangs in production after deploying:** the panel is now telling
the truth in the flash message. Check the deposit row and
`fundsvera_checkouts`; if a row exists, the checkout URL is in it. If the
provider truly never returns `checkout_url`, customers land on the deposit
page with the account details and a resume button instead of a dead end.

---

## 2. `/admin/inbox` — "can't currently handle this request" (HTTP 500)

**Root cause.** `application/controllers/admin/Inbox.php` was the only admin
controller whose `render()` reads `$this->dashboardstats->unread_count(...)`
without loading the `DashboardStats` library in its constructor —
`Undefined property: Inbox::$dashboardstats` → `Call to a member function
unread_count() on null` → 500.

**Fix.** `DashboardStats` is now loaded in `Inbox::__construct()` alongside
`InboxService`/`MailService`. `AdminStaffTest` gained a sweep that fails the
suite if **any** admin controller uses `$this->dashboardstats` without loading
the library, so this class of bug cannot silently return.

**Verified.** Reproduced the 500 against the reseeded dev database, applied
the fix, probed `GET /admin/inbox` → **200**.

---

## 3. email_queue: `503 HELO or EHLO required` (server315.web-hosting.com)

**Root cause class.** The server processed `MAIL FROM` while believing no
valid EHLO/HELO had been received. Stock CodeIgniter 3 has two behaviours
that produce exactly this class of failure on strict cPanel Exim setups:

1. Under cron there is no `$_SERVER['SERVER_NAME']`, so CI3 greeted the server
   as `EHLO localhost.localdomain` — a name strict HELO checks reject/mis-log.
2. When EHLO is refused, stock CI3 treats the send as failed instead of doing
   the RFC 5321 §4.1.4 fallback (retry the greeting as HELO when the client
   does not require extensions).

**What changed** (new `application/libraries/MY_Email.php`, automatically
loaded by CI3's `MY_` subclass prefix; `application/libraries/MailService.php`,
`application/libraries/CronWorkers.php`, `application/config/email.php`):

- The greeting name is now the panel's real domain: `VP_MAIL_HELO` env (or
  the `mail_helo` admin setting), falling back to the site URL's host —
  never `localhost.localdomain`.
- A refused EHLO is retried once as HELO (no AUTH required in that case),
  exactly as RFC 5321 §4.1.4 instructs.
- `smtp_keepalive` is pinned OFF — a keep-alive socket the server has
  already closed is precisely the state that makes Exim answer MAIL FROM
  with "503 HELO or EHLO required" (the greeting belonged to the old
  session).
- `smtp_failure_summary()` now reports the server's own failure line
  (`from: 503 HELO or EHLO required`) instead of CI3's generic
  `email_send_failure_smtp`, and adds a targeted operator hint.
- The queue worker stores the hint on the `email_queue` row too, so the
  error the operator reads on the mail-queue screen now tells them the fix.
- `.env.example` / `.env.production.example` document `MAIL_HELO` and make
  **`mail` the recommended production driver** (cPanel sendmail — no SMTP
  host, port or credentials, and nothing to handshake).

**Verified** at three levels:

- `MailGreetingTest` (new, in the unit suite) drives the real chain — CI3
  Email + `MY_Email` + `MailService::deliver()` — against an in-process
  scripted SMTP server and pins: the greeting uses the pinned hostname; a
  refused EHLO falls back to HELO and the send succeeds; a genuine
  `503 HELO or EHLO required` on MAIL FROM fails fast with the 503 line as
  the error and a `VP_MAIL_HELO` hint; the plain-HELO path still delivers;
  no credentials means no AUTH command.
- `fake_smtp.mjs` + `mail_smtp_probe.php` reproduce the **exact production
  transcript** (`from: 503 HELO or EHLO required`) over a real TCP
  connection and confirm the operator-visible result.
- Full unit suite green (1669/0).

**Production actions for the mail queue — do the fastest one first:**

1. **Switch the transport to `mail`** (the recommended fix on cPanel):
   Admin → Settings → Email → Transport → **mail**, then save. cPanel's
   sendmail needs no SMTP host, port, user or password and cannot produce a
   HELO/503 handshake error at all. Re-run the queue afterwards.
2. If you prefer SMTP: deploy the rebuilt package (below) — it greets the
   server with your real domain and retries refused EHLOs as HELO — and
   optionally set `VP_MAIL_HELO=www.marvysocials.com` in `.env`.
3. Re-run the queue (or wait for the cron worker). If a queue row fails, its
   error now names the exact server reply **and** the fix.

---

## Deploy checklist

- [ ] Upload the rebuilt `application-deployment.zip` through cPanel File
      Manager and extract over the existing install (back up first).
- [ ] `GET https://www.marvysocials.com/admin/inbox` → 200.
- [ ] Try a Fundsvera deposit end-to-end; confirm the browser lands on the
      provider's checkout page (or the deposit page with details if the
      provider returns none). No button may stay stuck on "Processing…".
- [ ] Mail: switch Admin → Settings → Email → Transport to **mail** (or keep
      smtp with the new package), then queue a test message. Any failure now
      names the real server reply and the fix.
- [ ] Optional: `VP_MAIL_HELO` / `FUNDSVERA_TIMEOUT_SECONDS` in `.env`.

Nothing here needs a database migration; the schema is unchanged.
