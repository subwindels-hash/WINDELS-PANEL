<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cron — CLI only (is_cli guard). Real crontab calls: php index.php cron <job>
 * No web cron URLs (§66).
 *
 * Every job is a thin wrapper: JobRunner takes an exclusive lock (so a slow run
 * never overlaps the next tick), records the run in `job_runs`, and contains
 * any exception. The work itself lives in the CronWorkers library so it can be
 * tested without a request, and the name → code mapping lives in CronRegistry,
 * so the CLI and the admin "Run now" button run byte-identical work under the
 * same harness.
 */
class Cron extends Cron_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('JobRunner', 'CronRegistry', 'CronControlService'));
    }

    public function index() {
        echo "Usage: php index.php cron <job>\n\nJobs:\n";
        foreach ($this->cronregistry->jobs() as $job) {
            $schedule = $this->config->item('cron')[$job] ?? '';
            printf("  %-24s %s\n", $job, $schedule);
        }
        echo "\n  status                   recent run history\n";
    }

    /* ------------------------------- jobs ------------------------------- */

    public function order_status() {
        $this->execute('order_status');
    }

    /** Settle VTU purchases the provider accepted but has not completed. */
    public function vtu_status() {
        $this->execute('vtu_status');
    }

    /**
     * Close service purchases no domain worker can settle, and refund them.
     *
     * The backstop for every domain: a purchase with no provider reference
     * cannot be polled by anything, and without this it stays PROCESSING for
     * ever with the customer's money in it.
     */
    public function service_recovery() {
        $this->execute('service_recovery');
    }

    /**
     * Settle virtual-number reservations: collect OTPs, expire the rest.
     *
     * Runs every minute rather than every two: a reservation lives for about
     * fifteen, and the customer is watching the screen for their code.
     */
    public function numbers_status() {
        $this->execute('numbers_status');
    }

    /**
     * Scrub identity results past their retention period (§22).
     *
     * Nightly and off-peak: retention is measured in days, so there is nothing
     * to gain from running it during business hours and something to lose —
     * deleting a record while support has it open.
     */
    public function identity_purge() {
        $this->execute('identity_purge');
    }

    /**
     * Collect gift card codes for orders the vendor has accepted (§23).
     *
     * Every two minutes. A gift card order is accepted in one call and
     * delivered in another, and until the second one lands the customer has
     * paid for nothing they can spend. The same sweep writes off orders the
     * vendor never fulfilled, which refunds them.
     */
    public function giftcard_codes() {
        $this->execute('giftcard_codes');
    }

    /** Make held earnings available once their holding period elapses. */
    public function earnings_release() {
        $this->execute('earnings_release');
    }

    /** Close bank-transfer checkouts whose 30-minute window has passed. */
    public function fundsvera_expire() {
        $this->execute('fundsvera_expire');
    }

    /** Release undisputed marketplace deliveries after the review window. */
    public function marketplace_release() {
        $this->execute('marketplace_release');
    }

    public function dripfeed() {
        $this->execute('dripfeed');
    }

    public function subscriptions() {
        $this->execute('subscriptions');
    }

    public function email_queue() {
        $this->execute('email_queue');
    }

    public function inbox_poll() {
        $this->execute('inbox_poll');
    }

    public function provider_health() {
        $this->execute('provider_health');
    }

    public function provider_sync() {
        $this->execute('provider_sync');
    }

    public function refill_status() {
        $this->execute('refill_status');
    }

    /**
     * Automatically rotate any customer security PIN older than the
     * configured window (24 hours by default). See CronWorkers::pin_rotation().
     */
    public function pin_rotation() {
        $this->execute('pin_rotation');
    }

    /**
     * Pay referral commissions that have cleared the hold window (Session 14).
     * Idempotent: each commission row is claimed with a compare-and-set and the
     * wallet credit carries a deterministic idempotency key, so overlapping runs
     * can never pay twice. The worker itself lives in CronRegistry.
     */
    public function affiliate_payouts() {
        $this->execute('affiliate_payouts');
    }

    /**
     * Reconcile payments left in a non-terminal state. Credits nothing — see
     * CronWorkers::payment_reconciliation().
     */
    public function payment_reconciliation() {
        $this->execute('payment_reconciliation');
    }

    /** Housekeeping: prune high-volume logs (audit_logs are never touched). */
    public function analytics() {
        $this->execute('analytics');
    }

    /** Recent run history, for "did the cron actually run?" */
    public function status() {
        // The DB loads defensively in the base controller: a host where MySQL
        // is down (or not yet configured) must print a one-line answer, not a
        // fatal on a null property — this is the exact command an operator
        // runs to find out whether cron works at all.
        $this->require_db('cannot show run history');
        $rows = $this->db->order_by('started_at', 'DESC')->limit(20)->get('job_runs')->result();
        if (!$rows) { echo "No cron runs recorded yet.\n"; return; }
        printf("%-24s %-9s %-20s %8s %6s  %s\n", 'JOB', 'STATUS', 'STARTED', 'MS', 'DONE', 'MESSAGE');
        foreach ($rows as $r) {
            printf("%-24s %-9s %-20s %8s %6s  %s\n",
                $r->job, $r->status, $r->started_at,
                $r->duration_ms === null ? '-' : $r->duration_ms,
                $r->processed === null ? '-' : $r->processed,
                (string)$r->message);
        }
    }

    /* ------------------------------ plumbing ----------------------------- */

    /**
     * Refuse to run when the database is genuinely unreachable.
     *
     * The framework autoloads the database connection, and a dead MySQL
     * leaves `$this->db` set with an empty `conn_id` — an isset() guard then
     * passes and the first query dies inside the driver with an opaque
     * "errorInfo() on bool". `marvy_load_database()` (same helper the base
     * controller and the heartbeat use) probes the server first and answers
     * honestly, so the operator gets one actionable line and a non-zero exit
     * instead.
     */
    private function require_db($what) {
        if (!marvy_load_database() || empty($this->db->conn_id)) {
            fwrite(STDERR, "Database unavailable — {$what}.\n"
                ."Check the DB_* settings or DB_DSN in .env, then run: php index.php deploy check\n");
            exit(1);
        }
    }

    /**
     * Run a job under the lock/record harness and print a one-line summary.
     *
     * The work is resolved from CronRegistry, which is the same map the admin
     * "Run now" button uses — one implementation of "run job X safely", two
     * doors into it.
     */
    private function execute($job) {
        $this->require_db('cannot run '.$job);

        $worker = $this->cronregistry->worker($job);
        if ($worker === null) {
            fwrite(STDERR, "Unknown cron job: {$job}\n");
            exit(1);
        }

        // An operator can pause a job from Admin → Cron jobs during an
        // incident. The pause is honoured HERE, at the last moment before the
        // work runs, rather than by editing the crontab: the tick still
        // happens, so the run is recorded as SKIPPED and the screen can show
        // that the schedule is alive and the job is deliberately idle. A
        // crontab line commented out looks identical to a broken cron.
        if ($this->croncontrolservice->is_paused($job)) {
            $state = $this->croncontrolservice->state($job);
            $reason = $state && $state->reason !== '' ? $state->reason : 'paused by an operator';
            $this->jobrunner->record_skip($job, 'paused: '.$reason);
            echo "{$job}: skipped (paused by an operator — {$reason})\n";
            return;
        }

        $result = $this->jobrunner->run($job, $worker);

        if (!empty($result['skipped'])) {
            echo "{$job}: skipped (already running)\n";
            return;
        }
        if (empty($result['ok'])) {
            // Non-zero exit so the crontab MAILTO / monitoring notices.
            fwrite(STDERR, "{$job}: FAILED — ".($result['error'] ?? 'unknown')."\n");
            exit(1);
        }
        echo "{$job}: ok — ".($result['message'] ?? 'done')
            .sprintf(" (%.3fs)\n", $this->jobrunner->elapsed());
    }
}
