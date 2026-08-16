# WINDELS PANEL — Artifact 3: Complete Module / Feature Dependency Map

> Checkpoint 01 | Covers: NestJS modules, Next.js features, queues/workers, and dependency direction

## 1. Principles

* **No circular dependencies.** Direction is strictly: `web → api-client → api` ; `worker → queues → api modules (service layer)` reused via shared packages.
* **Single responsibility per module.** Each NestJS module owns its Prisma models + validation + authorization.
* **Shared kernel:** `PrismaModule`, `RedisModule`, `QueuesModule`, `SecureHttpModule`, `StorageModule` are global.
* **All cross-module calls go through service interfaces**, never direct DB access across modules (e.g., Orders never writes to Wallet directly — calls `LedgerService.charge()`).

## 2. Top-Level Dependency Graph

```
┌─────────────────────────────────────────────────────────────┐
│                        PACKAGES                             │
│  @windels/types  @windels/validation  @windels/database     │
│  @windels/ui  @windels/config  @windels/api-client          │
└──────────────────────┬──────────────────────────────────────┘
                       │ imported by
         ┌─────────────┼─────────────┐
         ▼             ▼             ▼
   apps/web        apps/api      apps/worker
```

### apps/api internal

```
AppModule
 ├── ConfigModule (Zod-validated env)
 ├── PrismaModule (global)
 ├── RedisModule (global) — ioredis
 ├── QueuesModule (BullMQ) — registers all queues
 ├── HealthModule
 ├── Common (Guards, Interceptors, Filters, Pipes)
 ├── AuthModule
 ├── UsersModule ─┬─→ WalletsModule (ledger)
 │                └─→ RolesModule (RBAC)
 ├── WalletsModule ─→ LedgerService (authoritative)
 ├── PaymentsModule ─→ WalletsModule (credit on verified webhook)
 ├── ServicesCatalogModule ─→ ProvidersModule (read-only sync data)
 ├── ProvidersModule ─→ SecureHttpModule, QueuesModule
 ├── OrdersModule ─┬─→ ServicesCatalogModule (validate + pricing)
 │                 ├─→ ProvidersModule (adapter)
 │                 ├─→ WalletsModule (charge/refund)
 │                 ├─→ QueuesModule (order.submit)
 │                 └─→ NotificationsModule
 ├── DripFeedModule ─→ OrdersModule, QueuesModule
 ├── SubscriptionsModule ─→ OrdersModule, QueuesModule
 ├── RefillsModule ─→ OrdersModule, ProvidersModule
 ├── CancellationsModule ─→ OrdersModule, ProvidersModule, WalletsModule
 ├── TicketsModule ─→ StorageModule, NotificationsModule
 ├── AffiliateModule ─→ WalletsModule (commission credit), OrdersModule (hooks)
 ├── Blog / Faq / Announcements / Media / Blacklist / Search / Analytics / Settings / Branding / Homepage
 ├── NotificationsModule ─→ QueuesModule (email queue), EmailModule
 ├── EmailModule ─→ StorageModule (templates)
 ├── ResellerApiModule ─→ Auth (ApiKeyGuard) + reuses Orders/Services/Wallets services
 └── AuditModule (global interceptor; listens to sensitive actions)
```

### apps/worker internal

```
WorkerAppModule
 ├── PrismaModule, RedisModule, QueuesModule (same as api)
 ├── Processors (one per queue — see below)
 └── Schedulers (BullMQ repeatable jobs + Redlock for cron replacement)
```

## 3. NestJS Module Catalog (apps/api/src/modules/*)

| Module | Owns Tables | Exposes Service | Depends On | Queue Producer? |
|---|---|---|---|---|
| **Auth** | users, user_sessions, refresh_tokens, mfa_methods, login_attempts | AuthService, MfaService | Users, Redis, Wallets (create wallet on register) | No |
| **Users** | users, roles, permissions | UsersService, RolesService | Wallets, Audit | No |
| **Roles** | roles, permissions, role_permissions | RolesService | — | No |
| **Wallets** | wallets, wallet_transactions, ledger_entries | WalletsService, **LedgerService**, PricingService | Prisma tx | No (but emits events) |
| **Payments** | payment_methods, payment_transactions, payment_webhooks, payment_events | PaymentsService, GatewayRegistry | Wallets (credit), Queues (reconciliation) | Yes (payment-reconciliation) |
| **ServicesCatalog** | service_categories, services, service_prices, user_service_prices, service_favorites | CategoriesService, ServicesService, PricingService, FavoritesService, SearchService | Providers (read), Wallets (pricing) | No |
| **Providers** | providers, provider_services, provider_sync_logs, provider_health_logs | ProvidersService, SyncService, HealthService, ProviderAdapterFactory | SecureHttp, Redis (cache), Queues | Yes (provider-sync, provider-health) |
| **Orders** | orders, order_items, order_status_history, provider_orders, idempotency_keys | **OrderService**, OrderStateMachine | ServicesCatalog, Providers, Wallets, Queues | Yes (order-submit, order-status) |
| **DripFeed** | drip_feed_orders, drip_feed_runs | DripFeedService | Orders, Queues | Yes (drip-feed) |
| **Subscriptions** | subscriptions, subscription_events | SubscriptionsService | Orders, Providers, Queues | Yes (subscription) |
| **Refills** | refills, refill_status_history | RefillsService | Orders, Providers | Yes (refill-submit/status) |
| **Cancellations** | cancellation_requests | CancellationsService | Orders, Providers, Wallets | Yes (cancellation) |
| **Tickets** | tickets, ticket_messages, ticket_attachments | TicketsService | Storage, Notifications | No |
| **Affiliate** | referral_accounts, referrals, referral_commissions | AffiliateService, CommissionEngine | Wallets, Orders | No |
| **Blog** | blog_categories, blog_posts | BlogService | Storage, Audit | No |
| **Faq** | faqs | FaqService | — | No |
| **Announcements** | announcements | AnnouncementsService | Notifications | No |
| **Notifications** | notifications, notification_preferences | NotificationsService | Queues (email), Email | Yes (notifications, email) |
| **Email** | email_templates | EmailService | Queues | Yes (email) |
| **Blacklist** | blacklisted_ips/emails/links | BlacklistService | — | No |
| **Search** | (uses services) | SearchService (Postgres FTS) | ServicesCatalog | No |
| **Analytics** | (aggregates orders/payments) | AnalyticsService | Orders, Payments, Providers | Yes (analytics) |
| **Settings** | settings, currencies, feature_flags | SettingsService | — | No |
| **Branding** | settings (branding key) | BrandingService | Settings, Storage | No |
| **Homepage** | settings (homepage key) | HomepageService | Settings | No |
| **Media** | media | MediaService | Storage | No |
| **Audit** | audit_logs | AuditService | — | No |
| **ResellerApi** | api_keys, api_usage_logs | ResellerApiService | Auth, Orders, Services, Wallets | No (reuses queues via Orders) |
| **Health** | — | HealthService | Prisma, Redis, Queues, Storage | No |

### Cross-cutting (apps/api/src/*)

| Module | Purpose |
|---|---|
| **PrismaModule** | Singleton PrismaClient, `$transaction` helper, Decimal handling |
| **RedisModule** | ioredis client, cache helper, Redlock, rate-limit store |
| **QueuesModule** | Registers 13 BullMQ queues (see §5), exports queue tokens |
| **SecureHttpModule** | Centralized TLS-verified HTTP client (sec 62/63): timeout, retries, backoff, circuit breaker, request-id, structured logging |
| **StorageModule** | S3-compatible (AWS SDK) abstraction for avatars, blog images, ticket attachments |
| **Common** | JWT/ApiKey/Roles/RateLimit guards, RequestId/Logging interceptors, Zod pipe, exception filter |

## 4. Next.js Feature Map (apps/web)

| Route Group | Features | Data Source | Auth |
|---|---|---|---|
| **(public)** `/`, `/services`, `/pricing`, `/blog`, `/faq`, etc. | SEO pages, service catalogue (read-only), homepage switcher (reads `settings.activeHomepage`) | `GET /api/v1/services`, `/categories`, `/blog`, `/faqs`, `/settings/public` | Public, ISR/SSG where possible |
| **(auth)** `/login`, `/register`, `/forgot-password` | Forms (React Hook Form + Zod), captcha hook, blacklist checks | `POST /api/v1/auth/*` | Public |
| **(dashboard)** `/dashboard` | Wallet, stats, charts (Recharts), recent orders/tx, referrals | `GET /api/v1/wallet`, `/analytics/customer`, `/orders?limit=5` | JWT |
| **(dashboard)** `/new-order`, `/mass-order` | Dynamic ServiceType form engine, price calculator, link validation | `GET /services/:id`, `POST /orders`, `POST /orders/mass` | JWT |
| **(dashboard)** `/orders` | Filters, search, pagination, status history, refill/cancel actions | `GET /orders`, `GET /orders/:publicId`, `POST /refills`, `POST /cancellations` | JWT |
| **(dashboard)** `/add-funds`, `/transactions` | Gateway selector, webhook-aware status, ledger table | `GET /payments/methods`, `POST /payments/initialize`, `GET /wallet/transactions` | JWT |
| **(dashboard)** `/tickets`, `/api`, `/referrals` | Ticket CRUD + attachments, API key mgmt, referral dashboard | Respective endpoints | JWT |
| **(admin)** `/admin/*` | Full CRUD for all entities, provider sync/test, health cards, audit log viewer | `GET/POST/PATCH /api/v1/admin/*` | JWT + RBAC |
| **(admin)** `/admin/appearance/homepage` | Preview AURORA/NEXUS/PULSE (iframe/section), publish active template | `GET /settings/homepage`, `PATCH /admin/settings` | ADMIN |

**State management:** TanStack Query for all server state; Zustand only for `order-store` (ephemeral form state) and `ui-store` (theme, sidebar).

## 5. Queue / Worker Map (BullMQ + Redis)

| Queue Name | Processor | Trigger | Schedule | Notes |
|---|---|---|---|---|
| `provider-sync` | provider-sync | Admin manual, scheduler | Every 60m (configurable per provider) | `getServices()` + `getBalance()`, never overwrites admin overrides; writes `provider_services` + cache `provider:{id}:services` |
| `provider-health` | provider-health | Scheduler | Every 5m | `getBalance()` as health probe, updates `healthStatus`, `ProviderHealthLog` |
| `order-submit` | order-submit | OrderService after create | On demand | `createOrder()` with retries, exponential backoff, idempotency; writes `providerOrderId` + `PROCESSING` |
| `order-status` | order-status | Scheduler + on-demand | Every 2m | Groups eligible orders by provider, `getMultipleOrderStatus()`, maps to state machine, writes history, notifies |
| `refill-submit` | refill-submit | RefillService | On demand | `requestRefill()` |
| `refill-status` | refill-status | Scheduler | Every 5m | `getRefillStatus()` |
| `cancellation` | cancellation | CancellationsService | On demand | `requestCancel()` |
| `drip-feed` | drip-feed | Scheduler | Every 1m | Executes due `DripFeedRun`, creates child Order |
| `subscription` | subscription | Scheduler | Every 5m | Executes due subscriptions |
| `payment-reconciliation` | payment-reconciliation | Scheduler + webhook | Every 5m + on webhook | Verifies `PENDING` payments, credits wallet once |
| `email` | email | NotificationsService | On demand | Sends via EmailProvider (SMTP/API), retries |
| `notifications` | notifications | Many services | On demand | Creates in-app notification + enqueues email |
| `analytics` | analytics | Scheduler | Every 1h | Aggregates service stats, provider profitability (frozen cost) |

**Reliability per worker:** retry policy + dead-letter queue + structured logging (requestId/jobId/correlationId) + failure reason stored.

**Distributed locking:** Redlock via Redis for all scheduled jobs so multiple worker replicas don't duplicate work.

**Cron replacement (sec 66):** No PHP cron URLs. All schedules are BullMQ repeatable jobs defined in `apps/worker/src/jobs/schedulers.ts`.

## 6. Extension Points (Plugin Replacement — sec 80)

| Extension Type | Interface | How to Add |
|---|---|---|
| Payment Gateway | `PaymentGateway` | New class in `payments/gateways/` implementing `initializePayment/verifyPayment/handleWebhook/refund` + register in `GatewayRegistry` |
| Provider Adapter | `ProviderAdapter` | New class in `providers/adapters/` implementing 7 methods + register in `ProviderAdapterFactory` |
| Notification Channel | `NotificationChannel` | Implement `send()` + register in `NotificationsService` |
| Service Type | `ServiceTypeDefinition` | Add Zod schema + field config + price/payload mapping in `services-catalog/service-type-definitions/` |
| Homepage Template | React component | New folder in `apps/web/components/homepages/<name>/` + register in `apps/web/app/(public)/page.tsx` switch + admin preview |

No runtime code uploads. Extensions are deployed code.

## 7. Security Dependency Flow

```
Request → RateLimitGuard (Redis) → JwtGuard / ApiKeyGuard → RolesGuard (RBAC)
        → ZodValidationPipe → Controller → Service → Prisma ($transaction)
        → AuditInterceptor (logs sensitive actions)
        → SecureHttpService (TLS verify, timeout, retry, circuit breaker) → Provider/Payment gateway
```

Provider/payment secrets: encrypted at rest (`ENCRYPTION_KEY`), never returned in API responses, never in `apps/web`.

## 8. Build Order (Sessions 01→20)

```
01 Foundation (repo, monorepo, Docker, env, CI)
02 Database (Prisma schema + migrations)
03 Auth (register/login/sessions/MFA/RBAC)
04 Design System (@windels/ui)
05 Three Homepages (AURORA/NEXUS/PULSE + switcher)
06 Customer Dashboard (wallet, tx, profile)
07 Service System (catalog, search, favorites, pricing engine)
08 Provider System (CRUD, adapters, sync, health)
09 Order Engine (validation, ledger, state machine)
10 Advanced Orders (mass, drip-feed, subscriptions, refill/cancel)
11 Payment System (gateways, webhooks, ledger credit)
12 Reseller API (/api/v1 + OpenAPI)
13 Support+Content (tickets, blog, FAQ, announcements, email)
14 Affiliate (referrals, commissions)
15 Admin (users, staff, analytics, settings, appearance)
16 Workers (all processors + schedulers)
17 Security Hardening (audit)
18 Performance (indexes, caching, pagination)
19 Testing (unit/integration/E2E/financial concurrency)
20 Production Release (health checks, backups, monitoring)
```

This artifact satisfies spec §101 items 3,7,9,10,11 (feature→module mapping, folder structure, API/queue/UI maps).
