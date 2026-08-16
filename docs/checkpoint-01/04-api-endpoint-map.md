# WINDELS PANEL — Artifact 4: Complete API Endpoint Map

> Checkpoint 01 | Base: `/api/v1` | Versioning: `/api/v1` today, `/api/v2` future | Docs: `/api/docs` (OpenAPI)
> Auth: `Bearer JWT` for customer/admin; `X-Api-Key` for reseller API. Standard error shape (sec 73). Idempotency via `Idempotency-Key` header.

## 0. Conventions

* All responses: `{ success: boolean, data?: ..., error?: { code, message, requestId } }`
* Pagination: `?page=1&limit=20&sort=createdAt&order=desc` → `{ data, meta: { page, limit, total, totalPages } }`
* Public IDs (ULID) in URLs, never sequential IDs.
* Rate limiting: Redis, per-user + per-IP + per-endpoint; `429` with `Retry-After`.
* Webhooks: signature verification + idempotency (`gatewayType+eventId` unique).
* Never expose: provider API keys, payment secrets, password hashes, stack traces.

---

## 1. Health & Public

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/health` | Public | Liveness + readiness summary |
| GET | `/health/live` | Public | Process alive |
| GET | `/health/ready` | Public | DB + Redis + queues + storage checks |
| GET | `/api/v1/settings/public` | Public | Branding, currency, homepage, SEO (safe subset) |
| GET | `/api/v1/services` | Public* | List active services (paginated, filterable) |
| GET | `/api/v1/services/:publicId` | Public* | Service detail |
| GET | `/api/v1/categories` | Public | Categories + subcategories tree |
| GET | `/api/v1/blog/posts` | Public | Published posts |
| GET | `/api/v1/blog/posts/:slug` | Public | Single post |
| GET | `/api/v1/faqs` | Public | Active FAQs |
| GET | `/api/v1/announcements` | Auth? | Active announcements (scope by audience) |

*Public services hide provider data; price shown is default rate (user-specific pricing requires auth).

---

## 2. Auth

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/auth/register` | Public | Register; checks blacklist (email/ip/link), creates wallet, referral tracking |
| POST | `/api/v1/auth/login` | Public | Login → accessToken + refreshToken (HttpOnly cookie) |
| POST | `/api/v1/auth/logout` | JWT | Revoke session + refresh token |
| POST | `/api/v1/auth/refresh` | Refresh | Rotate refresh token, issue new access token |
| POST | `/api/v1/auth/forgot-password` | Public | Send reset email (queued) |
| POST | `/api/v1/auth/reset-password` | Public (token) | Reset via token |
| POST | `/api/v1/auth/verify-email` | Public (token) | Verify email |
| POST | `/api/v1/auth/verify-email/resend` | JWT | Resend verification |
| GET | `/api/v1/auth/me` | JWT | Current user profile |
| PATCH | `/api/v1/auth/me` | JWT | Update profile |
| POST | `/api/v1/auth/mfa/setup` | JWT (ADMIN) | TOTP setup |
| POST | `/api/v1/auth/mfa/verify` | JWT | Verify TOTP |
| POST | `/api/v1/auth/mfa/disable` | JWT | Disable MFA |

---

## 3. Users (Admin)

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/admin/users` | ADMIN `users.view` | List users (search, filter status/role) |
| GET | `/api/v1/admin/users/:publicId` | ADMIN | User detail + wallet + stats |
| PATCH | `/api/v1/admin/users/:publicId` | ADMIN `users.edit` | Update user, role, priceGroup, status |
| POST | `/api/v1/admin/users/:publicId/adjust-balance` | ADMIN `payments.manage` | Manual credit/debit (ledger + audit) |
| GET | `/api/v1/admin/users/:publicId/orders` | ADMIN | User's orders |
| GET | `/api/v1/admin/users/:publicId/transactions` | ADMIN | User's wallet tx |
| POST | `/api/v1/admin/staff` | SUPER_ADMIN `staff.manage` | Create staff |
| PATCH | `/api/v1/admin/staff/:publicId` | SUPER_ADMIN | Assign roles/permissions |
| DELETE | `/api/v1/admin/staff/:publicId` | SUPER_ADMIN | Disable staff |
| GET | `/api/v1/admin/audit-logs` | ADMIN `reports.view` | Audit logs (filterable) |

---

## 4. Wallet & Transactions (Customer)

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/wallet` | JWT | Own wallet + balance |
| GET | `/api/v1/wallet/transactions` | JWT | Own ledger (paginated, filter type) |
| GET | `/api/v1/admin/wallets` | ADMIN | All wallets (admin) |
| GET | `/api/v1/admin/wallets/:publicId` | ADMIN | Wallet detail |

---

## 5. Services & Catalog

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/services` | Public/JWT | List (search, category, platform, price-sorted) |
| GET | `/api/v1/services/:publicId` | Public/JWT | Detail (user-specific price if authed) |
| GET | `/api/v1/search/services` | Public/JWT | Full-text search (name/desc/category/platform) |
| POST | `/api/v1/services/:publicId/favorite` | JWT | Add favorite |
| DELETE | `/api/v1/services/:publicId/favorite` | JWT | Remove favorite |
| GET | `/api/v1/favorites` | JWT | List favorites |
| — | **Admin** | | |
| POST | `/api/v1/admin/categories` | ADMIN `services.manage` | Create category |
| PATCH | `/api/v1/admin/categories/:publicId` | ADMIN | Update category |
| DELETE | `/api/v1/admin/categories/:publicId` | ADMIN | Delete |
| POST | `/api/v1/admin/services` | ADMIN | Create service |
| PATCH | `/api/v1/admin/services/:publicId` | ADMIN | Update service (audit) |
| DELETE | `/api/v1/admin/services/:publicId` | ADMIN | Archive/delete |
| PUT | `/api/v1/admin/services/:publicId/price-groups/:priceGroupId` | ADMIN | Set group price |
| PUT | `/api/v1/admin/users/:publicId/service-prices/:servicePublicId` | ADMIN | Set user-specific price |

---

## 6. Providers (Admin)

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/admin/providers` | ADMIN `providers.manage` | List providers + health |
| POST | `/api/v1/admin/providers` | ADMIN | Create provider |
| GET | `/api/v1/admin/providers/:publicId` | ADMIN | Detail |
| PATCH | `/api/v1/admin/providers/:publicId` | ADMIN | Update (audit) |
| DELETE | `/api/v1/admin/providers/:publicId` | ADMIN | Delete |
| POST | `/api/v1/admin/providers/:publicId/test` | ADMIN | Test connection (live, not queued) |
| POST | `/api/v1/admin/providers/:publicId/sync` | ADMIN | Trigger `provider.sync.services` job |
| POST | `/api/v1/admin/providers/:publicId/sync-balance` | ADMIN | Trigger balance sync |
| GET | `/api/v1/admin/providers/:publicId/services` | ADMIN | Cached provider_services |
| GET | `/api/v1/admin/providers/:publicId/logs` | ADMIN | Sync + health logs |
| GET | `/api/v1/admin/providers/:publicId/orders` | ADMIN | Orders routed to provider |

---

## 7. Orders

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/orders` | JWT | Create standard order (Idempotency-Key supported) |
| POST | `/api/v1/orders/mass` | JWT | Mass order (array of instructions) |
| GET | `/api/v1/orders` | JWT | Own orders (filter status/service/date, sort, paginate) |
| GET | `/api/v1/orders/:publicId` | JWT | Own order detail + status history |
| POST | `/api/v1/orders/:publicId/cancel` | JWT | Request cancellation |
| POST | `/api/v1/orders/:publicId/refill` | JWT | Request refill |
| — | **Admin** | | |
| GET | `/api/v1/admin/orders` | ADMIN `orders.view` | All orders (filter status/provider/service/customer, search providerOrderId) |
| GET | `/api/v1/admin/orders/:publicId` | ADMIN | Detail + history + provider payload (no secrets) |
| PATCH | `/api/v1/admin/orders/:publicId/status` | ADMIN `orders.edit` | Manual status update (audit + history) |
| POST | `/api/v1/admin/orders/:publicId/refund` | ADMIN `payments.manage` | Issue refund (ledger + audit) |
| POST | `/api/v1/admin/orders/:publicId/notes` | ADMIN | Add internal note |
| POST | `/api/v1/admin/orders/:publicId/cancel` | ADMIN | Admin cancel request |
| POST | `/api/v1/admin/orders/:publicId/refill` | ADMIN | Admin refill request |

**Mass order response:**
```json
{ "success": true, "data": { "successful": [...], "failed": [{ "row": 2, "error": "Invalid link" }] } }
```

---

## 8. Drip Feed & Subscriptions

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/drip-feed` | JWT | Create drip-feed order |
| GET | `/api/v1/drip-feed` | JWT | Own drip-feed orders |
| GET | `/api/v1/drip-feed/:publicId` | JWT | Detail + runs |
| PATCH | `/api/v1/drip-feed/:publicId/cancel` | JWT | Cancel |
| POST | `/api/v1/subscriptions` | JWT | Create subscription |
| GET | `/api/v1/subscriptions` | JWT | Own subscriptions |
| GET | `/api/v1/subscriptions/:publicId` | JWT | Detail + events |
| PATCH | `/api/v1/subscriptions/:publicId/pause` | JWT | Pause |
| PATCH | `/api/v1/subscriptions/:publicId/resume` | JWT | Resume |
| PATCH | `/api/v1/subscriptions/:publicId/cancel` | JWT | Cancel |
| GET | `/api/v1/admin/drip-feed` | ADMIN | All drip-feed |
| GET | `/api/v1/admin/subscriptions` | ADMIN | All subscriptions |

---

## 9. Refills & Cancellations

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/refills` | JWT | Own refills |
| GET | `/api/v1/refills/:publicId` | JWT | Refill detail |
| GET | `/api/v1/cancellations` | JWT | Own cancellations |
| GET | `/api/v1/cancellations/:publicId` | JWT | Detail |
| GET | `/api/v1/admin/refills` | ADMIN | All refills |
| GET | `/api/v1/admin/cancellations` | ADMIN | All cancellations |

---

## 10. Payments

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/payments/methods` | JWT | Active gateways (public config only) |
| POST | `/api/v1/payments/initialize` | JWT | Initialize deposit → returns redirect URL / client secret |
| GET | `/api/v1/payments/:publicId` | JWT | Own payment status |
| GET | `/api/v1/payments` | JWT | Own payment history |
| POST | `/api/v1/payments/webhook/:gatewayType` | Public (signature) | Gateway webhook (Stripe/PayPal/etc.) — idempotent |
| GET | `/api/v1/admin/payments` | ADMIN `payments.manage` | All payments |
| POST | `/api/v1/admin/payments/:publicId/refund` | ADMIN | Refund |
| GET | `/api/v1/admin/payments/webhooks` | ADMIN | Webhook log |

---

## 11. Tickets

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/v1/tickets` | JWT | Create ticket |
| GET | `/api/v1/tickets` | JWT | Own tickets |
| GET | `/api/v1/tickets/:publicId` | JWT | Detail + messages |
| POST | `/api/v1/tickets/:publicId/messages` | JWT | Reply (supports multipart for attachments) |
| PATCH | `/api/v1/tickets/:publicId/close` | JWT | Close own ticket |
| GET | `/api/v1/admin/tickets` | ADMIN `tickets.manage` | All tickets |
| PATCH | `/api/v1/admin/tickets/:publicId` | ADMIN | Assign, priority, department, status |
| POST | `/api/v1/admin/tickets/:publicId/messages` | ADMIN | Staff reply |

---

## 12. Affiliate / Referrals

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/referrals/me` | JWT | Own referral account + code + stats |
| GET | `/api/v1/referrals/commissions` | JWT | Own commissions |
| GET | `/api/v1/admin/referrals` | ADMIN | All referrals + commissions |
| PATCH | `/api/v1/admin/referrals/commissions/:id` | ADMIN | Approve/reject commission |

---

## 13. Content (Blog, FAQ, Announcements)

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/admin/blog/categories` | ADMIN | List |
| POST | `/api/v1/admin/blog/categories` | ADMIN | Create |
| GET | `/api/v1/admin/blog/posts` | ADMIN | List (incl. drafts) |
| POST | `/api/v1/admin/blog/posts` | ADMIN | Create |
| PATCH | `/api/v1/admin/blog/posts/:publicId` | ADMIN | Update |
| DELETE | `/api/v1/admin/blog/posts/:publicId` | ADMIN | Delete |
| GET | `/api/v1/admin/faqs` | ADMIN | List |
| POST | `/api/v1/admin/faqs` | ADMIN | Create |
| PATCH | `/api/v1/admin/faqs/:publicId` | ADMIN | Update/reorder |
| DELETE | `/api/v1/admin/faqs/:publicId` | ADMIN | Delete |
| GET | `/api/v1/admin/announcements` | ADMIN | List |
| POST | `/api/v1/admin/announcements` | ADMIN | Create |
| PATCH | `/api/v1/admin/announcements/:publicId` | ADMIN | Update |

---

## 14. Blacklist, Media, Settings, Analytics

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/admin/blacklist/:type` | ADMIN `settings.manage` | List (email/ip/link) |
| POST | `/api/v1/admin/blacklist/:type` | ADMIN | Add |
| DELETE | `/api/v1/admin/blacklist/:type/:id` | ADMIN | Remove |
| POST | `/api/v1/media/upload` | JWT/ADMIN | Upload (validated MIME/signature/size) |
| GET | `/api/v1/admin/settings` | ADMIN `settings.manage` | All settings |
| PATCH | `/api/v1/admin/settings` | ADMIN | Update setting (audit) |
| PATCH | `/api/v1/admin/settings/branding` | ADMIN | Update branding |
| PATCH | `/api/v1/admin/settings/homepage` | ADMIN | Switch AURORA/NEXUS/PULSE + preview |
| GET | `/api/v1/admin/analytics/overview` | ADMIN `reports.view` | Revenue, orders, users, margin |
| GET | `/api/v1/admin/analytics/charts` | ADMIN | Time-series (revenue, orders, provider perf) |
| GET | `/api/v1/analytics/customer` | JWT | Own spending/volume charts |

---

## 15. Reseller API (API-Key Auth — sec 37/38)

Base: `/api/v1` with `X-Api-Key` header + per-key rate limiting.

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/services` | ApiKey | List services (same as public, but with reseller price) |
| GET | `/api/v1/balance` | ApiKey | Wallet balance + currency |
| POST | `/api/v1/orders` | ApiKey | Create order (supports all service types) |
| GET | `/api/v1/orders/:publicId` | ApiKey | Single order status |
| POST | `/api/v1/orders/status` | ApiKey | Bulk status: `{ orderIds: [...] }` |
| POST | `/api/v1/refills` | ApiKey | Request refill `{ orderId }` |
| GET | `/api/v1/refills/:publicId` | ApiKey | Refill status |
| POST | `/api/v1/cancellations` | ApiKey | Request cancellation `{ orderId }` |
| GET | `/api/v1/api/keys` | JWT | List own API keys |
| POST | `/api/v1/api/keys` | JWT | Generate key (returns raw key once) |
| DELETE | `/api/v1/api/keys/:publicId` | JWT | Revoke |
| POST | `/api/v1/api/keys/:publicId/regenerate` | JWT | Regenerate |
| PATCH | `/api/v1/api/keys/:publicId` | JWT | Update IP whitelist, scopes |

**Reseller errors:** same standard shape; `429` for rate limit, `401` for invalid key.

---

## 16. OpenAPI & Docs

| Method | Path | Description |
|---|---|---|
| GET | `/api/docs` | Swagger UI |
| GET | `/api/docs/json` | Machine-readable OpenAPI 3.1 JSON |
| GET | `/api/docs/yaml` | YAML variant |

Generated from decorators + Zod schemas via `@nestjs/swagger`.

---

## 17. Idempotency & Webhook Guarantees

* `Idempotency-Key` header on `POST /orders`, `POST /payments/initialize` — stored in `idempotency_keys` + unique constraints on ledger/payment tables.
* Webhooks: `PaymentWebhook(gatewayType, eventId)` unique; second delivery returns `200` without re-crediting.
* Provider submission: `Order.idempotencyKey` prevents duplicate provider orders on retry.

---

## 18. Admin vs Customer Separation

* All `/admin/*` routes require `ADMIN` or `STAFF` + explicit permission key (not just role name).
* Customer routes never return `provider_api_key`, `providerRate`, internal `id`, or other customers' data.
* Audit log created for: service/price/provider changes, balance adjustments, refunds, role changes, settings changes.
