# Session 17 — Security hardening

An audit of the areas §61 names — TLS verification, CSRF, XSS, SQL injection,
secret handling, rate limiting, brute force — and fixes for what it turned up.

Most of the checklist was already in good shape, which is worth stating plainly:
TLS verification is on and never disabled, there is not a single raw `query()`
call anywhere in the application, every `where(..., null, false)` passes either
a literal fragment or a `db->escape()`d value, passwords use `password_hash()`
with a dummy-hash comparison on the miss path, API keys are stored as SHA-256,
webhook signatures use `hash_equals`, and login regenerates the session id.

Four things were wrong. One of them was serious.

## 1. The rate limiter counted the wrong thing (serious)

`RateLimiter::too_many_failures()` counted failures matching **`ip = ? OR email = ?`**
— one shared bucket. That is wrong in both directions at once.

**Too strict.** Failures against *other* accounts from a shared IP counted
against a blameless user. Five bad logins from an office NAT or mobile CGNAT
locked out everyone behind it.

**Catastrophically too strict, in one specific case.** Password reset called it
with a constant pseudo-identifier:

```php
$this->ratelimiter->record('pwreset', $ip, false, ...);
$this->ratelimiter->too_many_failures($ip, 'pwreset', 5, 900);
```

Every reset request from every user wrote `email = 'pwreset'`, and the `OR email`
arm matched all of them regardless of IP. **Five password-reset requests
site-wide disabled password reset for the entire panel** for fifteen minutes —
trivially, anonymously, and repeatably.

**Too lax.** A single counter cannot express "5 per account but 15 per network",
so whichever threshold you pick is wrong for the other axis.

### The fix

IP and identifier get **separate counters**, and a lockout trips when either
does. The identifier limit is the strict one (`$max`); the IP limit is
deliberately looser (`IP_MULTIPLIER = 3`, so 15 by default) because one address
legitimately carries many users while a spray attack still gets stopped.

Non-login counters must namespace their key through a new `RateLimiter::scope()`:

```php
$bucket = RateLimiter::scope('pwreset', $email);   // "pwreset:a@x.test"
```

Each account now gets its own reset budget, and a user literally named `pwreset`
cannot collide with the bucket.

Two related bugs fell out of the same review:

- **Registration checked a counter it never incremented.** It called
  `too_many_failures($ip, $email, 10, 3600)` but never called `record()`, so the
  only thing that could trip it was unrelated *login* failures for that address
  — meaning a user who mistyped their password was then blocked from
  registering, while an actual bulk registration ran unimpeded. Now scoped and
  recorded.
- **MFA verification had no rate limit at all.** A TOTP code is six digits; once
  the password is known the second factor was brute-forceable in minutes.
  `mfa_verify()` now throttles on `scope('mfa', 'user:{id}')` — bucketed by the
  pending user rather than the IP, so the limit follows the account under
  attack. `AuthService::pending_mfa_identifier()` exposes that id without
  completing the login.

## 2. SSRF through provider URLs

`SecureHttpClient` is the one place server-side code fetches a URL that an admin
supplies and the database stores. It had `CURLOPT_FOLLOWLOCATION => TRUE` with
no protocol restriction and no address filtering, so a provider URL — or a
redirect from one — could reach `169.254.169.254` (cloud metadata credentials),
`127.0.0.1:6379` (Redis), or `file:///etc/passwd`, and the response body came
back to the caller.

Three guards, all in `reject_url()` before the first byte is sent:

1. `CURLOPT_PROTOCOLS` **and** `CURLOPT_REDIR_PROTOCOLS` restricted to
   http/https. The redirect one matters most: curl follows a 302 into `file://`
   or `gopher://` by default.
2. Hosts resolving to private, loopback or link-local addresses are refused.
   Resolution checks *every* A and AAAA record, since a name can return several.
3. `user:pass@host` in a stored URL is rejected outright.

The private-range check is disableable via `$config['http_allow_private_hosts']`
(env `HTTP_ALLOW_PRIVATE_HOSTS`, **default off**), because self-hosted panels do
legitimately run providers on a LAN address. The error message deliberately does
not echo the resolved address back, which would turn a failed sync into a port
scanner.

A blocked URL returns a normal error result rather than throwing, so a bad
provider config degrades to a failed sync instead of a 500.

## 3. No CSP, no HSTS

Three headers were set (`nosniff`, `SAMEORIGIN`, `Referrer-Policy`). Added
`Content-Security-Policy`, `Permissions-Policy`, and `Strict-Transport-Security`.

The CSP is **nonce-based**, not `'unsafe-inline'`. Five views ship inline
`<script>` blocks, and the easy fix — allowing `unsafe-inline` to accommodate
them — would forfeit essentially all of the XSS protection the policy exists to
provide. Instead `MY_Controller` generates a per-request nonce, and those five
views emit `<script <?=csp_nonce_attr()?>>`. A test scans every view for an
inline script lacking the nonce, so a new one that would be silently blocked in
the browser fails the build instead.

Inline **styles** are still permitted — they are used heavily for layout in the
admin views and cannot execute script.

HSTS is sent only when `APP_ENV=production` **and** the request is actually
HTTPS (including via `X-Forwarded-Proto`, since the origin request behind Nginx
is plaintext). Pinning a developer's `localhost` into HTTPS for six months is
not a mistake they can easily undo.

## 4. A CSRF exclusion that was broader than intended

CI3 anchors `csrf_exclude_uris` as `#^pattern$#i`. The entry `'health.*'`
therefore exempted anything *starting with* "health" — including any future
route like `healthcheck/run`. Tightened to `health(/.+)?`, and the other two
patterns from `.*` to `.+` so the bare prefixes are not exempt either.

Webhooks and `/api/v1` stay exempt on purpose: they authenticate by HMAC
signature and API key respectively, not by session cookie, so CSRF does not
apply and a token would be meaningless to a server-to-server caller.

## Tests

`tests/unit/SecurityHardeningTest.php` — 33 tests, 399 assertions.

The rate-limiter tests are behavioural, against a `login_attempts` double that
actually honours `where()` on email/ip/created_at. That matters: the pre-existing
limiter test used a fake that ignored `where()` entirely and hardcoded the
`ip OR email` logic in its own `count_all_results()`, so it passed happily
against the broken implementation and would have passed against almost anything.
The new tests encode the distinction that was missing — an attacker spraying
five accounts from a shared IP must not lock out a sixth innocent user, but
fifteen failures from one address must still trip the network limit.

The SSRF tests call `reject_url()` directly with metadata, loopback, private,
`file://`, `gopher://` and credential-bearing URLs, and assert a normal public
URL is still allowed — a guard that blocks everything is not a fix.

The rest are source scans, because "this pattern must never appear" is a
property of the codebase, not of a run: TLS never disabled, no interpolated raw
SQL, no unescaped `where()` carrying user input, no secret in a `log_message`,
every inline script carrying a nonce, CSP without `unsafe-inline`, HSTS gated on
production+TLS, CSRF on with tight exclusions.

I verified both scanners actually fail by temporarily introducing the bug each
one looks for — an interpolated `$_GET` in a `where()`, and a nonce-less inline
script — and confirming they caught it before reverting. A source-scan test that
cannot fail is worse than no test.

Suite: **344 tests, 3439 assertions, 0 failures** (was 311/3040). No schema
change; `tools/export_schema.php --check` clean.

## Deferred

- **Redis-backed rate limiting.** Still table-backed. The interface is
  unchanged, so a Redis implementation slots in behind it; `login_attempts` is
  now pruned by the `analytics` cron job from Session 16.
- **TOTP replay.** A code stays valid for its whole 30-second step, so the same
  code can be submitted twice inside one window. Fixing it properly needs a
  `last_used_counter` column on `mfa_methods` — a schema change, and this
  session added none. The new MFA rate limit bounds the exposure meaningfully.
- **DNS rebinding.** The SSRF guard resolves the host, then curl resolves it
  again independently; a hostile resolver could answer differently the second
  time. Closing that needs pinning the resolved IP via `CURLOPT_RESOLVE`.
- **Subresource Integrity** on the Google Fonts stylesheet.
- **`api_keys` IP whitelist** accepts exact matches only, not CIDR ranges.
