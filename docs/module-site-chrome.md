# Module 16 — site chrome, brand and the two missing admin screens

*Branch `arena/01a04558-windels-panel`. Follows module 15 (certification).*

Eight items raised by the operator, all of them about what a visitor actually
sees. Read together they are one defect wearing eight costumes: **the panel had
more than one idea of what it looked like, and none of those ideas was editable
by the person who owns it.**

Everything below is proved twice — a PHP unit test that pins the rule and an
end-to-end check that loads the real page over HTTP
(`tools/devserver/chrome_check.mjs`, 68 checks, now stage 6 of
`tools/verify_all.sh`).

---

## 1. Two competing headers, and pages with no header at all

`application/views/layouts/public_theme.php` carried a **second, hand-written
navbar**: a different set of links, a hard-coded brand string, and no mobile
menu at all. Any page rendered through that layout showed a different site
from the one rendered through `partials/navbar.php` — different destinations,
and on a phone no way to navigate whatsoever.

`/api/docs` was worse: no navigation, no footer, no announcement bar. A
reseller who followed the API link from the footer arrived on a page with no
way back to the site except the browser's back button.

Both now render the shared chrome: `partials/head`, `partials/announcement`,
`partials/navbar`, `partials/footer`, `partials/scripts`. There is exactly one
menu definition in the codebase, so a link added to it appears everywhere.

`SiteChromeTest` fails if any layout stops including the footer or the
announcement partial, and `chrome_check.mjs` walks the public, auth, docs and
signed-in surfaces asserting each one carries a menu *and* a footer.

## 2. Navy chrome, applied to every surface rather than one

The request was a navy header and footer with white type. The trap is that
this panel has five shells — public, auth, app (customer), admin sidebar and
the theme variants — and styling one of them is how sites end up looking
half-finished.

A palette was added to `assets/css/design-system.css`
(`--ws-navy-900: #0b1b3a` through `-600`, plus `--ws-navy-line`,
`--ws-navy-ink`, `--ws-navy-ink-dim`) and applied to `.ws-public-nav`,
`.ws-auth-header` (a new class on the auth layout's header, which previously
had no hook at all), `.ws-sidebar` and its brand/nav-link/summary/user
elements, `.ws-footer`, `.ws-app-footer` and the mobile `.ws-nav-panel`.

The logo on those dark surfaces used to be a light-coloured wordmark faked
with a CSS `invert()` filter — which inverts the mark's gradient too, and
looks like a rendering bug. Those call sites now ask
`partials/brand_logo.php` for `variant => 'dark'`, which is a real asset.

`SiteChromeTest` asserts the palette is defined once and used by each named
surface, so a future redesign that drops the sidebar rule fails the suite
rather than shipping a white sidebar under a navy header.

## 3. The sign-in copy that ran together — and was hidden from screen readers

On `/login`, `/register` and `/admin/login` the marketing panel read as one
unbroken run of words:

> MarvySocials A wallet you can audit. Orders you can follow. Prepaid SMM, VTU
> and digital goods — same ledger, same staff tools.

Two separate faults produced that. The whole panel was
`<aside class="ws-auth-visual" aria-hidden="true">`, so a screen-reader user
got **none** of it; and because the heading and the paragraph were not
separate blocks, the logo's alt text and the sentence collided into a single
line on narrow viewports.

The panel is no longer `aria-hidden` — only the decorative photograph inside
it is (`alt="" aria-hidden="true"`, which is what that attribute is for). The
heading and body are distinct blocks, `.ws-auth-visual` is a flex column
justified to the end with a `min-height` and a `rgba(11,27,58,.35 → .95)`
gradient overlay so white type stays legible over the photo, and below 880px
the photo is dropped and the copy becomes a compact banner instead of
overflowing.

The copy is also overridable per page (`$auth_visual_title`,
`$auth_visual_text`). `Auth::admin_login()` now passes **"Staff sign-in."** and
staff-specific wording on both the GET and the validation-failure path — a
customer who lands on the staff door should be told it is the staff door, not
sold a wallet.

### 3a. The mark has left the panel; the write-up owns it, centred

The logo used to sit immediately above the heading with a small gap, so on
`/login`, `/register` and `/admin/login` the wordmark read as the opening
words of "A wallet you can audit…". It was first fenced off as its own block,
`.ws-auth-visual-brand`, separated from `.ws-auth-visual-copy` by space and a
hairline rule — but the navy header above the panel already carries the same
mark, so the panel now belongs to the words alone: `ws-auth-visual-brand` is
gone from the markup and the stylesheet, and `brand_logo` is no longer loaded
inside the aside.

The write-up itself was also bottom-anchored, hugging the lower edge of a very
tall photograph. The panel is now a flex column justified and aligned to the
centre (`justify-content:center`, `align-items:center`, `text-align:center`),
so the heading, the line and the highlight list sit in the optical middle of
the panel with their text centred. The pitch grew, too: under
"Prepaid SMM, VTU and digital goods — same ledger, same staff tools." the
panel now carries a short list of promises (`.ws-auth-visual-points`) — prices
confirmed before the wallet is charged, one provable ledger, live order
tracking with a real support desk. The list is overridable per door via
`$auth_visual_points`, and `Auth::admin_login()` passes staff bullets on both
the GET and the validation-failure path so the staff panel never inherits the
customer highlights; below 880px the same blocks tighten rather than
collapsing together. Markup, not just spacing — a screen reader announces
heading, then line, then each highlight as its own list item.

## 3b. The staff door is no longer advertised to customers

`/admin/login` is a separate door on purpose, but three customer-facing
surfaces pointed straight at it:

* the customer sign-in form ("Staff can also use the dedicated *admin
  sign-in*"), now just *"Enter the email or username you registered with."*;
* the default announcement ticker line "Staff sign in at Admin login…",
  removed from the fallback list in `partials/announcement_bar.php`;
* the footer's **Staff login** link, now rendered only when
  `$current_user->role` is `SUPER_ADMIN`, `ADMIN` or `STAFF`.

No route, redirect or permission check changed — `/admin/login` still works
for anyone who types it, and `Auth::admin_login()` still refuses customer
credentials. Only the advertising is gone. The on-site assistant still answers
a direct "where do staff sign in?" question with the link, which is a
deliberate exception (`SiteOperatorEngineTest::testAdminQuestionPointsAtStaffLogin`).

## 3c. The footer wordmark rendered smaller than it was asked to

`partials/brand_logo` has always taken a `height`, but the stylesheet only
pinned `.ws-logo{max-height:2.25rem}` with `height:auto`. Two consequences:
the footer asked for 40px and drew at **36px**, and because nothing set a real
height, an operator-uploaded wordmark (`brand_logo_url`, which ships without a
`width` attribute) drew at its own intrinsic pixel size — a 120×24 upload
rendered a 24px logo, which is the "footer logo is tiny on some pages" report.

The requested height now travels to CSS as a custom property:

```php
$style = '--ws-logo-h:'.$h.'px';   // partials/brand_logo.php
```
```css
.ws-logo,.ws-logo-lg,.ws-logo-sm{display:block;width:auto;max-height:none;
  height:var(--ws-logo-h,2.25rem)}
.ws-footer-logo{--ws-logo-h:44px}
@media(max-width:520px){.ws-footer-logo{height:38px}}
```

Declared height = rendered height, for bundled artwork and uploads alike, on
every page that loads the footer. Only the *variable* is inline, so a media
query can still shrink a placement — the footer mark steps down to 38px under
520px. The footer itself now asks for 44px: it is the sign-off, and it is the
same size on the homepage, the auth pages, the legal pages and the API docs.
`width:auto` keeps every ratio, and the `width`/`height` attributes stay so the
browser still reserves the box before the image loads.

## 4. The announcement bar was hard-coded

`partials/announcement_bar.php` printed a fixed marquee. The operator could
not change the words, the colours or the speed without editing PHP — and the
CMS "announcements" feature that *did* exist was not what the bar rendered.

Precedence is now explicit: the `announcement_text` setting (one message per
line) beats published CMS announcements, which beat a built-in fallback. Five
settings were added to `SettingsService::catalogue()` under `branding`:

| Setting | Type | Default |
|---|---|---|
| `announcement_enabled` | bool | `true` |
| `announcement_text` | longtext | *(empty — falls through to CMS)* |
| `announcement_bg_color` | color | `#0b1b3a` |
| `announcement_text_color` | color | `#ffffff` |
| `announcement_speed_seconds` | int | `40` (`0` ⇒ static, centred) |

`color` is a **new setting type**, not a text box painted to look like one.
`SettingsService::coerce()` validates `/^#[0-9a-f]{6}$/` (expanding `#rgb`) and
the admin form renders a native picker beside the hex field. That matters:
these values are interpolated into a `style` attribute, so
`javascript:alert(1)` typed into a colour field is a stored-XSS attempt.
`chrome_check.mjs` types exactly that and asserts it is rejected.

## 5. WINDELSOCIALS was still in the artwork

The old name was hard-coded in four PHP files — all now call
`marvy_site_name()`, so the site name is a setting rather than a string — but
the real problem was that **`assets/brand/logo.png` literally drew the word
"WINDELSOCIALS"**. No amount of renaming in PHP changes a raster image, and
that logo appears on every page, every invoice and the browser tab.

The whole brand set was redrawn: a rounded-square `#0A0A0F` mark carrying a
`#6366F1 → #C026D3` gradient "M", with a "MarvySocials" wordmark. Regenerated
`logo.png`, `logo-horizontal.png` (972×192), `logo-dark.png`, `logo-white.png`,
`logo-icon.png`, `favicon-16/32/48.png`, `icon-192.png`, `icon-512.png`,
`apple-touch-icon.png`, `favicon.ico`, and rewrote the five SVGs.
`partials/brand_logo.php` reserves space from an aspect-ratio table, so that
was corrected to the artwork's real 5.0625 — a wrong ratio there is a layout
shift on every first paint.

`SiteChromeTest` greps the whole of `application/` for the old string and
measures the shipped artwork against the declared ratio.

## 6. Cron jobs were invisible

Scheduled work — deposit reconciliation, provider sync, order polling — ran
with no screen anywhere. An operator whose crontab was never installed had no
way to discover that, and "orders stopped updating three days ago" is exactly
the failure that produces.

New read-only screen at `/admin/cron` (`System::cron()`, gated on
`audit.view`, a tab in the system section and an entry in the admin sidebar).
For each job it shows the schedule, a **plain-English description** of it, a
state badge, the last run, and the exact crontab block to paste into cPanel,
followed by recent runs.

The state comes from `SystemAdminService::job_state()`: `never` (has not run at
all), `failing`, `late` (overdue by more than `max(15, cadence × 3)` minutes)
or `ok`. A fixed tolerance would cry wolf on an hourly job and stay silent on
a five-minute one, so the tolerance follows `cadence_minutes()`.

The screen writes nothing. It cannot trigger, pause or edit a job — a
"run now" button on a reconciliation sweep is a way to double-credit deposits,
and that belongs behind its own design rather than in a status page.

## 7. The contact page had an address and no map

New settings group `contact`: `contact_map_enabled` (**off by default**),
`contact_address`, `contact_map_query`, `contact_map_zoom` (15),
`contact_phone`, `contact_hours`. `Home::contact()` passes them through
`Home::contact_details()` and `public/contact.php` renders a "Find us" card.

Both embed paths are **keyless** — an operator should not have to obtain a
Google Maps API key to show where their office is. A `lat,lng` value uses
OpenStreetMap's `export/embed.html` with a bounding box and marker; free text
falls back to `maps.google.com/maps?q=…&output=embed`. Either way there is an
"Open in maps" link for people who want directions.

The security detail: embedding a third-party iframe means relaxing
`frame-src`, and the panel ships a strict CSP. `MY_Controller::map_frame_src()`
adds the three map origins **only while the map is enabled**, so a panel that
never turns the feature on keeps its original policy. `chrome_check.mjs`
asserts the header both ways.

## 8. The "customer reviews" were cartoons

`assets/images/reviews/reviewer-1..4.jpg` were 7–10 kB illustrations, and the
avatar `<img>` carried `alt=""`. On a page whose entire job is social proof,
clip-art reads as fabricated. They are now photographic headshots (87–124 kB,
≥400px, real JPEG) and the alt text names the reviewer, so the testimonial is
attributed in the accessibility tree as well as on screen.

`SiteChromeTest` asserts each file is a JPEG over 40 kB and at least 400px
wide — cheap, but it is precisely the check that catches someone dropping a
placeholder back in.

---

## Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php     # 1427 tests, 16763 assertions, 0 failures
node tools/devserver/chrome_check.mjs --admin-password '…'  # 68/68
bash tools/verify_all.sh --admin-password '…'               # 43 passed, 0 failed
```

New tests: `tests/unit/SiteChromeTest.php` (14) and
`tests/unit/AdminOperationsSurfacesTest.php` (9). Supporting sweeps re-run
green: `image_audit` (15 images, 0 broken), `link_crawl` (160 pages, 0
problems), `page_audit` (0 failing), `responsive_check` 16/16,
`ux_separation_check` 58/58, `content_check` 18/18, `deployment_check` 35/35.

`chrome_check.mjs` saves announcement text and colours through the real admin
form and **restores the operator's previous values afterwards**, so running the
certification sweep against a live panel does not overwrite their banner.

The deployment package and `database/marvysocials.sql` were rebuilt, because
the settings catalogue changed and a stale package is a panel that boots
without the new colour and map settings.

---

## Still open

- **The map is an iframe, not a first-party render.** It costs a request to
  OpenStreetMap or Google from the visitor's browser, which is a privacy
  disclosure worth mentioning in the cookie notice for an EU operator. A
  static-tile screenshot would avoid it and lose the pan-and-zoom.
- **`announcement_text` is plain text by design.** Operators will eventually
  want a link in the banner; that needs the same sanitising path the CMS uses,
  not a raw HTML setting.
- **The cron screen reports; it does not schedule.** Nothing in the panel can
  install a crontab. On cPanel that remains a manual paste, which is why the
  screen generates the exact block.
- **Brand artwork is generated, not designed.** The mark is legible and
  consistent at every size the panel uses, but an operator with a real
  identity should replace `assets/brand/*` wholesale — the ratio table in
  `partials/brand_logo.php` is the one thing they must update with it.
