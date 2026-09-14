# Password-reset delivery and Admin Dashboard customer access

## Password-reset delivery

The customer-file action at
`POST /admin/customers/:public_id/password-reset` issued a valid signed token
and then stopped. It displayed “emailed” even though it never rendered
`auth.password_reset` and never inserted an `email_queue` row, so no cron run
could deliver the link.

`MailService::enqueue_password_reset()` is now the single reset-message helper
used by both Forgot password and the admin action. It builds the absolute reset
URL, renders the standard template, and queues it. The admin action treats a
missing token, inactive/missing template, or failed queue insert as an error;
it audits `user.password_reset_queued` only after the queue write succeeds and
points the operator to **Admin → Mail queue** for delivery status.

The public Forgot password response remains identical for matching and unknown
identifiers to prevent account enumeration. A queue failure is logged without
changing that browser response.

## Customer access from the Admin Dashboard

Administrators with `users.impersonate` now see **Customer account access** at
the top of `/admin`. They can locate an account by email, username, or six-digit
account ID, enter the support reason, and choose read-only or full access.

This is not a password bypass. The dashboard form enters the existing
`ImpersonationService` boundary, which requires POST + CSRF + confirmation,
accepts only an active CUSTOMER target, writes mandatory audit records,
regenerates and isolates the session, expires after 30 minutes, blocks the
admin area while active, and keeps profile/security/identity/API credential
writes blocked in both modes. The persistent banner is the only safe route
back to the staff identity.

Migration 040 grants `users.impersonate` to the operational `ADMIN` role on an
existing database, matching the updated core role matrix. `STAFF` is unchanged
and still needs an explicit RBAC grant.

## Verification

- Full offline PHP suite: 1,724 tests, 19,248 assertions, 0 failures
  (1 environment-dependent skip).
- Focused unit suites: SeedTest 16/16, SchemaTest 18/18,
  ImpersonationTest 10/10, AdminUsersTest 25/25.
- End-to-end admin access check: 44/44, including an ADMIN seeing the dashboard
  form, an admin-generated reset email containing a usable reset URL in
  `email_queue`, and a full-access session started directly from `/admin`.
- The production SQL was regenerated at migration version 40 and imported by
  the MySQL-protocol development database without an error. The cPanel
  deployment package was rebuilt and passed its stale-file and archive checks.
