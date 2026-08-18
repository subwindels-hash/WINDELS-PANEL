# Session 30 — the sixteen missing admin modules

## What prompted it

A request to "list the unfinished modules" turned into an audit rather than a
recollection: `routes.php` was diffed against `application/controllers/admin/`.
Fifteen admin routes resolved to controllers that did not exist, and one
service domain (marketplace) was still absent.

Two of the fifteen were not merely unreachable — they were **live entries in
the admin sidebar**:

| Sidebar entry | Route | Controller | Result |
| --- | --- | --- | --- |
| Customers | `admin/customers` | `admin/users/customers` | 404 |
| Settings  | `admin/settings`  | `admin/settings/index`  | 404 |

That is the same shape as the VTU gap reported in session 23: permissions
seeded into the role matrix, granted to ADMIN and STAFF, and enforced by no
code because the screen behind them was never built. At the start of this
session **two** of roughly thirty-one seeded permission keys reached a
`require_perm()` call. It is now twenty-nine, and `AdminStaffTest::
testEverySeededPermissionIsEnforcedSomewhere` fails if that regresses.

## What shipped

| Module | Screen | Permission |
| --- | --- | --- |
| Users / customers / wallets | `admin/customers`, `admin/wallets` | `users.view`, `users.edit`, `wallets.adjust` |
| Settings | `admin/settings` | `settings.manage` |
| Staff directory | `admin/staff` | `staff.manage` |
| RBAC matrix | `admin/staff/permissions` | `staff.manage` |
| Blog | `admin/blog` | `blog.manage` |
| FAQ | `admin/faq` | `faq.manage` |
| Announcements | `admin/announcements` | `announcements.manage` |
| Refills | `admin/refills` | `orders.refill` |
| Cancellations | `admin/cancellations` | `orders.cancel` |
| Drip-feeds | `admin/drip-feed` | `orders.edit` |
| Subscriptions | `admin/subscriptions` | `orders.edit` |
| Service categories | `admin/categories` | `categories.manage` |
| Blacklist | `admin/blacklist` | `blacklist.manage` |
| Audit trail | `admin/audit-logs` | `audit.view` |
| Media library | `admin/media` | `media.manage` |
| Appearance | `admin/appearance` | `appearance.manage` |

Seven controllers rather than sixteen, grouped the way Admin → Catalogue
groups four product domains: `Users`, `Settings`, `Staff`, `Content`,
`Operations`, `System`, `Media`. Rules live in services — `UserAdminService`,
`SettingsService`, `RbacService`, `ContentService`, `SystemAdminService`,
`MediaService` — so a rule cannot hold on one screen and not another.

## The decisions worth keeping

### Money: a manual adjustment is a ledger movement

`LedgerService::adjust()` is new, and is the only writer of `wallet_
transactions.actor_id` and `.note` — two columns the schema has carried since
migration 002 for "an admin forced this" and nothing had ever filled. It goes
through the same `move()` as a purchase, so a correction is row-locked,
double-entry, floored at zero and idempotent. A reason is mandatory: an
unexplained balance change is indistinguishable from theft when someone reads
the ledger back six months later.

### Privilege: the guards are about staying recoverable

- Nobody edits their own role or status.
- Only a SUPER_ADMIN grants SUPER_ADMIN, or `staff.manage` is quietly
  equivalent to owning the panel.
- The last active SUPER_ADMIN cannot be demoted or suspended — counted, not
  flagged.
- SUPER_ADMIN's permission grid is not editable: `AuthService::can()`
  short-circuits before reading a row, so editing it would imply a restriction
  the code does not honour.
- You cannot remove `staff.manage` from your own role. That is the one click
  that ends all further clicks.
- CUSTOMER may hold nothing: every customer shares that role.

### Honesty: no control that does nothing

A grep found eleven settings seeded in session 02 that no code reads.
`SettingsService` renders none of them as controls and lists them instead,
each with the work it would take to honour it. `testEveryEditableSettingIs
ActuallyReadSomewhere` fails if an editable key has no consumer. `base_currency`
is shown read-only: `windels_base_currency()` reads config, so a form would
change nothing, and a form that worked would reinterpret every stored amount.

When the branding screen made `brand_primary_color`, `brand_logo_url` and
`brand_favicon_url` real, they moved out of that list — and a test enforces the
move, so the note cannot be deleted without the wiring.

### Security: three places where the naive version is harmful

1. **Stored XSS.** `views/public/blog/detail.php` prints a post body unescaped
   ("stored as trusted HTML by staff"), so the editor is a path from a staff
   session to script on every visitor's page. Content is sanitised on the way
   in by allow-list: dangerous elements go with their contents (`strip_tags`
   would leave `alert(1)` as visible text), `on*` handlers and
   `javascript:`/`data:` URLs are stripped from tags that are otherwise
   allowed, and `srcdoc` is dropped.
2. **ReDoS.** `Blacklist_model` runs any `/.../` entry against user text on
   every registration; its comment waved this off because "only staff may add
   entries", which held only while no form existed. Patterns are now compiled
   and probed before storage, so `/(a+)+$/` is refused rather than left to hang
   every future signup.
3. **Upload → RCE.** The document root is the repository root, so a `.php`
   file under `assets/` is code execution. The extension is derived from the
   sniffed MIME through a fixed map, the stored name is random, images must
   decode via `getimagesize()`, SVG is refused, and a hardened `.htaccess` is
   written beside the files.

### The audit trail has no write path

No edit, no delete, no purge, and no sub-routes. A log an administrator can
rewrite is not evidence, and this is the page most likely to be opened by
someone covering their tracks. A test pins the absence.

## Two harness bugs found on the way

Both were pre-existing, and both made the fake disagree with production in the
direction that hides defects:

- **Joins kept only the first match.** A many-to-many
  (`permissions → role_permissions → roles`) returned one role's permissions
  and an empty set for every other. It now fans out as SQL does, honouring
  left vs inner when nothing matches.
- **`group_start()`/`group_end()` were no-ops.** `a = 1 AND (b IS NULL OR
  b <= now)` behaved as a flat OR, so any `or_where` could rescue a row that
  had already failed the AND. `Announcement_model::visible()` is exactly that
  shape, so a *hidden* announcement came back visible.

`FakeDb` also gained `distinct()`.

## Not built

- **`users.impersonate`.** "Log in as this customer" is the most abusable
  button in a panel holding wallets: staff could spend someone else's balance
  with nothing in the ledger to distinguish their actions from the customer's
  afterwards. Admin → Customers answers the same support questions read-only.
- **`services.manage`** — no SMM service editor; Admin → Catalogue covers the
  four product domains.
- **`api.manage`** — reseller keys are issued from the customer dashboard.
- **Withdrawals** and the **marketplace half of phase F**, both tracked in
  `rebuild-spec-audit.md`.

## Tests

962 tests / 8,663 assertions / 0 failures, up from 825 / 7,832. Each new gate
was verified to bite by reverting the behaviour it protects: the naive upload
implementation fails five tests including storing a live `.php` shell, and a
`strip_tags`-only sanitiser fails six.
