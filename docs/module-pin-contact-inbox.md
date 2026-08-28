# Module 34 — the PIN's second life, provider deletion, and the contact inbox

*Branch `arena/01a04991-windels-panel`. Follows module 33 (the service-form
picker and the bulk import).*

Four operator requests against the live panel, each of which changed a
deliberate earlier decision rather than filling a gap:

1. accounts should start with a working security PIN;
2. staff should be able to **read** a customer's PIN from the admin file;
3. a provider and its synced catalogue should be deletable in one action;
4. visitors' contact emails should be readable — and answerable — from the
   admin dashboard, with email templates.

---

## 1 + 2. The PIN: issued at sign-up, readable by staff

The PIN shipped as a one-way `password_hash` with a written "no reveal"
contract: admins could clear it or lift a lockout, never read it. The operator
asked for the opposite, and the trade-off is worth stating plainly rather than
hiding behind crypto vocabulary:

> A database dump plus `ENCRYPTION_KEY` is enough to read every customer's
> transaction PIN. That was already true of provider API keys and MFA secrets;
> the difference is what a PIN *is* to the person it belongs to.

What keeps the feature survivable:

- **Encrypted at rest, never plaintext.** `users.pin_cipher` holds an
  AES-256-GCM envelope (`EncryptionService`, the same key infrastructure as
  provider credentials). The hash remains the verification path; the envelope
  is the reveal path.
- **Every reveal is audited** as `user.pin_revealed` naming the staff member —
  and the PIN itself never appears in the audit trail, the same rule the
  issuance audit follows.
- **POST-only, `users.edit`**, same permission as clearing the PIN.
- **The legacy gap is honest.** PINs chosen before migration 033 are hash-only;
  the customer file says *“set before encrypted PIN history was kept”* and the
  reveal refuses with that reason instead of guessing. The customer's next PIN
  (set, issued, or rotated) carries an envelope.
- **Sign-up issuance is a setting** (`pin_issue_at_signup`, on by default). The
  new customer is told their PIN exactly once — in-app notification plus email
  — and from then on it exists only as hash + envelope. The page never renders
  it back, which `pin_check.mjs` now asserts for the issued value itself.

`PinVisibilityTest` (10 tests) pins the envelope properties (not the plaintext,
not the hash), the reveal round-trip, replacement, rotation, the legacy
refusal, the reset clearing both halves, and registration through the real
`AuthService` both with the setting on and with it off.

## 3. Provider deletion with a named blast radius

A provider row was immortal: the schema's foreign keys (CASCADE on the mirror
tables, SET NULL on history) were defined in migration 004, but no screen ever
offered the delete. `ProviderSyncService::delete_provider()` performs exactly
what those constraints describe, so the same routine is correct on MySQL (where
the explicit child deletes are redundant with the cascades) and on engines
without enforced foreign keys:

- **Gone:** the provider, `provider_services`, sync/health logs,
  `provider_orders`, `provider_transactions` — mirrors of a dead upstream
  account.
- **Kept, unlinked:** panel services sourced from it (still sellable, at their
  own rate, `auto_price_sync` off since there is nothing left to follow) and
  past orders (history intact, provider link removed).

The confirmation names the counts before the button is pressed, the flash
repeats them after, and the audit row (`provider.deleted`) carries them.
POST-only behind `providers.manage`. `ProviderDeleteTest` pins the blast
radius row by row.

## 4. The contact inbox

An anonymous visitor's contact-form message used to exist only as an
`email_queue` row bound for the support mailbox — the “Customer messages”
screen could list its subject but not show the body, and there was no way to
answer from the panel at all.

- `contact_messages` (migration 033) is the dashboard half of the
  conversation; the mailbox copy still goes out, because the two channels fail
  differently.
- **Admin → Messages** shows each message expandable (a `<details>` element —
  no JavaScript needed to read), the sender's address as a column instead of a
  regex over an email body, and both halves once answered.
- **Replying** posts to `admin/messages/reply/:id` (`tickets.reply`,
  POST-only). The email is queued through `MailService` like every outbound
  mail — never sent inline — placeholders (`{{name}}`, `{{subject}}`,
  `{{site_name}}`) are filled and unknown ones stripped, and the reply is
  stored on the row so the dashboard holds the whole thread. Audited as
  `contact.replied`.
- **Templates.** Three reply starters ship (`contact.reply_general`,
  `_order`, `_billing`) seeded for fresh installs by `Core_seeder` and for
  existing installs by the migration. The reply box's picker fills from any
  active template whose key starts `contact.reply` — and since the Email
  templates screen used to be edit-only, it gained a create form so operators
  can add their own.
- **The admin dashboard counts it**: “Contact messages to answer” sits next to
  the ticket queues in *Needs attention*, from the same
  `AdminStats::action_queue()` the other widgets use (and within its query
  budget).

`ContactInboxTest` (6 tests) pins the reply path, the placeholder contract,
and the refusals.

---

## Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php    # 1563 tests, 0 failures
node tools/devserver/pin_check.mjs                         # 15/15 (new sign-up contract)
node tools/devserver/pin_rotation_check.mjs --admin-password '…'  # 13/13
# plus the full checker battery — smoke, chrome, support, commerce, admin,
# page_audit, link_crawl, security, attachment, notifications, smm_provider —
# all green, and ad-hoc end-to-end runs of the reveal (12/12), the provider
# delete (17/17), the contact inbox (24/24) and template creation (7/7).
```

## Deployment note

Migration 033 (`users.pin_cipher`, `contact_messages`, reply templates) is
CLI-only like every migration:

```bash
php index.php migrate
```

The generated `database/marvysocials.sql` and `application-deployment.zip`
already carry it for fresh installs; existing installs run the one command
above after unpacking the package.
