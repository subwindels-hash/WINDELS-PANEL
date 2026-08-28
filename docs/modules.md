# MarvySocials — module index

Twenty-four sessions of repair work, each one module: find the defect, fix it,
prove it with a test *and* an end-to-end check, document why it mattered.

Everything below is verifiable with one command:

```bash
node tools/devdb/server.js --port 3399 --stats-port 3400 --db storage/devdb/marvy.sqlite &
node tools/devserver/server.mjs --port 8080 --host 0.0.0.0 --max-requests 300 &
bash tools/verify_all.sh --admin-password '…' --with-load
```

| # | Module | The defect that opened it | Doc |
|---|---|---|---|
| 0 | Pages and theme | A half-copied layout loaded a partial that did not exist: `/dashboard`, `/admin/customers`, `/admin/settings`, `/admin/api-keys` and `/admin/affiliates` all returned 500. | *(commit `3526e5e`)* |
| 1 | Hosted payment gateways | Six gateways were ~35-line scaffolds that fabricated checkout URLs and made no HTTP call at all. A junk-signed webhook could permanently block the genuine one. | [module-payment-gateways.md](module-payment-gateways.md) |
| 2 | Deposit reconciliation | Every pending deposit was marked FAILED after 7 days **without asking the gateway**. | [module-deposit-reconciliation.md](module-deposit-reconciliation.md) |
| 3 | SMM provider adapter | Panels answer HTTP 200 with `{"error": …}`; the adapter read that as success, so a wrong API key was ONLINE and a refused refill was "accepted". | [module-smm-provider.md](module-smm-provider.md) |
| 4 | Notifications and email | Four seeded templates were never sent and nothing ever wrote a notification row. The bell was permanently empty. | [module-notifications-email.md](module-notifications-email.md) |
| 5 | Reseller API v1 | **Every response had an empty body**, `GET /api/v1/services` was a 500 on every call, and authentication was unrated-limited. | [module-reseller-api.md](module-reseller-api.md) |
| 6 | Refunds and refills | A refused refill was reported as requested and then ignored for ever; a partial delivery that delivered nothing refunded zero. | [module-refunds-refills.md](module-refunds-refills.md) |
| 7 | Analytics | Revenue counted *attempts*: cancelled orders and failed top-ups were income. `wallets.total_spent` was displayed on three screens and written by nothing. | [module-analytics.md](module-analytics.md) |
| 8 | Service recovery | A purchase the vendor accepted without a reference could never be settled by any worker — charged, undelivered, invisible for ever. | [module-service-recovery.md](module-service-recovery.md) |
| 9 | Authorisation | 195 admin routes audited: all gated. The tool that *reported* on that counted a mention in a nav array as enforcement. | [module-authorization.md](module-authorization.md) |
| 10 | Support | Sixteen answered assistant questions locked the visitor out of signing in. Ticket attachments existed in the schema, the service and the media library — and in no controller. | [module-support.md](module-support.md) |
| 11 | Marketplace fulfilment | The module-8 sweep did not know what escrow is and would have refunded buyers of shipped goods; a refunded digital order kept its download. | [module-marketplace-fulfilment.md](module-marketplace-fulfilment.md) |
| 12 | Performance | Measured under 12,000 orders: every formatted price re-read its currency (24 queries on one page), feature flags cost nine point queries per page. | [module-performance.md](module-performance.md) |
| 13 | Deployment | Nobody had ever executed the shipped SQL. The dev server served `.env`. Every VTU purchase in a customer's history linked to a 404. | [module-deployment.md](module-deployment.md) |
| 14 | Pricing and coupons | `usage_limit_per_user` enforced nothing; the minimum spend was only checked when the code was typed; catalogues were priced one service at a time. | [module-pricing-coupons.md](module-pricing-coupons.md) |
| 15 | Certification | This index, `tools/verify_all.sh`, and the dev server's request-recycling fix. | [COMPLETION_AUDIT.md](COMPLETION_AUDIT.md) |
| 16 | Site chrome, brand and operations screens | Two competing public headers, `/api/docs` with no navigation at all, a logo whose artwork still said WINDELSOCIALS, sign-in copy hidden from screen readers, a hard-coded announcement bar, no cron screen and no contact map. | [module-site-chrome.md](module-site-chrome.md) |
| 17 | Private support attachments | Ticket attachments — bank statements, ID photographs — were public files protected only by a random filename. Anyone who ever saw the URL kept them for ever. | [module-private-attachments.md](module-private-attachments.md) |
| 18 | The coupon redemption race | The per-customer coupon cap was a `COUNT(*)` taken moments before the insert: two overlapping checkouts both passed it, and the money had already moved by the time the second row was written. | [module-coupon-race.md](module-coupon-race.md) |
| 19 | Naming the operator | The Terms said the trader was "the party that deployed this instance" and no field existed anywhere to say who that is — a panel holding prepaid balances that named neither its trader nor its data controller. | [module-legal-identity.md](module-legal-identity.md) |
| 20 | The admin dashboard's query cost | The first screen every staff member opens cost 31 queries, a third of them the same questions asked twice — the status GROUP BY ran twice per load and two nested revenue windows cost eight queries. | [module-dashboard-cost.md](module-dashboard-cost.md) |
| 21 | A link in the announcement bar | An outage notice that cannot point at the page explaining the outage is half a notice — and the banner's text colour setting had been silently overridden by a hard-coded black since module 16. | [module-announcement-links.md](module-announcement-links.md) |
| 22 | Pausing a background job | The cron screen could report and not act: stopping a job that was refunding live orders meant SSH-ing in at 2am to comment out a crontab line — which nothing in the panel would ever mention again. | [module-cron-control.md](module-cron-control.md) |
| 23 | Partial marketplace refunds | Escrow was all-or-nothing, so a two-dead-keys-in-five dispute was settled with a wallet adjustment — which paid the customer and left the order reading as paid in full, overstating revenue by exactly the amount returned. | [module-partial-refunds.md](module-partial-refunds.md) |
| 24 | The commission that outlived the sale | A referral commission was priced once and never revisited, so a refund landing after it accrued left the referrer paid a share of money the platform had given back. | [module-commission-resync.md](module-commission-resync.md) |
| 34 | PINs at sign-up, provider deletion, the contact inbox | Accounts started PIN-less and staff could never read a PIN back; a provider row was immortal once added; a visitor's contact email could be listed but not read or answered from the panel. | [module-pin-contact-inbox.md](module-pin-contact-inbox.md) |
| 35 | Administrators you can add, admin self-service, full-access impersonation | The administrators screen could only list; admins had no visible route to their own profile/email/avatar; impersonation was read-only by design, so staff could never act on a customer's behalf. | [module-admin-account-access.md](module-admin-account-access.md) |
| 36 | Coupons on every purchase surface | A code minted for a launch gave 10% off a marketplace basket and nothing at all on the four things the panel exists to sell — the SMM order form, the five VTU tabs, numbers, identity and gift cards had no coupon field, so the operator's promise and the customer's experience were two different things. | [module-coupon-domains.md](module-coupon-domains.md) |

## The pattern

Read back to back, the same defect keeps appearing in different costumes:

- **A refusal read as a success.** Gateways (1), providers (3), refills (6),
  cancellations (6), vendors (8). Every one of them ends with a customer told
  something happened that did not.
- **A rule that exists and is never applied.** `$earned` (7),
  `usage_limit_per_user` (14), the minimum spend (14), `revoke()` (11),
  ticket attachments (10). The column, the setting and the admin form all
  shipped; the query never read them.
- **Money that moves without anyone being told.** Refunds in four domains (8),
  partial deliveries (6), cancellations (6).
- **A number nobody had ever measured.** Revenue (7), query cost (12), the
  shipped SQL (13).

Each module doc ends with a "still open" section listing what could not be
proved in this environment and why — no live merchant sandbox, no MySQL 8, no
real provider account. Those are the honest edges of this work.
