# Module 11 — marketplace fulfilment

*Branch `arena/01a04558-windels-panel`. Follows module 10 (support).*

Three defects, all at seams between things that each work on their own — which
is where this build keeps hiding them. One of them was **introduced by module
8 three commits ago**, which is the strongest argument for doing this pass at
all.

---

## 1. The stuck-purchase sweep did not know what escrow is

Module 8 added `service_recovery`, a sweep that closes and refunds service
purchases nothing else can settle: in flight for more than
`service_abandon_hours` (24), or with no provider reference to poll at all.
That is correct for a VTU top-up, which settles in seconds.

A **marketplace** purchase sits in `PROCESSING` for the whole inspection
window — 72 hours by default, up to 30 days — because that is precisely what
escrow *is*. The buyer's money is held on purpose, and `marketplace_release`
is the worker that ends the hold.

So the sweep would have:

- refunded buyers of goods that had already been delivered or shipped,
- left the marketplace order at `PAID`/`DELIVERED` with the stock still
  decremented and the digital download still live,
- and then broken the release worker, which would try to settle a service
  transaction the sweep had already made terminal.

### The fix

`service_recovery` is now domain-aware:

- `recovery_due()` gives MARKETPLACE its own window —
  `max(service_abandon_hours, marketplace_auto_release_hours + 24h)` — so an
  order is only treated as abandoned once the release worker has plainly
  stopped running, which is a genuine fault worth acting on. Skipped rows are
  reported (`skipped` in the job summary) rather than silently ignored.
- When it *does* act, it does not reverse the ledger behind the marketplace's
  back: `recover_marketplace()` calls `MarketplaceService::refund()`, the
  method that already claims the escrow row, refunds through
  `TransactionEngine`, restores the stock and writes the order event. A bare
  refund would have left the order and the stock behind.

---

## 2. A refunded digital order kept its download

`ShopDeliveryService::revoke()` has existed since the shop shipped, wired to an
admin button and nothing else. Every refund path — a dispute resolved in the
buyer's favour, an admin goodwill refund, and now the abandonment sweep —
returned the money and left the file in the buyer's **My Downloads**, live, for
ever. They kept the product and the payment, and no screen in the panel ever
mentioned it.

### The fix

`ShopDeliveryService::revoke_for_order()` revokes every delivery granted by one
order, audits each one, and never throws — it runs after the refund has
committed, and a download row that will not update must not undo a refund.
`MarketplaceService::refund()` calls it, so all three refund paths take the
goods back with the money. A physical order simply has no delivery to revoke.

---

## 3. A customer without a form token could buy exactly once, ever

`dashboard/Marketplace::buy()` built its idempotency key as
`sha1($_POST['form_token'])`. The listing page does send a token — but when it
is missing or blank, `sha1('')` is a **constant**, so every tokenless purchase
by the same customer produced the same key. `TransactionEngine` correctly
recognised that as a duplicate and returned the *original* transaction: no
charge, no new order, and a redirect to a purchase from days ago. The e2e check
written for this module hit it on its second run.

The same construction was in `dashboard/Vtu::buy()`.

Both now return `null` when no token was supplied: only a token the client
actually sent can deduplicate anything, and a missing one means no
double-click protection — not a permanent one-purchase limit.

---

## 4. How it was verified

### Unit / integration — `tests/unit/MarketplaceFulfilmentTest.php` (7 tests)

Real engine, real ledger, real escrow, real schema: a purchase 48 hours into a
72-hour escrow is skipped by the sweep with no money moved; an escrow nobody
released for 30 days is refunded; the refund puts the stock back; refunding a
digital order revokes the download and records why; a physical refund has
nothing to revoke and still succeeds; a released order is never swept away
however old; and the sweep is wired to the service that owns escrow rather than
reimplementing half of it.

**Suite: 1385 tests, 16516 assertions, 0 failures, 1 skipped.**

### End-to-end — `tools/devserver/marketplace_fulfilment_check.mjs` (15 checks)

A real ₦1,500 digital purchase over HTTP: the wallet is charged, a unit leaves
the stock, the download is granted and appears in My Downloads. Aged 48 hours,
the sweep leaves everything alone. Aged 40 days, the sweep refunds the buyer,
returns the stock to the shelf, revokes the download, and My Downloads stops
offering the file.

```
node tools/devserver/marketplace_fulfilment_check.mjs --admin-password '…'
15/15 checks passed
```

Regressions all green: `shop_check` 45/45, `marketplace_bulk_check` 21/21,
`physical_product_check` 21/21, `physical_order_refund_check` 24/24,
`service_recovery_check` 17/17, `commerce_check` 24/24, `smoke` 24/24,
`journey` 38/38, `security_check` 31/31, `support_check` 21/21, `page_audit`
(0 failing pages).

---

## 5. Still open

- **Digital files are protected by an unguessable URL, not by a session** —
  the same limitation recorded for ticket attachments in module 10. Revocation
  now stops the panel from *handing out* a link, and the download route checks
  the revoked flag, but a URL captured before revocation still resolves to the
  file until the storage key is rotated. Moving the digital store outside the
  document root is the deployment-level fix.
- **Physical shipping** has its own status flow (`shop_check`,
  `physical_product_check`) and was not re-audited here; nothing in this module
  changes it.
- **Partial refunds of a marketplace order** are not supported: escrow is
  all-or-nothing by design, which is the right default for a single-seller
  platform but would need revisiting if multi-item orders arrive.
