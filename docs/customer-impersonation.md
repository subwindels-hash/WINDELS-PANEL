# Customer impersonation — read-only lens or full-access session

Customer impersonation is a support tool, not a customer login. It lets
explicitly permitted staff open a customer's dashboard under one of two
deliberately separate modes, chosen on the start form:

- **Read-only** (the default, and the original behaviour) — a diagnostic lens.
  Staff see exactly what the customer sees; every write request is refused.
- **Full access** — act on the customer's behalf: place orders, open tickets,
  spend their wallet. Chosen explicitly with its own radio button and warning;
  never a silent default and never upgradeable mid-session.

Both modes share the same identity machinery: the original operator identity
is preserved for restoration, every request is attributed to that staff member
in the audit trail, and the session dies after 30 minutes no matter what.

## Operator workflow

1. Open **Admin → Customers**, select an active customer, and find
   **Customer impersonation**.
2. Pick the access level — *Read-only* or *Full access* — enter a specific
   support reason (preferably including the ticket reference), acknowledge the
   identity switch, and submit the CSRF-protected form.
3. The browser opens the customer's dashboard. A red persistent banner names
   the mode in plain words, identifies the operator and customer, shows the
   approximate hard-expiry time, and contains the exit action.
4. In read-only mode, inspect only what is required. In full-access mode,
   perform what the customer asked for — the banner reminds you that every
   action is recorded against you. Every dashboard request, read or write,
   creates a `user.impersonation.viewed` audit row attributed to the original
   staff account and carrying the mode.
5. Use **Return to Admin Dashboard** in the banner. Do not use the normal
   logout link; `/logout` redirects back to the dashboard until the support
   session is ended safely.

## Security contract

Shared by both modes:

- The start endpoint is POST-only through the admin controller, requires
  `users.impersonate`, requires an explicit confirmation and a 5–500 character
  reason, and repeats all authorization checks inside `ImpersonationService`.
- Only an authenticated, active `SUPER_ADMIN`, `ADMIN`, or `STAFF` actor may
  start. The target must be a different active `CUSTOMER`; self, staff,
  inactive, and nested targets are rejected.
- An unknown mode value is rejected at start rather than silently downgraded.
- The original actor and effective customer IDs are held in server-side
  session state. The session ID is regenerated when entering and leaving
  impersonation, and non-authentication session data is cleared at both
  transitions so staff filters, customer form tokens, and other
  identity-scoped state cannot bleed across the boundary.
- The customer identity's `last_login_at` is never touched because
  impersonation is not evidence of a customer login.
- The session has a non-sliding 30-minute hard expiry. On every authenticated
  request, the actor status, actor role, permission, target status/role,
  effective identity binding, mode, and expiry are revalidated. A context
  whose mode is corrupted — not one of the two known constants — destroys the
  session rather than resolving to full access.
- `/logout` cannot destroy the stored actor context. The operator must use the
  signed stop form.
- A malformed context or effective-identity mismatch destroys the session
  instead of trusting an actor ID from damaged state. Expiry, permission
  revocation, or an inactive target restores the actor only if that staff
  account is still active; otherwise the browser is logged out.
- If the mandatory start or per-page audit row cannot be written, access fails
  closed. End auditing is best effort so an audit outage cannot strand a
  browser in the customer identity after previously audited access.

The request boundary, enforced in the common controller base so it cannot be
bypassed by posting to a public or admin route:

- **Read-only mode** allows only `GET` and `HEAD` under `/dashboard`; it denies
  every write method and every public, auth, API, cron, admin, or other route.
  The rendered shell greys out and disables every form to match.
- **Full-access mode** allows any method under `/dashboard`, and does not grey
  out forms. It still denies every non-dashboard route — the admin area stays
  unreachable until the session ends.
- **Credential and identity screens stay writable by nobody** in either mode.
  Writing the email address (`dashboard/profile`), password / PIN / MFA
  (`dashboard/security`), identity documents (`dashboard/identity`) or API
  keys (`dashboard/api`) would hand the account to the staff member
  permanently — that is account takeover, not acting on the customer's behalf.
  Reading those screens remains allowed.
- The sole state-changing exception outside the boundary is the
  CSRF-protected `POST /impersonation/stop`.

Money under full access deserves its own sentence: an order or wallet spend
made during full-access impersonation is a real transaction by the customer's
account, paid from the customer's wallet, and the per-request audit rows name
the staff member who performed it. Manual balance corrections still belong to
`wallets.adjust` on the customer file, which goes through the ledger with its
own reason and idempotency rules.

## Audit evidence

All entries use the original staff user as `actor_id`, the customer as the
`users` resource, and a random 128-bit impersonation identifier to correlate
the session:

| Action | When | Additional evidence |
| --- | --- | --- |
| `user.impersonation.started` | Before the identity switches | support reason, **mode**, target ID, start and expiry |
| `user.impersonation.viewed` | Once per allowed dashboard request | request method and bounded path, **mode** |
| `user.impersonation.ended` | On manual or automatic termination | end reason and end time |

Common automatic end reasons are `EXPIRED`, `PERMISSION_REVOKED`,
`ACTOR_INACTIVE`, `TARGET_INACTIVE`, `SESSION_MISMATCH`, and
`AUDIT_UNAVAILABLE`.

## Incident response

To terminate access immediately, suspend the operator or customer, or remove
`users.impersonate` from the operator's role. The next dashboard request
revalidates and ends the session. Review the three audit action names above
using the shared impersonation identifier, filtering on `"mode":"FULL_ACCESS"`
to scope the investigation to sessions that could spend money. If the operator
account was suspended, restoration is refused and the session is destroyed.
