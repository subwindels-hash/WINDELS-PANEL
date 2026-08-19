<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * JobRunner — the harness every cron worker runs inside (Session 16).
 *
 * Responsibilities:
 *   1. **Mutual exclusion** — a job never overlaps with itself. Cron fires on a
 *      fixed schedule regardless of whether the previous run finished, so a
 *      slow provider sync would otherwise stack up and double-submit orders.
 *   2. **Run tracking** — every execution writes a `job_runs` row (RUNNING →
 *      SUCCESS/FAILED) with counts, duration and any error message, which is
 *      what makes "did the cron actually run last night?" answerable.
 *   3. **Failure containment** — a throwing worker is caught, logged and
 *      recorded as FAILED rather than taking the whole CLI process down and
 *      leaving a stale lock behind.
 *
 * Locking uses an exclusive flock() on a file in the lock directory: it works
 * on a single host with no Redis, and the kernel releases the lock when the
 * holding process dies, so a crash cannot wedge a job permanently. The
 * guarantee is the kernel's, not PHP's — it holds on every supported runtime
 * (native CLI/PHP-FPM on Linux). It is intentionally NOT weakened for the
 * PHP-WASM/emscripten build used by the offline test harness: that emulation
 * aliases flock() state between same-file handles inside one process, so
 * mutual exclusion cannot be demonstrated there (the dedicated test says so,
 * as a visible platform skip); cron never runs on that runtime in production.
 * §66 calls for Redis SET NX in a multi-host deployment; swap redis_lock() in
 * when the infrastructure is there — the interface here does not change.
 */
class JobRunner {

    /** A run holding the lock longer than this is treated as stale. */
    const DEFAULT_TIMEOUT = 3600;

    private $ci;
    private $handle;
    private $job;
    private $run_id;
    private $started;

    public function __construct() {
        $this->ci =& get_instance();
    }

    /**
     * Run $work exclusively under the name $job.
     *
     * The callable receives this runner so it can report progress, and should
     * return an array like array('processed'=>int, 'failed'=>int, 'message'=>string).
     *
     * @return array{ok:bool, skipped?:bool, processed?:int, failed?:int, message?:string, error?:string}
     */
    public function run($job, callable $work) {
        $this->job = $job;

        if (!$this->acquire($job)) {
            // Not an error: the previous run is simply still going.
            log_message('info', "cron {$job}: skipped, already running");
            return array('ok' => true, 'skipped' => true, 'message' => 'already running');
        }

        $this->started = microtime(true);
        $this->run_id  = $this->start_record($job);

        try {
            $result = $work($this);
            $result = is_array($result) ? $result : array();
            $this->finish_record('SUCCESS',
                (int)($result['processed'] ?? 0),
                (int)($result['failed'] ?? 0),
                $result['message'] ?? null);
            return array_merge(array('ok' => true), $result);
        } catch (Throwable $e) {
            // A worker blowing up must not kill the CLI process or leave the
            // job marked RUNNING forever.
            log_message('error', "cron {$job} failed: ".$e->getMessage());
            $this->finish_record('FAILED', 0, 0, substr($e->getMessage(), 0, 1000));
            return array('ok' => false, 'error' => $e->getMessage());
        } finally {
            $this->release();
        }
    }

    /** Seconds elapsed since the current run started. */
    public function elapsed() {
        return $this->started ? round(microtime(true) - $this->started, 3) : 0.0;
    }

    /* ------------------------------ locking ------------------------------ */

    private function acquire($job) {
        $dir = $this->lock_dir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir.'/'.preg_replace('/[^a-z0-9_\-]/i', '_', $job).'.lock';

        $this->handle = @fopen($path, 'c+');
        if (!$this->handle) {
            log_message('error', "cron {$job}: cannot open lock file {$path}");
            return false;
        }
        // Non-blocking: if another run holds it we skip rather than queue up.
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, (string)getmypid()."\n".gmdate('c')."\n");
        fflush($this->handle);
        return true;
    }

    private function release() {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    private function lock_dir() {
        $configured = $this->ci->config->item('cron_lock_dir');
        if ($configured) return rtrim($configured, '/');
        return rtrim(sys_get_temp_dir(), '/').'/windels-locks';
    }

    /* ---------------------------- run records ---------------------------- */

    private function start_record($job) {
        $this->ci->db->insert('job_runs', array(
            'job'        => $job,
            'status'     => 'RUNNING',
            'started_at' => gmdate('Y-m-d H:i:s'),
        ));
        return (int)$this->ci->db->insert_id();
    }

    private function finish_record($status, $processed, $failed, $message) {
        if (!$this->run_id) return;
        $this->ci->db->where('id', $this->run_id)->update('job_runs', array(
            'status'      => $status,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'duration_ms' => (int)round($this->elapsed() * 1000),
            'processed'   => $processed,
            'failed'      => $failed,
            'message'     => $message,
        ));
    }
}
