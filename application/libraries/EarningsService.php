<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * EarningsService — the referral/campaign earnings ledger.
 *
 * ## The balance is never a stored number
 *
 * Every figure this service reports is a SUM over the `earnings` table. There
 * is no `users.earnings_balance` column to drift, to be incremented twice by a
 * retried job, or to be edited by hand. If the ledger and the balance ever
 * disagree it is because someone wrote to the ledger, which is the point.
 *
 * ## States
 *
 *   PENDING   — earned, inside its holding period, not yet spendable
 *   AVAILABLE — holding period elapsed, may be paid out
 *   LOCKED    — reserved against an open payout request
 *   PAID      — settled and gone
 *   REVERSED  — cancelled; offset by a REVERSAL row, never deleted
 *
 * PENDING and AVAILABLE are reported separately and are never added together,
 * because "you have money" and "you can have money now" are different promises.
 *
 * ## Why credit() demands an idempotency key
 *
 * An earning is created by events that retry: a webhook redelivery, a cron
 * sweep, an admin clicking twice. The key is a UNIQUE column, so the second
 * attempt hits the constraint and returns the original row instead of paying
 * twice. Callers must derive it from the *event*, never from the clock.
 */
class EarningsService {

    const STATUS_PENDING   = 'PENDING';
    const STATUS_AVAILABLE = 'AVAILABLE';
    const STATUS_LOCKED    = 'LOCKED';
    const STATUS_PAID      = 'PAID';
    const STATUS_REVERSED  = 'REVERSED';

    /** Statuses that represent money the user still owns. */
    const OWNED = array(self::STATUS_PENDING, self::STATUS_AVAILABLE, self::STATUS_LOCKED);

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Earning_model', 'Setting_model'));
    }

    /* ------------------------------------------------------------------ */
    /* Reading                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The user's earnings position.
     *
     * @return array{available:string, pending:string, locked:string, paid:string,
     *               reversed:string, total_earned:string, currency:string}
     */
    public function balance($user_id) {
        $sums = $this->ci->Earning_model->sums_by_status((int)$user_id);

        $available = $sums[self::STATUS_AVAILABLE] ?? '0.00000000';
        $pending   = $sums[self::STATUS_PENDING]   ?? '0.00000000';
        $locked    = $sums[self::STATUS_LOCKED]    ?? '0.00000000';
        $paid      = $sums[self::STATUS_PAID]      ?? '0.00000000';
        $reversed  = $sums[self::STATUS_REVERSED]  ?? '0.00000000';

        // Total earned counts everything the user ever legitimately earned:
        // still held, plus already paid out. Reversed entries are excluded —
        // they were taken back, and including them would overstate the figure.
        $total = bcadd(bcadd(bcadd($available, $pending, 8), $locked, 8), $paid, 8);

        return array(
            'available'    => $available,
            'pending'      => $pending,
            'locked'       => $locked,
            'paid'         => $paid,
            'reversed'     => $reversed,
            'total_earned' => $total,
            'currency'     => marvy_base_currency(),
        );
    }

    /** Earnings broken down by where they came from. */
    public function by_source($user_id) {
        return $this->ci->Earning_model->sums_by_source((int)$user_id);
    }

    public function history($user_id, $limit = 25, $offset = 0) {
        return $this->ci->Earning_model->for_user((int)$user_id, $limit, $offset);
    }

    /* ------------------------------------------------------------------ */
    /* Writing                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Record an earning.
     *
     * @param array $data user_id, source, amount, idempotency_key, and
     *                    optionally hold_hours, description, referral_signup_id,
     *                    campaign_id
     * @return array{ok:bool, earning?:object, duplicate?:bool, error?:string, code?:string}
     */
    public function credit(array $data) {
        $user_id = (int)($data['user_id'] ?? 0);
        $amount  = $this->normalise_amount($data['amount'] ?? null);
        $key     = trim((string)($data['idempotency_key'] ?? ''));
        $source  = strtoupper(trim((string)($data['source'] ?? 'MANUAL')));

        if ($user_id <= 0)  return $this->err('NO_USER', 'An earning needs an owner.');
        if ($amount === null || bccomp($amount, '0', 8) <= 0) {
            return $this->err('BAD_AMOUNT', 'An earning must be a positive amount.');
        }
        if ($key === '') {
            // Refusing is deliberate. A caller with no key cannot be made
            // idempotent later, and a duplicated earning is real money.
            return $this->err('NO_IDEMPOTENCY_KEY',
                'Every earning must carry an idempotency key derived from its triggering event.');
        }

        // Fast path: the key has been seen, so this is a retry.
        $existing = $this->ci->Earning_model->by_idempotency_key($key);
        if ($existing) {
            return array('ok' => true, 'duplicate' => true, 'earning' => $existing);
        }

        $hold = array_key_exists('hold_hours', $data)
            ? max(0, (int)$data['hold_hours'])
            : $this->default_hold_hours();

        // A zero hold means immediately spendable; anything else starts PENDING
        // and is released by the cron sweep once available_at passes.
        $status = $hold === 0 ? self::STATUS_AVAILABLE : self::STATUS_PENDING;
        $available_at = gmdate('Y-m-d H:i:s', time() + ($hold * 3600));

        $row = array(
            'public_id'          => marvy_public_id(),
            'user_id'            => $user_id,
            'source'             => $source,
            'referral_signup_id' => isset($data['referral_signup_id']) ? (int)$data['referral_signup_id'] : null,
            'campaign_id'        => isset($data['campaign_id']) ? (int)$data['campaign_id'] : null,
            'amount'             => $amount,
            'currency'           => marvy_base_currency(),
            'status'             => $status,
            'description'        => isset($data['description'])
                                    ? mb_substr((string)$data['description'], 0, 255) : null,
            'idempotency_key'    => mb_substr($key, 0, 160),
            'available_at'       => $available_at,
            'created_at'         => gmdate('Y-m-d H:i:s'),
            'updated_at'         => gmdate('Y-m-d H:i:s'),
        );

        try {
            $id = $this->ci->Earning_model->insert_row($row);
        } catch (Exception $e) {
            // The UNIQUE key is the real guard: two concurrent callers both
            // passed the check above, and the database settled it.
            $existing = $this->ci->Earning_model->by_idempotency_key($key);
            if ($existing) return array('ok' => true, 'duplicate' => true, 'earning' => $existing);
            log_message('error', 'earnings: insert failed: '.$e->getMessage());
            return $this->err('INSERT_FAILED', 'Could not record the earning.');
        }

        $earning = $this->ci->Earning_model->find_by_id($id);
        $this->notify($user_id, 'Earning added',
            marvy_money($amount).' has been added to your earnings'
            .($status === self::STATUS_PENDING ? ' and will become available after the holding period.' : '.'));

        return array('ok' => true, 'earning' => $earning);
    }

    /**
     * Release PENDING earnings whose holding period has elapsed.
     *
     * Called by cron. Bounded per run so one sweep cannot lock the table.
     *
     * @return int how many were released
     */
    public function release_due($limit = 500) {
        $due = $this->ci->Earning_model->due_for_release(gmdate('Y-m-d H:i:s'), $limit);
        $released = 0;

        foreach ($due as $row) {
            // Compare-and-set: only move it if it is still PENDING, so two
            // overlapping cron runs cannot both release the same row.
            $changed = $this->ci->Earning_model->transition(
                $row->id, self::STATUS_PENDING, self::STATUS_AVAILABLE
            );
            if ($changed) $released++;
        }
        return $released;
    }

    /**
     * Reverse an earning (fraud, chargeback, staff decision).
     *
     * Never edits or deletes the original: it is marked REVERSED and a negative
     * REVERSAL row is written alongside it, so the ledger still explains every
     * figure it reports and an auditor can see what happened.
     */
    public function reverse($earning, $actor_id, $reason = null) {
        if (!$earning) return $this->err('NO_EARNING', 'Unknown earning.');

        if ($earning->status === self::STATUS_REVERSED) {
            return array('ok' => true, 'duplicate' => true);
        }
        if ($earning->status === self::STATUS_PAID) {
            // The money has left. Reversing the ledger row would misreport
            // history; recovering it is a manual, off-ledger matter.
            return $this->err('ALREADY_PAID',
                'That earning has already been paid out and cannot be reversed here.');
        }

        $this->ci->db->trans_start();

        $this->ci->Earning_model->transition($earning->id, $earning->status, self::STATUS_REVERSED, array(
            'reversed_at' => gmdate('Y-m-d H:i:s'),
        ));

        $this->ci->Earning_model->insert_row(array(
            'public_id'       => marvy_public_id(),
            'user_id'         => $earning->user_id,
            'source'          => 'REVERSAL',
            'amount'          => '-'.ltrim((string)$earning->amount, '-'),
            'currency'        => $earning->currency,
            'status'          => self::STATUS_REVERSED,
            'description'     => 'Reversal of '.$earning->public_id.($reason ? ': '.$reason : ''),
            'idempotency_key' => 'reversal:'.$earning->public_id,
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'updated_at'      => gmdate('Y-m-d H:i:s'),
        ));

        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return $this->err('REVERSAL_FAILED', 'Could not reverse the earning.');
        }

        $this->audit($actor_id, 'earnings.reversed', $earning->public_id, array('reason' => $reason));
        $this->notify($earning->user_id, 'Earning reversed',
            marvy_money($earning->amount).' was removed from your earnings'
            .($reason ? ': '.$reason : '.'));

        return array('ok' => true);
    }

    /* ------------------------------------------------------------------ */

    /** Holding period before an earning becomes spendable. */
    public function default_hold_hours() {
        $v = $this->setting('earnings_hold_hours', 72);
        return max(0, (int)$v);
    }

    /** Smallest payout the platform will process. */
    public function min_payout() {
        $v = $this->setting('earnings_min_payout', '1000.00000000');
        return (string)$v;
    }

    /** Whether cash payouts are open at all. */
    public function payouts_enabled() {
        $v = $this->setting('earnings_payouts_enabled', false);
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), array('1', 'true', 'yes', 'on'), true);
    }

    private function setting($key, $default) {
        try {
            $v = $this->ci->Setting_model->get($key, $default);
            return ($v === null || $v === '') ? $default : $v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private function normalise_amount($amount) {
        if ($amount === null || $amount === '') return null;
        if (!is_numeric($amount)) return null;
        return number_format((float)$amount, 8, '.', '');
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }

    private function audit($actor_id, $action, $entity, array $meta = array()) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id, $action, 'earnings', $entity, null, $meta ?: null,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'earnings audit failed: '.$e->getMessage());
        }
    }

    private function notify($user_id, $title, $body) {
        try {
            $this->ci->db->insert('notifications', array(
                'public_id'  => marvy_public_id(),
                'user_id'    => (int)$user_id,
                'type'       => 'earnings',
                'channel'    => 'IN_APP',
                'title'      => $title,
                'body'       => $body,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        } catch (Throwable $e) {
            log_message('error', 'earnings notification failed: '.$e->getMessage());
        }
    }
}
