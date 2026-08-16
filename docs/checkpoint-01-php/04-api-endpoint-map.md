# WINDELS PANEL — Artifact 4 (REVISED): Complete API / Route Map — PHP MVC (CodeIgniter 3.x)

> Revised 2026-08-16 | Base: CI3 `config/routes.php` | Reseller API: `/api/v1` via `Api_v1` controller
> Auth: CI session cookie (web) + `X-Api-Key` (reseller). Standard error shape (§73). CSRF on cookie-mutating POSTs; JWT not used (CI session).

## 0. Conventions

* CI3 routing in `application/config/routes.php` → controllers. Web routes are PHP pages; API routes return JSON `{success, data, error: {code,message,requestId}}`.
* Pagination: `?page=1&limit=20` → `{data, meta:{page,limit,total,totalPages}}` (CI Pagination lib).
* Public IDs (ULID `public_id`) in URLs — never sequential `id`.
* Rate limiting via Redis (`RateLimiter` lib) — `429` with `Retry-After`.
* Webhooks: signature verification + `payment_webhooks(gateway_type,event_id)` unique.
* Never expose: provider `api_key_encrypted`, payment secrets, `password_hash`, stack traces.
* Public pages are SSR PHP views (SEO: meta, OG, Twitter, canonical, sitemap, robots).

---

## 1. Health & System

| Method | Route | Controller | Auth | Description |
|---|---|---|---|---|
| GET | `/health` | `Health::index` | public | liveness + readiness summary |
| GET | `/health/live` | `Health::live` | public | process alive |
| GET | `/health/ready` | `Health::ready` | public | MySQL + Redis + storage checks |
| GET | `/sitemap.xml` | `Home::sitemap` | public | SEO sitemap |
| GET | `/robots.txt` | `Home::robots` | public | robots |
| GET | `/manifest.json` | `Home::manifest` | public | PWA manifest |

---

## 2. Public Website (§11)

| Method | Route | Controller | Auth |
|---|---|---|---|
| GET | `/` | `Home::index` — reads `settings.active_homepage` (AURORA/NEXUS/PULSE) | public |
| GET | `/?preview=PULSE` | `Home::index` (admin session required) | admin preview, no persist |
| GET | `/services` | `Services::index` — filterable, paginated, FULLTEXT search | public |
| GET | `/services/:slug` | `Services::detail` | public |
| GET | `/pricing` | `Home::pricing` | public |
| GET | `/about` | `Home::about` | public |
| GET | `/faq` | `Home::faq` | public |
| GET | `/blog` | `Home::blog` | public |
| GET | `/blog/:slug` | `Home::blog_detail` | public |
| GET | `/contact` | `Home::contact` (GET form + POST) | public |
| POST | `/contact` | `Home::contact_post` | public + rate limit |
| GET | `/terms` | `Home::terms` | public |
| GET | `/privacy` | `Home::privacy` | public |
| GET | `/refund-policy` | `Home::refund_policy` | public |
| GET | `/acceptable-use` | `Home::acceptable_use` | public |

---

## 3. Auth (§43 + §46)

| Method | Route | Controller | Auth | Notes |
|---|---|---|---|---|
| GET | `/login` | `Auth::login` | guest | form |
| POST | `/login` | `Auth::login_post` | guest | checks `blacklisted_ips/emails`, `login_attempts`, rate limit, brute-force |
| GET | `/register` | `Auth::register` | guest | referral code `?ref=CODE` tracked |
| POST | `/register` | `Auth::register_post` | guest | creates `users` + `wallets` + `referral_accounts` |
| GET | `/logout` | `Auth::logout` | auth | destroys session |
| GET | `/forgot-password` | `Auth::forgot_password` | guest | |
| POST | `/forgot-password` | `Auth::forgot_password_post` | guest | queued email |
| GET | `/reset-password/:token` | `Auth::reset_password` | guest (token) | |
| POST | `/reset-password/:token` | `Auth::reset_password_post` | guest | Argon2id hash |
| GET | `/verify-email/:token` | `Auth::verify_email` | token | |
| POST | `/verify-email/resend` | `Auth::verify_email_resend` | auth | rate limited |
| POST | `/auth/mfa/setup` | `Auth::mfa_setup` | auth (admin) | TOTP |
| POST | `/auth/mfa/verify` | `Auth::mfa_verify` | auth | |

Session: CI `sess` driver (database or Redis), `HttpOnly`, `SameSite=Lax`, `Secure` in production, CSRF token on all POSTs.

---

## 4. Customer Dashboard (§12–13)

All under `/dashboard` — `Dashboard/*` controllers, `AuthController` + `CUSTOMER` role.

| Method | Route | Controller |
|---|---|---|
| GET | `/dashboard` | `Dashboard/Dashboard::index` — wallet balance, total spent/orders, active/completed/pending/failed, recent orders/tx, favorites, charts (spending, volume) |
| GET | `/dashboard/new-order` | `Dashboard/Orders::new_order` (GET form) |
| POST | `/dashboard/orders` | `Dashboard/Orders::create` — validates service, blacklist, pricing (`PricingService`), `LedgerService::charge`, state machine `PENDING` → queue inline `order_submit` |
| GET | `/dashboard/orders` | `Dashboard/Orders::index` — search/filter/sort/paginate |
| GET | `/dashboard/orders/:public_id` | `Dashboard/Orders::detail` — status history (never provider secrets) |
| POST | `/dashboard/orders/mass` | `Dashboard/Orders::mass_create` — per-row validation, returns `{successful, failed}` |
| POST | `/dashboard/orders/:public_id/cancel` | `Dashboard/Orders::request_cancel` |
| POST | `/dashboard/orders/:public_id/refill` | `Dashboard/Orders::request_refill` |
| GET | `/dashboard/drip-feed` | `Dashboard/Dripfeed::index` |
| POST | `/dashboard/drip-feed` | `Dashboard/Dripfeed::create` — total/per-run/runs/interval |
| GET | `/dashboard/subscriptions` | `Dashboard/Subscriptions::index` |
| POST | `/dashboard/subscriptions` | `Dashboard/Subscriptions::create` |
| GET | `/dashboard/services` | `Dashboard/Services::index` |
| GET | `/dashboard/favorites` | `Dashboard/Services::favorites` |
| POST | `/dashboard/favorites/:public_id` | `Dashboard/Services::favorite_add` |
| DELETE | `/dashboard/favorites/:public_id` | `Dashboard/Services::favorite_remove` (POST with `_method=DELETE` or AJAX) |
| GET | `/dashboard/add-funds` | `Dashboard/Wallet::add_funds` — gateway list |
| POST | `/dashboard/payments/initialize` | `Dashboard/Wallet::initialize_payment` — `PaymentGateway::initializePayment` |
| GET | `/dashboard/transactions` | `Dashboard/Wallet::transactions` — `wallet_transactions` + `ledger_entries` |
| GET | `/dashboard/tickets` | `Dashboard/Tickets::index` |
| GET | `/dashboard/tickets/:public_id` | `Dashboard/Tickets::detail` |
| POST | `/dashboard/tickets` | `Dashboard/Tickets::create` |
| POST | `/dashboard/tickets/:public_id/messages` | `Dashboard/Tickets::reply` (multipart for attachments → `StorageService`) |
| GET | `/dashboard/api` | `Dashboard/Account::api_keys` — list, generate, revoke, IP whitelist |
| GET | `/dashboard/referrals` | `Dashboard/Referrals::index` — code, link, stats, commissions |
| GET | `/dashboard/notifications` | `Dashboard/Notifications::index` |
| GET | `/dashboard/profile` | `Dashboard/Account::profile` |
| POST | `/dashboard/profile` | `Dashboard/Account::profile_update` |
| GET | `/dashboard/security` | `Dashboard/Account::security` |

---

## 5. Admin (§78–79)

All under `/admin` — `Admin/*` controllers, `AdminController`, permission checks (`$this->auth->can('orders.view')`).

| Method | Route | Controller | Permission |
|---|---|---|---|
| GET | `/admin` | `Admin/Dashboard::index` — revenue/orders/customers/provider cost/gross profit + revenue/order charts + provider health + top services/payments/tickets | `reports.view` |
| GET | `/admin/orders` | `Admin/Orders::index` — search/filter by status/provider/service/customer/provider_order_id, pagination | `orders.view` |
| GET | `/admin/orders/:public_id` | `Admin/Orders::detail` — history, provider payload, notes | `orders.view` |
| POST | `/admin/orders/:public_id/status` | `Admin/Orders::update_status` — manual status + audit + history | `orders.edit` |
| POST | `/admin/orders/:public_id/refund` | `Admin/Orders::refund` — via `LedgerService::refund` + audit | `payments.manage` |
| POST | `/admin/orders/:public_id/notes` | `Admin/Orders::add_note` | `orders.edit` |
| GET | `/admin/services` | `Admin/Services::index` | `services.manage` |
| POST | `/admin/services` | `Admin/Services::create` | `services.manage` |
| POST | `/admin/services/:public_id` | `Admin/Services::update` — audit, `provider_source_snapshot` preserved | `services.manage` |
| GET | `/admin/categories` | `Admin/Categories::index` | `services.manage` |
| GET | `/admin/providers` | `Admin/Providers::index` | `providers.manage` |
| POST | `/admin/providers` | `Admin/Providers::create` — `api_key_encrypted` via `EncryptionService` | `providers.manage` |
| POST | `/admin/providers/:public_id/test` | `Admin/Providers::test_connection` — live `SecureHttpClient` call | `providers.manage` |
| POST | `/admin/providers/:public_id/sync` | `Admin/Providers::sync_services` | `providers.manage` |
| POST | `/admin/providers/:public_id/sync-balance` | `Admin/Providers::sync_balance` | `providers.manage` |
| GET | `/admin/customers` | `Admin/Users::customers` | `users.view` |
| GET | `/admin/customers/:public_id` | `Admin/Users::detail` — wallet, orders, tx, audit | `users.view` |
| POST | `/admin/customers/:public_id/adjust-balance` | `Admin/Users::adjust_balance` — ledger + audit | `payments.manage` |
| POST | `/admin/customers/:public_id/price` | `Admin/Users::set_user_price` — `user_service_prices` | `services.manage` |
| GET | `/admin/wallets` | `Admin/Users::wallets` | `payments.manage` |
| GET | `/admin/payments` | `Admin/Payments::index` | `payments.manage` |
| GET | `/admin/refills` | `Admin/Refills::index` | `orders.view` |
| GET | `/admin/cancellations` | `Admin/Cancellations::index` | `orders.view` |
| GET | `/admin/drip-feed` | `Admin/Dripfeed::index` | `orders.view` |
| GET | `/admin/subscriptions` | `Admin/Subscriptions::index` | `orders.view` |
| GET | `/admin/tickets` | `Admin/Tickets::index` | `tickets.manage` |
| GET | `/admin/affiliates` | `Admin/Affiliates::index` | `reports.view` |
| GET | `/admin/blog` (+ CRUD) | `Admin/Blog::*` | `settings.manage` |
| GET | `/admin/faq` (+ CRUD) | `Admin/Faq::*` | `settings.manage` |
| GET | `/admin/announcements` | `Admin/Announcements::*` | `settings.manage` |
| GET | `/admin/staff` | `Admin/Staff::index` | `staff.manage` |
| GET | `/admin/audit-logs` | `Admin/Audit_logs::index` | `reports.view` |
| GET | `/admin/blacklist` | `Admin/Blacklist::index` — email/ip/link add/edit/import/export | `settings.manage` |
| GET | `/admin/reports` | `Admin/Reports::index` | `reports.view` |
| GET | `/admin/analytics` | `Admin/Analytics::index` — charts §55 | `reports.view` |
| GET | `/admin/appearance` | `Admin/Appearances::index` | `settings.manage` |
| GET | `/admin/appearance/homepage` | `Admin/Appearances::homepage` — AURORA/NEXUS/PULSE switcher + preview iframe | `settings.manage` |
| POST | `/admin/appearance/homepage` | `Admin/Appearances::homepage_save` — updates `settings.active_homepage` | `settings.manage` |
| GET | `/admin/settings` | `Admin/Settings::index` — §58 categories | `settings.manage` |
| POST | `/admin/settings` | `Admin/Settings::save` — audit `before/after` | `settings.manage` |
| POST | `/admin/media/upload` | `Admin/Media::upload` — validates MIME/extension/size/signature, stores via `StorageService` | `settings.manage` |

---

## 6. Payments & Webhooks (§40–42)

| Method | Route | Controller | Auth | Notes |
|---|---|---|---|---|
| GET | `/dashboard/payments/methods` | `Dashboard/Wallet::methods` | auth | active gateways (public config only) |
| POST | `/dashboard/payments/initialize` | `Dashboard/Wallet::initialize_payment` | auth | `Idempotency-Key` header supported → creates `payment_transactions` `CREATED→PENDING` |
| GET | `/dashboard/payments/:public_id` | `Dashboard/Wallet::payment_status` | auth (own) | |
| POST | `/webhook/stripe` | `Webhooks::stripe` | public (signature) | `Stripe-Signature` verify → idempotent `payment_webhooks` → `PENDING→VERIFIED→WALLET_CREDITED` via `LedgerService` |
| POST | `/webhook/paypal` | `Webhooks::paypal` | public | likewise |
| POST | `/webhook/flutterwave` | `Webhooks::flutterwave` | public | |
| POST | `/webhook/razorpay` | `Webhooks::razorpay` | public | |
| POST | `/webhook/paystack` | `Webhooks::paystack` | public | |
| POST | `/webhook/coinpayments` | `Webhooks::coinpayments` | public | |

* Never credit on browser redirect — only on verified server-side webhook (`signature_valid=1` + `processed` unique).
* Duplicate webhook → `uq_gateway_event` prevents double ledger credit (return 200).

---

## 7. Reseller API — `/api/v1` via `Api_v1` (§37–39)

Auth: `X-Api-Key: wind_...` → `Api_key_model` (hash lookup), `revoked_at IS NULL`, IP whitelist, `RateLimiter`, `api_usage_logs`.

| Method | Route | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/services` | ApiKey | list services (reseller price via `PricingService`) |
| GET | `/api/v1/services/:public_id` | ApiKey | single service |
| GET | `/api/v1/balance` | ApiKey | wallet balance + currency |
| POST | `/api/v1/orders` | ApiKey | create order (all service types), `Idempotency-Key` supported |
| GET | `/api/v1/orders/:public_id` | ApiKey | order status + history |
| POST | `/api/v1/orders/status` | ApiKey | bulk: `{orderIds:[...]}` → statuses |
| POST | `/api/v1/refills` | ApiKey | `{orderId}` → refill request |
| GET | `/api/v1/refills/:public_id` | ApiKey | refill status |
| POST | `/api/v1/cancellations` | ApiKey | `{orderId}` → cancel request |
| GET | `/api/v1/orders` (filtered) | ApiKey | list own orders |

**Rate limiting:** per-key + per-IP via Redis; `429 {code: RATE_LIMITED, retryAfter}`.

**Docs:** `GET /api/docs` (OpenAPI via `application/views/api/docs.php` + `GET /api/docs/json` machine-readable) — generated from `Api_v1` phpdoc + Zod-equivalent validation rules.

### API Keys (customer dashboard)

| Method | Route | Controller |
|---|---|---|
| GET | `/dashboard/api/keys` | `Dashboard/Account::api_keys` |
| POST | `/dashboard/api/keys` | `Dashboard/Account::api_key_create` — returns raw key once, stores `key_hash` |
| DELETE | `/dashboard/api/keys/:public_id` | `Dashboard/Account::api_key_revoke` |
| POST | `/dashboard/api/keys/:public_id/regenerate` | `Dashboard/Account::api_key_regenerate` |

---

## 8. `config/routes.php` Excerpt (illustrative)

```php
$route['default_controller'] = 'home';
$route['health'] = 'health/index';
$route['health/live'] = 'health/live';
$route['health/ready'] = 'health/ready';

// public
$route['services'] = 'services/index';
$route['services/(:any)'] = 'services/detail/$1';
$route['pricing'] = 'home/pricing';
$route['about'] = 'home/about';
$route['faq'] = 'home/faq';
$route['blog'] = 'home/blog';
$route['blog/(:any)'] = 'home/blog_detail/$1';
$route['terms'] = 'home/terms';
$route['privacy'] = 'home/privacy';

// auth
$route['login'] = 'auth/login';
$route['register'] = 'auth/register';
$route['logout'] = 'auth/logout';
$route['forgot-password'] = 'auth/forgot_password';

// dashboard (group — auth filter in MY_Controller)
$route['dashboard'] = 'dashboard/dashboard/index';
$route['dashboard/orders'] = 'dashboard/orders/index';
$route['dashboard/orders/(:any)'] = 'dashboard/orders/detail/$1';
$route['dashboard/new-order'] = 'dashboard/orders/new_order';

// admin (group — admin filter)
$route['admin'] = 'admin/dashboard/index';
$route['admin/orders'] = 'admin/orders/index';
$route['admin/appearance/homepage'] = 'admin/appearances/homepage';

// reseller API
$route['api/v1/services'] = 'api_v1/services';
$route['api/v1/orders'] = 'api_v1/orders';
$route['api/v1/balance'] = 'api_v1/balance';
$route['api/v1/refills'] = 'api_v1/refills';
$route['api/docs'] = 'api_v1/docs';

// webhooks
$route['webhook/(:any)'] = 'webhooks/index/$1';
```

---

## 9. Error Standard (§73)

All JSON APIs:

```json
{
  "success": false,
  "error": { "code": "INSUFFICIENT_BALANCE", "message": "Insufficient wallet balance.", "requestId": "req_..." }
}
```

* CI `MY_Controller::json_error($code,$message,$http)` — never leaks stack traces; logs via `log_message('error', ...)` with `request_id`.
* Validation errors: `code: VALIDATION_ERROR`, `details: {field: message}`.

## 10. Idempotency

* `Idempotency-Key` header on `POST /dashboard/orders`, `POST /api/v1/orders`, `POST /dashboard/payments/initialize`.
* Stored in `idempotency_keys` + unique constraints on `orders.idempotency_key`, `wallet_transactions.idempotency_key`, `payment_transactions.idempotency_key`.
* Retry of provider submission uses `Order.idempotency_key` to prevent duplicate provider orders.
