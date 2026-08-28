# Module: SMM provider adapter

`StandardSmmAdapter` is the code every SMM order, status poll, refill and
cancellation in the panel passes through. It was 67 lines that assumed the
provider always behaves.

## The problem: these panels fail with HTTP 200

The "SMM panel API v2" that every reseller panel implements reports **every**
error as `HTTP 200` with `{"error": "..."}` — wrong API key, unknown order id,
service withdrawn, insufficient provider balance. The old adapter only checked
`http_code !== 200`, so a refusal was handed back as success:

```php
// before
$res  = $this->call(array('action'=>'balance'));
if ($res['http_code']!==200) return array('ok'=>FALSE, ...);
$data = json_decode($res['body'],TRUE);
return array('ok'=>TRUE,'data'=>$data);   // data = ['error' => 'Incorrect API key']
```

Consequences, all of them silent:

| Call | What the panel did with a refusal |
|---|---|
| `getBalance()` | Health probe recorded the provider **ONLINE**; operators saw a green provider with dead credentials |
| `getServices()` | Catalogue sync "succeeded" with 0 services |
| `requestRefill()` | Customer told the refill was accepted; nothing was requested |
| `requestCancel()` | Customer told the order was cancelled; it kept running |
| any | An HTML maintenance page decoded to `null` and was treated as valid data |

`requestRefill`, `getRefillStatus` and `requestCancel` did not look at the HTTP
status at all — a 500 was reported as success.

## What the adapter does now

Every call goes through one place that separates **transport failure**
(unreachable, HTTP ≥ 400), **provider refusal** (any of the three documented
error envelopes) and a **real payload**, and returns the `{ok, data|error}`
shape the rest of the panel already expects.

Beyond that:

- **Batch status is chunked to 100 ids.** A panel refuses an oversized batch
  *for the whole batch*, so on a busy panel the poller silently stopped
  updating every order. One failing chunk no longer discards the others.
- **One status shape.** Single and batch status both return
  `[provider_order_id => payload]`, including the list-shaped and positional
  variants some panels answer with. Vocabulary is left untranslated — mapping
  provider statuses onto the order state machine stays in `CronWorkers`, so the
  two cannot drift.
- **An order accepted without an id is a failure.** Without the provider's
  order id it could never be polled, refilled or cancelled; charging for an
  untrackable order is worse than refusing.
- **Refill and cancel understand both documented response shapes**, and a
  per-order refusal inside a list response (`{"cancel": {"error": …}}`) is a
  failure, not a success.
- Timeout comes from the provider row (`timeout_ms`), with a sane floor.

## The mock now matches

`MockProviderAdapter` answered in different shapes from the real adapter
(flat single status, no currency on balance). Development and the whole test
suite run on that mock, so any divergence means dry runs prove nothing. It now
mirrors the real envelope exactly, and a test asserts it.

## Tests

- `tests/unit/SmmAdapterTest.php` — 21 tests / 72 assertions with a scripted
  HTTP double: error envelopes (all three shapes), HTML bodies, transport and
  HTTP failures, balance normalisation, order id enforcement, single/batch/list
  status shapes, 250-id chunking, partial-chunk survival, both refill shapes,
  refused refill and cancel, plus mock/real shape parity.
- `tools/devserver/smm_provider_check.mjs` + `fake_smm_panel.mjs` — 13
  end-to-end checks against a real HTTP panel process: balance, catalogue,
  order placement, keyed status, wrong-key refusal, maintenance page, refused
  refill/cancel, 150-id batching (verified as two requests of ≤100 at the panel
  end), and finally `php index.php cron order_status` driving a real order to
  COMPLETED through the real adapter.

The check needs `HTTP_ALLOW_PRIVATE_HOSTS=true` in `.env` because the fake
panel is on localhost and `SecureHttpClient` blocks non-public hosts (SSRF
guard); it says so if the flag is missing. Outbound calls are issued from the
PHP CLI runtime, which is how cron runs in production.
