# Settings audit inventory

This is the full "every setting key" audit the platform-fixes spec asked
for: every row that Admin → Settings (`SettingsService::schema()`) or
Admin → Settings → Feature flags (`feature_flags` table) can edit, where it
is stored, what UI edits it, what code reads it, what it is supposed to do,
and its status.

Verified against the real app (WASM CodeIgniter + SQLite-backed MySQL dev
server), not by inspection alone — see
`tools/devserver/feature_flags_check.mjs` (32/32) and
`tools/devserver/settings_validation_check.mjs` (20/20) for the automated
proof behind the "Implemented" rows added or confirmed in this pass.

Status legend:
- **Implemented** — a real code path reads it and changes real behaviour.
- **Fixed this pass** — was seeded but unread before this audit; now wired,
  with a test proving it.
- **Read-only by design** — shown in the admin UI but intentionally not a
  form field, with the reason.
- **Managed elsewhere** — edited on a dedicated screen, not the generic
  settings form.

## `settings` table (`SettingsService::schema()`)

| Key | Type | Category | DB location | Edited from | Read by | Behaviour | Status |
|---|---|---|---|---|---|---|---|
| `site_name` | text | general | `settings.setting_key='site_name'` | Admin → Settings | `marvy_site_name()` (helper), page `<title>`, emails, meta tags | Site name shown in browser title, emails, meta | Implemented |
| `support_email` | email | general | settings row | Admin → Settings | `EmailTemplateService`, contact page, reply-to header | Reply-to on outgoing mail, contact page | Implemented |
| `site_tagline` | text | general | settings row | Admin → Settings | `marvy_site_tagline()`, homepage meta description fallback | Fallback meta description / strapline | Implemented |
| `maintenance_mode` | bool | general | settings row | `MY_Controller::enforce_maintenance()` | Holds all non-staff traffic on a 503 branded page except login/health routes | Implemented |
| `active_homepage` | choice: AURORA\|NEXUS\|PULSE | homepage | settings row | Admin → Settings | `Home::active_homepage()` | Chooses which of 3 homepage designs renders | Implemented |
| `homepage_hero_kicker`/`_title`/`_lede`/`_cta_primary`/`_cta_secondary`/`_services_title`/`_services_lede`/`_cta_band_title`/`_cta_band_body`/`_meta_description` | text/longtext | homepage | settings rows | Admin → Settings | `Home::index()` passes each into the homepage view | Homepage copy is fully admin-editable per the active design | Implemented |
| `registration_enabled` | bool | security | settings row | `Auth::register()` | Off returns "registration is closed" instead of creating an account | Implemented |
| `email_verification_required` | bool | security | settings row | `Auth::register()`/`AuthService` | Off skips the "verify your email before ordering" gate | Implemented |
| `admin_mfa_required` | bool | security | settings row | `MY_Controller`/`Admin_Controller` | On redirects any staff account without MFA to enrol before entering `/admin` | Implemented |
| `pin_auto_rotation_enabled` | bool | security | settings row | `CronWorkers::pin_rotation()`, `pin_rotation_check.mjs` | On, the scheduled worker replaces every PIN after the window below | Implemented |
| `pin_rotation_hours` | int | security | settings row | `PinService`, `CronWorkers::pin_rotation()` | How long a PIN stays valid before rotation | Implemented |
| `min_deposit` / `max_deposit` | money | payments | settings rows | `Payments::add_funds()`/`PaymentService` | Bounds on a single top-up; save() also cross-validates min ≤ max | Implemented |
| `order_auto_submit` | bool | orders | settings row | `OrderService::place()` | Off holds new orders in PENDING for manual staff submission | Implemented |
| `partial_refund_enabled` | bool | orders | settings row | `OrderService`/refund worker | On auto-refunds the undelivered share of a partial delivery | Implemented |
| `referral_commission_percent` | percent | affiliate | settings row | `AffiliateService::commission_percent()` | % of a referred customer's spend paid to the referrer | Implemented |
| `referral_commission_scope` | choice: LIFETIME\|FIRST_ORDER | affiliate | settings row | `AffiliateService::scope()` | Whether commission is paid on every order or once | Implemented |
| `referral_hold_hours` | int | affiliate | settings row | `AffiliateService::hold_hours()` | Holding period before a referral commission can be paid | Implemented |
| `referral_min_payout` | money | affiliate | settings row | `AffiliateService`/`PayoutService` | Minimum payable referral commission balance | Implemented |
| `identity_retention_days` | int | identity | settings row | `CronWorkers::identity_purge()` | How long an encrypted NIN/BVN result is retained before purge | Implemented |
| `giftcard_sender_name` | text | giftcards | settings row | `GiftcardService::purchase()` | "From" name printed on a delivered gift card | Implemented |
| `marketplace_auto_release_hours` | int | marketplace | settings row | `MarketplaceService`, escrow auto-release cron | Hours before an undisputed marketplace order completes automatically | Implemented |
| `blockonomics_btc_enabled` / `blockonomics_usdt_enabled` | bool | crypto | settings rows | `BlockonomicsGateway` | Shows/hides the BTC option on Add funds | Implemented |
| `blockonomics_api_key` / `blockonomics_callback_secret` | secret | crypto | settings rows (masked in the UI) | `BlockonomicsGateway` | Credentials for address issuance and callback verification | Implemented |
| `blockonomics_confirmations` | int | crypto | settings row | `BlockonomicsGateway` | Confirmations required before a BTC deposit is credited | Implemented |
| `blockonomics_timeout_minutes` | int | crypto | settings row | `BlockonomicsGateway` | How long a quoted BTC amount stays valid | Implemented |
| `fundsvera_enabled` | bool | fundsvera | settings row | `FundsveraGateway` | Shows/hides bank-transfer option on Add funds | Implemented |
| `fundsvera_public_key` / `fundsvera_secret_key` / `fundsvera_webhook_secret` | secret | fundsvera | settings rows (masked) | `FundsveraGateway` | Gateway auth + webhook signature verification | Implemented |
| `fundsvera_base_url` | text | fundsvera | settings row | `FundsveraGateway` | API endpoint override | Implemented |
| `referral_signup_reward` | money | referrals | settings row | `ReferralService` | Amount paid to a referrer when a referred account qualifies | Implemented |
| `referral_qualify_event` | choice: REGISTERED\|EMAIL_VERIFIED\|FIRST_DEPOSIT\|FIRST_ORDER | referrals | settings row | `ReferralService::qualify_event()` | Which event triggers the referral reward | Implemented |
| `referral_max_per_user` / `referral_max_per_ip_day` | int | referrals | settings rows | `ReferralService` | Referral abuse caps | Implemented |
| `earnings_hold_hours` | int | referrals | settings row | `EarningsService` | Holding period before an earning can be withdrawn | Implemented |
| `earnings_min_payout` | money | referrals | settings row | `EarningsService`/`PayoutService` | Minimum cash payout amount | Implemented |
| `earnings_payouts_enabled` | bool | referrals | settings row | `EarningsService` | Off still allows wallet-credit conversion, blocks cash payout requests | Implemented |
| `api_enabled` | bool | api | settings row | `Api_v1::__construct()` | Off returns 503 for every `/api/v1` call | Implemented |
| `reseller_webhook_url` | url (optional) | api | settings row | `OrderService` (order-status webhook dispatch) | Where signed order-status JSON is POSTed; blank disables it | Implemented (validation fixed this pass — see below) |
| `reseller_webhook_secret` | secret | api | settings row (masked) | `OrderService` | HMAC key for `X-Marvy-Signature` | Implemented |
| `currency_display` | choice: symbol\|code | currency | settings row | `marvy_money()`, `CurrencyService::format()` | Whether prices render as `₦1,234.56` or `NGN 1,234.56` | Implemented (validation fixed this pass) |
| `default_theme` | choice: system\|light\|dark | branding | settings row | `marvy_default_theme()`, public `<head>` init script | Default light/dark/system theme for visitors | Implemented (validation fixed this pass) |
| `base_currency` | text | currency | settings row (display copy only) | *(none — read-only)* | *(not read; `marvy_base_currency()` reads `config/marvy.php` instead)* | The real accounting currency is a config value, not this row, because changing it must never reinterpret historical amounts (see migration 011) | **Read-only by design** |
| `brand_primary_color` / `brand_logo_url` / `brand_favicon_url` | — | branding | settings rows | Admin → Appearance (`admin/Media.php`), not the generic settings form | `marvy_brand_logo()`, `<head>` favicon tags, CSS variable | Correct, just edited from a dedicated media-picker screen instead of a text field | **Managed elsewhere** |

### Validation bugs fixed this pass (pre-existing, now fixed and tested)

These three settings were already wired to real behaviour, but
`SettingsService::coerce()` rejected legitimate values before they ever
reached that behaviour:

- `reseller_webhook_url` had no `case 'url':` in `coerce()`, so an
  intentionally-empty (disabled) webhook fell through to the generic `text`
  case, which refuses empty strings — every save with a blank webhook
  failed with *"Reseller webhook URL cannot be empty."* Fixed by adding a
  dedicated `url` type: empty saves as disabled, non-empty must be a
  well-formed `http(s)` URL.
- `currency_display` (`symbol|code`) and `default_theme`
  (`system|light|dark`) are declared lowercase in the schema, but the
  `choice:` branch of `coerce()` unconditionally `strtoupper()`'d the
  submission before comparing it against the allow-list — so `"symbol"`
  became `"SYMBOL"` and could never match `"symbol"`, rejecting every
  legitimate value including the form's own pre-filled default. Fixed by
  matching case-insensitively (`strcasecmp()`) and storing using the
  schema's own declared casing, which every existing reader already
  tolerates.

Proof: `tools/devserver/settings_validation_check.mjs`, 20/20 checks,
covering empty/valid/invalid webhook × symbol/code × system/light/dark, and
that a saved value actually changes rendered output (not just what is
stored).

## `feature_flags` table (Admin → Settings → Feature flags)

This is where the audit found the real "seeded but not honoured" bug the
spec was worried about: **7 of the 9 rows saved to the database and did
nothing** — no controller or library anywhere checked them. A flag that
saves and changes nothing is worse than no flag, because it tells an
operator they have a working kill switch when they do not.

| Flag | Seeded default | Before this pass | Now | Status |
|---|---|---|---|---|
| `demo_mode` | off | Read by `Preflight::check_demo_mode()` for a startup **warning** only — never actually blocked a mutation | `MY_Controller::enforce_demo_mode()` refuses every POST/PUT/PATCH/DELETE from non-staff traffic (403, or a flash message + redirect for a normal form post), while every GET keeps working and staff are exempt so they can still operate the panel and switch it back off | **Fixed this pass** |
| `dripfeed` | on | Nothing checked it — turning it off changed nothing | `DripfeedService::create()` refuses new schedules; `CronWorkers::dripfeed()` skips the queue entirely; the "Drip feed" nav item hides for customers | **Fixed this pass** |
| `subscriptions` | on | Nothing checked it | `SubscriptionService::create()` refuses new subscriptions; `CronWorkers::subscriptions()` skips the queue; nav item hides | **Fixed this pass** |
| `mass_order` | on | Already correctly gated `dashboard/Orders::mass_order()`/`mass_create()` and the nav item | Unchanged (used as the reference pattern for the fixes above) | Implemented (pre-existing) |
| `reseller_api` | on | Nothing checked it — only the separate `api_enabled` *setting* (not this flag) actually shut the API down | `Api_v1::__construct()` now checks both; either one off returns 503 | **Fixed this pass** |
| `affiliate_program` | on | Already correctly read by `AffiliateService::enabled()`, gating `record_for_order()`/`record_ongoing()` | Unchanged | Implemented (pre-existing) |
| `marketplace` | on | Nothing checked it — the customer marketplace, `/shop`, `/cart` and `/checkout` all stayed reachable and purchasable regardless | `MarketplaceService::purchase()` refuses the charge; `dashboard/Marketplace`, `Shop`, `Cart`, `Checkout` controllers 404 for new browsing/buying; existing order/escrow history (`dashboard/marketplace/orders`, downloads) stays reachable; nav items hide | **Fixed this pass** |
| `tickets` | on | Nothing checked it — customers could always open new tickets | `TicketService::open()` refuses a new ticket; existing inbox and conversations stay fully visible (support history is never hidden); nav item hides for customers | **Fixed this pass** |
| `blog` | on | Nothing checked it — `/blog` and `/blog/:slug` were always reachable and always linked from the homepage/footer, and always listed in the sitemap | `Blog::__construct()` 404s the whole controller when off; the homepage stops querying posts; the footer link and sitemap entries disappear | **Fixed this pass** |

Central helper added for all of the above: `marvy_feature_enabled($key,
$default)` in `application/helpers/marvy_helper.php` — a single fail-open
lookup so every caller checks a flag the same way, instead of five
different ad-hoc `Feature_flag_model` lookups with five different failure
behaviours.

Proof: `tools/devserver/feature_flags_check.mjs`, 32/32 checks, each
toggling the real flag through the real admin screen and then proving the
real behaviour changed for a real customer session — including that
turning a module off never deletes or hides a customer's *existing* data,
only new activity through that module (the same contract `maintenance_mode`
and `mass_order` already honoured).

## What is intentionally not a setting

- **Referral/affiliate program on/off** has two separate switches that both
  matter for different reasons: the `affiliate_program` feature flag (should
  the mechanism run at all) and `referral_commission_percent = 0` (should it
  pay anything). There is no single `affiliate_enabled` *setting* row — the
  spec's suggestion to add one was considered and rejected as redundant with
  the feature flag that already does exactly that job; adding a second
  switch with overlapping meaning is the kind of "two controls, one truth"
  problem this audit exists to prevent, not create.
- **Multi-currency checkout** is deliberately not a setting yet. Enabling a
  currency and setting a default *display* currency (Admin → Currencies) is
  fully live and audited; actually charging a wallet in a non-base currency
  would need `OrderService`, `TransactionEngine`, `MarketplaceService` and
  `GiftcardService` to all settle in the same currency they display, which
  is a deliberately separate, larger piece of work (see
  `docs/session-22-currency.md` for the base-currency history and
  `tools/devserver/currency_check.mjs` for what is verified so far.
