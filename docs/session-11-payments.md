# MarvySocials — Session 11: Payments

> Wallet deposits with a gateway interface, fee/bonus handling, idempotent
> transaction lifecycle, and signature-verified webhook reconciliation. The
> enabled **Manual/bank** gateway works end-to-end; hosted gateways plug in
> behind `GatewayInterface`.

## What shipped

| Area | Files |
|---|---|
| Payment engine | `libraries/PaymentService.php` |
| Gateway contract + manual adapter | `libraries/GatewayInterface.php`, `libraries/ManualGateway.php` |
| Models | `models/Payment_transaction_model.php`, `models/Payment_event_model.php` (webhook model extended) |
| Customer controller + views | `controllers/dashboard/Wallet.php`, `views/dashboard/wallet/{add_funds,deposits}.php` |
| Public webhook endpoint | `controllers/Webhooks.php` (`POST /webhook/(:gateway)`) |
| Routes | `dashboard/wallet/deposit`, `dashboard/wallet/deposits/(:any)` |
| Tests | `tests/unit/PaymentsTest.php` |

## Deposit flow (`PaymentService::deposit`)

1. Resolve and validate the **payment method** (active, amount within
   `min_amount`/`max_amount`, 3-letter currency).
2. Compute the **fee** (`fee_percent` + `fee_fixed`) and **bonus**
   (`bonus_percent`). The `credited_amount` = amount − fee + bonus, stored as
   `DECIMAL` strings.
3. Create a `payment_transactions` row (`CREATED`) with a normalised
   idempotency key; a repeat request returns the original transaction.
4. Delegate to the gateway's `initiate()`. The Manual gateway returns `PENDING`
   + bank instructions; hosted gateways return a `redirect_url`.
5. Record a `payment_events` transition `CREATED → PENDING`.

## Confirmation & reconciliation (`confirm`)

* On admin approval (manual) or a verified success webhook, `confirm()` credits
  the wallet **exactly once** via `LedgerService::credit('DEPOSIT', ...)`,
  using an idempotency key of `payment:credit:<tx>`.
* It then writes the `wallet_transaction_id`, sets `status = SUCCESS` and
  `verified_at`, and appends a `payment_events` row.
* Confirming an already-`SUCCESS` transaction returns `duplicate=true`;
  confirming a `FAILED` transaction returns `BAD_STATE`.

## Webhooks (`POST /webhook/:gateway`)

* Raw body is read from `php://input`; headers normalised (including
  `getallheaders` fallback).
* `Payment_webhook_model::record_once()` enforces the `(gateway_type, event_id)`
  unique constraint — duplicate deliveries are acknowledged and never
  reprocessed.
* The gateway's `verify_webhook()` decides signature validity; invalid
  signatures are stored with `signature_valid = 0` and rejected (401).
* Non-terminal events are recorded and marked processed without side effects;
  success events resolve the transaction (`provider_tx_id` or idempotency key)
  and call `confirm()` with source `WEBHOOK`.
* The endpoint never starts a session/cookie and returns JSON; it replies 200
  to replayable/non-fatal payloads to avoid endless retries.

## Safety rules

* Only `PaymentService` credits a wallet for a deposit — controllers never
  call `LedgerService` directly or insert wallet transactions (verified by a
  test).
* All money is processed as bcmath strings; the credited amount is frozen on
  the transaction row.
* Public IDs are ULIDs; internal IDs never appear in URLs.
* Gateway credentials live in env and are decrypted only inside adapters; the
  Manual gateway needs no secret.
* Every status change appends an immutable `payment_events` row.

## Follow-ups

* Hosted adapters (Stripe/Paystack/Flutterwave/Razorpay/CoinPayments)
  implement `GatewayInterface`; the registry in `config/payment_gateways.php`
  already lists them (disabled). The webhook controller is gateway-agnostic.
* The admin approval screen for manual deposits arrives in Session 15.
