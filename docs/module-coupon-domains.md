# Module 36 — coupons on every purchase surface

*Branch `arena/01a04991-windels-panel`. Follows module 35 (administrators,
self-service, impersonation).*

Item 1 of [unfinished.md](unfinished.md) ("A. Features that are incomplete by
decision"), closed — the last line of which module 18 left behind: *"Coupons
remain shop-only. Nothing here changes that."* This changes that.

---

## 1. What was wrong with shop-only

A coupon worked at exactly one till. The operator who minted `LAUNCH10` could
give a customer 10% off a marketplace basket of gift cards someone else
listed, but the same customer buying airtime, renting a WhatsApp number,
running an identity check or ordering 5,000 TikTok views — the four things the
panel actually exists to sell — typed the code and was silently ignored,
because those forms had no coupon field at all. There was nothing to reject;
the code simply went nowhere.

That is worse than a missing feature. It is a promo tool that tells the
operator one story ("create a code, customers save money") and the customer
another ("this field does not exist"). Every domain had its own pricing
surface — the order form, five VTU tabs, the number, identity and gift-card
screens — and each one priced money out of the wallet with no discount step.

## 2. One code, any checkout — not five coupon systems

The design decision that shaped everything else: **a coupon is site-wide, not
domain-scoped.** There is no `domain` column on `coupons`. A code is a
promise of money off, and the domain it is redeemed against is a fact about
the redemption, recorded when it happens, not a permission checked up front.

- `CouponService::quote($user, $code, $subtotal, $domain)` is the single
  pricing verb every surface calls: same validity window, same minimum spend,
  same per-customer and global limits, same percent/fixed math and cap as the
  cart has always enforced — just with a subtotal that now comes from an order
  charge or a VTU bundle instead of a basket.
- `Coupon_model::reserve_redemption()` — the slot, the counter, the UNIQUE
  index from module 18 — is unchanged and is what protects the per-customer
  limit **across** domains: use the code on an SMM order and the same code is
  refused on the airtime form with *"You have already used this coupon."*
- Migration **034** widens `coupon_redemptions` with `domain`
  (`SHOP`, `SMM`, `VTU`, `NUMBER`, `IDENTITY`, `GIFTCARD`; existing rows are
  stamped `SHOP`, which is what they were) and `reference` — the order's or
  transaction's `public_id` — plus an index over `(domain, reference)` so the
  order detail page can find its redemption without a table scan.

Which engine owns the redemption follows from which engine already owned the
money:

| Domain | Priced by | Reservation | `reference` |
|---|---|---|---|
| SHOP | `ShopCheckoutService` (unchanged) | as of module 18 | `marketplace_order_id` kept, `reference` NULL |
| SMM | `OrderService::place()` | after the idempotency check, before the charge | order `public_id` |
| VTU / NUMBER / IDENTITY / GIFTCARD | `TransactionEngine::execute()` | after the idempotency check, before the charge | `service_transactions.public_id` |

The reservation **precedes** the charge in both engines, and every failure
path after it — insufficient balance, provider refusal, a persisted order that
cannot be submitted, a vendor outage that refunds — releases the slot through
the same `release_redemption()` the cart uses. A failed purchase must never
burn a customer's single use of a launch code; that was the lesson of module
18 and it travels with the mechanism.

## 3. Money rules that had to be decided, not discovered

**The discount is computed on the charge, after product pricing.** A VTU
bundle that already costs ₦980 for ₦1,000 of face value (the vendor's 2% is
the platform's margin to give or keep) takes a 10% coupon on ₦980 → ₦882.
The customer sees one price; the ledger sees one number; `service_transactions`
records `coupon_code` and `coupon_discount` in its metadata so support can
answer "why was I charged this?" from the receipt alone.

**A 100% coupon is a real purchase that charges nothing.** The wallet
transaction is skipped entirely rather than written as a zero — a ₦0 ledger
row is noise, and its absence is the honest statement — but the redemption is
still recorded and `times_used` still moves, because a free purchase is
exactly the kind a customer will happily repeat.

**Idempotency outranks the coupon.** A retried POST resolves to the original
transaction before any quote runs, so a double-click cannot burn a second
reservation or double-discount anything.

**The reseller API (`Api_v1::create_order`) deliberately cannot redeem
coupons.** The API prices programmatically at volume; a promo code is a
human-facing gesture. The payload is built explicitly *without* `coupon_code`,
and a source-gate test fails if anyone quietly adds it. That is a product
decision recorded in code, not an omission — flip it consciously, not by
accident.

## 4. The customer's side

All nine purchase forms — the order form, the five VTU tabs (airtime, data,
cable, electricity, education), numbers, identity, gift cards — carry the same
field: `coupon_code`, uppercased, 32 characters, one hint line ("Applied when
the order is placed"). No second validation path: whatever is typed goes
through `quote()` and gets the same refusal messages the cart has always
given — *not valid or has expired*, *already used this coupon*, *requires a
subtotal of at least ₦N* — with the wallet untouched. On success the
confirmation flash says what it did: *"Coupon LAUNCH10 applied — you saved
₦98.00."* The order detail page carries a `−₦98 coupon` badge on the charge
line, resolved from the redemption's `reference`.

## 5. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php            # 1580 tests, 17895 assertions, 0 failures
node tools/devserver/coupon_domains_check.mjs                      # 29/29
node tools/devserver/php_run.mjs tools/build_production_sql.php    # schema regenerated
bash tools/build_deployment_package.sh                             # application-deployment.zip rebuilt
```

`tests/unit/CouponDomainsTest.php` (12 tests) drives every domain's service
through the harness: the quote rule matrix (no code, unknown code, already
used, below minimum, percent-with-cap, fixed clamped to the subtotal,
case-insensitive matching); an SMM order discounted to the kobo with the
ledger still balanced; a VTU purchase at face ₦1,000 → ₦980 product price →
₦882 with the coupon, metadata included; number rental, identity check and
gift-card purchases; a vendor outage that refunds and **releases the slot**;
the per-user limit travelling across domains; a 100% coupon charging nothing
and still recording the redemption; the legacy SHOP attach still writing
`marketplace_order_id`; and source gates — all nine forms carry the field,
both engines carry reserve/release, and `Api_v1.php` does not.

`coupon_domains_check.mjs` proves it against the running panel: admin creates
three coupons through the real admin form, funds a customer through the real
wallet-adjust form; the customer places an SMM order and buys airtime with
coupons, both charges land at the discounted total, both wallets move by
exactly that, both redemptions carry the right `domain` and `reference`, the
SMM code is refused on the VTU form, an unknown and a below-minimum code
refuse the order without touching the wallet or writing a redemption row, and
`times_used` agrees with the rows.

`migration_version` moved to 34; `database/marvysocials.sql` and
`application-deployment.zip` were regenerated and re-verified
(`CpanelDeploymentTest`, `MarketplaceTest`, `SchemaTest` green).

## 6. Still open

- **Shop `reference` stays NULL by design** — a shop redemption's link is its
  `marketplace_order_id`, which migration 034 leaves exactly where it was.
- **The global `usage_limit`** remains increment-protected rather than
  constraint-protected (module 18's open edge; unchanged by this work).
- **Multi-currency** (item 2) is untouched: coupons discount in the base
  currency, like everything else on the platform.
