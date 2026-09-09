# Module 38 — the cron heartbeat: background jobs that run themselves

*Branch `arena/01a058f9-windels-panel`, commit `987c488` (2026-08-31).
Follows module 22 (cron pause/resume).*

Item 5 of [unfinished.md](unfinished.md) — "cron scheduling (write side)" —
had one remainder: installing and editing schedules is still a crontab paste,
so a panel whose crontab was never installed simply never reconciles a
deposit or settles an order. This module closes the operational half of that
gap: **the panel now runs its own due jobs from ordinary traffic**, no
crontab required, and the operator error that started the whole task —
`php index.php cron status` dying with an opaque driver crash on a dead
database — is fixed alongside it.

---

## 1. What the operator saw

Two failure modes, both reproduced before anything was written:

- `php index.php cron status` (and every job command) with MySQL down:
  the framework **autoloads** the database connection, so a dead MySQL
  leaves `$this->db` set with an empty `conn_id`; the first query explodes
  inside the driver — `Call to a member function errorInfo() on bool`
  (`pdo_driver.php:304`) — instead of a sentence a human can act on.
- A deployment configured through a single `VP_DB_DSN=mysql:host=…` (the
  documented dev-database and socket-host spelling) was invisible to
  `marvy_db_reachable()`, which only read the discrete `DB_HOST`/`DB_NAME`
  keys: every guarded command reported "Database unavailable" against a
  perfectly healthy server.

## 2. The fixes

- **`Cron::require_db()`** guards `status` and every job entry point. It asks
  `marvy_load_database()` — the same probe the base controller and the
  heartbeat use — prints one actionable line and exits 1:
  `Database unavailable — cannot show run history. / Check the DB_* settings
  or DB_DSN in .env, then run: php index.php deploy check`.
- **`marvy_db_reachable()`** parses `host`, `port` and `dbname` out of
  `DB_DSN` when the discrete keys are absent, so DSN-only deployments probe
  (and run) correctly.
- **`CronScheduler`** (new library): the traffic-driven heartbeat.
  `MY_Controller` registers it on every web request — including
  `/health/live`, which uptime monitors already ping. An `flock` marker
  (`storage/cache/cron/autorun.tick`) lets exactly one request per minute
  win the race; that request runs whatever the schedule says is due,
  most-overdue first, at most 3 jobs within a 20-second budget, paused jobs
  skipped silently, every run recorded through `JobRunner` exactly as a
  crontab run is. `CRON_AUTORUN=0` or the `cron_autorun_enabled` setting
  turns it off; a quiet host should still install the crontab, and the admin
  cron screen says so.
- **The admin cron screen** shows the heartbeat's state (on/off, last tick
  age), and the "cron has never run" alarm stays calm while it is on.
- **`cron/crontab.example`** regenerated from the canonical schedule with an
  auto-run-first header.

## 3. The design decision a shutdown hook lost

The classic shape — `register_shutdown_function(tick)` after
`fastcgi_finish_request()` — **wedged the wasm dev-server worker**: the page
flushed, the tick ran, and the runtime never returned to its dispatcher, so
every later request on that worker queued for ever. Instrumented runs
(file-lap markers through the tick) located the hang after the last job row
was written. The heartbeat therefore runs **inline** at the end of
controller boot: the flock marker caps it at one pass per minute (every
other request loses the race in microseconds — 0.05s pages), the budget caps
the pass, and run history — not the page — absorbs the cost. On PHP-FPM a
shutdown hook would have saved the minute's ticker page a few milliseconds;
here it cost the whole worker.

## 4. Evidence

- End to end on the dev stack (no crontab): backdate a job's last run, load
  one page — the due job auto-runs (`SUCCESS` row, 32 ms), the next request
  inside the minute throttles (no row), the server keeps answering. Three
  consecutive requests: `0.59s → 0.05s → 0.05s`.
- Dead database: both guarded commands print the friendly line and exit 1
  (verified with the real server down).
- `CronSchedulerTest`: 12 tests, 35 assertions, covering throttle, cap,
  overdue ordering, silent pause skip, env/setting kill-switch, the admin
  surface and the DSN probe.
- Full suite: 1658 tests, 18740 assertions, 0 failures; deployment package
  rebuilt and re-verified; `verify_all.sh` battery 48/48 stages.

## 5. Still open

- **Editing schedules from the panel** remains deliberately unbuilt (module
  22's reasoning stands: the panel and the crontab must not be able to
  disagree about *when*). The heartbeat does not change that — it reads the
  same `$config['cron']` schedule the crontab example is generated from.
- `testAJobCannotOverlapItself` stays platform-skipped under wasm
  (unfinished.md item 16 documents why); the portable skip-branch test
  covers the behaviour on every runtime.
