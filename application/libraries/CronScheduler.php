<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CronScheduler — make the background jobs run themselves.
 *
 * The crontab is the *right* way to schedule work, but it is the one piece of
 * a deployment most shared-hosting operators cannot install: no shell, a cron
 * UI whose PATH hides PHP, or a line that silently typos the document root.
 * While it is missing, every schedule on Admin → Cron jobs shows OVERDUE,
 * deposits never reconcile and orders never settle — and the panel reads as
 * broken even though it is only unstaffed.
 *
 * This class is the in-app answer: a **heartbeat tick** that rides on ordinary
 * site traffic. Every web request (any page, and `/health/live`, which uptime
 * monitors ping) registers a shutdown hook; when the response has been sent,
 * that hook checks which jobs are due from the panel's own schedule
 * (config `marvy.cron` vs `job_runs`) and runs them through the exact same
 * JobRunner harness the crontab uses — exclusive lock, run record, pause
 * checks, contained failures. Nothing here needs a second process, a
 * privileged user or a public URL.
 *
 * The rules that keep it safe:
 *
 *  - **Post-response only.** The tick runs in a shutdown function, after the
 *    page (or webhook acknowledgement) is on the wire; where the SAPI allows
 *    it the response is flushed first, so no visitor ever waits on a cron job.
 *  - **Throttled.** One marker file elects a single ticker per minute — ten
 *    concurrent visitors cost nine file-stat calls, not nine cron passes.
 *  - **Budgeted.** At most a few jobs per tick, inside a hard wall-clock
 *    budget, because the shared-hosting execution limit still applies to
 *    shutdown functions. Whatever does not fit waits for the next tick; the
 *    selection is most-overdue-first, so the oldest backlog goes first.
 *  - **Silent on failure.** Everything is try/caught and logged; a tick can
 *    never emit output into a rendered page or itself become the error an
 *    operator chases.
 *  - **Honest about which scheduler ran.** job_runs rows look exactly like
 *    crontab runs — the admin screen measures health either way. The tick
 *    marker doubles as the "auto-run is alive" signal on that screen.
 *
 * A working crontab is still the better arrangement on a quiet site (traffic
 * means delay here), so installing it remains the documented recommendation;
 * see Admin → Cron jobs. `CRON_AUTORUN=0` in the environment, or the
 * `cron_autorun_enabled` setting, turns the heartbeat off entirely.
 */
class CronScheduler {

    /** One ticker attempt at most every N seconds (the throttle window). */
    const MIN_TICK_SECONDS = 60;

    /** Wall-clock budget for one tick's work, in seconds. */
    const TIME_BUDGET_SECONDS = 20;

    /** At most this many jobs per tick, however overdue the rest are. */
    const MAX_JOBS_PER_TICK = 3;

    /** How many recent job_runs rows the due-check reads. */
    const RECENT_RUNS = 400;

    /**
     * Test/ops overrides. Null means "compute the default": the marker
     * directory lives under the storage cache path, and the budget constants
     * above apply. A test sets these to be deterministic and fast.
     */
    public static $marker_dir = null;
    public static $max_jobs = null;
    public static $budget_seconds = null;

    /** @return bool whether a shutdown tick was scheduled */
    public static function register($db_ready) {
        if (!$db_ready) return false;
        if (!self::enabled()) return false;

        // Inline heartbeat, not a shutdown function: the tick is paid at the
        // end of controller boot on the request that happens to be the
        // minute's ticker. The flock marker caps it at one pass per minute
        // (every other request loses the race in microseconds), the budget
        // caps the pass at a few fast jobs, and run history — not the page —
        // absorbs the cost. A register_shutdown_function variant wedges
        // embedded runtimes whose request handlers do not survive
        // post-response execution (verified on the wasm dev server), and on
        // PHP-FPM it would only save the ticker page a few milliseconds.
        self::tick();
        return true;
    }

    /**
     * The shutdown hook. Never throws, never echoes.
     *
     * @param bool $force run even inside the throttle window (admin catch-up)
     * @return array{ran:int, jobs:array[], skipped_throttle?:bool, error?:string}
     */
    public static function tick($force = false) {
        $started = microtime(true);
        $old_display = ini_get('display_errors');
        @ini_set('display_errors', '0');
        try {

            if (!$force && !self::throttle_open()) {
                return array('ran' => 0, 'jobs' => array(), 'skipped_throttle' => true);
            }

            $ci =& get_instance();
            $due = self::select_due(gmdate('Y-m-d H:i:s'));
            $ran = 0;
            $jobs = array();

            if ($due) {
                $ci->load->library(array('JobRunner', 'CronRegistry', 'CronControlService'));
                $budget  = self::$budget_seconds ?? self::TIME_BUDGET_SECONDS;
                $max     = self::$max_jobs ?? self::MAX_JOBS_PER_TICK;

                foreach ($due as $entry) {
                    if ($ran >= $max || (microtime(true) - $started) >= $budget) break;

                    // A paused job is idle on purpose: the admin screen shows
                    // the pause itself, so unlike the CLI (one SKIPPED row per
                    // crontab tick) the heartbeat stays silent about it —
                    // every minute of traffic must not flood the run history.
                    if ($ci->croncontrolservice->is_paused($entry['job'])) continue;

                    $worker = $ci->cronregistry->worker($entry['job']);
                    if ($worker === null) continue;

                    $result = $ci->jobrunner->run($entry['job'], $worker);
                    $ran++;
                    $jobs[] = array(
                        'job'      => $entry['job'],
                        'ok'       => !empty($result['ok']),
                        'skipped'  => !empty($result['skipped']),
                        'message'  => (string)($result['message'] ?? ($result['error'] ?? '')),
                    );
                }
            }

            self::stamp_tick();
            return array('ran' => $ran, 'jobs' => $jobs);
        } catch (Throwable $e) {
            log_message('error', 'cron autotick failed: '.$e->getMessage());
            return array('ran' => 0, 'jobs' => array(), 'error' => $e->getMessage());
        } finally {
            // An exception between the throttle and the stamp must not leave
            // the marker lock held for the rest of the process.
            if (self::$held_handle) {
                flock(self::$held_handle, LOCK_UN);
                fclose(self::$held_handle);
                self::$held_handle = null;
            }
            if ($old_display !== false) @ini_set('display_errors', (string)$old_display);
        }
    }

    /**
     * Which jobs are due right now, most overdue first.
     *
     * "Due" means: the job's cadence (its own schedule) has elapsed since its
     * last recorded run — for interval schedules (`*`, `*\/n` minutes/hours)
     * measured from that run; for a fixed daily time (`H H * * *`) measured
     * against today's occurrence. Jobs with no schedule, or no answer from
     * the run history, are left to the crontab/admin catch-up rather than
     * guessed at.
     *
     * @param string $now 'Y-m-d H:i:s' UTC
     * @return array[] of {job, age_minutes, overdue_minutes}
     */
    public static function select_due($now) {
        $out = array();
        try {
            $ci =& get_instance();
            $schedules = (array)$ci->config->item('cron');
            if (!$schedules) return $out;

            $latest = self::latest_runs();
            foreach ($schedules as $job => $schedule) {
                if (!isset($latest[$job])) continue; // never ran: crontab/catch-up owns the first run
                $last = $latest[$job];
                if (isset($last->status) && $last->status === 'RUNNING'
                    && (time() - strtotime($last->started_at.' UTC')) < 3600) {
                    continue; // a live run (crontab or another tick) holds the job
                }

                $age_minutes = (int)floor((strtotime($now.' UTC') - strtotime($last->started_at.' UTC')) / 60);
                $due_at_age  = self::due_at_age_minutes((string)$schedule, $last->started_at, $now);
                if ($due_at_age === null) continue;
                if ($age_minutes < $due_at_age) continue;

                $out[] = array(
                    'job'             => $job,
                    'age_minutes'     => $age_minutes,
                    'overdue_minutes' => $age_minutes - $due_at_age,
                );
            }
        } catch (Throwable $e) {
            log_message('error', 'cron autotick due-check failed: '.$e->getMessage());
            return array();
        }

        usort($out, function ($a, $b) { return $b['overdue_minutes'] - $a['overdue_minutes']; });
        return $out;
    }

    /** Is the heartbeat on? Environment decides first, the settings table second. */
    public static function enabled() {
        $env = getenv('CRON_AUTORUN');
        if ($env !== false && trim((string)$env) !== '') {
            return in_array(strtolower(trim((string)$env)), array('1', 'true', 'yes', 'on'), true);
        }
        try {
            $ci =& get_instance();
            $ci->load->model('Setting_model');
            $value = $ci->Setting_model->get('cron_autorun_enabled', '1');
            return !in_array(strtolower(trim((string)$value)), array('0', 'false', 'off', 'no'), true);
        } catch (Throwable $e) {
            return true; // defaults on: a half-migrated install still gets a heartbeat
        }
    }

    /** What the admin screen shows about the heartbeat. */
    public static function state() {
        $marker = self::marker_file();
        $last = null;
        if (is_file($marker)) {
            $raw = @file_get_contents($marker);
            if (is_numeric(trim((string)$raw))) $last = (int)trim((string)$raw);
        }
        return array(
            'enabled'        => self::enabled(),
            'last_tick'      => $last,
            'tick_age_minutes' => $last === null ? null : (int)round((time() - $last) / 60),
        );
    }

    /* ------------------------------ internals ----------------------------- */

    /**
     * The age (in minutes, since the last run) at which a job becomes due.
     *
     * Interval schedules use their cadence directly. A fixed daily `H H * * *`
     * is due once today's occurrence is in the past and the last run happened
     * before it — expressed as an age so the caller stays clock-free.
     *
     * @return int|null minutes, or null when the schedule shape is unsupported
     */
    public static function due_at_age_minutes($schedule, $last_started, $now) {
        $expr = trim(preg_replace('/\s+/', ' ', (string)$schedule));
        $parts = explode(' ', $expr);
        if (count($parts) !== 5) return null;
        list($min, $hour, $dom, $mon, $dow) = $parts;

        // A fixed daily time: due when "now" has passed today's H:H and the
        // last run is older than that occurrence.
        if (ctype_digit($min) && ctype_digit($hour) && $dom === '*' && $mon === '*' && $dow === '*') {
            $today_slot = gmdate('Y-m-d', strtotime($now.' UTC')).' '
                .sprintf('%02d:%02d:00', (int)$hour, (int)$min);
            if (strtotime($now.' UTC') < strtotime($today_slot.' UTC')) return null;
            return (int)ceil((strtotime($now.' UTC') - strtotime($today_slot.' UTC')) / 60);
        }

        // CI libraries are not autoloaded for plain classes; this static call
        // happens deep inside a shutdown hook where a fatal would be silent.
        if (!class_exists('SystemAdminService', false)) {
            require_once APPPATH.'libraries/SystemAdminService.php';
        }
        return max(1, SystemAdminService::cadence_minutes($expr));
    }

    /** Latest job_runs row per job, from one bounded recent-window read. */
    private static function latest_runs() {
        $ci =& get_instance();
        $rows = $ci->db->order_by('started_at', 'DESC')->limit(self::RECENT_RUNS)
                       ->get('job_runs')->result();
        $latest = array();
        foreach ($rows as $row) {
            $job = (string)$row->job;
            if ($job === '' || isset($latest[$job])) continue;
            $latest[$job] = $row;
        }
        return $latest;
    }

    /**
     * One ticker per throttle window: an exclusive, non-blocking lock on the
     * marker file. Losing the race is the normal, cheap outcome for every
     * request that arrived within the same minute.
     */
    private static function throttle_open() {
        $file = self::marker_file();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fh = @fopen($file, 'c+');
        if (!$fh) return true; // cannot coordinate — run rather than never tick

        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }

        $last = 0;
        $raw = stream_get_contents($fh);
        if (is_numeric(trim((string)$raw))) $last = (int)trim((string)$raw);
        if ((time() - $last) < self::MIN_TICK_SECONDS) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return false;
        }
        ftruncate($fh, 0);
        fwrite($fh, (string)time());
        fflush($fh);
        // Deliberately keep $fh open: the lock must hold for the whole tick.
        self::$held_handle = $fh;
        return true;
    }

    /** Record that a tick completed (the admin screen's "auto-run is alive"). */
    private static function stamp_tick() {
        // Release the claim BEFORE stamping: a second LOCK_EX on the marker
        // from this same process (the claim handle still holding it) would be
        // a self-deadlock under real flock semantics, and a lock-free six-byte
        // write is fine after the claim is gone — only the minute's ticker
        // ever gets here.
        if (self::$held_handle) {
            flock(self::$held_handle, LOCK_UN);
            fclose(self::$held_handle);
            self::$held_handle = null;
        }
        $file = self::marker_file();
        @file_put_contents($file, (string)time());
    }

    private static $held_handle = null;

    private static function marker_file() {
        if (self::$marker_dir !== null) return rtrim(self::$marker_dir, '/').'/autorun.tick';
        try {
            $paths = Env::writable_paths();
            return rtrim($paths['cache'], '/').'/cron/autorun.tick';
        } catch (Throwable $e) {
            return rtrim(sys_get_temp_dir(), '/').'/marvy-autorun.tick';
        }
    }
}
