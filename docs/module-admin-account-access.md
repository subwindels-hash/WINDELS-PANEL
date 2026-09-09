# Module 35 — administrators who can be added, and accounts you can act on

*Branch `arena/01a04991-windels-panel`. Follows module 34 (signup PINs, admin
PIN reveal, provider deletion, the contact inbox).*

Three operator requests, all about access:

1. an admin should be able to **add another admin**;
2. admins — like customers — should be able to **edit their own email,
   password and profile picture**;
3. an admin should be able to **log in to a user's account from the admin
   dashboard** and act on their behalf.

---

## 1. Adding an administrator

**Admin → Administrators** was a read-only list of SUPER_ADMIN and ADMIN
accounts: the only way to create one was the setup wizard or a role promotion
on an existing user's file. The screen now carries an **Add an administrator**
form — username, email, starting password, role — posted to
`POST /admin/administrators/create` (`Staff::create`, `staff.manage`).

The rules live in `UserAdminService::create_admin()`, next to `set_role()`
where the same rules already existed, so the two cannot drift:

- **Only a SUPER_ADMIN may create a SUPER_ADMIN.** Minting an owner is exactly
  as powerful as promoting one; without this rule `staff.manage` quietly
  grants full ownership of the panel. A plain ADMIN's form does not even offer
  the SUPER_ADMIN option, and the service refuses it anyway.
- **Only the two administrator roles are creatable here.** Customers register
  themselves; ordinary staff are promoted on their own user file where the
  operator is already looking at them.
- Username (`[A-Za-z0-9_-]{3,64}`), email, and an 8–72 character password are
  validated; duplicates are refused with readable messages, not unique-key
  error pages.
- The password is hashed through `AuthService::hash_password()` (Argon2id when
  the build has it), so the account verifies with the same algorithm family as
  every other login. The creator hands the password over privately; the flash
  message tells them the newcomer should change it from Account → Security.
- The account is born complete: ACTIVE, email marked verified (the operator
  vouched for it), a zero-balance wallet row so the user file and ledger
  queries never special-case an administrator without one.
- **The creation is audited** (`staff.admin_created`) inside the service —
  creator, username, email, role; never the password — so the evidence row is
  written even if a future caller forgets to.

Creation is additive, so the last-super-admin lockout guards don't apply
(they guard *removing* owners, not adding colleagues).

## 2. Self-service email, password and picture — for admins too

The customer screens already did all of this
(`/dashboard/profile`: username, names, email with re-verification, avatar
upload and removal; `/dashboard/security`: password, PIN, MFA). `Auth_Controller`
never role-blocks staff from `/dashboard/*`, so the functionality worked for
administrators — it was just undiscoverable: the admin sidebar's System group
linked Security but not Profile.

- The admin sidebar now links **My profile** (`dashboard/profile`) directly
  above Security. `dashboard/profile` is not one of the customer-only markers
  the UX-separation check forbids in the admin shell, and the separation check
  still passes 58/58 — staff get the link, customers keep their own Profile /
  settings entry, and no admin-only or customer-only leakage either way.
- Email changes by an administrator behave exactly like a customer's: the new
  address is stored, `email_verified_at` is nulled, and a re-verification mail
  is queued (`auth.verify_email`). Keeping the old verification would let
  anyone move notices to an address they do not control and still look
  verified.
- Avatars go through the same `MediaService` path (`users.avatar_url`),
  image-only, and render in the sidebar for both shells.
- Password changes require the current password and are confirmed by the
  audit trail (`profile.update`, `security.password_changed` as before).

## 3. Logging in to a user's account — full-access impersonation

Impersonation shipped as a strictly read-only lens (see
[customer-impersonation.md](customer-impersonation.md), updated). The operator
asked for the real thing: log in as the customer and act for them. Rather than
loosening the existing mode, impersonation now has **two explicit modes**,
chosen with a radio button on the customer file:

- **READ_ONLY** — unchanged behaviour: dashboard GET/HEAD only, forms greyed
  out, every write refused.
- **FULL_ACCESS** — any method under `/dashboard`: place orders, open tickets,
  spend the customer's wallet. The banner says so in plain words, and the
  shell does *not* grey out forms.

What did not change — the parts that make the feature survivable:

- The mode is stored in the server-side session context and re-validated on
  every request; a corrupted value destroys the session rather than resolving
  to full access. An unknown mode at start is rejected, not downgraded — a
  caller that cannot name the mode has not made the decision the mode
  represents.
- **Credential and identity screens stay unwritable in both modes**:
  `dashboard/profile` (email → account takeover), `dashboard/security`
  (password, PIN, MFA), `dashboard/identity` (KYC documents), `dashboard/api`
  (minting customer API keys). Writing any of those would transfer the
  account to the staff member permanently — that is account takeover, not
  support. Reading them is allowed.
- The admin area stays unreachable in both modes; only `/dashboard/*` is
  served. `/logout` still refuses to discard the staff identity; only the
  banner's stop form ends the session and restores the actor.
- The 30-minute hard expiry, per-request revalidation of both identities and
  the permission, the fail-closed start and per-page audit rows, and the
  identity isolation (`sess_regenerate` + session-data clearing at both
  crossings) all still apply to both modes.
- Every request — now including writes — writes a
  `user.impersonation.viewed` row naming method, path **and mode**, against
  the staff member. `user.impersonation.started`/`ended` carry the mode too,
  so an investigator can scope to money-moving sessions with
  `"mode":"FULL_ACCESS"`.

Money note: an order or wallet spend during full access is a real transaction
by the customer, paid from their wallet, with the staff member's fingerprints
on the audit trail. Manual balance corrections still belong to
`wallets.adjust` on the customer file, which goes through the ledger with its
own reason and idempotency rules.

## Verification

- **Unit** (`tools/phpunit_lite.php`): 1568 tests, 0 failures. New:
  `AdminUsersTest::testAnAdminCreatesAnotherAdministrator`,
  `...RejectsBadInputAndDuplicates` (duplicates, weak password, bad
  username/email, CUSTOMER not mintable, ADMIN cannot mint SUPER_ADMIN,
  customer cannot mint anything, no audit row on refusal),
  `...IsWiredIntoTheScreen`; `ImpersonationTest::testFullAccessModeIsExplicitValidatedAndAudited`,
  `...ACorruptedModeDestroysTheSessionInsteadOfUpgradingIt`, plus the
  source-pinning test extended to the mode radio, the banner wording, the
  credential blocklist and the layout's conditional grey-out.
- **End-to-end** (`node tools/devserver/admin_access_check.mjs`): 40/40 —
  create admin (screen, success, directory row, DB shape, wallet, audit
  without password), duplicate refused, new admin signs in and reaches
  `/admin`, plain ADMIN cannot mint SUPER_ADMIN; admin opens
  `/dashboard/profile`, uploads and removes an avatar (real multipart upload
  through MediaService), changes email (un-verifies + queues
  `auth.verify_email`), changes password (old one stops working, rotated one
  signs in); impersonation read-only leg (banner, grey-out, write refused 403
  with no ticket leak, admin area 403, stop restores staff) and full-access
  leg (banner, no grey-out, ticket opened and attributed to the customer,
  credential write refused 403, admin area 403, stop restores staff), with
  both starts audited with their modes and the POST itself recorded against
  the staff member.
- **Regression**: ux_separation 58/58 (the admin sidebar gained "My profile"
  without tripping the customer-only markers), settings_validation 20/20,
  feature_flags 32/32, perf_check 38/38, the module-34 e2e checks still green
  (pin reveal 12/12, provider delete 17/17, contact inbox 24/24),
  `CpanelDeploymentTest` 33/33 after rebuilding `application-deployment.zip`
  (routes.php and MY_Controller.php changed).
- No schema change: the three features reuse existing tables and session
  state, so no migration, no `marvysocials.sql` regeneration. The deployment
  zip was rebuilt because `routes.php`, `MY_Controller.php` and the views are
  inside it.

## Still open

- Full access is gated on the same `users.impersonate` permission as
  read-only. If an operator wants full access restricted to senior staff
  while first-line support keeps the read-only lens, that needs a separate
  permission key (`users.impersonate_full`) and a matrix decision — nothing in
  the current permission catalogue distinguishes them.
- The new administrator's starting password travels out-of-band (shown in
  plain sight on the form, handed over privately). There is no email-invite
  flow that forces a first-login password change, though the flash message
  tells the creator to expect one.
- Admin e2e covers `layouts/app.php`; `app_theme.php` shares the same
  `_app_context.php` nav and the same banner partial, so the link and mode
  handling are structurally identical, but the second theme is not separately
  crawled by this check.
