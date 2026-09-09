# MarvySocials — Artifact 3 (REVISED): Module / Feature Dependency Map — CodeIgniter 3.x

> Revised 2026-08-16 | PHP MVC mapping. Supersedes Node module map.

## 1. Principles

* **CI3 MVC:** `Controller → Library/Service → Model → DB`. Controllers are thin; libraries own business rules; models own queries.
* **No circular deps:** Libraries depend on models + other libraries via `get_instance()` / constructor injection; never `Model → Controller`.
* **Shared kernel:** `MY_Controller`, `MY_Model`, `SecureHttpClient`, `LedgerService`, `PricingService`, `ProviderAdapterFactory`, Redis, Storage are available to all.
* **Financial safety:** `LedgerService` is the only writer to `wallets`/`wallet_transactions`/`ledger_entries`; `Order` controller never does `UPDATE wallets SET balance`.

## 2. Top-Level Graph

```
                    application/
                         │
       ┌─────────────────┼─────────────────┐
       │                 │                 │
   Controllers      Libraries          Models
   (HTTP/CLI)       (domain)          (DB)
       │                 │                 │
       └─────────────────┼─────────────────┘
                         │
              ┌──────────┴──────────┐
              │  Core + Helpers     │
              │  MY_Controller etc. │
              └─────────────────────┘
                         │
              ┌──────────┴──────────┐
              │  External           │
              │  MySQL  Redis  S3   │
              └─────────────────────┘

Views ← Controllers → Libraries → Models → DB
  ↑
assets (Tailwind, JS) — no build step required for PHP
Cron/CLI → Controllers/Cron.php → same Libraries/Models
API /api/v1/* → Controllers/Api_v1.php → same Libraries/Models
```

## 3. Controller Catalog

| Controller | File | Responsibility |
|---|---|---|
| **Home** | `controllers/Home.php` | `/`, `/pricing`, `/about`, `/faq`, `/blog`, `/contact`, `/terms`, `/privacy`; reads `settings.active_homepage` and loads `homepages/{aurora,nexus,pulse}/*` views |
| **Auth** | `controllers/Auth.php` | register/login/logout/forgot/reset/verify-email; blacklist checks; session + remember; audit |
| **Services** | `controllers/Services.php` | public `/services`, `/services/[slug]`, search (FULLTEXT), favorites toggle |
| **Health** | `controllers/Health.php` | `GET /health`, `/health/live`, `/health/ready` (DB, Redis, storage) |
| **Webhooks** | `controllers/Webhooks.php` | `POST /webhook/{gateway}` — signature verify + idempotency, enqueues reconciliation |
| **Api_v1** | `controllers/Api_v1.php` | Reseller API: `GET /services`, `POST /orders`, `GET /orders/:id`, bulk status, `POST /refills`, `GET /balance` — `X-Api-Key` auth + rate limit (Redis) |
| **Cron** | `controllers/Cron.php` | CLI-only (`is_cli()` guard): `provider_sync`, `provider_health`, `order_status`, `refill_status`, `dripfeed`, `subscriptions`, `payment_reconciliation`, `email`, `analytics`. No web access. |
| **Dashboard/** | `controllers/Dashboard/*` | Customer app (all `AuthController` children, `customer` role required) |
| **Admin/** | `controllers/Admin/*` | Admin app (all `AdminController` children, `permissions.*` checks) |

### Dashboard controllers

| File | Routes |
|---|---|
| `Dashboard/Dashboard.php` | `GET /dashboard` — wallet, stats, recent orders/tx, referrals, charts |
| `Dashboard/Orders.php` | `GET /orders`, `GET /orders/:id`, `POST /orders` (new), `POST /orders/mass`, `POST /orders/:id/cancel`, `POST /orders/:id/refill` |
| `Dashboard/Dripfeed.php` | `GET/POST /drip-feed` |
| `Dashboard/Subscriptions.php` | `GET/POST /subscriptions` (+ pause/resume/cancel) |
| `Dashboard/Services.php` | `GET /services`, `GET /favorites`, `POST /favorites/:id` |
| `Dashboard/Wallet.php` | `GET /add-funds`, `GET /transactions`, `POST /payments/initialize` |
| `Dashboard/Tickets.php` | `GET/POST /tickets` (+ messages, attachments) |
| `Dashboard/Referrals.php` | `GET /referrals` |
| `Dashboard/Notifications.php` | `GET /notifications` |
| `Dashboard/Account.php` | `GET /profile`, `POST /profile`, `GET /security`, API keys CRUD |

### Admin controllers (selected)

| File | Permissions |
|---|---|
| `Admin/Dashboard.php` | `reports.view` |
| `Admin/Users.php` | `users.view / users.edit / staff.manage` |
| `Admin/Services.php`, `Admin/Categories.php` | `services.manage` |
| `Admin/Providers.php` | `providers.manage` — also `test_connection`, `sync_services`, `sync_balance` |
| `Admin/Orders.php` | `orders.view / orders.edit` |
| `Admin/Payments.php` | `payments.manage` |
| `Admin/Appearances.php` | `settings.manage` — homepage switcher `AURORA/NEXUS/PULSE` + branding |
| `Admin/Settings.php` | `settings.manage` — all §58 categories |
| `Admin/Audit_logs.php` | `reports.view` |

## 4. Library (Service) Catalog — the Domain Layer

| Library | Owns Tables | Exposed Methods | Depends On |
|---|---|---|---|
| **SecureHttpClient** | — | `get/post` with TLS verify ON, timeout, retry, backoff, request_id, structured log | `curl` (no `VERIFYPEER=false` ever) |
| **ProviderAdapterInterface** | — | `getServices, createOrder, getOrderStatus, getMultipleOrderStatus, getBalance, requestRefill, getRefillStatus, requestCancel` | `SecureHttpClient` |
| **StandardSmmAdapter** | — | implements 8 methods (standard SMM JSON API) | `SecureHttpClient` |
| **MockProviderAdapter** | — | mock for tests/CI | — |
| **ProviderAdapterFactory** | `providers` | `forProvider($providerRow) → Adapter` | config `providers.php` |
| **LedgerService** | `wallets`, `wallet_transactions`, `ledger_entries` | `charge, credit, refund, bonus` — all in `FOR UPDATE` transactions, `DECIMAL` via bcmath, idempotency | `Wallet_model`, `IdempotencyService` |
| **PricingService** | `services`, `service_prices`, `user_service_prices`, `price_groups` | `priceFor($service, $user)` → `user > group > default` | `Service_model` |
| **OrderStateMachine** | `orders`, `order_status_history` | `canTransition(from,to)`, `transition(order,to,source,reason)` | — |
| **ServiceTypeEngine** | `services.metadata` | `fieldsFor(service_type)`, `validate(input)`, `providerPayload(input)` | — |
| **OrderService** *(logic in `Dashboard/Orders.php` + libraries)* | `orders`, `provider_orders` | create flow (§23): validate → blacklist → price → `LedgerService.charge` → insert order → queue provider submission | `PricingService`, `LedgerService`, `ProviderAdapterFactory`, `OrderStateMachine`, `Blacklist_model` |
| **PaymentGatewayInterface** | — | `initializePayment, verifyPayment, handleWebhook, refund, getTransaction` | — |
| **PaymentGatewayFactory** | `payment_methods` | `forMethod($row) → Gateway` | `EncryptionService` |
| **Gateways/*Gateway** | `payment_transactions` | each gateway implements interface | `SecureHttpClient` |
| **ReferralEngine** | `referral_accounts`, `referrals`, `referral_commissions` | `trackRegistration, commissionFor(order)` | `LedgerService` (credit commission) |
| **NotificationService** | `notifications` | `notify(user,type,title,body,data)` → in-app + enqueue email | `EmailService`, `RateLimiter` |
| **EmailService** | `email_templates` | `send(templateKey, to, vars)` — queued, never sync from request | `StorageService` (templates), cron |
| **StorageService** | `media` | `put/get/delete` via S3/R2 (AWS SDK) | `EncryptionService` |
| **EncryptionService** | — | `encrypt/decrypt` (AES-256-GCM, `ENCRYPTION_KEY`) | env |
| **IdempotencyService** | `idempotency_keys`, `payment_webhooks` | `check(key)`, `store(key, response)` | — |
| **RateLimiter** | Redis | `hit(key, limit, window) → allow/429` | `Redis` (`Predis`) |

**Cross-cutting:** `MY_Controller` enforces `csrf`, `session`, `audit`, `rate limit`, `request_id`; `MY_Model` provides `findByPublicId`, `publicId()` (ULID), UTC timestamps.

## 5. Model Catalog (one per table group)

```
User_model, Role_model, Permission_model
Wallet_model, Wallet_transaction_model, Ledger_entry_model
Service_model, Service_category_model, Service_price_model, User_service_price_model, Service_favorite_model
Provider_model, Provider_service_model, Provider_sync_log_model, Provider_health_log_model
Order_model, Order_status_history_model, Provider_order_model
Refill_model, Cancellation_model, Dripfeed_order_model, Dripfeed_run_model, Subscription_model
Payment_method_model, Payment_transaction_model, Payment_webhook_model
Ticket_model (+ messages/attachments), Referral_model, Blog_model, Faq_model, Announcement_model
Audit_log_model, Api_key_model, Blacklist_model, Setting_model, Notification_model, Currency_model, Media_model
```

Models contain **only queries**; no business rules (those live in Libraries).

## 6. Cron / Worker Map (replaces Node BullMQ — §65–66)

Real cron calls CLI controller; no BullMQ. Distributed lock via Redis `SET NX`.

| Cron entry (`cron/crontab.example`) | Controller method (`Cron.php`) | Schedule | What it does |
|---|---|---|---|
| `* * * * * php index.php cron dripfeed` | `Cron::dripfeed()` | every minute | executes due `dripfeed_runs`, creates child orders via `OrderService` |
| `*/2 * * * * php index.php cron order_status` | `Cron::order_status()` | every 2m | groups eligible orders by provider → `getMultipleOrderStatus` → state machine → history → notifications → partial/refund |
| `*/5 * * * * php index.php cron provider_health` | `Cron::provider_health()` | every 5m | `getBalance()` probe → `health_status` + `provider_health_logs` |
| `*/5 * * * * php index.php cron refill_status` | `Cron::refill_status()` | every 5m | `getRefillStatus()` |
| `*/5 * * * * php index.php cron payment_reconciliation` | `Cron::payment_reconciliation()` | every 5m | verifies `PENDING` payments, credits wallet once (idempotent) |
| `*/5 * * * * php index.php cron email_queue` | `Cron::email_queue()` | every 5m | drains email queue (if using DB queue) / sends via `EmailService` |
| `0 * * * * php index.php cron analytics` | `Cron::analytics()` | hourly | aggregates service stats, provider profitability (frozen `provider_charge`) |
| `*/60 * * * * php index.php cron provider_sync` | `Cron::provider_sync()` | every 60m (per-provider `sync_interval_minutes`) | `getServices()` + `getBalance()`, never overwrites admin overrides (`provider_source_snapshot` vs effective) |
| (on-demand) | `Cron::order_submit` / called inline | on order create | `createOrder()` with retry, backoff, idempotency; writes `provider_order_id` → `PROCESSING` |

* `Cron` model: jobs have `job_id`, `correlation_id`, structured logging, failure reason, dead-letter via `audit_logs` + `provider_sync_logs`.
* Old PHP cron URL anti-pattern (`/cron/order_status?key=...`) is **not** recreated — CLI only, `is_cli()` guard returns 404 on web.

## 7. View / Homepage System

```
layouts/public.php  — used by Home, Services, Blog, FAQ (SEO: meta, OG, canonical, sitemap, robots)
layouts/dashboard.php — customer app (sidebar + mobile bottom nav)
layouts/admin.php     — admin app (sidebar §78, health cards, charts)

Home.php controller:
  $active = $this->Setting_model->get('active_homepage'); // AURORA|NEXUS|PULSE
  $this->load->view("homepages/" . strtolower($active) . "/hero", $data);
  // preview: /?preview=PULSE (admin only, session check) — renders without persisting

Each homepage folder has 8-12 PHP partials (see folder structure) — genuinely different layouts, not recolors.
Preview in Admin → Appearance → Homepage: iframe loading `/?preview=PULSE` (see wireframes artifact).
```

## 8. Extension Points (§80 — no arbitrary PHP uploads)

| Extension | Interface | How to add |
|---|---|---|
| Payment Gateway | `PaymentGatewayInterface` | new `libraries/Gateways/XGateway.php` + register in `config/payment_gateways.php` |
| Provider Adapter | `ProviderAdapterInterface` | new `libraries/*Adapter.php` + register in `config/providers.php` |
| Notification Channel | `NotificationService::registerChannel()` | implement `send()` in library |
| Service Type | `ServiceTypeEngine::definitions` | add entry: required/optional fields, validation, price calc, payload map |
| Homepage Template | PHP view folder | new `views/homepages/<name>/` + register in `config/marvy.php` + `Home.php` switch |

Extensions are deployed code, not admin uploads.

## 9. Build Order (Sessions 01→20, PHP-adapted)

```
01 Foundation: repo, CI3 skeleton, composer, MySQL, Redis, docker-compose, .env, CI
02 Database: 9 migrations + seed (categories, services, mock provider, demo users/orders) — APP_ENV=demo gated
03 Auth: register/login/logout/forgot/verify, sessions, MFA for admin, RBAC (permissions, not just roles)
04 Design System: Tailwind via PHP layouts, shadcn-inspired components as PHP partials, light/dark, responsive
05 Three Homepages: AURORA/NEXUS/PULSE + switcher + preview
06 Customer Dashboard: wallet, transactions, profile, notifications
07 Service System: catalog, search (FULLTEXT), favorites, ServiceTypeEngine, PricingService
08 Provider System: CRUD, adapters, SecureHttpClient, sync/balance/health, Redis cache provider:{id}:services
09 Order Engine: validation, LedgerService, state machine, provider submission
10 Advanced Orders: mass, drip-feed, subscriptions, refill/cancel, partial
11 Payment System: gateway abstraction, webhooks (signature+idempotency), ledger credit
12 Reseller API: /api/v1 + API keys (hash-only, prefix, IP whitelist) + OpenAPI docs
13 Support+Content: tickets+attachments, blog/faq/announcements, email queue
14 Affiliate: referral code/link, commission engine, ledger
15 Admin: users/staff, services, providers, orders, payments, analytics, appearance, audit
16 Workers: all cron jobs + locks + logging + DLQ
17 Security Hardening: audit per §61 (TLS verify, CSRF, XSS, SQL injection via Query Builder, secrets, rate limit, brute force)
18 Performance: indexes, slow-query review, Redis cache, pagination, provider calls off page render
19 Testing: PHPUnit unit/integration + browser E2E (register→deposit→order→status→refund)
20 Production: migrations, workers, storage, webhooks, SSL, backups, health checks
```

Per §100, module is COMPLETE only when controller+library+model+view+validation+authorization+error handling+tests+audit+docs integrated.
