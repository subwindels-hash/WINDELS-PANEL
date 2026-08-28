# Module: reseller API v1

The reseller API had authentication, scopes, rate limiting, idempotency and
usage logging in code — and nothing had ever exercised it over HTTP. The first
request this module made against it found that **every response had an empty
body**.

## The three real defects

### 1. Every response was blank

```php
$this->output->set_status_header($code)->set_output(json_encode($out));
exit;
```

CodeIgniter only writes its output buffer during its own teardown, which
`exit` skips. So the whole API answered with a bare status code, `text/html`,
and **no body** — including every authentication error a client needs to read
(`MISSING_API_KEY`, `INVALID_API_KEY`, `IP_NOT_ALLOWED`, rate limits). A
reseller integration had nothing to parse and no error code to branch on.
Responses now flush explicitly through one `respond_json()` helper, the same
way `MY_Controller::json()` already did.

### 2. `GET /api/v1/services` was a 500 on every call

```php
$total = $this->db->count_all_results('services', false);       // registers FROM services
$rows  = $this->db->…->get('services')->result();               // registers it AGAIN
```

`FROM services, services` — a cross join. The flagship endpoint of the API,
the one every reseller integration calls first, could never have worked.

### 3. Authentication itself was unlimited

Rate limiting started *after* a key was accepted, so API keys could be guessed
as fast as the server would answer. Failed authentications are now counted per
IP **before** the key is checked (`rate_limits.api_auth`, 20 per 5 minutes),
using `peek()` for the refusal so a client hammering a locked window cannot
extend its own lockout for ever.

Also fixed: `rate_limits.api_orders` was configured and read by nothing, so
placing orders was bounded only by the global per-key limit — order creation
now takes that tighter window as well, and a freshly placed order no longer
returns `"service": null` in the documented payload.

## Rate limiter

Counters were written to `sys_get_temp_dir()`. On shared hosting that is
frequently shared between accounts and swept by the host, so counters could be
read, poisoned or reset from outside the panel — while the application already
ships a private, web-inaccessible `storage/cache/ratelimit`. That path is used
first, with the system temp directory as a last resort.

File counters are also per-node, so two web servers behind a load balancer each
granted the full limit. When Redis is configured (the compose stack ships it)
counters go there and every node shares one window; Redis being unreachable
falls back to files rather than failing the request.

## Scoped keys reached the people who create keys

Scopes were enforced by the API and settable only by an admin — a customer
creating their own key always got full wallet-spending access. The key form now
offers full access or a chosen set of scopes, taken from `ApiKeyPolicy::scopes()`
so there is no second list to drift, with anything unknown dropped.

## Tests

- `tests/unit/ApiContractTest.php` — 12 tests: the response really is flushed,
  the services query no longer duplicates its FROM, the auth guard runs before
  authentication and uses `peek()`, both order endpoints take the write limit,
  counters land in the panel's own directory, limits/windows/rollover/peek
  behave, an unwritable directory fails open instead of 500-ing, Redis falls
  back to files, and every scope the API requires is one a key can be granted.
- `tools/devserver/api_check.mjs` — 31 end-to-end checks with a key created
  through the dashboard: envelope and request id on success and failure,
  missing/invalid/revoked key, balance, paginated services, order placement,
  **idempotency proven at the database** (same key → same order, one row),
  order read-back and bulk status, malformed JSON, wrong method, scope
  enforcement (allowed scope 200, missing scope 403, read-only key cannot
  order), usage logging including denied calls, and documentation parity —
  every documented endpoint resolves to a route and every documented scope is
  one the code enforces.
