# MarvySocials — Reseller API

> JSON API at `/api/v1` for placing and tracking orders, authenticated by
> `X-Api-Key` (SHA-256 verifier at rest) with per-key scopes, rate limiting,
> exact-IP allowlists, expiry, immutable revocation, and usage evidence.

## What shipped

| Area | Files |
|---|---|
| API controller and usage logging | `controllers/Api_v1.php` |
| Key authentication and scope checks | `libraries/ApiAuthenticator.php` |
| Rate limiting | `libraries/ApiRateLimiter.php` |
| Customer key issuance | `controllers/dashboard/Account.php` |
| Admin policy and usage console | `controllers/admin/Api_keys.php`, `libraries/ApiKeyAdminService.php` |
| Shared policy validation | `libraries/ApiKeyPolicy.php` |
| Human + machine docs | `views/api/docs.php` (`/api/docs`, `/api/docs/json`) |
| Tests | `tests/unit/ResellerApiTest.php`, `tests/unit/AdminResellerApiManagementTest.php` |

## Endpoints and scopes

| Method | Path | Required scope | Purpose |
|---|---|---|---|
| GET | `/api/v1/services` | `services.read` | Active services with the caller's resolved price; `?category`, `?q`, `?page`, `?limit` |
| GET | `/api/v1/services/:public_id` | `services.read` | Single service |
| GET | `/api/v1/balance` | `account.read` | Wallet balance and currency |
| POST | `/api/v1/orders` | `orders.write` | Place an order (`{service, link, quantity, fields?, note?}`) |
| POST | `/api/v1/orders/mass` | `orders.write` | Place up to 100 independent instructions |
| GET | `/api/v1/orders` | `orders.read` | List own orders (`?status`, `?page`, `?limit`) |
| GET | `/api/v1/orders/:public_id` | `orders.read` | Order and status history |
| POST | `/api/v1/orders/status` | `orders.read` | Bulk status `{orderIds:[…]}` (max 100) |
| POST | `/api/v1/refills` | `orders.write` | Request `{orderId}` |
| GET | `/api/v1/refills/:public_id` | `orders.read` | Refill status |
| POST | `/api/v1/cancellations` | `orders.write` | Cancel `{orderId}` |
| GET | `/api/v1/referrals` | `referrals.read` | Referral summary and commission totals |

A legacy `NULL` scope policy means full access for backward compatibility. Once
an explicit JSON scope array is stored, it is an exact allowlist; an empty array
blocks every endpoint and malformed policy fails closed. Access outside the
allowlist returns `403 SCOPE_FORBIDDEN`.

## Authentication and envelope

* Send the raw key as `X-Api-Key: wind_…`. It is hashed before lookup and is
  rejected if expired or revoked. The owning account must be `ACTIVE`.
* An optional allowlist accepts exact IPv4 and IPv6 addresses only. CIDR is not
  accepted because the runtime performs exact matching. Malformed stored
  allowlist data fails closed.
* All responses use `{ success:bool, data?:mixed, error?:{code,message}, meta?:object, requestId }`.
* HTTP status codes include `200/201` success, `401` bad/missing key, `403`
  account/IP/scope policy, `404` not found, `422` validation, `402` insufficient
  balance, `429` rate limited, and `502` provider failure.
* Public IDs only; sequential IDs never appear in API URLs or payloads.

## Idempotency and rate limiting

* `POST /api/v1/orders` honors `Idempotency-Key` (with a deterministic fallback)
  so retries do not double-charge. Mass order also supports exact replay.
* Each key gets a fixed 60-second window. `rate_limit_per_minute` is validated
  between 1 and 10,000. `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and
  `Retry-After` describe the current window.

## Usage evidence

Every authenticated response path writes one best-effort `api_usage_logs` row
with the normalized endpoint, HTTP method, source IP, status, duration, and key
ID. This includes successful requests and authenticated failures such as rate,
scope, validation, and provider errors. Logging failures are reported to the
application log but never replace the API response. Unknown credentials are not
persisted, which avoids turning the table into an unauthenticated write target.

The `api.manage`-guarded admin console provides bounded/filterable safe key
reads, policy editing, permanent revocation, recent calls, and grouped endpoint
counts. Its projections never select `key_hash`, and it cannot recover raw
credentials, rotate customer keys, un-revoke keys, or touch orders and wallets.
Policy and revocation mutations are POST-only and append audit records.

Customer key lists use the same safe projection. Customer revocation is also
POST-only, ownership-scoped, compare-and-set, and audited.

## Delegation

Order create, cancel, and refill continue through `OrderService` and
`RefillService`; pricing comes from `PricingService`. The controller does not
write orders or wallets directly.

## Docs

`/api/docs` and `/api/docs/json` are public references. API requests themselves
require a valid key.

## Follow-up

A Redis-backed rate limiter can replace the current atomic-file implementation
behind the existing interface for a horizontally scaled deployment.
