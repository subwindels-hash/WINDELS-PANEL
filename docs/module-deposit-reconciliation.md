# Module: deposit reconciliation

Makes the `payment_reconciliation` cron job do what its name claims. Before
this it was an expiry sweep with a dangerous default; now it settles deposits
whose gateway callback never arrived, and only writes off what it can prove was
never paid.

## What it used to do

```php
// every CREATED/PENDING deposit older than 7 days
$this->ci->paymentservice->mark_failed($tx->id,
    "Expired: no payment received within {$stale_days} days");
```

No gateway was ever asked. One lost webhook — a deploy during the callback, a
500, a firewall rule, a rotated secret that made the signature fail — turned a
**real payment into a written-off deposit**. From the customer's side the money
left their account and the wallet was never credited; from the panel's side the
row looked like an abandoned checkout.

## What it does now

Three passes, in this order:

1. **Replay stored callbacks that never finished.** `record_webhook()` leaves a
   row unprocessed when the credit itself failed (a ledger rollback, a database
   blip). Only `signature_valid = 1` rows are replayed.
2. **Ask the gateway.** For every deposit older than the grace period, call the
   adapter's `verify()` (added with the gateway module). Provider says paid →
   credit through `PaymentService::confirm()`, which is idempotent. Provider
   says failed → close it with the provider's own reason.
3. **Expire what is genuinely dead.** Only after the window, and only when the
   answer was neither "provider unreachable" nor "paid, but short".

### Rules

| Situation | Outcome |
|---|---|
| Callback stored but unprocessed | Replayed; wallet credited exactly once |
| Provider confirms payment | Credited, marked SUCCESS, source `RECONCILIATION` |
| Provider confirms failure | Closed immediately — no need to wait out the window |
| **Provider unreachable** | **Never expired**, at any age; retried next tick |
| **Paid but short** | **Never credited and never expired**; flagged for staff |
| No adapter to ask (manual transfer) | Aged out after the window, as before |
| Inside the grace period | Untouched |

An outage on our side must not cost a customer their money — that is the single
rule the whole worker is built around.

## Fixed on the way: replaying a webhook destroyed it

Both the sweep and the admin **Reprocess** button replayed a stored event
through `record_webhook()`, which re-runs signature verification. The signature
lives in HTTP headers that were never stored, so re-verification always failed
and the row was rewritten as `invalid signature` — a verified, genuine event
was destroyed by the act of retrying it. `PaymentService::reprocess_stored_webhook()`
now picks up after verification: it parses the stored payload, reopens the row
and re-runs only the matching and crediting. Admin reprocess uses the same path.

## Configuration

Admin → Settings → Deposits:

- **Wait for the callback (minutes)** — `deposit_grace_minutes`, default 20.
  How long a pending deposit is left alone before the provider is asked.
- **Close unpaid deposits after (days)** — `deposit_expiry_days`, default 7.

`cron/crontab.example` now runs the job every 5 minutes, matching
`config/marvy.php` (it said 15 while the config said 5).

## Staff visibility

A short payment writes `metadata.reconciliation` onto the deposit and the admin
payment screen shows a warning with expected vs received, saying explicitly that
nothing was credited and nothing was closed.

## Tests

- `CronWorkersTest` — 8 reconciliation tests: credited-not-expired when the
  provider confirms, closed on provider failure, **never expired when the
  gateway is unreachable**, short payment flagged rather than credited, grace
  period respected, verified-but-unprocessed callbacks replayed, and a source
  guard that only signature-verified rows are replayed and never through
  `record_webhook()`.
- `tools/devserver/reconciliation_check.mjs` — 10 end-to-end checks running the
  real `php index.php cron payment_reconciliation` against the dev database:
  a missed callback credits the wallet, an unreachable gateway leaves a 30-day-old
  deposit open, a fresh deposit is untouched, a dead manual transfer is expired
  with a readable reason, and a second run credits nothing twice.
