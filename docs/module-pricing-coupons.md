# Module 14 — pricing and coupons

*Branch `arena/01a04558-windels-panel`. Follows module 13 (deployment).*

Three defects in the space between "the rule exists" and "the rule is applied",
plus a confirmation that the scariest money path in this area is already right.

---

## 1. `usage_limit_per_user` enforced nothing

`coupons.usage_limit_per_user` has been on the table since the shop shipped.
The admin form sets it. It defaults to **1**. And no query ever read it:
`Coupon_model::find_valid()` checked the code, the active flag, the date window
and the *global* usage cap — never the per-customer one.

So a code created as "one per customer" could be redeemed by the same customer
on every order they ever placed. That is precisely what happens to a public
discount code within hours of it appearing anywhere, and it is a direct
revenue leak that no screen in the panel would have shown.

`find_valid($code, $user_id = null)` now applies the cap when a customer is in
hand, `within_user_limit()` and `redemptions_by()` express the rule once, and
`CartService::apply_coupon()` refuses with *"You have already used this
coupon."*. `NULL` or `0` still means unlimited — an empty box in the admin form
must not silently make a live coupon unusable.

## 2. The minimum spend was only checked when the code was typed

`apply_coupon()` compared the subtotal against `min_order_amount`. `cart_view()`
— which renders the cart *and* is what checkout charges from — re-read the
coupon with `find_valid()` and never re-checked the minimum.

Qualify with a ₦6,000 basket, remove everything down to ₦1,000, check out: the
discount still applied. The cart is now re-validated on every render and every
charge against **this customer** and **this subtotal**, so the total shown and
the total charged obey the same rule.

## 3. Pricing resolved one service at a time

`PricingService::price_for()` costs two point queries — right for one service,
wrong inside the loops that render whole catalogues:

| Surface | Before | After |
|---|---|---|
| `/dashboard/mass-order` (20 services) | **49 queries** (40 of them pricing) | **11** |
| `GET /api/v1/services` (500-service panel) | ~1,000+ per call | 2, whatever the length |

`rates_for(array $services, $user)` applies the same three-step rule — customer
rate beats group rate beats list rate — in one query per price table, and
`price_for()` now delegates to it. The reseller API and the mass-order picker
use the batch; an anonymous visitor costs **zero** pricing queries.

## 4. What was already right, and is now pinned

The money path under a non-base display currency. With USD set as the display
currency at 0.00065, a real order placed over HTTP was charged
**₦0.18 — the base-currency amount** — the wallet moved by exactly that, the
receipt showed ₦, and only the public catalogue carried the USD *estimate*.
Prices are converted for browsing and never for charging, which is the
behaviour the currency module documented and nothing had verified end to end.

---

## 5. How it was verified

### Unit / integration — `tests/unit/PricingCouponTest.php` (12 tests)

A one-per-customer code is refused the second time and still works for a
different customer; a limit of three allows exactly three; an unset limit is
unlimited; the global cap still stops everyone; a cart whose coupon no longer
meets its minimum shows no discount and no applied coupon; a cart holding a
code this customer has already spent is charged in full; percentage and fixed
discounts are still capped by their ceiling and by the basket; the three-step
price fallback still resolves in the documented order; pricing 25 services
costs **two** queries; an anonymous visitor costs none.

**Suite: 1404 tests, 16584 assertions, 0 failures, 1 skipped.**

### End-to-end — `tools/devserver/pricing_check.mjs` (12 checks)

Against the running panel: the coupon applies once, is refused the second time
with the customer-facing message, and a cart still carrying the spent code
earns nothing; a minimum-spend coupon applies to a qualifying basket and the
discount is withdrawn once the basket shrinks below it; and the mass-order
picker prices its whole catalogue in at most two queries (read from the dev
database's stats channel).

Regressions all green: `shop_check` 45/45, `coupon_discovery_check` 20/20,
`currency_check` 28/28, `commerce_check` 24/24, `marketplace_bulk_check` 21/21,
`api_check` 31/31, `smoke` 24/24, `journey` 38/38, `security_check` 31/31,
`page_audit` (0 failing pages).

### Two test doubles corrected, not weakened

`OrderServiceTest` and `ServicesTest` both modelled pricing as *two point
queries* — the implementation, not the rule — so they broke when the rule was
resolved in bulk. Both now answer the batched shape (rows keyed by
`service_id`); every assertion about which rate wins is unchanged.
`FakeDb` gained `escape()` and `set()`, including the unescaped
`set('times_used', 'times_used + 1', false)` increment form the counters use —
without it, no test could exercise a usage limit at all.

---

## 6. Still open

- **Multi-currency wallets.** Wallets, orders and service transactions all
  carry a `currency` column and every row in this build is the base currency.
  Charging in a second currency would need conversion at the ledger boundary
  and a decision about which rate applies to a refund weeks later; the panel
  deliberately converts for display only, and the analytics caveat from module
  7 stands.
- **Coupons apply to the shop only.** SMM orders, VTU and the other domains
  have no coupon path. That is a product decision, not an oversight, but it is
  worth stating: an operator who expects a site-wide promo code will not find
  one.
- **`times_used` is incremented atomically; per-customer counting is a
  `COUNT(*)` over `coupon_redemptions`.** Two simultaneous checkouts by the
  same customer could in principle both pass the per-user check. The window is
  a few milliseconds and the loss is one extra discount; closing it properly
  needs a unique index on `(coupon_id, user_id, marketplace_order_id)` and a
  caught duplicate-key error, which is a schema change worth making the next
  time the shop tables are touched.
