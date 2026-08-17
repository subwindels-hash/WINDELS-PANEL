# WINDELS PANEL — Session 03: Auth & RBAC

> Implements Checkpoint 01 / Artifact 4 §3 (Auth) and the access-control
> boundary for the customer dashboard and admin areas.
> Stack: **CodeIgniter 3.1.13**, CI session cookies (no JWT), Argon2id/bcrypt,
> TOTP MFA, HMAC-signed stateless tokens for email verification & password reset.

## What shipped

| Area | Files |
|---|---|
| Auth controller (login/register/logout/forgot/reset/verify/MFA) | `application/controllers/Auth.php` |
| Auth service (the only authentication authority) | `application/libraries/AuthService.php` |
| HMAC-signed stateless tokens (verify email / reset password) | `application/libraries/SignedToken.php` |
| TOTP MFA (RFC 6238/4226) + recovery codes | `application/libraries/Totp.php` |
| Brute-force throttling over `login_attempts` | `application/libraries/RateLimiter.php` |
| Templated, queued email | `application/libraries/MailService.php` |
| Blacklist lookups (email/IP/link) | `application/models/Blacklist_model.php` |
| Real RBAC wired into the base controllers | `application/core/MY_Controller.php` |
| Authenticated shell (customer + admin) | `controllers/dashboard/Dashboard.php`, `controllers/admin/Dashboard.php`, `views/layouts/app.php` |
| Auth views | `views/layouts/auth.php`, `views/auth/{login,register,mfa,forgot_password,reset_password}.php`, `views/dashboard/index.php`, `views/admin/dashboard.php` |
| Tests | `tests/unit/SignedTokenTest.php`, `TotpTest.php`, `AuthRbacTest.php` |

No database migration was required: identity, sessions, refresh tokens, MFA
methods, login attempts, roles/permissions and audit logs all landed in
migration **001** and **009** (Session 02).

## Commands

```bash
# No new CLI commands. Auth is exercised through the browser:
/open/login, /register, /forgot-password, /reset-password/<token>,
# /verify-email/<token>, and (authenticated) /dashboard and /admin.
#
# Offline tests (no MySQL/Redis/composer required):
php tools/phpunit_lite.php
```

## Security model

* **Passwords:** `password_hash()` with Argon2id when available (64 MiB / t=4 /
  2 threads), else bcrypt cost 12. `password_needs_rehash()` transparently
  upgrades hashes on successful login. Plaintext passwords are never written to
  any column, log or audit row.
* **No user enumeration:** the login path always runs `password_verify()`
  against a dummy bcrypt hash for unknown accounts; `forgot-password` returns
  the same response whether or not the identifier exists and burns comparable
  time generating a throwaway token.
* **Brute-force protection:** `RateLimiter` counts failed `login_attempts` by
  IP **or** identifier over a 15-minute window and locks out after 5 failures
  (10 / hour for registrations). Blacklisted IPs/emails are refused before the
  password check. A Redis-backed limiter can replace the table-backed one
  behind the same interface later.
* **Session:** CI cookie sessions, `HttpOnly`, `SameSite=Lax`, `Secure` in
  production. `sess_regenerate(true)` on every login and password change
  defeats fixation. Authenticated state is `user_id` only; the user row is
  loaded per request so role/status changes take effect immediately.
* **CSRF:** CI3 CSRF protection is on for all POSTs (auth forms use
  `form_open()`). Webhook and `/api/v1` routes remain excluded as before.
* **MFA:** TOTP (RFC 6238, 30 s period, ±1 window for clock skew) with 8
  single-use recovery codes. The TOTP secret is stored encrypted at rest via
  `EncryptionService` (AES-256-GCM); recovery codes are hashed with
  `password_hash()`. The password step sets a 5-minute `mfa_pending` session
  value; an authenticated session is only established after the second factor.
* **Email verification & password reset:** stateless HMAC-signed tokens
  (`SignedToken`) — no token table. Reset tokens are additionally bound to a
  fragment of the user's current password hash, so changing the password
  invalidates every outstanding reset link without a revocation list. Tokens
  are URL-safe base64 and signed with a key derived from `APP_KEY` (falling
  back to `ENCRYPTION_KEY`).
* **Email delivery:** all mail goes through `MailService` into `email_queue`;
  the web path never blocks on SMTP. In non-production, `MAIL_LOG=1` also
  writes the rendered message (including verify/reset links) to the log, and
  the verify/reset URL is surfaced as a flash banner so the flow can be
  completed without an inbox.
* **Audit:** register, login, logout, email verification, password
  reset/change, and MFA enable/disable/API-key creation all write an append-only
  `audit_logs` row. Audit failures are caught and logged — they never break the
  request.

## RBAC

* Four roles from the core seed: `SUPER_ADMIN`, `ADMIN`, `STAFF`, `CUSTOMER`.
* `SUPER_ADMIN` bypasses every permission check. `ADMIN` and `STAFF` are granted
  keys from `Core_seeder::role_matrix()` via `role_permissions`; `CUSTOMER`
  holds no admin permission.
* Base controllers:
  * `Auth_Controller` — requires an authenticated, `ACTIVE` user and stores it
    as `$this->current_user`.
  * `Admin_Controller` — extends `Auth_Controller`, restricts to staff/admin
    roles, and exposes `require_perm('orders.view')` which delegates to
    `AuthService::can()` → `Permission_model::role_has()`.
  * `Api_Controller` — additionally rejects disabled accounts after the API
    key/IP-whitelist checks.
* Permission keys are loaded per request and cached on the service.

## Open follow-ups (later sessions, not blockers)

* Session 15 — Admin: real widgets/charts, the staff manager, and per-action
  `require_perm()` calls across every admin controller.
* Session 16 — Workers: the `email_queue` cron sender (this session only
  enqueues).
* Session 17 — Security Hardening: swap the table-backed `RateLimiter` for the
  Redis-backed version and add per-API-key token-bucket limiting; add MFA
  management UI to the customer "Security" page.
* Account lockout/email-change confirmation flows beyond MFA.
