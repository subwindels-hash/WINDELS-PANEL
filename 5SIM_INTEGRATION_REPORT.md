# 5SIM integration — 404 diagnosis & current-protocol rebuild report

**Branch:** `arena/01a05a46-windels-panel` · **Commit:** `5cbdcf2` (ready to push —
the sandbox's GitHub token expired, reconnect GitHub in Arena to push/PR)

---

## 1. The actual cause of the 404

The current codebase **does not 404 anywhere in the 5SIM key-entry flow.** A live
HTTP reproduction (admin → create provider → detail → test connection, plus
sync / sync-balance / credentials rotation) answers **200/302 with friendly
flash messages on every step** — verified over real HTTP, and again by the
40-check end-to-end run. Nothing 404s even when the provider carries the old
deprecated `handler_api.php` URL: those are refused with a clear message, not
a 404.

Two in-tree failure modes did produce **error pages** (not 404s) when entering
a key, and both are fixed in this commit:

- **`Class "FiveSimAdapter" not found`** → 500 error page on provider
  create/credentials save (the service referenced the adapter without loading
  it). Fixed with an explicit `require_once`.
- **`Undefined property: stdClass::$name`** on the admin numbers catalogue
  (number products are code-based — `NG-WHATSAPP` — and have no `name` column),
  which crashed the catalogue list/edit pages after the first successful sync.
  Fixed with service-name/code fallbacks in the views, controller and service.

**Where the production 404 therefore almost certainly came from:**

1. **A stale deployed package.** The previous "fix" was committed but the
   operator-side screenshot flow (new RBAC screen) contradicts what 5sim
   support observed (requests still hitting the deprecated API). The old
   deployment most likely still calls `5sim.net/stubs/handler_api.php`, and the
   **vendor itself answers 404** for those endpoints — which is exactly what
   5sim support reported. **Action: actually deploy the rebuilt
   `application-deployment.zip`** from this commit.
2. **Wrong HTTP method on POST-only routes** (e.g. opening the test/sync URL
   directly in a browser). The UI always POSTs; a GET shows a 404 by design.

**Permanent instrumentation:** every 404 is now logged before rendering
(`application/core/MY_Exceptions.php`) with **method + full request URI +
referer**. If any 404 recurs after deployment, `storage/logs/` will name the
exact URL — no more guessing.

---

## 2. Audit — deprecated endpoints, client and auth

- Grepped the entire tree for `api1.5sim.net`, `stubs/handler_api.php` and
  query-param `api_key=` auth: **zero production occurrences** — only refusal
  logic, comments and tests.
- The only vendor-facing HTTP client is `SecureHttpClient` (TLS-verified,
  SSRF-guarded, protocol-pinned). The only 5sim adapter is `FiveSimAdapter`,
  pinned to `https://5sim.net/v1` + Bearer auth.
- Seeded provider rows that still carry the old `stubs/handler_api.php` URL are
  refused at sync / balance-sync / test with a message naming the deprecated
  API1 protocol, and the admin forms refuse that URL at save time.

## 3. Deprecated implementation removed

Nothing deprecated remains to delete in this tree. What remains is
**refuse-and-advise** logic (constructor + save-time validation + vendor-404
mapping). The old-format API key (a `handler_api` JWT) is not used by any code
path. If the live server still runs an older package, deploying item 1's zip
removes it from production.

## 4. Current 5sim protocol implemented (per https://5sim.net/docs)

- Base `https://5sim.net/v1`; every request sends
  `Authorization: Bearer <key>` + `Accept: application/json` — never a query
  param.
- Endpoints used: `GET /v1/user/profile` (balance probe),
  `GET /v1/guest/countries`, `GET /v1/guest/prices`,
  `GET /v1/guest/products/{country}/{operator}`,
  `GET /v1/user/buy/activation/{country}/{operator}/{product}`,
  `GET /v1/user/check|finish|cancel|ban/{id}`.
- Handled vendor semantics: plain-text errors with HTTP 200, HTTP 400
  `not enough user balance`, the vendor's own `expires` deadline (never a
  locally guessed one), RUB costs converted through `FIVESIM_RATE_TO_BASE`,
  and the full status map (PENDING/RECEIVED/FINISHED/CANCELED/TIMEOUT/BANNED).

## 5. Key stored server-side in `FIVESIM_API_KEY`

- The adapter reads the environment first (`FIVESIM_API_KEY`, or the portable
  `VP_FIVESIM_API_KEY` spelling), then the encrypted
  `providers.api_key_encrypted` column. `.env.example` /
  `.env.production.example` already carry the variable.
- **`FIVESIM_RATE_TO_BASE` must also be set in production** — without it,
  5sim's RUB costs from `/v1/guest/prices` cannot be converted to naira and
  sync records NULL costs (the operator would see missing margins).

## 6. Key never exposed to the frontend

- Grep across controllers/views: no page renders or decrypts
  `api_key_encrypted`; the credentials dialog never echoes the stored key.
- The key travels only as a Bearer header (never in URLs), and adapter logs
  record header **names**, status and body size — never values or bodies.
- The e2e check asserts the token never appears in any rendered admin page.

## 7. Authentication tested first via `/v1/user/profile`

- The provider "Test connection" button probes `GET /v1/user/profile` before
  anything else and displays the vendor balance. In the sandboxed end-to-end it
  returns the fake balance ("Connection OK — balance 1000.00 NGN").
- **The one step this sandbox cannot perform for you:** calling the real
  5sim.net (the sandbox has no CA bundle / TLS egress). On your server, after
  deployment: Admin → Providers → add the provider with the new key and
  `https://5sim.net/v1` → **Test connection must show "Connection OK —
  balance …"**. Only then run Sync services.

## 8. Complete integration + failure matrix — tested, 40/40 e2e checks

`tools/devserver/fakesim_check.mjs` drives the real panel over HTTP against a
fake current-protocol 5sim (sticky failure injection). All 40 checks pass:

- **Admin:** create with the correct v1 URL (no 404), detail renders, key never
  echoed, test connection, catalogue sync from `/v1/guest/…`, deprecated URL
  refused at save time, credentials rotation saves and re-verifies in one
  action, product priced + activated.
- **Customer:** rent → number displayed → poll ("no code yet") → poll (OTP
  arrives) → OTP shown → release → **wallet charged exactly once (price 50)**.
- **Duplicate submission:** same form token resolves to the same reservation,
  **no second vendor order**.
- **Failure matrix (no charge in every case, wallet unchanged):** no free
  phones → out-of-stock; not enough user balance → vendor funds error;
  vendor 500 → did not respond; vendor hang → timeout. All friendly flashes,
  all refunded/never charged.
- **Protocol hygiene:** the panel never calls `handler_api`/`stubs`; every
  `/v1/user/` call carries the Bearer token; profile/countries/products/buy/
  check all exercised.
- **Purchase never retries** (`max_retries=0` on buy): a lost response can no
  longer double-order the vendor. Reads (profile/prices/products/check) keep
  the retry ladder.
- **Full offline test suite: 1662 tests, 18,762 assertions, 0 failures,
  1 skipped.**

---

## Required production actions

1. **Deploy the rebuilt `application-deployment.zip`** (this commit).
2. **Rotate the exposed key** — the JWT shared in chat must be revoked in the
   5sim dashboard; create a new current-protocol key and set it via
   `FIVESIM_API_KEY` or the provider credentials form (one action: save →
   auto-probe).
3. Set `FIVESIM_API_KEY` **and** `FIVESIM_RATE_TO_BASE` in the production
   environment.
4. Confirm **Test connection** reports the real balance, then run **Sync
   services**.
5. If any 404 recurs, read `storage/logs/` — every 404 now self-identifies
   (method + URI + referer).
