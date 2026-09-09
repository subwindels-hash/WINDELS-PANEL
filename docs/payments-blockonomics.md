# Bitcoin deposits via Blockonomics

MarvySocials takes Bitcoin through [Blockonomics](https://www.blockonomics.co/),
a **non-custodial** address service. Coins go directly to a wallet you control:
Blockonomics derives receive addresses from an extended public key (xPub) you
supply and notifies the panel as payments confirm. It never holds customer
funds, and the API key cannot move money.

---

## 1. What the operator configures

Everything lives in **Admin → Settings → Bitcoin and crypto deposits**. No file
needs editing.

| Setting | What it is |
| --- | --- |
| Accept Bitcoin (BTC) | Shows the option on Add funds |
| Blockonomics API key | blockonomics.co → Merchants → API |
| Callback secret | A random string you choose; also goes in the callback URL |
| Required confirmations | Network confirmations before crediting (default 2) |
| Address validity (minutes) | How long a quoted BTC amount stays valid (default 60) |

The API key and callback secret are stored as `secret`-typed settings: the
admin form shows a masked placeholder and **never renders the stored value back
into the page**, so rotating a key does not put it in page source, the browser
cache or a screenshot.

### Blockonomics side

1. Add your wallet's **xPub** under Merchants → Receive Addresses.
2. Set the **callback URL** to, with your own secret:

   ```
   https://marvysocials.com/webhook/blockonomics?secret=YOUR_CALLBACK_SECRET
   ```

3. Paste the same secret into Admin → Settings.

The `blockonomics` payment method ships **inactive**. Activate it in
Admin → Payments only after the API key is in place — the adapter refuses to
initiate a deposit it has no way to confirm, so an early activation cannot take
money it would then fail to credit.

---

## 2. How a deposit flows

```
customer picks Bitcoin on Add funds
        │
        ▼
POST /api/new_address ............ a fresh receive address, derived from your xPub
        │
        ▼
GET  /api/price?currency=NGN ..... live rate; the BTC amount is quoted and stored
        │
        ▼
customer sends BTC from any wallet
        │
        ▼
Blockonomics GETs the callback ... once at 0 confirmations, then per confirmation
        │
        ▼
wallet credited exactly once ..... at the configured confirmation threshold
```

---

## 3. The safety rules, and why each exists

**No rate, no deposit.** If the price endpoint fails, `initiate()` returns
`RATE_UNAVAILABLE` instead of quoting a guessed amount. Showing a customer a BTC
figure we invented is how underpayments happen.

**No secret, no credit.** The callback is an unauthenticated GET to a public
URL, so anything on the internet can hit it. With no callback secret configured,
`verify_webhook()` returns `NULL` — "cannot verify" — and `PaymentService`
stores the event for inspection while moving no money. A wrong secret is a hard
401.

**Underpayments never credit in full.** The quoted amount is stored at
initiation; the callback reports what actually arrived, in satoshis. A confirmed
payment short of the quote by more than 0.5% (exchange drift tolerance) is
recorded as `PARTIAL` and is *not* a success. Crediting the full deposit for a
partial payment is a direct loss.

**Repeated callbacks are idempotent.** The event id is
`address:txid:confirmation-status`. Replaying the same confirmation is a
duplicate and is dropped; the next confirmation is a new event that legitimately
advances the payment. On top of that, `LedgerService` credits against an
idempotency key, so even a bug upstream cannot double-credit.

**Unknown addresses are ignored.** A callback naming an address the panel never
issued is logged as unmatched and never credited.

---

## 4. USDT

There is a **USDT toggle** in settings, and it is off. Blockonomics' address
flow here is implemented for BTC only. Turning the toggle on does not create a
USDT receive flow — it is a placeholder for one, and the Add funds screen will
not offer USDT until that flow exists. This is called out explicitly rather than
shipping a switch that appears to work.

---

## 5. Verification status

Honest reporting of what has and has not been proven:

| Area | Status |
| --- | --- |
| Address issuance, rate quoting, refusal paths | **Verified with fakes** — `tests/unit/BlockonomicsTest.php`, 16 tests |
| Callback secret verification (missing / wrong / correct) | **Verified end to end** against the running panel |
| Confirmation threshold, underpayment, idempotency, unknown address | **Verified end to end** — `tools/devserver/blockonomics_check.mjs`, 14 checks |
| Wallet credited exactly once, deposit marked SUCCESS | **Verified end to end** |
| Live Blockonomics API request/response shapes | **Implemented, awaiting production credentials** |

The last row is the honest gap: every decision the panel makes has been
exercised, but no request has been made to the real Blockonomics service from
this environment. Before going live, do one small real deposit and confirm the
wallet credits at your configured confirmation count.

---

## 6. Troubleshooting

**Deposits stay pending.** Check that the callback URL is reachable over HTTPS
and carries the secret. Blockonomics retries, so a fixed URL will settle
outstanding payments on the next confirmation.

**Callbacks return 401.** The secret in the URL does not match the one in
Admin → Settings.

**Callbacks return 200 but nothing credits.** Look at Admin → Payments for the
transaction and the `blockonomics_addresses` row: a `PARTIAL` status means the
customer underpaid, which is deliberate and needs an operator decision.
