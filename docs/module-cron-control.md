# Module 22 — pausing a background job

*Branch `arena/01a04558-windels-panel`. Follows module 21 (announcement links).*

Item 5 of [unfinished.md](unfinished.md), closed. The cron screen could report
and not act; now it can stop a job — with the one safeguard that decides
whether a feature like this helps or hurts.

---

## 1. Reporting is not enough at 2am

Module 16 gave the panel a screen that answers *"is the background work
running?"*. It could not answer the next question: **make it stop.**

Two real situations need that:

- a provider starts refusing every call, so `order_status` marks a wave of
  live orders failed and refunds them;
- a gateway starts answering nonsense, and `payment_reconciliation` is about
  to write off deposits that genuinely arrived.

The only remedy was to SSH in and comment out a crontab line. Most cPanel
operators cannot do that at all, and the ones who can are doing it at 2am
under pressure, in a file nothing in the panel ever looks at again.

## 2. The dangerous part is forgetting, not pausing

A commented-out crontab line is invisible. Nothing mentions it, nothing warns
about it, and weeks later earnings have not matured, deposits have not been
reconciled and refunds have not landed — with no error anywhere, because
nothing ran to produce one.

So a pause here is **a decision with an expiry**:

| Rule | Why |
|---|---|
| Every pause carries `resume_at`, capped at **24 hours** | the runner resumes the job itself; an incident switch cannot be left on |
| A **reason** is required (min 5 characters) | the next person to read this screen is probably not the person who paused it |
| The **consequence is named** before committing | *"Deposits whose callback never arrived stay PENDING; nobody is credited"* |
| The tick still runs and records `SKIPPED` | a deliberate pause must not look like a broken crontab |
| Pausing needs `settings.manage`, reading needs `audit.view` | checking job health is everyday support; stopping the reconciliation sweep is not |
| Expiry is audited with a **NULL actor** | nobody resumed it — the clock did; naming the pauser would read as though they came back |

Ten jobs are flagged as money-moving (`payment_reconciliation`,
`earnings_release`, `service_recovery`, `marketplace_release`,
`affiliate_payouts`, `fundsvera_expire`, `order_status`, `vtu_status`,
`numbers_status`, `refill_status`). Flagged, **not blocked** — an operator who
needs to stop a bad refund sweep must be able to. They simply cannot do it
without reading what stops happening.

## 3. Still no "run now"

The obvious next button is deliberately absent, and the screen says so:
triggering a reconciliation or refund sweep from a web request is how deposits
get credited twice — a double-submit, a proxy retry, an impatient second click.
The screen already prints the exact command, and the pause is the control an
operator actually needs during an incident.

Nothing here can edit a schedule either. The crontab remains the source of
truth for *when*; this only controls *whether*.

---

## 4. Verification

```bash
node tools/devserver/php_run.mjs tools/phpunit_lite.php   # 1510 tests, 17222 assertions, 0 failures
node tools/devserver/chrome_check.mjs --admin-password '…'  # 83/83
bash tools/verify_all.sh --admin-password '…'               # 45 passed, 0 failed
```

`tests/unit/CronControlTest.php` (18 tests): pausing records who, why and an
expiry; other jobs are unaffected; resuming remembers who and clears the
expiry; **an expired pause lifts itself and the lift is written down**; the
cap and the floor hold whatever the form asks for; four unusable reasons are
refused and leave nothing half-applied; a typo in a job name creates no row;
money-moving jobs carry their consequence and are still pausable; both
transitions are audited and the automatic one has no actor; the runner honours
the pause and records the skipped tick; the write actions are POST-only and
gated on `settings.manage` while the screen stays on `audit.view`; there is no
run-now; and the screen promises the expiry in two places.

`chrome_check.mjs` drills it against the running panel: pause `analytics`
through the real form, confirm the row, the audit entry and the *"Paused …
Resumes automatically"* wording; a pause with no reason is refused and pauses
nothing; a money-moving job shows its warning; resume by hand; then a pause
wound into the past **lifts itself on the next page load** and is audited with
`actor_id IS NULL`. It snapshots and restores the operator's own control rows,
because this checker runs against live panels.

Proved end to end on the CLI too:

```
$ php index.php cron payment_reconciliation
payment_reconciliation: skipped (paused by an operator — gateway outage e2e)
# job_runs: status=SKIPPED, message="paused: gateway outage e2e"
```

### One test restated, not weakened

`AdminOperationsSurfacesTest::testTheCronScreenIsReadOnly` asserted the view
contained no `<form>` at all. That rule is genuinely superseded — the screen
now has two write actions by design — so it became
`testTheCronScreenOnlyPausesAndResumes`: it parses every form action out of
the view and asserts the set is **exactly** `admin/cron/pause` and
`admin/cron/resume`, plus no `cron/run` anywhere and `settings.manage` in the
controller. Stricter than the original, and it still fails if someone adds a
"trigger" button.

---

## 5. Still open

- **No scheduling from the panel.** The crontab remains the source of truth
  for *when* a job runs; this controls only *whether*. Editing schedules from
  a browser would mean the panel and the crontab could disagree, which is
  worse than the current arrangement.
- **A pause is global, not per-provider.** Pausing `order_status` stops
  polling for every provider, including the healthy ones. Per-provider
  suspension belongs with the provider health work, not here.
- **The 24-hour cap is not configurable.** Deliberate for now: an operator who
  can set it to a year has recreated the commented-out crontab line.
