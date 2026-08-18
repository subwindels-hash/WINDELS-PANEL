<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cron — CLI only (is_cli guard). Real crontab calls: php index.php cron <job>
 * No web cron URLs (§66).
 *
 * Every job is a thin wrapper: JobRunner takes an exclusive lock (so a slow run
 * never overlaps the next tick), records the run in `job_runs`, and contains
 * any exception. The work itself lives in the CronWorkers library so it can be
 * tested without a request.
 */
class Cron extends Cron_Controller {

    /** Jobs that can be invoked, in the order `index` lists them. */
    private static $jobs = array(
        'dripfeed', 'order_status', 'vtu_status', 'numbers_status', 'subscriptions', 'provider_health',
        'refill_status', 'payment_reconciliation', 'email_queue',
        'analytics', 'provider_sync', 'affiliate_payouts',
    );

    public function __construct() {
        parent::__construct();
        $this->load->library(array('JobRunner', 'CronWorkers'));
    }

    public function index() {
        echo "Usage: php index.php cron <job>\n\nJobs:\n";
        foreach (self::$jobs as $job) {
            $schedule = $this->config->item('cron')[$job] ?? '';
            printf("  %-24s %s\n", $job, $schedule);
        }
        echo "\n  status                   recent run history\n";
    }

    /* ------------------------------- jobs ------------------------------- */

    public function order_status() {
        $this->execute('order_status', function () {
            return $this->cronworkers->order_status();
        });
    }

    /** Settle VTU purchases the provider accepted but has not completed. */
    public function vtu_status() {
        $this->execute('vtu_status', function () {
            return $this->cronworkers->vtu_status();
        });
    }

    /**
     * Settle virtual-number reservations: collect OTPs, expire the rest.
     *
     * Runs every minute rather than every two: a reservation lives for about
     * fifteen, and the customer is watching the screen for their code.
     */
    public function numbers_status() {
        $this->execute('numbers_status', function () {
            return $this->cronworkers->numbers_status();
        });
    }

    public function dripfeed() {
        $this->execute('dripfeed', function () {
            return $this->cronworkers->dripfeed();
        });
    }

    public function subscriptions() {
        $this->execute('subscriptions', function () {
            return $this->cronworkers->subscriptions();
        });
    }

    public function email_queue() {
        $this->execute('email_queue', function () {
            return $this->cronworkers->email_queue();
        });
    }

    public function provider_health() {
        $this->execute('provider_health', function () {
            return $this->cronworkers->provider_health();
        });
    }

    public function provider_sync() {
        $this->execute('provider_sync', function () {
            return $this->cronworkers->provider_sync();
        });
    }

    public function refill_status() {
        $this->execute('refill_status', function () {
            return $this->cronworkers->refill_status();
        });
    }

    /**
     * Pay referral commissions that have cleared the hold window (Session 14).
     * Idempotent: each commission row is claimed with a compare-and-set and the
     * wallet credit carries a deterministic idempotency key, so overlapping runs
     * can never pay twice.
     */
    public function affiliate_payouts() {
        $this->execute('affiliate_payouts', function () {
            $this->load->library('AffiliateService');
            $result = $this->affiliateservice->pay_due(500);
            if (!empty($result['disabled'])) {
                return array('processed'=>0, 'failed'=>0, 'message'=>'skipped (program disabled)');
            }
            return array(
                'processed' => (int)$result['paid'],
                'failed'    => (int)($result['skipped'] ?? 0),
                'message'   => "paid {$result['paid']}, skipped {$result['skipped']}, total {$result['amount']}",
            );
        });
    }

    /**
     * Reconcile payments left in a non-terminal state. Credits nothing — see
     * CronWorkers::payment_reconciliation().
     */
    public function payment_reconciliation() {
        $this->execute('payment_reconciliation', function () {
            return $this->cronworkers->payment_reconciliation();
        });
    }

    /** Housekeeping: prune high-volume logs (audit_logs are never touched). */
    public function analytics() {
        $this->execute('analytics', function () {
            return $this->cronworkers->analytics();
        });
    }

    /** Recent run history, for "did the cron actually run?" */
    public function status() {
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

    /** Run a job under the lock/record harness and print a one-line summary. */
    private function execute($job, callable $work) {
        $result = $this->jobrunner->run($job, $work);

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
