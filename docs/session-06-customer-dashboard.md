# MarvySocials — Session 06: Customer Dashboard

> Builds the authenticated customer application on the Session 03 auth boundary
> and the Session 04 design system. All pages extend `Auth_Controller` and are
> server-rendered PHP views inside the shared app shell.

## What shipped

| Area | Files |
|---|---|
| Stats aggregate library | `application/libraries/DashboardStats.php` |
| Model helpers | `Order_model` (public lookup, joined listing, counts), `Notification_model` (listing/counts) |
| Overview | `controllers/dashboard/Dashboard.php`, `views/dashboard/index.php` |
| Orders (list + detail + new-order placeholder) | `controllers/dashboard/Orders.php`, `views/dashboard/orders/*` |
| Wallet (transactions + add-funds) | `controllers/dashboard/Wallet.php`, `views/dashboard/wallet/*` |
| Notifications | `controllers/dashboard/Notifications.php`, `views/dashboard/notifications/index.php` |
| Account (profile / security / API keys) | `controllers/dashboard/Account.php`, `views/dashboard/account/*` |
| Catalog / support / referrals / dripfeed / subscriptions placeholders | `controllers/dashboard/{Services,Tickets,Referrals,Dripfeed,Subscriptions}.php` + views |
| App shell | `views/layouts/app.php` — sidebar with icons, notification bell, mobile bottom nav |
| Icon set | `views/partials/icon.php` (Lucide-style SVG) |
| Tests | `tests/unit/DashboardTest.php` |

## Routes

All routes were already declared in `config/routes.php`; this session makes the
controllers behind them real. Customer routes live under `/dashboard/*` and are
gated by `Auth_Controller` (an unauthenticated visitor is redirected to `/login`,
with the requested path preserved for post-login return).

```
/dashboard                     overview (wallet, stats, recent orders & activity)
/dashboard/orders              order history (status filter + pagination)
/dashboard/orders/:public_id   order detail + status timeline
/dashboard/new-order           new-order form (Session 09 completes it)
/dashboard/services            browsable catalog (Session 07 adds tiers/favorites)
/dashboard/add-funds           wallet top-up (Session 11 adds checkout)
/dashboard/transactions        ledger transaction history
/dashboard/tickets             support (Session 13)
/dashboard/referrals           referral program (Session 14)
/dashboard/notifications       inbox + mark-read
/dashboard/profile             profile editing
/dashboard/security            password change, MFA/API-key status
/dashboard/api                 create/list/revoke API keys
```

## Data & safety

* **Read-only this session.** No order placement, no wallet movement happens
  from the dashboard. Money is only ever written by `LedgerService`; a CI guard
  confirms no `update('wallets'…)` exists outside it. The add-funds screen is a
  UI shell — gateway flows arrive in Session 11.
* `DashboardStats` sums money with SQL `SUM()` over `DECIMAL(20,8)` and returns
  strings (never floats), ready for `bccomp`/`number_format`.
* Orders are looked up by **public ULID + user_id** — internal sequential ids
  never appear in URLs or the UI, and a user can only see their own rows.
* Every state-mutating form includes the CI CSRF token; the shell passes the
  unread-notification count to every page for the bell badge.
* API key creation goes through `AuthService::create_api_key()` (raw key shown
  once, only `sha256` stored); revocation is a soft `revoked_at`.

## App shell

`layouts/app.php` renders a sidebar (desktop) and a five-item bottom tab bar
(mobile). The sidebar items for admins differ from customers and items are
hidden when the user lacks the required permission. The notification bell links
to `/dashboard/notifications` and shows a count badge. Active state is driven by
a `nav_active` variable each controller passes.

## Placeholders & follow-ups

The order/new-order, add-funds, drip-feed, subscriptions, tickets and referrals
screens are scaffolded UIs that point at their future sessions (09–14). They are
deliberately non-mutating and link forward so navigation never 404s.

## Verification

```bash
npm run build:css
php tools/phpunit_lite.php DashboardTest
```

The offline test asserts every `/dashboard/*` route maps to an existing
controller method, all customer controllers extend `Auth_Controller`, each sets
`nav_active`/unread count, the app shell contains the notification bell and
mobile nav, status/transaction labels are human-readable, and the overview
aggregate bundle is assembled correctly against a fake query builder.
