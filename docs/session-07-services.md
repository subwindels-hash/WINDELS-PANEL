# MarvySocials — Session 07: Services

> Public service catalog and customer favorites, built on the Session 02 schema
> (`services`, `service_categories`, `service_prices`, `user_service_prices`,
> `service_favorites`) and the existing `PricingService`.

## What shipped

| Area | Files |
|---|---|
| Public catalog (search, filters, sort, pagination) | `controllers/Services.php`, `views/public/services/index.php` |
| Public service detail (price, live total, related, favorite) | `views/public/services/detail.php` |
| Customer catalog + favorites list | `controllers/dashboard/Services.php`, `views/dashboard/services/index.php` |
| Favorite toggle (CSRF, POST-only, AJAX-capable) | `controllers/dashboard/Favorites.php` |
| Model helpers | `Service_model` (`find_by_public_id`, `active`, `search`, `find_by_slug` already present) |
| Shell wiring | favorites sidebar link in `views/layouts/app.php` |
| Routes | `services`, `services/(:any)`, `dashboard/favorites`, `dashboard/favorites/{add,remove}/(:any)` |
| Tests | `tests/unit/ServicesTest.php` |

## Behavior

### Public catalog (`/services`)

* **Search:** a single text box. When the term is three or more word characters
  the query uses the InnoDB `FULLTEXT` index on `services(name, description)` in
  natural-language mode, with a `LIKE` fallback on the name so short tokens and
  names still match. Empty/short terms fall back to `LIKE`.
* **Filters:** category (by slug), platform (e.g. `instagram`), and service type
  (`DEFAULT`, `CUSTOM_COMMENTS`, `PACKAGE`, `SUBSCRIPTION`,
  `MENTIONS_USER_FOLLOWERS`) — all applied server-side.
* **Sort:** popular (trending + sorting weight), price asc/desc, name A–Z, newest.
* **Pagination:** 12 per page, prev/next with the current query string preserved.
* Only `status = 'ACTIVE'` services are shown; the catalog joins
  `service_categories` for the platform filter and display name.

### Service detail (`/services/:slug`)

* Breadcrumb, description, start time, min/max, service type, and the
  refill/cancel/drip-feed/subscription badge set from the service flags.
* **Price:** per-1k (or per-package for `PACKAGE` services) with a client-side
  quantity total that recomputes on input. Authenticated visitors also see their
  resolved price from `PricingService` (user-specific → price group → default)
  highlighted when it undercuts the retail rate.
* **Order:** a quantity form posts to `/dashboard/new-order?service=<public_id>`
  (the new-order form is completed by the order engine in Session 09). Guests
  see a disabled "Log in to order" button.
* **Favorites:** a CSRF-protected POST toggle for authenticated users; the
  current state is shown (filled vs. outline star).
* **Related services:** up to four active services from the same category,
  cheapest first, never leaking the current service.
* No provider secrets, API keys, provider rates or internal IDs are rendered.

### Favorites

* `/dashboard/favorites` lists the user's saved services (joined through
  `service_favorites`) using the same card grid.
* `POST /dashboard/favorites/add/:public_id` and
  `POST /dashboard/favorites/remove/:public_id` are CSRF-protected, scoped to
  the authenticated user, and use `public_id` (never the internal id). They
  return JSON for AJAX requests and otherwise redirect back to the HTTP referrer
  when it is local (open-redirect safe), falling back to the services list.
* Favorites are idempotent: adding an already-favorited service is a no-op.

## Safety & rules

* Catalog browsing is read-only — no wallet, user or order mutation anywhere in
  the module.
* Pricing is always resolved through `PricingService`; there is no second code
  path computing prices in a controller or view.
* Quantity is bounded by `min_quantity`/`max_quantity`/`increment_step` on the
  detail form; the server-side order creation will re-validate in Session 09.
* The full-text query is parameterized via `$this->db->escape()`; user input is
  never interpolated raw into SQL.

## Follow-ups (later sessions)

* **Session 09 — Order Engine:** the new-order form consumes the `service`
  query parameter, validates the link/quantity, resolves the price, charges the
  wallet through `LedgerService`, and submits to the provider.
* **Session 15 — Admin:** category/service CRUD, provider sync, featured and
  trending flags, and the per-user/price-group editors behind
  `services.manage` / `pricing.manage`.
