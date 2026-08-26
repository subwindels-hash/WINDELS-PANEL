# MarvySocials — Artifact 1 (REVISED): Complete Folder Structure — PHP MVC / CodeIgniter 3.x

> Checkpoint 01 — Revised 2026-08-16
> Stack correction: **CodeIgniter 3.x + MySQL/MariaDB + PHP 7.4/8.1 + Redis + S3-compatible storage**
> Supersedes `docs/checkpoint-01/01-folder-structure.md` (Node stack withdrawn).
> Status: REVIEW REQUIRED before implementation.

## 0. Why CI3 Structure

This is a **traditional PHP MVC SMM panel** — not a Node app. Controller → Model → View, CI3 routing, `application/` as source of truth. No `apps/web + apps/api + apps/worker` split. One deployable PHP codebase + cron/CLI workers. Modern practices (composer, secure HTTP client, ledger, adapters) are added *inside* CI3 conventions.

## 1. Repository Root

```
marvysocials/                          # repo root
├── application/
│   ├── cache/                          # CI cache (gitignored, writable)
│   ├── config/
│   │   ├── autoload.php
│   │   ├── config.php                  # base_url, encryption, csrf, sess
│   │   ├── constants.php
│   │   ├── database.php                # MySQL/MariaDB, charset utf8mb4
│   │   ├── email.php                   # SMTP / API mailer config (env)
│   │   ├── hooks.php
│   │   ├── migration.php
│   │   ├── payment_gateways.php        # gateway registry, no secrets
│   │   ├── providers.php               # adapter map
│   │   ├── redis.php                   # host/port/prefix for cache/queue
│   │   ├── routes.php                  # see §6
│   │   ├── storage.php                 # S3/R2 bucket, region (keys via .env)
│   │   └── marvy.php                 # app-level: branding, homepage, currency, maintenance
│   ├── controllers/
│   │   ├── Home.php                    # public site + homepage router (AURORA/NEXUS/PULSE)
│   │   ├── Auth.php                    # login/register/logout/forgot/verify
│   │   ├── Services.php                # public /services + /services/[slug]
│   │   ├── Api_v1.php                  # reseller API: /api/v1/* (ApiKey auth)
│   │   ├── Health.php                  # /health, /health/live, /health/ready
│   │   ├── Cron.php                    # CLI-only cron entry (guarded, no web access)
│   │   ├── Webhooks.php                # POST /webhook/{gateway} (signature verify)
│   │   ├── Dashboard/
│   │   │   ├── Dashboard.php           # /dashboard
│   │   │   ├── Orders.php              # new-order, mass-order, history
│   │   │   ├── Dripfeed.php
│   │   │   ├── Subscriptions.php
│   │   │   ├── Services.php            # catalog + favorites + search (customer view)
│   │   │   ├── Wallet.php              # add-funds, transactions
│   │   │   ├── Tickets.php
│   │   │   ├── Referrals.php
│   │   │   ├── Notifications.php
│   │   │   └── Account.php             # profile, security, api keys
│   │   └── Admin/
│   │       ├── Dashboard.php
│   │       ├── Users.php               # customers + staff
│   │       ├── Services.php
│   │       ├── Categories.php
│   │       ├── Providers.php           # CRUD + test + sync + health
│   │       ├── Orders.php              # search/filter/status/refund/notes
│   │       ├── Payments.php
│   │       ├── Refills.php
│   │       ├── Cancellations.php
│   │       ├── Dripfeed.php
│   │       ├── Subscriptions.php
│   │       ├── Tickets.php
│   │       ├── Affiliates.php
│   │       ├── Reports.php
│   │       ├── Analytics.php
│   │       ├── Blog.php
│   │       ├── Faq.php
│   │       ├── Announcements.php
│   │       ├── Staff.php
│   │       ├── Audit_logs.php
│   │       ├── Appearances.php         # branding + homepage switcher (AURORA/NEXUS/PULSE)
│   │       ├── Settings.php            # general/currency/payments/email/security/...
│   │       ├── Blacklist.php
│   │       └── Media.php
│   ├── core/
│   │   ├── MY_Controller.php           # BaseController, AuthController, AdminController, ApiController, CronController
│   │   ├── MY_Model.php                # BaseModel (public_id helpers, timestamps UTC, decimal helpers)
│   │   └── MY_Loader.php               # (if needed)
│   ├── helpers/
│   │   ├── marvy_helper.php          # public_id, money_format, uuid/ulid
│   │   ├── currency_helper.php
│   │   └── audit_helper.php
│   ├── hooks/
│   │   └── AuditHook.php               # post_controller_constructor for audit on sensitive routes
│   ├── language/
│   │   ├── english/
│   │   │   ├── marvy_lang.php        # all UI strings (i18n-ready, per §95)
│   │   │   ├── validation_lang.php
│   │   │   └── email_lang.php
│   │   └── _template/                  # fr, ar, es skeletons
│   ├── libraries/
│   │   ├── SecureHttpClient.php        # ★ centralized cURL client: TLS verify ON, timeout, retry, backoff, request_id, logging
│   │   ├── ProviderAdapterInterface.php
│   │   ├── StandardSmmAdapter.php      # implements getServices/createOrder/getOrderStatus/getBalance/requestRefill/requestCancel
│   │   ├── MockProviderAdapter.php     # for tests/CI
│   │   ├── ProviderAdapterFactory.php
│   │   ├── PricingService.php          # user > group > default priority (never duplicated)
│   │   ├── OrderStateMachine.php       # valid transitions, guards
│   │   ├── LedgerService.php           # wallet charge/credit/refund with transactions + ledger_entries
│   │   ├── PaymentGatewayInterface.php
│   │   ├── PaymentGatewayFactory.php
│   │   ├── Gateways/
│   │   │   ├── StripeGateway.php
│   │   │   ├── PaypalGateway.php
│   │   │   ├── FlutterwaveGateway.php
│   │   │   ├── RazorpayGateway.php
│   │   │   ├── PaystackGateway.php
│   │   │   └── CoinpaymentsGateway.php
│   │   ├── ServiceTypeEngine.php       # dynamic form definitions per service_type (fields, validation, payload map)
│   │   ├── ReferralEngine.php
│   │   ├── NotificationService.php     # in-app + queued email
│   │   ├── EmailService.php            # EmailTemplate + queue
│   │   ├── StorageService.php          # S3/R2 via AWS SDK (avatars, blog, ticket attachments)
│   │   ├── EncryptionService.php       # at-rest encryption for provider keys/payment secrets (ENCRYPTION_KEY)
│   │   ├── IdempotencyService.php
│   │   └── RateLimiter.php             # Redis-backed per-user/per-ip/per-endpoint
│   ├── models/
│   │   ├── User_model.php
│   │   ├── Role_model.php
│   │   ├── Permission_model.php
│   │   ├── Wallet_model.php
│   │   ├── Wallet_transaction_model.php
│   │   ├── Ledger_entry_model.php
│   │   ├── Service_model.php
│   │   ├── Service_category_model.php
│   │   ├── Service_price_model.php
│   │   ├── User_service_price_model.php
│   │   ├── Service_favorite_model.php
│   │   ├── Provider_model.php
│   │   ├── Provider_service_model.php
│   │   ├── Provider_sync_log_model.php
│   │   ├── Provider_health_log_model.php
│   │   ├── Order_model.php
│   │   ├── Order_status_history_model.php
│   │   ├── Provider_order_model.php
│   │   ├── Refill_model.php
│   │   ├── Cancellation_model.php
│   │   ├── Dripfeed_order_model.php
│   │   ├── Dripfeed_run_model.php
│   │   ├── Subscription_model.php
│   │   ├── Payment_method_model.php
│   │   ├── Payment_transaction_model.php
│   │   ├── Payment_webhook_model.php
│   │   ├── Ticket_model.php
│   │   ├── Ticket_message_model.php
│   │   ├── Referral_model.php
│   │   ├── Blog_model.php
│   │   ├── Faq_model.php
│   │   ├── Announcement_model.php
│   │   ├── Media_model.php
│   │   ├── Audit_log_model.php
│   │   ├── Api_key_model.php
│   │   ├── Blacklist_model.php
│   │   ├── Setting_model.php
│   │   ├── Notification_model.php
│   │   └── Currency_model.php
│   ├── views/
│   │   ├── layouts/
│   │   │   ├── public.php              # public layout (nav + footer)
│   │   │   ├── dashboard.php           # customer layout (sidebar + topbar + mobile bottom nav)
│   │   │   └── admin.php               # admin layout (sidebar per §78)
│   │   ├── partials/
│   │   │   ├── public_nav.php
│   │   │   ├── footer.php
│   │   │   ├── dashboard_sidebar.php
│   │   │   ├── admin_sidebar.php
│   │   │   ├── mobile_bottom_nav.php
│   │   │   ├── pagination.php
│   │   │   ├── skeletons.php
│   │   │   ├── empty_state.php
│   │   │   └── toast.php
│   │   ├── homepages/
│   │   │   ├── aurora/                 # Homepage 01 — AURORA
│   │   │   │   ├── hero.php
│   │   │   │   ├── stats.php
│   │   │   │   ├── popular_services.php
│   │   │   │   ├── how_it_works.php
│   │   │   │   ├── platforms.php
│   │   │   │   ├── why_choose.php
│   │   │   │   ├── categories.php
│   │   │   │   ├── pricing.php
│   │   │   │   ├── faq.php
│   │   │   │   ├── testimonials.php
│   │   │   │   └── cta.php
│   │   │   ├── nexus/                  # Homepage 02 — NEXUS (dark enterprise)
│   │   │   │   ├── hero.php
│   │   │   │   ├── network_viz.php
│   │   │   │   ├── stats.php
│   │   │   │   ├── service_explorer.php
│   │   │   │   ├── automation.php
│   │   │   │   ├── reseller_api.php
│   │   │   │   └── ...
│   │   │   └── pulse/                  # Homepage 03 — PULSE (bright marketplace)
│   │   │       ├── hero_search.php
│   │   │       ├── categories.php
│   │   │       ├── trending.php
│   │   │       ├── fast_order.php
│   │   │       └── ...
│   │   ├── public/
│   │   │   ├── services.php
│   │   │   ├── service_detail.php
│   │   │   ├── pricing.php
│   │   │   ├── about.php
│   │   │   ├── faq.php
│   │   │   ├── blog_list.php
│   │   │   ├── blog_detail.php
│   │   │   ├── contact.php
│   │   │   ├── terms.php
│   │   │   └── privacy.php
│   │   ├── auth/
│   │   │   ├── login.php
│   │   │   ├── register.php
│   │   │   ├── forgot_password.php
│   │   │   └── verify_email.php
│   │   ├── dashboard/
│   │   │   ├── home.php
│   │   │   ├── new_order.php           # dynamic ServiceType form + price calc
│   │   │   ├── mass_order.php
│   │   │   ├── orders.php
│   │   │   ├── order_detail.php
│   │   │   ├── services.php
│   │   │   ├── wallet.php
│   │   │   ├── transactions.php
│   │   │   ├── tickets.php
│   │   │   ├── referrals.php
│   │   │   └── account/
│   │   ├── admin/
│   │   │   ├── dashboard.php           # revenue/orders/customers/margin + charts + provider health
│   │   │   ├── orders/
│   │   │   ├── services/
│   │   │   ├── providers/
│   │   │   ├── users/
│   │   │   ├── payments/
│   │   │   ├── analytics/
│   │   │   ├── appearance/
│   │   │   │   └── homepage.php        # AURORA/NEXUS/PULSE switcher + preview
│   │   │   └── settings/
│   │   └── errors/
│   ├── migrations/                     # CI3 migrations — one per table group (see Artifact 2)
│   │   ├── 001_identity.php
│   │   ├── 002_wallets_ledger.php
│   │   ├── 003_services.php
│   │   ├── 004_providers.php
│   │   ├── 005_orders.php
│   │   ├── 006_refill_cancel_drip_subscription.php
│   │   ├── 007_payments.php
│   │   ├── 008_support_content.php
│   │   └── 009_security_system.php
│   └── third_party/
│       └── aws-sdk-php/                # S3 client (via composer vendor)
├── assets/
│   ├── css/
│   │   ├── tailwind.css                # built via Tailwind CLI
│   │   └── app.css
│   ├── js/
│   │   ├── app.js
│   │   ├── new-order.js                # dynamic form engine (no provider calls from frontend)
│   │   ├── search.js
│   │   └── admin.js
│   ├── images/
│   └── vendor/                         # lucide, recharts (CDN or bundled)
├── system/                             # CodeIgniter 3.x core (untouched)
├── vendor/                             # composer: aws-sdk-php, predis/predis, ramsey/ulid, etc.
├── storage/
│   ├── logs/
│   ├── cache/
│   └── uploads/                        # tmp only; canonical store is S3/R2
├── cron/
│   ├── crontab.example                 # real cron lines calling `php index.php cron <job>`
│   └── README.md
├── docker/
│   ├── php.Dockerfile                  # php-fpm 8.1 + extensions (pdo_mysql, redis, curl, openssl)
│   ├── nginx.conf
│   └── mysql/
├── docs/
│   ├── checkpoint-01/                  # superseded Node artifacts (kept for history)
│   ├── checkpoint-01-php/              # ← this revision (PHP MVC)
│   ├── architecture.md
│   ├── database.md
│   ├── api.md
│   └── ...
├── tests/
│   ├── unit/                           # PHPUnit: pricing, wallet, state machine, adapters
│   ├── integration/                    # DB, Redis, webhooks
│   └── e2e/                            # browser: register → deposit → order → status
├── .env.example                        # never .env with secrets; keys via env
├── composer.json
├── package.json                        # tailwind build only (optional)
├── tailwind.config.js
├── phpunit.xml
├── index.php                           # CI front controller
└── README.md
```

## 2. Environment & Config

```
DATABASE_URL / DB_HOST, DB_USER, DB_PASS, DB_NAME  (MySQL/MariaDB)
REDIS_URL / REDIS_HOST, REDIS_PORT
ENCRYPTION_KEY              # AES-256-GCM for provider keys/payment secrets at rest
JWT_SECRET / SESSION_SECRET # CI session + API key signing
STORAGE_ENDPOINT, STORAGE_ACCESS_KEY, STORAGE_SECRET_KEY, STORAGE_BUCKET, STORAGE_REGION
SMTP_HOST / SMTP_USER / SMTP_PASS  (or EMAIL_API_KEY)
APP_URL, APP_ENV=development|staging|production|demo
```

* `.env` gitignored; `.env.example` committed. CI3 reads env via `$_ENV` / `getenv()` in `config/*.php`.
* No `MARVYSOCIALS_LICENSE_KEY`, `PURCHASE_CODE`, `LICENSE_SERVER` — installer has no license step (§81).

## 3. Frontend Build

* Views are **PHP views** (CI3 ` $this->load->view()` ), not React. Tailwind CSS via CLI (`npx tailwindcss -i assets/css/app.css -o assets/css/tailwind.css`).
* Icons: Lucide via CDN or bundled SVG. Charts: Chart.js or Recharts-equivalent via CDN (admin/customer dashboards).
* Responsive: mobile bottom nav, collapsible forms, swipeable filters — all in PHP layouts + vanilla JS.
* PWA: `manifest.json` + `service-worker.js` in `assets/`, linked from layouts.

## 4. What This Structure Deliberately Avoids

* No `apps/web`, `apps/api`, `apps/worker` split.
* No Prisma / NestJS / Next.js.
* No `plugins/` that executes uploaded PHP/JS.
* No `license/` module or Envato activation.
* No `curl_setopt(..., CURLOPT_SSL_VERIFYPEER, false)` — `SecureHttpClient` enforces `VERIFYHOST=2, VERIFYPEER=true` in production (§62).
