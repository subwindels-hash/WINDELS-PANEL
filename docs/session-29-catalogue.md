# Session 29 — Catalogue pricing and shelf control

The last open gap from the [spec audit](rebuild-spec-audit.md) and the
[phase G write-up](session-28-analytics.md): every product domain could be
imported, sold, refunded and reported on — and none of them could be **priced**
without hand-written SQL.

## The gap

Every catalogue sync in this panel obeys the same rule, deliberately:

```php
'price'     => null,   // the vendor owns its cost, never our margin
'is_active' => 0,      // nothing becomes buyable without somebody deciding it should
```

That rule is right, and it is enforced everywhere — `upsert_from_provider()` in
all four product models writes `price` only when the row has none, `active()`
filters `price IS NOT NULL`, and every service re-checks with `NO_PRICE` before
charging. What it never had was the other half. A rule that refuses to invent a
price is only useful if a human can supply one.

They could not. A fresh install seeds 18 VTU networks, 7 number countries, 11
number services, 8 gift card brands and 3 identity checks, and **not one
sellable product**. Putting a data bundle on sale meant:

```sql
UPDATE vtu_products SET price = 300.00000000, is_active = 1 WHERE code = 'MTN-1GB';
```

— typed against production, with no validation, no audit entry, and nothing
stopping the four failure modes below.

## What shipped

| File | What it is |
| --- | --- |
| `application/libraries/CatalogueService.php` | All pricing rules, once, for four domains |
| `application/controllers/admin/Catalogue.php` | One screen: grid, edit, create, on/off switch |
| `application/views/admin/catalogue/{index,edit,_form}.php` | Grid, detail, shared field set |
| `application/models/*_product_model.php` | `admin_search`/`admin_count`, `create`/`update_fields`, `other_active` |
| `tests/unit/CatalogueTest.php` | 45 tests, 159 assertions |

Reading needs `services.view`; changing anything needs `pricing.manage` — a
permission that was seeded in session 03 and had gone unused in `application/`
ever since. STAFF has the first and not the second: a price is money.

## The four failure modes it refuses

**1. An active row with no price.** Not a cheap product — a broken one. The
service layer refuses the purchase with `NO_PRICE`, so the customer meets an
error at the checkout instead of a price on the shelf. Activation therefore
requires a price, and the check runs on both the edit form and the one-click
switch.

**2. A second variable-amount product.** `VtuService::variable_product()` is:

```php
$rows = $this->ci->Vtu_product_model->active_for($network_id, $service_type);
return $rows ? $rows[0] : null;
```

A second active airtime row on MTN does not error. It silently decides the
discount and the amount limits by sort order. `NumberService` has the identical
shape through `find_for_pair()`. Both are now refused at the point of
activation, with a message that **names the row already on sale** — an error
that says "a conflict exists" without saying where is an error the operator
cannot act on.

**3. A provider that cannot serve the domain.** Assigning an SMM panel to a data
bundle produces a product that charges the customer and then cannot be
dispatched. The picker is scoped to `Provider_manager::supported_types()` for
the domain, and the save re-checks it.

**4. A gift card with an assumed currency.** Migration 014 refuses to default
`recipient_currency` because a card whose currency we guessed would be a dollar
card sold as a naira one. The form refuses it for the same reason.

## What it warns about instead of refusing

Refusing these would refuse legitimate decisions; hiding them lets a typo ship.

- **Selling below cost.** A loss-leader and a fat finger look identical from
  here, and only the operator knows which. ₦42,000 gift cards sold for ₦4,200
  all weekend is the failure mode.
- **Zero stock on a number.** Advisory — it is a snapshot from the last sync —
  but `NumberService::reserve()` refuses `stock <= 0`, and an operator who is
  not told reads that as the panel being broken.
- **A card under a switched-off brand.** Invisible however it is priced, because
  `Giftcard_brand_model::sellable()` joins on the brand.
- **A variable-amount product with no limits.** Customers may top up any amount
  their wallet covers.

Warnings needed somewhere to go: `views/layouts/app.php` rendered only `success`
and `error` flashes, so a `warning` was written to the session and silently
dropped. It now renders all three.

## Two smaller things

**A variable-amount product never stores a row price.** Nothing reads it — the
customer names the amount — so a stored price is an authoritative-looking number
that never applies, which is how an operator "fixes" a margin by editing a field
with no effect. It is written as NULL regardless of what the form sends.

**`admin/services` was a dead route.** `$route['admin/services']` pointed at a
controller that does not exist, and the admin nav linked to it — so *Services*
in the sidebar had been a 404 for every operator since the nav was written. The
nav entry now points at the catalogue, and a test asserts the controller stays
absent (or gets its entry back).

## Testing

45 tests. The behavioural half runs the real `CatalogueService` and the real
product models against the migration-derived schema, then **buys through the
real `VtuService`/`NumberService`** — because a price that saves but does not
sell is exactly the failure this screen exists to prevent:

```php
$before = $app->vtuservice->data($user, [...]);        // NO_PRODUCT
$app->catalogueservice->save('vtu', $product, ['price' => '3000', 'is_active' => '1']);
$after = $app->vtuservice->data($user, [...]);         // ok
$this->assertSame('3000.00000000', $after['transaction']->amount);
```

Both invariants were verified to bite by removing them: dropping the activation
check fails 2 tests, dropping the one-live-row check fails 1.

The source-level half pins the same admin-surface guarantees the other domains
pin — POST-only mutations, `pricing.manage`, CSRF on every form, an audit entry
carrying the whole row before and after, `PER_PAGE` on the grid, action routes
before the catch-alls, and no pricing logic in the controller.

Suite: **825 tests / 7832 assertions / 0 failures** (was 780 / 7612).

No migration: the columns were always there. Nobody could reach them.
