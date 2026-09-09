# Unfinished work — MarvySocials

*As of 2026-08-31, branch `arena/01a058f9-windels-panel`. Item 5's
auto-run remainder is closed by module 38
([module-cron-autorun.md](module-cron-autorun.md), commit `987c488`): the
panel now runs its own due jobs from ordinary traffic — no crontab
required — and the dead-DB crash in the `cron` CLI is fixed with it.
Item 22 moved: both workflows are now authored and stored in
[github-actions/](github-actions/); what remains is the one-time UI
activation, still blocked on the `workflows` permission.*

**Progress:** items 11 and 12 are **closed** by module 17
([module-private-attachments.md](module-private-attachments.md)) and item 13 by
module 18 ([module-coupon-race.md](module-coupon-race.md)), and item 9 by
module 19 ([module-legal-identity.md](module-legal-identity.md)) and item 14 by
module 20 ([module-dashboard-cost.md](module-dashboard-cost.md)) and item 6 by
module 21 ([module-announcement-links.md](module-announcement-links.md)) and item 5
by module 22 ([module-cron-control.md](module-cron-control.md)) and item 3 by
module 23 ([module-partial-refunds.md](module-partial-refunds.md)), and the
commission overpayment module 23 itself left open by module 24
([module-commission-resync.md](module-commission-resync.md)), and item 1 by
module 36 ([module-coupon-domains.md](module-coupon-domains.md)), and item 2
by module 37 ([module-multi-currency-wallets.md](module-multi-currency-wallets.md)), item 16
by the portable skip-branch test added in this session, and item 4 by the
physical-shipping escrow audit in this session
([module-physical-shipping-escrow.md](module-physical-shipping-escrow.md)).
Closed items stay in the table below, struck through, so the list reads as a
record rather than a moving target.

There are **no half-built modules left**. Every module in
[modules.md](modules.md) is implemented, tested and driven end to end
(`tools/verify_all.sh` — 48 stages, 0 failed re-confirmed 2026-08-31; 1658
PHP tests, 0 failures), and a grep of `application/` finds no `TODO`,
`FIXME`, "not implemented" or scaffold markers.

What follows is the honest list of what is **not finished**, in three
categories: features deliberately left incomplete, known defects/races still
open, and things this sandbox cannot prove.

---

## A. Features that are incomplete by decision (name → what is missing)

| # | Name | What is not built |
|---|---|---|
| ~~1~~ | ~~**Coupons — non-shop domains**~~ | **Closed (module 36).** One code now works on every purchase surface — SMM orders, the five VTU tabs, numbers, identity, gift cards and the shop — through the same `CouponService::quote()` rules and the module-18 slot reservation, with the per-customer limit enforced **across** domains. Migration 034 stamps each redemption with a `domain` and the order/transaction `public_id` as `reference`. The reseller API deliberately still cannot redeem coupons (a recorded product decision). |
| ~~2~~ | ~~**Multi-currency wallets**~~ | **Closed (module 37).** A wallet may hold any enabled foreign currency — chosen once, while empty, by the customer or staff, frozen forever after the first movement. Conversion happens at the single ledger boundary (LedgerService, the only wallet writer), so every purchase domain supports a foreign wallet with **no engine changes**; each conversion writes its own four-legged double entry through an `fx:CODE` translation account so each currency's books balance independently. The refund-rate policy is enforced in the ledger: a refund replays the rate **pinned on the charge**, so FX drift can never make a refund create or destroy money. Gateway deposits *denominated* in a foreign currency remain C-category work. |
| ~~3~~ | ~~**Marketplace partial refunds**~~ | **Closed (module 23).** `refund_partial()` returns part of an order's money with a cumulative ceiling, optional restock, the goods left in place, and the figure written to both `marketplace_orders` and `service_transactions` so revenue stops overstating. Per-line refunds across a multi-order basket are still per order. |
| ~~4~~ | ~~**Physical shipping flow**~~ | **Closed (this session, [module-physical-shipping-escrow.md](module-physical-shipping-escrow.md)).** Re-audited line by line against the module 11 escrow rules: purchase, the shipment state machine, release, refund and the sweep all hold. The audit found one real defect — a **part refund of a physical order in transit left the order uncloseable** (a part-refunded order can never be recorded delivered, so its escrow remainder rode the abandonment sweep back to a buyer who still receives the parcel: goods AND full money). `refund_partial()` now refuses any shipment-bound order whose parcel is not `DELIVERED` (`SHIPMENT_IN_TRANSIT`), pointing staff at the two honest options; a full refund stays available in transit, and the same part refund stands after delivery. Pinned by a new unit test, ten corrected money tests, and 14 new end-to-end checks (`physical_order_refund_check` 38/38). Two adjacent defects fixed along the way: four checks with hardcoded demo passwords (a README violation), and `marketplace_fulfilment_check` silently depending on other stages to top up its wallet. |
| ~~5~~ | ~~**Cron scheduling (write side)**~~ | **Closed (module 22 + module 38).** A job can be paused and resumed from the panel, with a required reason, a named consequence, an audited trail and a 24-hour expiry that resumes it automatically. A "Run now" button runs any job once from the screen through the same `CronRegistry` worker and `JobRunner` exclusive lock as the crontab tick (POST-only, `settings.manage`, paused jobs refused, every run recorded and audited). And since module 38 (commit `987c488`) the panel **runs its own due jobs from traffic**: a flock-throttled heartbeat on every web request and `/health/live` ping executes what the schedule says is due — most-overdue first, ≤3 jobs / 20 s budget, paused jobs silent, runs recorded exactly as crontab runs — so a host without any crontab still reconciles deposits and settles orders. Editing the *schedule* itself stays in `$config['cron']` + the generated crontab paste, by design (module 22: the panel and the crontab must not disagree about when). |
| ~~6~~ | ~~**Announcement bar links**~~ | **Closed (module 21).** A line may carry `[label](target)`; the anchor is built, never pasted through, and only site paths, http(s) and mailto are accepted. Raw HTML renders as visible text. |
| ~~7~~ | ~~**Contact map — first-party render**~~ | **Closed (this session; [module-site-chrome.md](module-site-chrome.md) §7).** The iframes are gone: `ContactMapService` resolves the operator's query on the server (`lat,lng` locally, free text with one Nominatim geocode cached 30 days), fetches the nine OSM tiles around the point on the server and caches them 30 days under `storage/cache/maps/`, and serves them from this origin at `/contact/map/tile/{key}/{i}/{j}` — a 96-bit key over the configured map, so the endpoint cannot proxy arbitrary tiles. A visitor on `/contact` now makes requests to exactly one origin; when there is no outbound route or the place is unknown the map box is omitted (address, phone, hours and the user-initiated "Open in maps" link remain) instead of rendering broken. The CSP's conditional `frame-src` relaxation is gone with it — `frame-src 'self'` is unconditional now that the panel has no iframes. Pinned by `ContactMapServiceTest` (15) and `contact_map_check.mjs` (28/28), with `chrome_check.mjs` §8 updated to match. |
| ~~8~~ | ~~**Brand artwork**~~ | **Closed (this session).** The set was audited file by file (every size, format and variant verified: 972×192 wordmarks at the documented 5.0625 ratio, 256 icon, 192/512 PWA, 16/32/48/180 favicons, multi-size ICO, coherent SVG sources) and the design is now recorded in [brand.md](brand.md): the mark, the palette, every file's role, where each variant renders, and the operator's replacement procedure — including the ratio table in `partials/brand_logo.php` that must follow a new wordmark. The stale styleguide line ("an A and two rising bars") now describes the actual gradient-M mark. What remains is what the panel can never do for an operator: choose the operator's own identity. |
| ~~9~~ | ~~**Legal pages**~~ | **Closed (module 19).** A `legal` settings group records the entity, registration number, registered address, governing law, courts, notice email, privacy contact and supervisory authority; Terms, Privacy and the footer print them, say plainly when they are absent, and Preflight WARNs until they are filled in. |
| ~~10~~ | ~~**Support assistant**~~ | **Closed (this session; [module-support.md](module-support.md)).** Deliberately not an LLM — it answers from the local knowledge base, and the second half of that design is now implemented: `SiteOperatorEngine` flags a question `unanswered` when nothing in the knowledge base covers it, and `Chat::message()` turns a signed-in customer's unanswerable question into a real support ticket on the spot (question verbatim as the body, `Site assistant:` subject, `source = 'assistant'` via migration 039 so staff can filter the hand-offs), naming and linking the ticket in the reply. A second unanswerable question within 24 hours joins the open ticket instead of duplicating it (`TicketService::recent_assistant_ticket()`); a visitor — who has no account to hang a ticket on — keeps the contact-form hand-off; an answerable question opens nothing. Pinned by three new unit tests and twelve new end-to-end checks (`support_check` 33/33). |

## B. Known defects and races still open

| # | Name | The risk |
|---|---|---|
| ~~11~~ | ~~**Digital-file URL after revocation**~~ | **Closed (module 17).** Proved by replaying a link captured *before* a refund: refused, and no fresh link can be minted. The concern was unfounded — `resolve_download()` re-checks revocation on every request — but the check that should have shown that was fetching a file that did not exist. Fixture corrected. |
| ~~12~~ | ~~**Ticket-attachment URLs**~~ | **Closed (module 17).** Attachments now live outside the document root and are served only by `Attachment::ticket()`, which checks the session, the ticket's owner and the internal-note flag. Migration 029 moves the files that already exist and breaks the old URLs. |
| ~~13~~ | ~~**Per-customer coupon race**~~ | **Closed (module 18).** Migration 030 adds `redemption_slot` and a UNIQUE index over `(coupon_id, user_id, redemption_slot)`; the slot is reserved before any charge and released if the checkout does not complete. Proved by two simultaneous `/checkout/place` requests: exactly one redemption. |
| ~~14~~ | ~~**Admin dashboard query cost**~~ | **Closed (module 20).** 31 queries → 20: the status GROUP BY is memoised and carries "today" and "stuck", two nested revenue windows cost one pass per table instead of eight queries, `users` and `tickets` are each scanned once. Held at 22 by `perf_check` under the 12,000-order load fixture. |
| 15 | **No index review under load** | SQLite's planner is not MySQL's, so no `EXPLAIN` work has been done on the real engine. |
| ~~16~~ | ~~**`testAJobCannotOverlapItself`**~~ | **Closed (this session).** The skip was load-bearing, not lazy: the aliasing was re-probed on the current build (two `fopen()` handles on one lock file — the second `flock(LOCK_EX|LOCK_NB)` returns `true` under emscripten, `false` on a kernel), so the *primitive* still cannot be asserted under wasm, and the visible platform skip stays. What the lock exists **for** now is pinned on every runtime by a new portable test, `testAnUnavailableLockIsSkippedWithoutRunning`: a directory placed where the lock file should be makes `fopen()` fail on every POSIX kernel and in the wasm filesystem alike, driving the exact skip branch a held lock would — skipped reported, work not run, no `RUNNING` row left behind. The suite is 1603 tests, 0 failures, and its one remaining skip is documented in the test itself, with a cross-reference to the portable coverage. |

## C. Cannot be finished in this environment (no credentials / no services)

| # | Name | Blocked on |
|---|---|---|
| 17 | **Live gateway runs** — Paystack, Stripe, Flutterwave, PayPal, Razorpay, CoinPayments | Real merchant sandboxes. Adapters are written to the published APIs and driven against scripted doubles; the first live transaction on each should be watched. |
| 18 | **Live vendor runs** — VTpass, 5sim, Dojah, Reloadly | Real vendor accounts. `fake_smm_panel.mjs` reproduces the lying-200 behaviour, but not their real payloads. |
| 19 | **MySQL 8 verification** | No MySQL here. Two `deploy-verify.php` schema-*shape* checks can only pass on the real engine. |
| 20 | **Redis paths** | Redis-backed sessions, cache and rate limits are code-reviewed only; the file-backed fallbacks are what the suite exercises. |
| 21 | **Apache `.htaccess`** | No Apache. The rules are asserted as text and the dev server refuses the same paths. |
| 22 | **CI runner** | `tools/verify_all.sh` is the same pipeline and is CI-ready; both GitHub Actions workflows are now **authored and stored** in [github-actions/](github-actions/) — `verify.yml` runs all 48 stages on `ubuntu-latest` (dev database + application server booted exactly as locally), `deployment-package.yml` builds and verifies the zip. What remains is the one-time activation: this environment's token is refused the `workflows` permission (the push is rejected by GitHub), so a human must paste each file to `.github/workflows/<name>.yml` via the UI — the click-path is in [github-actions/README.md](github-actions/README.md). |

---

## Suggested order of attack

1. ~~**11 + 12**~~ — done, module 17.
2. **17 / 18** — point one gateway and one vendor at their sandboxes and re-run
   `gateway_check` and `smm_provider_check`. Nothing else de-risks a launch as
   much.
3. **19** — run `tools/verify_all.sh` once against real MySQL 8.
   *(And activate the two authored workflows — [github-actions/README.md](github-actions/README.md);
   `verify.yml` then proves the pipeline on a hosted runner on every push,
   closing item 22.)*
4. ~~**13**~~ — done, module 18.
5. ~~**9**~~ — done, module 19.
6. ~~**1 / 2**~~ — done, modules 36 and 37.
7. ~~**16**~~ — done, this session (portable skip-branch test).
8. ~~**4**~~ — done, this session (physical-shipping escrow audit).
