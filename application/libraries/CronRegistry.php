<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CronRegistry — the one map from a job's name to the code that runs it.
 *
 * Two entry points need this mapping: the CLI controller (`php index.php cron
 * <job>`) and the "Run now" button on Admin → System → Cron jobs. Keeping the
 * map in one place means the two can never disagree about what a job does —
 * the browser button runs exactly the code the crontab would have run, through
 * exactly the same JobRunner harness (exclusive lock, job_runs record,
 * contained failures), so a manual run is as safe as a scheduled tick and can
 * never overlap one.
 *
 * `affiliate_payouts` is the one job that is not a plain CronWorkers method:
 * it lives in AffiliateService and reports its own counts, so it gets an
 * explicit closure here rather than a special case at every call site.
 */
class CronRegistry {

    /**
     * CronWorkers methods that are safe to invoke with no arguments, i.e. the
     * jobs. Explicit on purpose: the registry must never hand out a callable
     * for a helper method that happens to be public.
     */
    const WORKER_JOBS = array(
        'dripfeed', 'order_status', 'vtu_status', 'numbers_status',
        'identity_purge', 'giftcard_codes', 'service_recovery',
        'marketplace_release', 'earnings_release', 'fundsvera_expire',
        'subscriptions', 'provider_health', 'provider_sync', 'refill_status',
        'payment_reconciliation', 'email_queue', 'analytics', 'pin_rotation',
        'inbox_poll',
    );

    /** The one job whose worker is not a CronWorkers method. */
    const SERVICE_JOBS = array('affiliate_payouts');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
    }

    /** Every job this build can execute, in schedule order. */
    public function jobs() {
        $known = array_merge(self::WORKER_JOBS, self::SERVICE_JOBS);
        $ordered = array();
        foreach (array_keys((array)$this->ci->config->item('cron')) as $job) {
            if (in_array($job, $known, true)) $ordered[] = $job;
        }
        foreach ($known as $job) {
            if (!in_array($job, $ordered, true)) $ordered[] = $job;
        }
        return $ordered;
    }

    /** Is $job a job this build knows how to run? */
    public function has($job) {
        return is_string($job) && (
            in_array($job, self::WORKER_JOBS, true)
            || in_array($job, self::SERVICE_JOBS, true)
        );
    }

    /**
     * The work a scheduled tick of $job performs, as a callable for
     * JobRunner::run(), or NULL when the job is unknown.
     *
     * @return callable|null
     */
    public function worker($job) {
        if (!$this->has($job)) return null;
        $ci = $this->ci;

        if ($job === 'affiliate_payouts') {
            return function () use ($ci) {
                $ci->load->library('AffiliateService');
                $result = $ci->affiliateservice->pay_due(500);
                if (!empty($result['disabled'])) {
                    return array('processed' => 0, 'failed' => 0,
                        'message' => 'skipped (program disabled)');
                }
                return array(
                    'processed' => (int)$result['paid'],
                    'failed'    => (int)($result['skipped'] ?? 0),
                    'message'   => "paid {$result['paid']}, skipped {$result['skipped']}, total {$result['amount']}",
                );
            };
        }

        return function () use ($ci, $job) {
            $ci->load->library('CronWorkers');
            return $ci->cronworkers->$job();
        };
    }
}
