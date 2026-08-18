# Read-only customer impersonation

Customer impersonation is a support diagnostic tool, not a customer login and not a way to perform work on a customer's behalf. It lets explicitly permitted staff see authenticated customer dashboard pages while preserving the original operator identity for restoration and audit attribution.

## Operator workflow

1. Open **Admin → Customers**, select an active customer, and find **Read-only customer impersonation**.
2. Enter a specific support reason (preferably including the ticket reference), acknowledge the identity switch, and submit the CSRF-protected form.
3. The browser opens the customer's dashboard. A red persistent banner identifies the operator and customer, states that the session is read-only, shows the approximate hard-expiry time, and contains the exit action.
4. Inspect only what is required. Every dashboard request creates a `user.impersonation.viewed` audit row attributed to the original staff account.
5. Use **End impersonation and return to staff** in the banner. Do not use the normal logout link; `/logout` redirects back to the dashboard until the support session is ended safely.

## Security contract

- The start endpoint is POST-only through the admin controller, requires `users.impersonate`, requires an explicit confirmation and a 5–500 character reason, and repeats all authorization checks inside `ImpersonationService`.
- Only an authenticated, active `SUPER_ADMIN`, `ADMIN`, or `STAFF` actor may start. The target must be a different active `CUSTOMER`; self, staff, inactive, and nested targets are rejected.
- The original actor and effective customer IDs are held in server-side session state. The session ID is regenerated when entering and leaving impersonation, and non-authentication session data is cleared at both transitions so staff filters, customer form tokens, and other identity-scoped state cannot bleed across the boundary.
- The customer identity's `last_login_at` is never touched because impersonation is not evidence of a customer login.
- The session has a non-sliding 30-minute hard expiry. On every authenticated request, the actor status, actor role, permission, target status/role, effective identity binding, and expiry are revalidated.
- The common controller boundary allows only `GET` and `HEAD` under `/dashboard`; it denies every write method and every public, auth, API, cron, admin, or other route. The sole state-changing exception is CSRF-protected `POST /impersonation/stop`.
- `/logout` cannot destroy the stored actor context. The operator must use the signed stop form.
- A malformed context or effective-identity mismatch destroys the session instead of trusting an actor ID from damaged state. Expiry, permission revocation, or an inactive target restores the actor only if that staff account is still active; otherwise the browser is logged out.
- If the mandatory start or per-page audit row cannot be written, access fails closed. End auditing is best effort so an audit outage cannot strand a browser in the customer identity after previously audited access.

## Audit evidence

All entries use the original staff user as `actor_id`, the customer as the `users` resource, and a random 128-bit impersonation identifier to correlate the session:

| Action | When | Additional evidence |
| --- | --- | --- |
| `user.impersonation.started` | Before the identity switches | support reason, mode, target ID, start and expiry |
| `user.impersonation.viewed` | Once per allowed dashboard request | request method and bounded path |
| `user.impersonation.ended` | On manual or automatic termination | end reason and end time |

Common automatic end reasons are `EXPIRED`, `PERMISSION_REVOKED`, `ACTOR_INACTIVE`, `TARGET_INACTIVE`, `SESSION_MISMATCH`, and `AUDIT_UNAVAILABLE`.

## Incident response

To terminate access immediately, suspend the operator or customer, or remove `users.impersonate` from the operator's role. The next dashboard request revalidates and ends the session. Review the three audit action names above using the shared impersonation identifier. If the operator account was suspended, restoration is refused and the session is destroyed.
