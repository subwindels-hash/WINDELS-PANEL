# MarvySocials — Completion Audit (2026-08-28)

Branch: `arena/01a04558-windels-panel`
Standard applied: **find the defect → fix it → prove it with a test *and* an
end-to-end check → document why it mattered**.

This supersedes the audit of 2026-08-19, which was written by file inspection
against a different branch and before any of the work indexed in
[modules.md](modules.md). Its central claim — "code completeness ~90% verified
by direct inspection" — is exactly the kind of statement fifteen modules of
repair have since disproved: almost everything it marked **PASS** *existed*,
and a good deal of it did not *work*. Six payment gateways were 35-line
scaffolds that never made an HTTP call. The reseller API returned an empty body
on every request. Every pending deposit was written off after seven days
without asking the gateway. None of that is visible from reading file sizes and
method names, which is why nothing in this document is claimed on that basis.

---

## A. What is proven, and how

Everything here is reproducible with one command:

```bash
node tools/devdb/server.js --port 3399 --stats-port 3400 --db storage/devdb/marvy.sqlite &
node tools/devserver/server.mjs --port 8080 --host 0.0.0.0 --max-requests 300 &
bash tools/verify_all.sh --admin-password '<demo password>' --with-load
```

Last full run: **45 stages, 45 passed, 0 failed.**

| Stage | What it proves | Result |
|---|---|---|
| PHP syntax | 480 files parse | pass |
| JS behaviour tests | 13 jsdom checks of the delegated event layer | 13/13 |
| **phpunit-lite (full suite)** | unit + integration against real models, real ledger, real schema | **1404 tests, 16584 assertions, 0 failures, 1 skipped** |
| production SQL | `database/marvysocials.sql` regenerates from migrations + seeder | pass |
| deployment package | `application-deployment.zip` builds and passes its own validator | pass |
| smoke / journey / page_audit / link_crawl / image_audit | every public and authenticated route answers; 160 pages crawled, 0 dead links; 17 images, 0 broken | 24/24, 38/38, 0 failing, 0 problems, 0 broken |
| responsive / ux_separation / content | layout, customer-vs-staff separation, CMS round trip | 16/16, 58/58, 18/18 |
| commerce / gateway / reconciliation / refunds / service_recovery | the money paths, including refusal and abandonment | 24/24, 20/20, 10/10, 32/32, 17/17 |
| marketplace_fulfilment / physical_order_refund / physical_product / shop / marketplace_bulk | escrow, shipping, digital delivery, revocation | 15/15, 24/24, 21/21, 45/45, 21/21 |
| pricing / coupon_discovery / currency / earnings / affiliate_withdrawal / fundsvera / blockonomics | discounts, price groups, display currency, referral money | 12/12, 20/20, 28/28, 24/24, 47/47, 38/38, 14/14 |
| smm_provider | the real adapter against a fake panel that lies the way real ones do | 13/13 |
| admin / settings_validation / feature_flags / notifications / support / api / analytics / security / pin / pin_rotation | staff surfaces, the reseller API, the authorisation matrix, PIN lifecycle | 18/18, 20/20, 32/32, 22/22, 21/21, 31/31, 20/20, 30/30, 13/13, 12/12 |
| **deployment_check** | the shipped zip extracted into an empty account, the shipped SQL imported into an empty database, the site booted on it and signed into with the documented passwords | **35/35** |
| perf_check (under 12,000 orders) | query cost of every heavy screen, and that no list page's cost grows with its rows | 40/40 |

## B. What each module fixed

See [modules.md](modules.md) for the index, one line per defect, with links to
the fifteen module documents. Each of those ends with its own "still open"
section.

## C. Environment limits — unchanged, and honestly the same as before

These are properties of this sandbox, not of the code. Where a limit exists,
the work was arranged so that the *logic* is still proved and only the last
mile is not:

| Limit | What it blocks | What was done instead |
|---|---|---|
| No PHP binary | native PHPUnit, PHPStan, composer | the whole suite runs on a PHP 8.2 wasm runtime through `tools/devserver/php_run.mjs`; 1 test skipped (`flock` semantics under wasm) |
| No MySQL 8 | real DECIMAL/ENUM/FK behaviour, index plans | a MySQL-wire-protocol server over SQLite (`tools/devdb`), with its fidelity gaps documented; `deploy-verify.php`'s two schema-*shape* checks can only pass on real MySQL |
| No Redis | Redis-backed sessions/cache/rate limits | the file-backed fallbacks are exercised; the Redis paths are code-reviewed only |
| No Docker / no CI runner | compose stack, GitHub Actions | `tools/verify_all.sh` is the same pipeline, runnable here and in CI |
| No live merchant sandboxes | Paystack / Stripe / Flutterwave / PayPal / Razorpay / CoinPayments end to end | adapters written against the published APIs and driven against scripted doubles; signature verification and the "cannot verify ⇒ do not credit" rule are tested |
| No live vendor accounts | VTpass / 5sim / Dojah / Reloadly | the same, plus `fake_smm_panel.mjs` which answers the way real panels do (HTTP 200 with an error envelope) |
| No Apache | `.htaccess` rewriting | the rules are asserted as text, both deny mechanisms ship, and the dev server now refuses the same paths |

## D. Integrity

- No test was deleted to make a run green.
- No failure was converted to a skip. The single skip predates this work and is
  documented (`testAJobCannotOverlapItself`, `flock` under wasm).
- Where a **test double** disagreed with production, the double was corrected
  and the assertion left alone — `FakeDb` gained `conn_id`, `field_data()`,
  `escape()`, `set()`, `where_not_in()` and `SUBSTR`; the harness adapter was
  made to speak `ProviderAdapterInterface`. Each is recorded in the module doc
  that needed it, with the reason.
- Three checks were corrected rather than the code, each with the reasoning
  written down: an assertion about a running counter that only holds while
  refunds have not overtaken debits (module 12), a form-count assertion that
  predated multipart uploads (module 10), and a stale refill-status expectation
  after the provider double was fixed (module 6).
- Every fix in this project is accompanied by a test that fails without it.

## E. What I would do next

1. **Run this pipeline on real MySQL 8.** `tools/verify_all.sh` is written to
   be portable; the two `deploy-verify.php` schema checks and the index plans
   are the only things waiting on it.
2. **Point one gateway and one vendor at their sandboxes** and re-run
   `gateway_check` and `smm_provider_check` against them. The adapters are
   built for it: every one of them already distinguishes refusal, transport
   failure and success.
3. **Close the two known races** noted in the module docs: the per-customer
   coupon count (needs a unique index) and the digital-file URL that remains
   fetchable after revocation (needs the store moved outside the document
   root).
4. **Consolidate the admin dashboard's six aggregate widgets**, worth ~4
   queries, deliberately left alone in the same pass that changed how
   currencies are read.
