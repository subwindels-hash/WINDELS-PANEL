# Unfinished work — MarvySocials

*As of 2026-08-28, branch `arena/01a04558-windels-panel`.*

**Progress:** items 11 and 12 are **closed** by module 17
([module-private-attachments.md](module-private-attachments.md)) and item 13 by
module 18 ([module-coupon-race.md](module-coupon-race.md)), and item 9 by
module 19 ([module-legal-identity.md](module-legal-identity.md)) and item 14 by
module 20 ([module-dashboard-cost.md](module-dashboard-cost.md)). Closed items stay
in the table below, struck through, so the list reads as a record rather than a
moving target.

There are **no half-built modules left**. Every module in
[modules.md](modules.md) is implemented, tested and driven end to end
(`tools/verify_all.sh` — 43 stages, 0 failed; 1427 PHP tests, 0 failures), and
a grep of `application/` finds no `TODO`, `FIXME`, "not implemented" or
scaffold markers.

What follows is the honest list of what is **not finished**, in three
categories: features deliberately left incomplete, known defects/races still
open, and things this sandbox cannot prove.

---

## A. Features that are incomplete by decision (name → what is missing)

| # | Name | What is not built |
|---|---|---|
| 1 | **Coupons — non-shop domains** | Coupons apply to the marketplace/shop only. SMM orders, VTU, numbers, identity and gift cards have no coupon path at all. An operator expecting a site-wide promo code will not find one. |
| 2 | **Multi-currency wallets** | `wallets`, `orders` and `service_transactions` carry a `currency` column and every row is the base currency. Currency is display-only; charging in a second currency needs conversion at the ledger boundary and a refund-rate policy. |
| 3 | **Marketplace partial refunds** | Escrow is all-or-nothing. Multi-item orders would need per-line release/refund. |
| 4 | **Physical shipping flow** | Exists and passes `shop_check` / `physical_product_check`, but was never re-audited against the escrow rules in module 11. |
| 5 | **Cron scheduling (write side)** | `/admin/cron` reports only. Nothing in the panel can install, pause, trigger or edit a job; on cPanel the crontab is still a manual paste of the generated block. |
| 6 | **Announcement bar links** | `announcement_text` is plain text. A clickable banner needs the CMS sanitising path, not a raw-HTML setting. |
| 7 | **Contact map — first-party render** | The map is a third-party iframe (OpenStreetMap / Google). It leaks the visitor's IP to that origin, which an EU operator must disclose. |
| 8 | **Brand artwork** | `assets/brand/*` is generated, not designed. Legible at every size the panel uses, but an operator with a real identity should replace the set (and the ratio table in `partials/brand_logo.php`). |
| ~~9~~ | ~~**Legal pages**~~ | **Closed (module 19).** A `legal` settings group records the entity, registration number, registered address, governing law, courts, notice email, privacy contact and supervisory authority; Terms, Privacy and the footer print them, say plainly when they are absent, and Preflight WARNs until they are filled in. |
| 10 | **Support assistant** | Deliberately not an LLM — it answers from a local knowledge base. Anything outside that file becomes a ticket. |

## B. Known defects and races still open

| # | Name | The risk |
|---|---|---|
| ~~11~~ | ~~**Digital-file URL after revocation**~~ | **Closed (module 17).** Proved by replaying a link captured *before* a refund: refused, and no fresh link can be minted. The concern was unfounded — `resolve_download()` re-checks revocation on every request — but the check that should have shown that was fetching a file that did not exist. Fixture corrected. |
| ~~12~~ | ~~**Ticket-attachment URLs**~~ | **Closed (module 17).** Attachments now live outside the document root and are served only by `Attachment::ticket()`, which checks the session, the ticket's owner and the internal-note flag. Migration 029 moves the files that already exist and breaks the old URLs. |
| ~~13~~ | ~~**Per-customer coupon race**~~ | **Closed (module 18).** Migration 030 adds `redemption_slot` and a UNIQUE index over `(coupon_id, user_id, redemption_slot)`; the slot is reserved before any charge and released if the checkout does not complete. Proved by two simultaneous `/checkout/place` requests: exactly one redemption. |
| ~~14~~ | ~~**Admin dashboard query cost**~~ | **Closed (module 20).** 31 queries → 20: the status GROUP BY is memoised and carries "today" and "stuck", two nested revenue windows cost one pass per table instead of eight queries, `users` and `tickets` are each scanned once. Held at 22 by `perf_check` under the 12,000-order load fixture. |
| 15 | **No index review under load** | SQLite's planner is not MySQL's, so no `EXPLAIN` work has been done on the real engine. |
| 16 | **`testAJobCannotOverlapItself`** | The suite's single skipped test — `flock` semantics under the PHP-wasm runtime. Predates this work. |

## C. Cannot be finished in this environment (no credentials / no services)

| # | Name | Blocked on |
|---|---|---|
| 17 | **Live gateway runs** — Paystack, Stripe, Flutterwave, PayPal, Razorpay, CoinPayments | Real merchant sandboxes. Adapters are written to the published APIs and driven against scripted doubles; the first live transaction on each should be watched. |
| 18 | **Live vendor runs** — VTpass, 5sim, Dojah, Reloadly | Real vendor accounts. `fake_smm_panel.mjs` reproduces the lying-200 behaviour, but not their real payloads. |
| 19 | **MySQL 8 verification** | No MySQL here. Two `deploy-verify.php` schema-*shape* checks can only pass on the real engine. |
| 20 | **Redis paths** | Redis-backed sessions, cache and rate limits are code-reviewed only; the file-backed fallbacks are what the suite exercises. |
| 21 | **Apache `.htaccess`** | No Apache. The rules are asserted as text and the dev server refuses the same paths. |
| 22 | **Docker / CI runner** | No Docker. `tools/verify_all.sh` is the same pipeline and is CI-ready, but has never run on a hosted runner. |

---

## Suggested order of attack

1. ~~**11 + 12**~~ — done, module 17.
2. **17 / 18** — point one gateway and one vendor at their sandboxes and re-run
   `gateway_check` and `smm_provider_check`. Nothing else de-risks a launch as
   much.
3. **19** — run `tools/verify_all.sh` once against real MySQL 8.
4. ~~**13**~~ — done, module 18.
5. ~~**9**~~ — done, module 19.
6. **1 / 2** — product decisions, not repairs. Only if the roadmap wants them.
