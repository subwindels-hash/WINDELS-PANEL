<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CronControlService — pausing and resuming background jobs.
 *
 * The cron screen could report and not act (module 16). When a provider starts
 * refusing every call, or a gateway answers nonsense and the reconciliation
 * sweep is about to write off live deposits, an operator needs to stop one job
 * *now* — and on cPanel, editing a crontab at 2am is not a realistic answer.
 *
 * Three rules make this safe enough to ship:
 *
 *  1. **A pause always expires.** The failure mode of this feature is not
 *     pausing, it is forgetting: a job disabled during an incident and never
 *     re-enabled means earnings that never mature and deposits that are never
 *     reconciled, silently, for weeks. Every pause carries `resume_at`, capped
 *     at MAX_HOURS, and `is_paused()` resumes the job itself when that passes.
 *  2. **A reason is required.** The person reading this screen next week is
 *     probably not the person who paused it.
 *  3. **The consequence is named.** Pausing `payment_reconciliation` or
 *     `earnings_release` stops money moving for real customers, so those jobs
 *     are flagged and the screen says what stops happening. They can still be
 *     paused — an operator who needs to stop a bad sweep must be able to —
 *     but never by accident.
 *
 * There is deliberately no "run now". Triggering a reconciliation or refund
 * sweep from a web request is how deposits get double-credited, and the screen
 * already prints the exact command to run.
 */
class CronControlService {

    /** Longest a job may be paused before the runner turns it back on. */
    const MAX_HOURS = 24;

    /** Shortest useful pause; anything less is a mis-typed form. */
    const MIN_HOURS = 1;

    /**
     * Jobs whose pause stops money moving for customers, and what stops.
     *
     * Not a block list — a warning list. Every one of these is a job an
     * operator might genuinely need to stop, and every one of them has a
     * customer-visible consequence they should read first.
     */
    const MONEY_JOBS = array(
        'payment_reconciliation' => 'Deposits whose callback never arrived stay PENDING; nobody is credited.',
        'earnings_release'       => 'Referral earnings stay held and cannot be withdrawn.',
        'service_recovery'       => 'Purchases stuck with no provider reference are not refunded.',
        'marketplace_release'    => 'Escrow is not released to sellers and not returned to buyers.',
        'affiliate_payouts'      => 'Approved payouts are not sent.',
        'fundsvera_expire'       => 'Abandoned bank transfers keep their reservation.',
        'order_status'           => 'Delivered orders are not marked delivered; refunds for failures are not issued.',
        'vtu_status'             => 'Accepted VTU top-ups are never settled or refunded.',
        'numbers_status'         => 'Virtual-number OTPs are not collected and holds are not released.',
        'refill_status'          => 'Requested refills are neither submitted nor followed up.',
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
    }

    /** Is this job currently paused? Expired pauses are lifted here. */
    public function is_paused($job) {
        $row = $this->row($job);
        if (!$row || (int)$row->is_paused !== 1) return false;

        if ($this->expired($row)) {
            $this->auto_resume($row);
            return false;
        }
        return true;
    }

    /** The control row for a job, or NULL. Expired pauses are lifted first. */
    public function state($job) {
        $row = $this->row($job);
        if ($row && (int)$row->is_paused === 1 && $this->expired($row)) {
            $this->auto_resume($row);
            $row = $this->row($job, true);
        }
        return $row;
    }

    /** Every control row, keyed by job — one query for the whole screen. */
    public function all() {
        if (!$this->table()) return array();
        $out = array();
        foreach ($this->ci->db->get('cron_job_controls')->result() as $row) {
            if ((int)$row->is_paused === 1 && $this->expired($row)) {
                $this->auto_resume($row);
                $row->is_paused = 0;
                $row->resumed_at = gmdate('Y-m-d H:i:s');
            }
            $out[$row->job] = $row;
        }
        return $out;
    }

    /**
     * Pause a job for a bounded number of hours.
     *
     * @return array{ok:bool, error?:string, code?:string, resume_at?:string}
     */
    public function pause($job, $reason, $actor_id, $hours = self::MAX_HOURS) {
        if (!$this->table()) {
            return $this->err('UNAVAILABLE', 'The cron controls table is missing — run the migrations.');
        }
        $job = trim((string)$job);
        if (!$this->known($job)) {
            return $this->err('UNKNOWN_JOB', 'There is no scheduled job called "'.$job.'".');
        }

        $reason = trim(preg_replace('/\s+/', ' ', (string)$reason));
        if (mb_strlen($reason) < 5) {
            // Not bureaucracy: this is the only thing the next person has to
            // decide whether the pause still applies.
            return $this->err('NO_REASON', 'Say why the job is being paused — at least a few words.');
        }
        $reason = mb_substr($reason, 0, 255);

        $hours = (int)$hours;
        if ($hours < self::MIN_HOURS) $hours = self::MIN_HOURS;
        if ($hours > self::MAX_HOURS) $hours = self::MAX_HOURS;

        $now = gmdate('Y-m-d H:i:s');
        $resume_at = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        $this->upsert($job, array(
            'is_paused'     => 1,
            'reason'        => $reason,
            'paused_by_id'  => $actor_id ? (int)$actor_id : null,
            'paused_at'     => $now,
            'resume_at'     => $resume_at,
            'resumed_by_id' => null,
            'resumed_at'    => null,
        ));
        $this->audit($actor_id, 'cron.paused', $job,
            array('reason' => $reason, 'hours' => $hours, 'resume_at' => $resume_at));

        return array('ok' => true, 'resume_at' => $resume_at, 'hours' => $hours);
    }

    /** Resume a job by hand, before its pause expires. */
    public function resume($job, $actor_id) {
        if (!$this->table()) {
            return $this->err('UNAVAILABLE', 'The cron controls table is missing — run the migrations.');
        }
        $row = $this->row($job);
        if (!$row || (int)$row->is_paused !== 1) {
            return $this->err('NOT_PAUSED', 'That job is already running on its schedule.');
        }
        $this->upsert($job, array(
            'is_paused'     => 0,
            'resumed_by_id' => $actor_id ? (int)$actor_id : null,
            'resumed_at'    => gmdate('Y-m-d H:i:s'),
            'resume_at'     => null,
        ));
        $this->audit($actor_id, 'cron.resumed', $job, array('reason' => $row->reason));
        return array('ok' => true);
    }

    /** What stops happening if this job is paused, or NULL if nothing customer-visible. */
    public static function consequence($job) {
        return self::MONEY_JOBS[$job] ?? null;
    }

    /** Does pausing this job stop money moving? */
    public static function moves_money($job) {
        return array_key_exists($job, self::MONEY_JOBS);
    }

    /* ------------------------------------------------------------------ */

    /** A pause is over when its expiry has passed. */
    private function expired($row) {
        if (empty($row->resume_at)) return false;
        return strtotime($row->resume_at.' UTC') <= time();
    }

    /**
     * Lift an expired pause and say so in the audit trail.
     *
     * Recorded with a NULL actor because nobody did it: the expiry did. An
     * audit entry that named the person who paused the job would read as
     * though they came back and resumed it.
     */
    private function auto_resume($row) {
        $this->upsert($row->job, array(
            'is_paused'  => 0,
            'resumed_at' => gmdate('Y-m-d H:i:s'),
            'resume_at'  => null,
        ));
        $this->audit(null, 'cron.auto_resumed', $row->job, array(
            'reason' => $row->reason,
            'paused_at' => $row->paused_at,
        ));
    }

    private function known($job) {
        $schedules = (array)$this->ci->config->item('cron');
        return $job !== '' && array_key_exists($job, $schedules);
    }

    private function row($job, $fresh = false) {
        if (!$this->table()) return null;
        return $this->ci->db->where('job', (string)$job)->get('cron_job_controls')->row();
    }

    private function upsert($job, array $fields) {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        $existing = $this->ci->db->where('job', $job)->get('cron_job_controls')->row();
        if ($existing) {
            $this->ci->db->where('id', $existing->id)->update('cron_job_controls', $fields);
            return;
        }
        $this->ci->db->insert('cron_job_controls', array_merge(array(
            'job'        => $job,
            'reason'     => '',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ), $fields));
    }

    private function table() {
        try {
            return $this->ci->db->table_exists('cron_job_controls');
        } catch (Throwable $e) {
            return false;
        }
    }

    private function audit($actor_id, $action, $job, array $meta = array()) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id ?: null, $action, 'cron_job_controls', (string)$job, null, $meta ?: null,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            // A pause must not fail because the audit write did; the pause is
            // the safety control, the log is the record of it.
            log_message('error', 'cron control audit failed: '.$e->getMessage());
        }
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }
}
