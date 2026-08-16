# WINDELS PANEL — Artifact 2: Complete PostgreSQL / Prisma Schema Plan

> Checkpoint 01 | See `prisma/schema.prisma` for executable version after approval
> All timestamps `TIMESTAMPTZ`, stored UTC. Money = `Decimal @db.Decimal(20,8)`.

## 1. Generator & Datasource

```prisma
generator client { provider = "prisma-client-js" }
datasource db { provider = "postgresql"; url = env("DATABASE_URL") }
```

## 2. Enums

```prisma
enum UserRole           { SUPER_ADMIN ADMIN STAFF CUSTOMER }
enum UserStatus         { ACTIVE SUSPENDED BANNED PENDING_VERIFICATION }
enum OrderStatus        { PENDING PROCESSING IN_PROGRESS COMPLETED PARTIAL CANCELED REFUNDED FAILED ERROR PAUSED REJECTED EXPIRED }
enum OrderStatusSource  { SYSTEM ADMIN PROVIDER CUSTOMER CRON WORKER }
enum ServiceType        { DEFAULT CUSTOM_COMMENTS CUSTOM_COMMENTS_PACKAGE MENTIONS_HASHTAG MENTIONS_CUSTOM_LIST MENTIONS_MEDIA_LIKERS MENTIONS_USER_FOLLOWERS COMMENT_LIKES PACKAGE SUBSCRIPTION POLL OTHER }
enum ServiceStatus      { ACTIVE INACTIVE DRAFT ARCHIVED }
enum ProviderStatus     { ACTIVE INACTIVE DEGRADED }
enum ProviderApiType    { STANDARD_SMM CUSTOM }
enum HealthStatus       { ONLINE DEGRADED OFFLINE UNKNOWN }
enum PaymentStatus      { CREATED PENDING VERIFIED WALLET_CREDITED FAILED EXPIRED CANCELED REFUNDED CHARGEBACK }
enum PaymentGatewayType { STRIPE PAYPAL FLUTTERWAVE RAZORPAY PAYSTACK COINPAYMENTS MANUAL }
enum WalletTxType       { DEPOSIT ORDER_CHARGE REFUND MANUAL_CREDIT MANUAL_DEBIT BONUS REFERRAL_COMMISSION CHARGEBACK CANCELLATION_REFUND PARTIAL_REFUND }
enum LedgerDirection    { CREDIT DEBIT }
enum RefillStatus       { PENDING PROCESSING COMPLETED FAILED REJECTED }
enum CancellationStatus { PENDING PROCESSING COMPLETED FAILED REJECTED REFUNDED }
enum DripFeedStatus     { ACTIVE PAUSED COMPLETED CANCELED FAILED }
enum SubscriptionStatus { ACTIVE PAUSED COMPLETED CANCELED EXPIRED FAILED }
enum TicketStatus       { OPEN PENDING ANSWERED RESOLVED CLOSED }
enum TicketPriority     { LOW MEDIUM HIGH URGENT }
enum NotificationChannel{ IN_APP EMAIL }
enum NotificationType   { ORDER_CREATED ORDER_PROCESSING ORDER_COMPLETED ORDER_PARTIAL ORDER_CANCELED REFUND DEPOSIT_SUCCESS DEPOSIT_FAILED TICKET_REPLY SUBSCRIPTION_UPDATE REFILL_COMPLETED SECURITY_ALERT ANNOUNCEMENT }
enum BlogPostStatus     { DRAFT PUBLISHED ARCHIVED }
enum AnnouncementSeverity{ INFO WARNING CRITICAL }
enum BlacklistType      { EMAIL IP LINK }
```

## 3. Identity & Access

```prisma
model User {
  id                String    @id @default(cuid())
  publicId          String    @unique @default(cuid()) @map("public_id") // ULID in app; cuid placeholder in schema
  username          String    @unique
  email             String    @unique
  passwordHash      String    @map("password_hash")
  firstName         String?   @map("first_name")
  lastName          String?   @map("last_name")
  phone             String?
  avatarUrl         String?   @map("avatar_url")
  status            UserStatus @default(ACTIVE)
  role              UserRole  @default(CUSTOMER)
  priceGroupId      String?   @map("price_group_id")
  priceGroup        PriceGroup? @relation(fields: [priceGroupId], references: [id])
  referralCode      String?   @unique @map("referral_code")
  referredById      String?   @map("referred_by_id")
  referredBy        User?     @relation("Referrals", fields: [referredById], references: [id])
  referrals         User[]    @relation("Referrals")
  emailVerifiedAt   DateTime? @map("email_verified_at") @db.Timestamptz
  lastLoginAt       DateTime? @map("last_login_at") @db.Timestamptz
  mfaEnabled        Boolean   @default(false) @map("mfa_enabled")
  mfaSecret         String?   @map("mfa_secret") // encrypted
  createdAt         DateTime  @default(now()) @map("created_at") @db.Timestamptz
  updatedAt         DateTime  @updatedAt @map("updated_at") @db.Timestamptz

  wallet            Wallet?
  sessions          UserSession[]
  refreshTokens     RefreshToken[]
  apiKeys           ApiKey[]
  favorites         ServiceFavorite[]
  orders            Order[]
  tickets           Ticket[]
  referralAccount   ReferralAccount?
  auditLogs         AuditLog[] @relation("AuditActor")

  @@index([status, createdAt])
  @@index([role])
  @@map("users")
}

model PriceGroup {
  id        String   @id @default(cuid())
  name      String   @unique // e.g. "VIP", "Reseller"
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  users     User[]
  prices    ServicePrice[]
  @@map("price_groups")
}

model Role {
  id          String @id @default(cuid())
  name        String @unique // SUPER_ADMIN etc. or custom staff roles
  description String?
  permissions RolePermission[]
  @@map("roles")
}

model Permission {
  id          String @id @default(cuid())
  key         String @unique // e.g. "orders.view"
  description String?
  roles       RolePermission[]
  @@map("permissions")
}

model RolePermission {
  roleId       String @map("role_id")
  permissionId String @map("permission_id")
  role         Role       @relation(fields: [roleId], references: [id], onDelete: Cascade)
  permission   Permission @relation(fields: [permissionId], references: [id], onDelete: Cascade)
  @@id([roleId, permissionId])
  @@map("role_permissions")
}

model UserSession {
  id        String   @id @default(cuid())
  userId    String   @map("user_id")
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  tokenHash String   @map("token_hash")
  ip        String?
  userAgent String?  @map("user_agent")
  expiresAt DateTime @map("expires_at") @db.Timestamptz
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([userId, expiresAt])
  @@map("user_sessions")
}

model RefreshToken {
  id        String   @id @default(cuid())
  userId    String   @map("user_id")
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  tokenHash String   @unique @map("token_hash")
  expiresAt DateTime @map("expires_at") @db.Timestamptz
  revokedAt DateTime? @map("revoked_at") @db.Timestamptz
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([userId])
  @@map("refresh_tokens")
}

model MfaMethod {
  id        String   @id @default(cuid())
  userId    String   @map("user_id")
  type      String   // TOTP
  secret    String   // encrypted
  verified  Boolean  @default(false)
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("mfa_methods")
}
```

## 4. Wallet & Ledger (sec 24/25/56)

```prisma
model Wallet {
  id        String   @id @default(cuid())
  publicId  String   @unique @map("public_id")
  userId    String   @unique @map("user_id")
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  balance   Decimal  @default(0) @db.Decimal(20, 8)
  currency  String   @default("USD") // ISO 4217; single-currency ledger per spec 57
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  updatedAt DateTime @updatedAt @map("updated_at") @db.Timestamptz
  transactions WalletTransaction[]
  @@map("wallets")
}

model WalletTransaction {
  id              String       @id @default(cuid())
  publicId        String       @unique @map("public_id")
  walletId        String       @map("wallet_id")
  wallet          Wallet       @relation(fields: [walletId], references: [id])
  type            WalletTxType
  amount          Decimal      @db.Decimal(20, 8) // positive magnitude; direction via ledger
  direction       LedgerDirection
  balanceBefore   Decimal      @map("balance_before") @db.Decimal(20, 8)
  balanceAfter    Decimal      @map("balance_after") @db.Decimal(20, 8)
  referenceType   String?      @map("reference_type") // Order, PaymentTransaction, etc.
  referenceId     String?      @map("reference_id")
  idempotencyKey  String?      @unique @map("idempotency_key")
  metadata        Json?        @db.JsonB
  createdAt       DateTime     @default(now()) @map("created_at") @db.Timestamptz

  ledgerEntries   LedgerEntry[]

  @@index([walletId, createdAt])
  @@index([type, createdAt])
  @@index([referenceType, referenceId])
  @@map("wallet_transactions")
}

model LedgerEntry {
  id                  String   @id @default(cuid())
  walletTransactionId String   @map("wallet_transaction_id")
  walletTransaction   WalletTransaction @relation(fields: [walletTransactionId], references: [id], onDelete: Cascade)
  account             String   // e.g. wallet:{id}, revenue, provider_cost, commission
  direction           LedgerDirection
  amount              Decimal  @db.Decimal(20, 8)
  currency            String
  createdAt           DateTime @default(now()) @map("created_at") @db.Timestamptz

  @@index([account, createdAt])
  @@map("ledger_entries")
}
```

> All wallet mutations run in a transaction: `SELECT ... FOR UPDATE` on wallet row + insert WalletTransaction + LedgerEntry + update wallet.balance. Decimal arithmetic only.

## 5. Services & Categories

```prisma
model ServiceCategory {
  id          String   @id @default(cuid())
  publicId    String   @unique @map("public_id")
  name        String
  slug        String   @unique
  parentId    String?  @map("parent_id")
  parent      ServiceCategory? @relation("CategoryHierarchy", fields: [parentId], references: [id])
  children    ServiceCategory[] @relation("CategoryHierarchy")
  description String?
  icon        String?
  sorting     Int      @default(0)
  isActive    Boolean  @default(true) @map("is_active")
  createdAt   DateTime @default(now()) @map("created_at") @db.Timestamptz
  updatedAt   DateTime @updatedAt @map("updated_at") @db.Timestamptz
  services    Service[]
  @@index([parentId, sorting])
  @@map("service_categories")
}

model Service {
  id                      String      @id @default(cuid())
  publicId                String      @unique @map("public_id")
  name                    String
  slug                    String      @unique
  categoryId              String      @map("category_id")
  category                ServiceCategory @relation(fields: [categoryId], references: [id])
  description             String?     @db.Text
  serviceType             ServiceType @default(DEFAULT) @map("service_type")
  rate                    Decimal     @db.Decimal(20, 8) // per 1000 or per unit; pricing service interprets
  minQuantity             Int         @map("min_quantity")
  maxQuantity             Int         @map("max_quantity")
  averageTime             String?     @map("average_time") // e.g. "0-1h", free text + minutes field
  averageTimeMinutes      Int?        @map("average_time_minutes")
  providerId              String?     @map("provider_id")
  provider                Provider?   @relation(fields: [providerId], references: [id])
  providerServiceId       String?     @map("provider_service_id")
  providerRate            Decimal?    @map("provider_rate") @db.Decimal(20, 8)
  status                  ServiceStatus @default(ACTIVE)
  cancelSupported         Boolean     @default(false) @map("cancel_supported")
  refillSupported         Boolean     @default(false) @map("refill_supported")
  dripFeedSupported       Boolean     @default(false) @map("drip_feed_supported")
  subscriptionSupported   Boolean     @default(false) @map("subscription_supported")
  packageSupported        Boolean     @default(false) @map("package_supported")
  customCommentsSupported Boolean     @default(false) @map("custom_comments_supported")
  sorting                 Int         @default(0)
  featured                Boolean     @default(false)
  trending                Boolean     @default(false)
  metadata                Json?       @db.JsonB // service-type field definitions, platform, etc.
  // admin override vs provider source (sec 21)
  providerSourceSnapshot  Json?       @map("provider_source_snapshot") @db.JsonB
  createdAt               DateTime    @default(now()) @map("created_at") @db.Timestamptz
  updatedAt               DateTime    @updatedAt @map("updated_at") @db.Timestamptz

  prices                  ServicePrice[]
  userPrices              UserServicePrice[]
  favorites               ServiceFavorite[]
  orders                  Order[]

  @@index([categoryId, status, sorting])
  @@index([providerId, providerServiceId])
  @@index([status, featured])
  @@index([slug])
  // PostgreSQL FTS index added via raw migration: GIN on to_tsvector(name||description||category)
  @@map("services")
}

model ServicePrice {
  id            String   @id @default(cuid())
  serviceId     String   @map("service_id")
  service       Service  @relation(fields: [serviceId], references: [id], onDelete: Cascade)
  priceGroupId  String   @map("price_group_id")
  priceGroup    PriceGroup @relation(fields: [priceGroupId], references: [id], onDelete: Cascade)
  rate          Decimal  @db.Decimal(20, 8)
  createdAt     DateTime @default(now()) @map("created_at") @db.Timestamptz
  updatedAt     DateTime @updatedAt @map("updated_at") @db.Timestamptz
  @@unique([serviceId, priceGroupId])
  @@map("service_prices")
}

model UserServicePrice {
  id        String   @id @default(cuid())
  userId    String   @map("user_id")
  serviceId String   @map("service_id")
  service   Service  @relation(fields: [serviceId], references: [id], onDelete: Cascade)
  rate      Decimal  @db.Decimal(20, 8)
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@unique([userId, serviceId])
  @@index([userId])
  @@map("user_service_prices")
}

model ServiceFavorite {
  userId    String   @map("user_id")
  serviceId String   @map("service_id")
  user      User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  service   Service  @relation(fields: [serviceId], references: [id], onDelete: Cascade)
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@id([userId, serviceId])
  @@map("service_favorites")
}
```

## 6. Providers

```prisma
model Provider {
  id                  String          @id @default(cuid())
  publicId            String          @unique @map("public_id")
  name                String
  apiUrl              String          @map("api_url")
  apiKeyEncrypted     String          @map("api_key_encrypted") // encrypted at rest
  apiType             ProviderApiType @default(STANDARD_SMM) @map("api_type")
  status              ProviderStatus  @default(ACTIVE)
  currency            String          @default("USD")
  timeoutMs           Int             @default(15000) @map("timeout_ms")
  retryPolicy         Json?           @map("retry_policy") @db.JsonB // {maxRetries, backoff}
  rateMultiplier      Decimal         @default(1) @map("rate_multiplier") @db.Decimal(20, 8)
  markup              Decimal         @default(0) @db.Decimal(20, 8)
  syncIntervalMinutes Int             @default(60) @map("sync_interval_minutes")
  healthStatus        HealthStatus    @default(UNKNOWN) @map("health_status")
  lastSuccessfulSyncAt DateTime?      @map("last_successful_sync_at") @db.Timestamptz
  lastError           String?         @map("last_error") @db.Text
  createdAt           DateTime        @default(now()) @map("created_at") @db.Timestamptz
  updatedAt           DateTime        @updatedAt @map("updated_at") @db.Timestamptz

  services            Service[]
  providerServices    ProviderService[]
  syncLogs            ProviderSyncLog[]
  healthLogs          ProviderHealthLog[]
  orders              Order[]

  @@map("providers")
}

model ProviderService {
  id                String   @id @default(cuid())
  providerId        String   @map("provider_id")
  provider          Provider @relation(fields: [providerId], references: [id], onDelete: Cascade)
  providerServiceId String   @map("provider_service_id") // provider's own ID
  name              String
  rate              Decimal  @db.Decimal(20, 8)
  minQuantity       Int      @map("min_quantity")
  maxQuantity       Int      @map("max_quantity")
  serviceType       ServiceType @default(DEFAULT) @map("service_type")
  cancelSupported   Boolean  @default(false) @map("cancel_supported")
  refillSupported   Boolean  @default(false) @map("refill_supported")
  dripFeedSupported Boolean  @default(false) @map("drip_feed_supported")
  rawPayload        Json?    @map("raw_payload") @db.JsonB
  lastSyncedAt      DateTime @default(now()) @map("last_synced_at") @db.Timestamptz

  @@unique([providerId, providerServiceId])
  @@index([providerId])
  @@map("provider_services")
}

model ProviderSyncLog {
  id          String   @id @default(cuid())
  providerId  String   @map("provider_id")
  provider    Provider @relation(fields: [providerId], references: [id], onDelete: Cascade)
  type        String   // services | balance
  status      String   // success | failed
  message     String?  @db.Text
  metadata    Json?    @db.JsonB
  createdAt   DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([providerId, createdAt])
  @@map("provider_sync_logs")
}

model ProviderHealthLog {
  id          String      @id @default(cuid())
  providerId  String      @map("provider_id")
  provider    Provider    @relation(fields: [providerId], references: [id], onDelete: Cascade)
  status      HealthStatus
  latencyMs   Int?        @map("latency_ms")
  error       String?     @db.Text
  createdAt   DateTime    @default(now()) @map("created_at") @db.Timestamptz
  @@index([providerId, createdAt])
  @@map("provider_health_logs")
}
```

## 7. Orders

```prisma
model Order {
  id                String      @id @default(cuid())
  publicId          String      @unique @map("public_id")
  userId            String      @map("user_id")
  user              User        @relation(fields: [userId], references: [id])
  serviceId         String      @map("service_id")
  service           Service     @relation(fields: [serviceId], references: [id])
  providerId        String?     @map("provider_id")
  provider          Provider?   @relation(fields: [providerId], references: [id])
  providerOrderId   String?     @map("provider_order_id")
  providerServiceId String?     @map("provider_service_id")

  status            OrderStatus @default(PENDING)
  link              String      @db.Text
  quantity          Int
  charge            Decimal     @db.Decimal(20, 8) // what customer paid
  providerCharge    Decimal?    @map("provider_charge") @db.Decimal(20, 8) // frozen at order time (sec 56)
  currency          String      @default("USD")

  // dynamic fields per service type (validated via Zod + ServiceType engine)
  fields            Json?       @db.JsonB // {comments, usernames, hashtags, etc}
  remains           Int?        // for partial
  startCount        Int?        @map("start_count")
  idempotencyKey    String?     @unique @map("idempotency_key")

  // drip-feed / subscription linkage
  dripFeedOrderId   String?     @unique @map("drip_feed_order_id")
  subscriptionId    String?     @map("subscription_id")

  createdAt         DateTime    @default(now()) @map("created_at") @db.Timestamptz
  updatedAt         DateTime    @updatedAt @map("updated_at") @db.Timestamptz

  statusHistory     OrderStatusHistory[]
  providerOrders    ProviderOrder[]
  refills           Refill[]
  cancellations     CancellationRequest[]
  dripFeedOrder     DripFeedOrder? @relation(fields: [dripFeedOrderId], references: [id])
  subscription      Subscription?  @relation(fields: [subscriptionId], references: [id])

  @@index([userId, status, createdAt])
  @@index([serviceId, status])
  @@index([providerId, providerOrderId])
  @@index([status, createdAt])
  @@index([publicId])
  @@map("orders")
}

model OrderStatusHistory {
  id              String           @id @default(cuid())
  orderId         String           @map("order_id")
  order           Order            @relation(fields: [orderId], references: [id], onDelete: Cascade)
  previousStatus  OrderStatus?     @map("previous_status")
  newStatus       OrderStatus      @map("new_status")
  reason          String?          @db.Text
  source          OrderStatusSource
  providerStatus  String?          @map("provider_status")
  metadata        Json?            @db.JsonB
  createdAt       DateTime         @default(now()) @map("created_at") @db.Timestamptz
  @@index([orderId, createdAt])
  @@map("order_status_history")
}

model ProviderOrder {
  id              String   @id @default(cuid())
  orderId         String   @map("order_id")
  order           Order    @relation(fields: [orderId], references: [id], onDelete: Cascade)
  providerId      String   @map("provider_id")
  providerOrderId String   @map("provider_order_id")
  requestPayload  Json?    @map("request_payload") @db.JsonB
  responsePayload Json?    @map("response_payload") @db.JsonB
  createdAt       DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([providerId, providerOrderId])
  @@map("provider_orders")
}

model IdempotencyKey {
  id        String   @id @default(cuid())
  key       String   @unique
  scope     String   // order:create, payment:webhook, etc.
  response  Json?    @db.JsonB
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  expiresAt DateTime @map("expires_at") @db.Timestamptz
  @@index([scope, expiresAt])
  @@map("idempotency_keys")
}
```

## 8. Refill / Cancellation / Drip Feed / Subscriptions

```prisma
model Refill {
  id                String       @id @default(cuid())
  publicId          String       @unique @map("public_id")
  orderId           String       @map("order_id")
  order             Order        @relation(fields: [orderId], references: [id])
  providerId        String?      @map("provider_id")
  providerRefillId  String?      @map("provider_refill_id")
  status            RefillStatus @default(PENDING)
  requestedAt       DateTime     @default(now()) @map("requested_at") @db.Timestamptz
  completedAt       DateTime?    @map("completed_at") @db.Timestamptz
  metadata          Json?        @db.JsonB
  history           RefillStatusHistory[]
  @@index([orderId, status])
  @@map("refills")
}

model RefillStatusHistory {
  id        String      @id @default(cuid())
  refillId  String      @map("refill_id")
  refill    Refill      @relation(fields: [refillId], references: [id], onDelete: Cascade)
  previous  RefillStatus? @map("previous")
  newStatus RefillStatus  @map("new_status")
  source    String
  createdAt DateTime    @default(now()) @map("created_at") @db.Timestamptz
  @@map("refill_status_history")
}

model CancellationRequest {
  id              String             @id @default(cuid())
  publicId        String             @unique @map("public_id")
  orderId         String             @map("order_id")
  order           Order              @relation(fields: [orderId], references: [id])
  providerId      String?            @map("provider_id")
  status          CancellationStatus @default(PENDING)
  reason          String?            @db.Text
  refundAmount    Decimal?           @map("refund_amount") @db.Decimal(20, 8)
  createdAt       DateTime           @default(now()) @map("created_at") @db.Timestamptz
  updatedAt       DateTime           @updatedAt @map("updated_at") @db.Timestamptz
  @@index([orderId, status])
  @@map("cancellation_requests")
}

model DripFeedOrder {
  id              String        @id @default(cuid())
  publicId        String        @unique @map("public_id")
  userId          String        @map("user_id")
  serviceId       String        @map("service_id")
  link            String        @db.Text
  totalQuantity   Int           @map("total_quantity")
  quantityPerRun  Int           @map("quantity_per_run")
  runs            Int
  intervalMinutes Int           @map("interval_minutes")
  startAt         DateTime?     @map("start_at") @db.Timestamptz
  nextRunAt       DateTime?     @map("next_run_at") @db.Timestamptz
  status          DripFeedStatus @default(ACTIVE)
  createdAt       DateTime      @default(now()) @map("created_at") @db.Timestamptz
  updatedAt       DateTime      @updatedAt @map("updated_at") @db.Timestamptz
  runsHistory     DripFeedRun[]
  orders          Order?
  @@index([userId, status])
  @@index([nextRunAt, status])
  @@map("drip_feed_orders")
}

model DripFeedRun {
  id              String   @id @default(cuid())
  dripFeedOrderId String   @map("drip_feed_order_id")
  dripFeedOrder   DripFeedOrder @relation(fields: [dripFeedOrderId], references: [id], onDelete: Cascade)
  runNumber       Int      @map("run_number")
  orderId         String?  @map("order_id")
  status          String   @default("PENDING")
  executedAt      DateTime? @map("executed_at") @db.Timestamptz
  createdAt       DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([dripFeedOrderId, runNumber])
  @@map("drip_feed_runs")
}

model Subscription {
  id                    String             @id @default(cuid())
  publicId              String             @unique @map("public_id")
  userId                String             @map("user_id")
  serviceId             String             @map("service_id")
  providerId            String?            @map("provider_id")
  providerSubscriptionId String?           @map("provider_subscription_id")
  target                String             @db.Text // link / username
  quantity              Int
  interval              String             // e.g. daily, weekly
  runs                  Int?
  status                SubscriptionStatus @default(ACTIVE)
  startAt               DateTime?          @map("start_at") @db.Timestamptz
  nextExecutionAt       DateTime?          @map("next_execution_at") @db.Timestamptz
  expiresAt             DateTime?          @map("expires_at") @db.Timestamptz
  metadata              Json?              @db.JsonB
  createdAt             DateTime           @default(now()) @map("created_at") @db.Timestamptz
  updatedAt             DateTime           @updatedAt @map("updated_at") @db.Timestamptz
  events                SubscriptionEvent[]
  orders                Order[]
  @@index([userId, status])
  @@index([nextExecutionAt, status])
  @@map("subscriptions")
}

model SubscriptionEvent {
  id             String   @id @default(cuid())
  subscriptionId String   @map("subscription_id")
  subscription   Subscription @relation(fields: [subscriptionId], references: [id], onDelete: Cascade)
  type           String
  payload        Json?    @db.JsonB
  createdAt      DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("subscription_events")
}
```

## 9. Payments

```prisma
model PaymentMethod {
  id          String            @id @default(cuid())
  name        String            // Stripe, PayPal...
  type        PaymentGatewayType
  isActive    Boolean           @default(true) @map("is_active")
  configEncrypted Json?         @map("config_encrypted") @db.JsonB // encrypted credentials
  createdAt   DateTime          @default(now()) @map("created_at") @db.Timestamptz
  updatedAt   DateTime          @updatedAt @map("updated_at") @db.Timestamptz
  transactions PaymentTransaction[]
  @@map("payment_methods")
}

model PaymentTransaction {
  id              String        @id @default(cuid())
  publicId        String        @unique @map("public_id")
  userId          String        @map("user_id")
  paymentMethodId String        @map("payment_method_id")
  paymentMethod   PaymentMethod @relation(fields: [paymentMethodId], references: [id])
  amount          Decimal       @db.Decimal(20, 8)
  currency        String
  status          PaymentStatus @default(CREATED)
  providerTxId    String?       @map("provider_tx_id")
  idempotencyKey  String?       @unique @map("idempotency_key")
  metadata        Json?         @db.JsonB
  verifiedAt      DateTime?     @map("verified_at") @db.Timestamptz
  createdAt       DateTime      @default(now()) @map("created_at") @db.Timestamptz
  updatedAt       DateTime      @updatedAt @map("updated_at") @db.Timestamptz
  webhooks        PaymentWebhook[]
  events          PaymentEvent[]

  @@index([userId, status, createdAt])
  @@index([providerTxId])
  @@map("payment_transactions")
}

model PaymentWebhook {
  id                    String   @id @default(cuid())
  paymentTransactionId  String?  @map("payment_transaction_id")
  paymentTransaction    PaymentTransaction? @relation(fields: [paymentTransactionId], references: [id])
  gatewayType           PaymentGatewayType @map("gateway_type")
  eventId               String?  @map("event_id") // provider event id for idempotency
  payload               Json     @db.JsonB
  signatureValid        Boolean? @map("signature_valid")
  processed             Boolean  @default(false)
  createdAt             DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@unique([gatewayType, eventId])
  @@index([processed, createdAt])
  @@map("payment_webhooks")
}

model PaymentEvent {
  id                    String   @id @default(cuid())
  paymentTransactionId  String   @map("payment_transaction_id")
  paymentTransaction    PaymentTransaction @relation(fields: [paymentTransactionId], references: [id], onDelete: Cascade)
  fromStatus            PaymentStatus? @map("from_status")
  toStatus              PaymentStatus  @map("to_status")
  reason                String?  @db.Text
  createdAt             DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("payment_events")
}
```

## 10. Support, Content, Referrals

```prisma
model Ticket {
  id          String        @id @default(cuid())
  publicId    String        @unique @map("public_id")
  userId      String        @map("user_id")
  user        User          @relation(fields: [userId], references: [id])
  subject     String
  status      TicketStatus  @default(OPEN)
  priority    TicketPriority @default(MEDIUM)
  department  String?       // support, billing...
  assignedToId String?      @map("assigned_to_id")
  createdAt   DateTime      @default(now()) @map("created_at") @db.Timestamptz
  updatedAt   DateTime      @updatedAt @map("updated_at") @db.Timestamptz
  messages    TicketMessage[]
  @@index([userId, status])
  @@index([status, priority, createdAt])
  @@map("tickets")
}

model TicketMessage {
  id          String   @id @default(cuid())
  ticketId    String   @map("ticket_id")
  ticket      Ticket   @relation(fields: [ticketId], references: [id], onDelete: Cascade)
  authorId    String   @map("author_id")
  message     String   @db.Text
  isStaff     Boolean  @default(false) @map("is_staff")
  createdAt   DateTime @default(now()) @map("created_at") @db.Timestamptz
  attachments TicketAttachment[]
  @@index([ticketId, createdAt])
  @@map("ticket_messages")
}

model TicketAttachment {
  id              String   @id @default(cuid())
  ticketMessageId String   @map("ticket_message_id")
  ticketMessage   TicketMessage @relation(fields: [ticketMessageId], references: [id], onDelete: Cascade)
  fileUrl         String   @map("file_url")
  fileName        String   @map("file_name")
  mimeType        String   @map("mime_type")
  size            Int
  createdAt       DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("ticket_attachments")
}

model ReferralAccount {
  id              String   @id @default(cuid())
  userId          String   @unique @map("user_id")
  user            User     @relation(fields: [userId], references: [id], onDelete: Cascade)
  code            String   @unique
  totalReferred   Int      @default(0) @map("total_referred")
  totalEarned     Decimal  @default(0) @map("total_earned") @db.Decimal(20, 8)
  createdAt       DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("referral_accounts")
}

model Referral {
  id                  String   @id @default(cuid())
  referrerId          String   @map("referrer_id")
  referredId          String   @unique @map("referred_id")
  referralAccountId   String   @map("referral_account_id")
  createdAt           DateTime @default(now()) @map("created_at") @db.Timestamptz
  commissions         ReferralCommission[]
  @@index([referrerId])
  @@map("referrals")
}

model ReferralCommission {
  id              String   @id @default(cuid())
  referralId      String   @map("referral_id")
  referral        Referral @relation(fields: [referralId], references: [id], onDelete: Cascade)
  orderId         String?  @map("order_id")
  amount          Decimal  @db.Decimal(20, 8)
  currency        String
  status          String   @default("PENDING") // PENDING | APPROVED | PAID | REJECTED
  createdAt       DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([referralId, status])
  @@map("referral_commissions")
}

model BlogCategory {
  id        String   @id @default(cuid())
  name      String
  slug      String   @unique
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  posts     BlogPost[]
  @@map("blog_categories")
}

model BlogPost {
  id              String        @id @default(cuid())
  publicId        String        @unique @map("public_id")
  title           String
  slug            String        @unique
  excerpt         String?       @db.Text
  content         String        @db.Text
  featuredImage   String?       @map("featured_image")
  metaTitle       String?       @map("meta_title")
  metaDescription String?       @map("meta_description") @db.Text
  status          BlogPostStatus @default(DRAFT)
  authorId        String?       @map("author_id")
  categoryId      String?       @map("category_id")
  category        BlogCategory? @relation(fields: [categoryId], references: [id])
  publishedAt     DateTime?     @map("published_at") @db.Timestamptz
  createdAt       DateTime      @default(now()) @map("created_at") @db.Timestamptz
  updatedAt       DateTime      @updatedAt @map("updated_at") @db.Timestamptz
  @@index([status, publishedAt])
  @@map("blog_posts")
}

model Faq {
  id        String   @id @default(cuid())
  question  String   @db.Text
  answer    String   @db.Text
  sorting   Int      @default(0)
  isActive  Boolean  @default(true) @map("is_active")
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  updatedAt DateTime @updatedAt @map("updated_at") @db.Timestamptz
  @@map("faqs")
}

model Announcement {
  id          String                @id @default(cuid())
  title       String
  content     String                @db.Text
  severity    AnnouncementSeverity  @default(INFO)
  isActive    Boolean               @default(true) @map("is_active")
  audience    String?               // all | customers | staff
  startsAt    DateTime?             @map("starts_at") @db.Timestamptz
  endsAt      DateTime?             @map("ends_at") @db.Timestamptz
  createdAt   DateTime              @default(now()) @map("created_at") @db.Timestamptz
  @@map("announcements")
}

model Media {
  id        String   @id @default(cuid())
  publicId  String   @unique @map("public_id")
  uploaderId String? @map("uploader_id")
  url       String
  fileName  String   @map("file_name")
  mimeType  String   @map("mime_type")
  size      Int
  purpose   String?  // avatar | blog | ticket | service
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("media")
}
```

## 11. Security & System

```prisma
model AuditLog {
  id          String   @id @default(cuid())
  actorId     String?  @map("actor_id")
  actor       User?    @relation("AuditActor", fields: [actorId], references: [id])
  action      String   // e.g. "service.price.update"
  resource    String   // e.g. "Service"
  resourceId  String?  @map("resource_id")
  before      Json?    @db.JsonB
  after       Json?    @db.JsonB
  ip          String?
  userAgent   String?  @map("user_agent") @db.Text
  requestId   String?  @map("request_id")
  createdAt   DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([actorId, createdAt])
  @@index([resource, resourceId])
  @@index([action, createdAt])
  @@map("audit_logs")
}

model LoginAttempt {
  id        String   @id @default(cuid())
  email     String?
  ip        String
  success   Boolean
  reason    String?
  userAgent String?  @map("user_agent")
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([ip, createdAt])
  @@index([email, createdAt])
  @@map("login_attempts")
}

model ApiKey {
  id          String    @id @default(cuid())
  publicId    String    @unique @map("public_id")
  userId      String    @map("user_id")
  user        User      @relation(fields: [userId], references: [id], onDelete: Cascade)
  name        String?   // label
  keyHash     String    @unique @map("key_hash") // store hash only
  prefix      String    @map("prefix") // e.g. wind_... for display
  lastUsedAt  DateTime? @map("last_used_at") @db.Timestamptz
  ipWhitelist String[]  @map("ip_whitelist")
  scopes      String[]  // permissions
  expiresAt   DateTime? @map("expires_at") @db.Timestamptz
  revokedAt   DateTime? @map("revoked_at") @db.Timestamptz
  createdAt   DateTime  @default(now()) @map("created_at") @db.Timestamptz
  @@index([userId])
  @@map("api_keys")
}

model ApiUsageLog {
  id        String   @id @default(cuid())
  apiKeyId  String?  @map("api_key_id")
  endpoint  String
  ip        String?
  status    Int?
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@index([apiKeyId, createdAt])
  @@map("api_usage_logs")
}

model BlacklistedEmail {
  id        String   @id @default(cuid())
  email     String   @unique
  reason    String?  @db.Text
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("blacklisted_emails")
}

model BlacklistedIp {
  id        String   @id @default(cuid())
  ip        String   @unique
  reason    String?  @db.Text
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("blacklisted_ips")
}

model BlacklistedLink {
  id        String   @id @default(cuid())
  pattern   String   @unique // domain or regex
  reason    String?  @db.Text
  createdAt DateTime @default(now()) @map("created_at") @db.Timestamptz
  @@map("blacklisted_links")
}

model Setting {
  key       String   @id
  value     Json     @db.JsonB
  category  String   // general | branding | currency | etc (sec 58)
  updatedAt DateTime @updatedAt @map("updated_at") @db.Timestamptz
  @@map("settings")
}

model Notification {
  id        String             @id @default(cuid())
  userId    String             @map("user_id")
  type      NotificationType
  channel   NotificationChannel @default(IN_APP)
  title     String
  body      String?            @db.Text
  data      Json?              @db.JsonB
  isRead    Boolean            @default(false) @map("is_read")
  createdAt DateTime           @default(now()) @map("created_at") @db.Timestamptz
  @@index([userId, isRead, createdAt])
  @@map("notifications")
}

model NotificationPreference {
  userId    String  @map("user_id")
  type      NotificationType
  inApp     Boolean @default(true)
  email     Boolean @default(true)
  @@id([userId, type])
  @@map("notification_preferences")
}

model FeatureFlag {
  key       String  @id
  enabled   Boolean @default(false)
  payload   Json?   @db.JsonB
  updatedAt DateTime @updatedAt @map("updated_at") @db.Timestamptz
  @@map("feature_flags")
}

model EmailTemplate {
  id        String   @id @default(cuid())
  key       String   @unique // e.g. "order.completed"
  subject   String
  bodyHtml  String   @map("body_html") @db.Text
  bodyText  String?  @map("body_text") @db.Text
  updatedAt DateTime @updatedAt @map("updated_at") @db.Timestamptz
  @@map("email_templates")
}

model Currency {
  code            String  @id // USD, EUR...
  symbol          String
  name            String
  decimalPrecision Int    @default(2) @map("decimal_precision")
  exchangeRate    Decimal @default(1) @map("exchange_rate") @db.Decimal(20, 8) // vs base
  isBase          Boolean @default(false) @map("is_base")
  isActive        Boolean @default(true) @map("is_active")
  @@map("currencies")
}
```

## 12. Indexes, Constraints & Notes

* All `publicId` fields indexed unique; API exposes only `publicId`.
* `Wallet.balance` check constraint: `balance >= 0` (enforced in app + DB).
* `Order.idempotencyKey`, `PaymentTransaction.idempotencyKey`, `WalletTransaction.idempotencyKey` unique — prevents double-credit on webhook/job retry.
* `ServiceFavorite @@id([userId, serviceId])` unique.
* `FOREIGN KEY` on all relations; `onDelete: Cascade` only where safe (sessions, history); otherwise `Restrict`.
* `Prisma.Decimal` everywhere for money; `NUMERIC(20,8)` to support crypto gateways without loss.
* Full-text search: migration adds `GENERATED` tsvector column on services + `GIN` index (see `docs/database.md` for SQL).
* Settings seeded with `activeHomepage: "AURORA"`, `baseCurrency: "USD"`, `maintenance: false`.

## 13. Migration Strategy

1. `prisma migrate dev --name init` generates SQL — review before apply.
2. Seed via `prisma/seed/seed.ts` (idempotent, skips if data exists; demo data gated by `APP_ENV=demo`).
3. Production: `prisma migrate deploy` in Docker entrypoint before `api`/`worker` start; never `migrate dev` in prod.

## 14. What This Schema Deliberately Omits

* No `license_keys`, `purchase_codes`, `domain_locks` tables.
* No `plugins` table storing executable code.
* No denormalized `users.balance` without ledger — wallet is source of truth.
