# Module: hosted payment gateways

Completes the six gateway adapters that shipped as scaffolds — Paystack,
Flutterwave, Stripe, PayPal, Razorpay and CoinPayments — so an operator with a
merchant account can take card, wallet and crypto deposits without touching
code or SQL.

## What was actually wrong

Each adapter was ~35 lines with **no HTTP call in it at all**. `initiate()`
built a plausible-looking URL by string concatenation:

```php
// PaystackGateway, before
$checkout_url = 'https://checkout.paystack.com/' . ($this->public_key ?: 'demo');
```

A customer pressing *Pay* was redirected to a page that does not exist, while
the panel recorded a PENDING deposit that could never be reconciled. The
adapters were nevertheless listed in `implemented_gateways()`, so they also
took over webhook verification from the generic fail-closed envelope: with no
credentials configured, `verify_webhook()` returned `false` and a genuine,
correctly signed callback was **discarded**.

## What each adapter does now

| Gateway | Checkout | Webhook verification |
|---|---|---|
| Paystack | `POST /transaction/initialize` → `data.authorization_url` | HMAC-SHA512 of the raw body, `X-Paystack-Signature` |
| Flutterwave | `POST /v3/payments` → `data.link` | `verif-hash` compared against the configured secret hash |
| Stripe | `POST /v1/checkout/sessions` (form-encoded) → `url` | `Stripe-Signature` `t=`/`v1=`, HMAC-SHA256 over `t.body`, 5-minute tolerance |
| PayPal | OAuth2 → `POST /v2/checkout/orders` → `approve` link | `POST /v1/notifications/verify-webhook-signature` (PayPal verifies its own) |
| Razorpay | `POST /v1/payment_links` → `short_url` | HMAC-SHA256, `X-Razorpay-Signature`, using the **webhook** secret |
| CoinPayments | `cmd=create_transaction` signed with HMAC-SHA512 → `checkout_url` | HMAC-SHA512 of the raw IPN with the IPN secret, plus a merchant-id check |

Shared behaviour lives in `HostedGateway` (config resolution, HTTP, header
lookup, minor units, error surfacing). Each adapter also has a `verify()` that
asks the provider directly, for the case where a callback never arrived.

### Rules the adapters follow

- **Never invent a URL.** No provider link, no deposit — the customer sees the
  provider's own error message instead of a dead page.
- **Money moves on the callback, not the return trip.** The browser's return
  URL is something anyone can open.
- **`null` means "cannot verify".** With no secret configured (or PayPal
  unreachable) the event is stored for the operator and credits nothing —
  `false` would throw a real payment away.
- **The provider's status wins over the event name.** A `charge.success`
  carrying `status: failed` does not credit; a completed Stripe session that is
  not `paid` does not credit; a CoinPayments IPN credits only at status ≥ 100
  (or 2), never at "seen in the mempool".
- **Our reference travels both ways.** `internal_reference` is sent as the
  provider's reference/`client_reference_id`/`tx_ref`/`custom`, stored on the
  transaction at initiation, and matched on the way back.

## Security fix found while wiring this

`Payment_webhook_model::record_once()` deduplicated on `(gateway, event_id)`
*before* the signature was known, and any stored row counted as "already
handled". Gateway event ids are guessable (Paystack's are sequential integers),
so an attacker could POST a junk-signed callback carrying the id the real
payment would use, and the genuine delivery would then be dropped as a
duplicate — the customer pays and is never credited. A row closed as *invalid
signature* is now reopened when a correctly signed delivery of the same id
arrives; a replay of a **verified** event stays idempotent as before.
Pinned by `PaymentsTest::testARefusedWebhookDoesNotBlockTheGenuineOne` and by
the forged-then-genuine sequence in `gateway_check.mjs`.

## Operating it

1. **Admin → Settings → Card and wallet gateways** — paste the credentials
   (stored encrypted, never rendered back). Environment variables of the same
   name win, so containers can inject them instead.
2. **Admin → Payments → Deposit methods** *(new screen)* — switch the method
   on, set fee/bonus/limits/order. Each row shows whether its credentials are
   present. Previously this table could only be changed with SQL.
3. Register the callback URL with the provider: `https://<host>/webhook/<code>`.

A method that is enabled but unconfigured is **hidden from Add funds** rather
than failing at the last step, and saving it says so.

## Customer-visible additions

- The checkout payload (link, crypto address, coin amount, expiry, reference)
  is stored on the transaction, so **Deposits → Resume payment** works after a
  closed tab instead of forcing a second deposit.

## Tests

- `tests/unit/HostedGatewaysTest.php` — 26 tests / 160 assertions: real
  endpoints and payload shapes, signature verification (valid, forged, absent,
  replayed, expired), status normalisation, minor-unit conversion, env-beats-
  settings precedence, and a source sweep proving no adapter concatenates a
  checkout URL or still calls itself a scaffold.
- `tools/devserver/gateway_check.mjs` — 20 end-to-end checks over real HTTP:
  admin configuration, hidden-until-configured, an unreachable provider
  refusing cleanly with no wallet movement, a forged callback (401), a signed
  callback crediting exactly once, and a replay that does not double-credit.

## Not done here, and why

No adapter has been run against a live merchant sandbox — that needs real
credentials, which this environment does not have. Everything that can be
verified without them is verified above; the first live transaction on each
gateway should still be watched.
