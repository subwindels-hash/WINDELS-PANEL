# WINDELS PANEL — Session 08: Providers

> Admin management of upstream SMM providers: list, create, test connection,
> sync services and balance. Built on the existing provider adapters and
> SecureHttpClient (TLS-verified) with API keys encrypted at rest.

## What shipped

| Area | Files |
|---|---|
| Sync/test service | `application/libraries/ProviderSyncService.php` |
| Admin controller (CRUD + actions) | `application/controllers/admin/Providers.php` |
| Model methods (find, paginate, health/sync logs, upsert) | `models/Provider_model.php`, `models/Provider_service_model.php` |
| Admin views (list + detail, create dialog) | `views/admin/providers/{index,detail}.php` |
| Auth helper (unread count auto-exposed) | `libraries/AuthService.php`, `core/MY_Controller.php` |
| Routes | `admin/providers/create`, `…/:id/test`, `…/:id/sync`, `…/:id/sync-balance` |
| Tests | `tests/unit/ProvidersTest.php` |

## Behavior

### List (`GET /admin/providers`)

* Permission-gated by `providers.manage`.
* Shows each provider's type, status, health badge (ONLINE/OFFLINE/UNKNOWN),
  last-known balance, synced service count and last successful sync.
* A native `<dialog>` "Add provider" form posts to `create`.

### Create (`POST /admin/providers/create`)

* Validates name, URL (`filter_var`), API key, type (`STANDARD_SMM` or `MOCK`),
  timeout (1–60 s), positive rate multiplier and non-negative markup.
* The API key is encrypted with `EncryptionService` (AES-256-GCM) before it is
  stored as `api_key_encrypted`; the plaintext is never written or logged.
* The creation is recorded in the append-only `audit_logs`.
* On success the admin is redirected to the new provider's detail page.

### Test connection (`POST /admin/providers/:public_id/test`)

* Builds the right adapter via `ProviderSyncService::adapter()` (MOCK uses the
  offline adapter; everything else uses `StandardSmmAdapter` over
  `SecureHttpClient`).
* Calls `getBalance()`; on success writes an ONLINE `provider_health_logs` row
  and updates the provider's balance/currency/last_health_check_at; on failure
  writes an OFFLINE row with the error and surfaces it as a flash message.
* Latency is measured and recorded.

### Sync services (`POST …/sync`)

* Calls `getServices()` and normalizes each row (tolerating `service`/`ID`,
  `rate`/`cost`, `min`/`minimum`, etc.) into our canonical shape.
* Upserts into `provider_services` keyed by `(provider_id, provider_service_id)`
  — insert on first sight, update thereafter, with `raw_payload` preserved.
* Writes a `provider_sync_logs` row with inserted/updated counts and duration.
* Invalid rows (missing id/name/rate, non-numeric/negative rate) are skipped.

### Sync balance (`POST …/sync-balance`)

* A thin wrapper over `test_connection()` used for a quick balance refresh.

### Detail (`GET /admin/providers/:public_id`)

* Shows configuration (without the API key), action buttons, a paginated table
  of the provider's mirrored services, and the 10 most recent sync and health
  log entries.

## Safety rules

* All mutating actions are POST-only and CSRF-protected; GET requests to them
  return 404.
* API keys are only ever decrypted inside `StandardSmmAdapter::apiKey()` at the
  moment a provider call is made — they are never passed to a view or logged.
* All outbound HTTP goes through `SecureHttpClient`, which enforces
  `CURLOPT_SSL_VERIFYPEER=TRUE` and `CURLOPT_SSL_VERIFYHOST=2` with retries and
  backoff. There is no code path to disable verification.
* The provider `id` is never exposed — the admin UI links by `public_id` (ULID),
  and lookup is always scoped by it.
* Routes are ordered so `/create` and the action segments are declared before
  the `(:any)` detail catch-all (verified by a test).

## Follow-ups (later sessions)

* **Session 09/10** — order submission uses the synced `provider_service_id` and
  the adapter's `createOrder()`; refill/cancel buttons on orders call
  `requestRefill()`/`requestCancel()`.
* **Session 15** — full provider CRUD (edit/delete), per-provider pricing
  rules, service mapping UI (linking a `provider_services` row to an internal
  `services` row), and the cron-driven `provider_sync`/`provider_health` jobs
  using `Provider_model::due_for_sync()`.
