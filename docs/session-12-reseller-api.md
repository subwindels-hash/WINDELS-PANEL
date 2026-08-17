# WINDELS PANEL — Session 12: Reseller API

> JSON API at `/api/v1` for placing and tracking orders, authenticated by
> `X-Api-Key` (sha256-hashed at rest) with per-key rate limiting, IP
> whitelisting and idempotency. Built on the Session 09–11 services — the
> controller never writes orders or wallets directly.

## What shipped

| Area | Files |
|---|---|
| API controller (all endpoints) | `controllers/Api_v1.php` |
| Key authentication | `libraries/ApiAuthenticator.php` |
| Rate limiting | `libraries/ApiRateLimiter.php` |
| Human + machine docs | `views/api/docs.php` (`/api/docs`, `/api/docs/json`) |
| Tests | `tests/unit/ResellerApiTest.php` |

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/services` | Active services with the caller's resolved price; `?category`, `?q`, `?page`, `?limit` |
| GET | `/api/v1/services/:public_id` | Single service |
| GET | `/api/v1/balance` | Wallet balance + currency |
| POST | `/api/v1/orders` | Place an order (`{service, link, quantity, fields?, note?}`) |
| GET | `/api/v1/orders` | List own orders (`?status`, `?page`, `?limit`) |
| GET | `/api/v1/orders/:public_id` | Order + status history |
| POST | `/api/v1/orders/status` | Bulk status `{orderIds:[…]}` (max 100) |
| POST | `/api/v1/refills` | Request `{orderId}` |
| GET | `/api/v1/refills/:public_id` | Refill status |
| POST | `/api/v1/cancellations` | Cancel `{orderId}` |

## Authentication & envelope

* Send the raw key as `X-Api-Key: wind_…`. It is hashed (sha256) before the DB
  lookup; rejected if revoked/expired; the account must be ACTIVE; an optional
  per-key IP whitelist is enforced. `last_used_at/ip` are touched on success.
* All responses use `{ success:bool, data?:mixed, error?:{code,message}, meta?:object, requestId }`.
* HTTP status codes: `200/201` success, `401` bad/missing key, `403` IP/account,
  `404` not found, `422` validation, `402` insufficient balance, `429` rate
  limited, `502` provider failure.
* Public ULIDs only — sequential IDs never appear in URLs or payloads.

## Idempotency & rate limiting

* `POST /api/v1/orders` honors an `Idempotency-Key` header (and falls back to a
  deterministic hash) so retries don't double-charge; the underlying
  `OrderService::place` also guards on the key.
* Each key gets a fixed 60-second window (default 60, overridable per key via
  `rate_limit_per_minute`). `X-RateLimit-Limit`, `X-RateLimit-Remaining` and
  `Retry-After` are returned; `429 RATE_LIMITED` is emitted when exceeded. The
  limiter uses an atomic file lock (swap for Redis in production behind the same
  interface).

## Delegation

* Order create/cancel/refill call `OrderService` / `RefillService` (Sessions
  09–10) — validation, wallet charge, state machine, provider submission and
  partial refunds are reused unchanged.
* Prices come from `PricingService` (user > group > default).
* The controller contains no direct `INSERT`/`UPDATE` to orders, wallets or
  wallet_transactions (enforced by a test) and never renders provider/payment
  secrets.

## Docs

`/api/docs` is a lightweight HTML reference; `/api/docs/json` returns the
machine-readable endpoint list for code generation.

## Follow-ups

* Redis-backed rate limiter and per-scope usage logging (`api_usage_logs`) in
  the security/ops session.
* Hosted-payment adapters don't affect this API; they only top up the wallet.
