<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PayoutService — controlled withdrawal of *earnings*.
 *
 * ## What this can and cannot pay out
 *
 * Only the earnings ledger. It never touches `wallets`. Deposited funds stay
 * non-withdrawable (migration 018), because a balance you can top up and then
 * cash out makes the platform a money transmitter. Earnings are different: they
 * are money the platform owes the user for referrals and campaigns, and paying
 * that is ordinary commission settlement.
 *
 * The separation is structural. `reserve()` selects rows from `earnings` with
 * `status = AVAILABLE`; there is no code path from a payout request to a wallet
 * balance, so this cannot be broken by forgetting a check.
 *
 * ## Locking, not decrementing
 *
 * Requesting a payout does not subtract from a number. It flips specific
 * earning rows from AVAILABLE to LOCKED and stamps them with the request id.
 * Two concurrent requests therefore cannot both spend the same earning: the
 * compare-and-set on each row means the second finds nothing left to lock.
 * A rejected request flips the same rows back.
 *
 * ## Nothing pays automatically
 *
 * `mark_paid()` requires a human-supplied payout reference. Fundsvera documents
 * no disbursement endpoint, so the money leaves through the operator's own bank
 * and the reference is recorded here for audit. A request never settles itself.
 */
class PayoutService {

    const STATUS_REQUESTED = 'REQUESTED';
    const STATUS_APPROVED  = 'APPROVED';
    const STATUS_REJECTED  = 'REJECTED';
    const STATUS_PAID      = 'PAID';
    const STATUS_CANCELLED = 'CANCELLED';

    /** Where the money can go. */
    const METHODS = array('BANK_TRANSFER', 'WALLET_CREDIT');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Payout_request_model', 'Earning_model', 'Setting_model'));
        $this->ci->load->library(array('EarningsService', 'LedgerService'));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Request a payout of available earnings.
     *
     * @return array{ok:bool, payout?:object, error?:string, code?:string}
     */
    public function request($user, array $input) {
        if (!$user) return $this->err('NO_USER', 'Sign in first.');

        $method = strtoupper(trim((string)($input['method'] ?? 'BANK_TRANSFER')));
        if (!in_array($method, self::METHODS, true)) {
            return $this->err('BAD_METHOD', 'Choose a supported payout method.');
        }

        // Converting earnings into spending credit is always allowed: the money
        // stays inside the platform, so none of the cash-out controls apply.
        if ($method === 'BANK_TRANSFER' && !$this->ci->earningsservice->payouts_enabled()) {
            return $this->err('PAYOUTS_DISABLED',
                'Cash payouts are not currently open. You can still convert earnings into wallet credit.');
        }

        $amount = $this->normalise_amount($input['amount'] ?? null);
        if ($amount === null || bccomp($amount, '0', 8) <= 0) {
            return $this->err('BAD_AMOUNT', 'Enter an amount greater than zero.');
        }

        $min = $this->ci->earningsservice->min_payout();
        if ($method === 'BANK_TRANSFER' && bccomp($amount, $min, 8) < 0) {
            return $this->err('BELOW_MINIMUM',
                'The minimum payout is '.marvy_money($min).'.');
        }

        $balance = $this->ci->earningsservice->balance($user->id);
        if (bccomp($amount, $balance['available'], 8) > 0) {
            return $this->err('INSUFFICIENT',
                'You have '.marvy_money($balance['available']).' available.');
        }

        // One open request at a time. Without this a user could submit five
        // requests for their whole balance before any is reviewed.
        if ($this->ci->Payout_request_model->has_open($user->id)) {
            return $this->err('ALREADY_OPEN',
                'You already have a payout request awaiting review.');
        }

        $destination = trim((string)($input['destination'] ?? ''));
        if ($method === 'BANK_TRANSFER' && $destination === '') {
            return $this->err('NO_DESTINATION', 'Enter the account the money should be sent to.');
        }

        // Derived from the user and the amount within a short window, so a
        // double-submitted form does not create two requests.
        $idem = 'payout:'.$user->id.':'.$amount.':'.floor(time() / 60);
        $existing = $this->ci->Payout_request_model->by_idempotency_key($idem);
        if ($existing) {
            return array('ok' => true, 'duplicate' => true, 'payout' => $existing);
        }

        $this->ci->db->trans_start();

        $id = $this->ci->Payout_request_model->create(array(
            'user_id'          => (int)$user->id,
            'amount'           => $amount,
            'currency'         => marvy_base_currency(),
            'method'           => $method,
            'destination'      => $destination !== '' ? mb_substr($destination, 0, 255) : null,
            'destination_name' => mb_substr(trim((string)($input['destination_name'] ?? '')), 0, 160) ?: null,
            'status'           => self::STATUS_REQUESTED,
            'idempotency_key'  => $idem,
        ));

        $locked = $this->reserve($user->id, $amount, $id);
        if (!$locked['ok']) {
            $this->ci->db->trans_rollback();
            return $locked;
        }

        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return $this->err('REQUEST_FAILED', 'Could not create the payout request.');
        }

        $payout = $this->ci->Payout_request_model->find_by_id($id);

        // Wallet credit is not a cash payout and needs no human review — the
        // money never leaves the platform.
        if ($method === 'WALLET_CREDIT') {
            return $this->settle_as_wallet_credit($payout, $user);
        }

        $this->audit($user->id, 'payout.requested', $payout->public_id,
            array('amount' => $amount, 'method' => $method));

        return array('ok' => true, 'payout' => $payout);
    }

    /**
     * Lock enough AVAILABLE earnings to cover the amount.
     *
     * Oldest first, and each row is moved with a compare-and-set so two
     * concurrent requests cannot lock the same earning. Rows are locked whole;
     * when the last one overshoots the requested amount it is split so the
     * remainder stays available.
     */
    private function reserve($user_id, $amount, $payout_id) {
        $remaining = $amount;
        $rows = $this->ci->Earning_model->available_for_user($user_id);

        foreach ($rows as $row) {
            if (bccomp($remaining, '0', 8) <= 0) break;

            $row_amount = (string)$row->amount;

            if (bccomp($row_amount, $remaining, 8) > 0) {
                // Partially needed: split it so the unused part stays spendable.
                $keep = bcsub($row_amount, $remaining, 8);

                $moved = $this->ci->Earning_model->transition(
                    $row->id, 'AVAILABLE', 'LOCKED',
                    array('amount' => $remaining, 'payout_request_id' => (int)$payout_id)
                );
                if (!$moved) continue; // someone else took it; try the next row

                $this->ci->Earning_model->insert_row(array(
                    'public_id'       => marvy_public_id(),
                    'user_id'         => (int)$user_id,
                    'source'          => $row->source,
                    'amount'          => $keep,
                    'currency'        => $row->currency,
                    'status'          => 'AVAILABLE',
                    'description'     => 'Remainder of '.$row->public_id,
                    'idempotency_key' => 'split:'.$row->public_id.':'.$payout_id,
                    'available_at'    => $row->available_at,
                    'created_at'      => gmdate('Y-m-d H:i:s'),
                    'updated_at'      => gmdate('Y-m-d H:i:s'),
                ));

                $remaining = '0.00000000';
                break;
            }

            $moved = $this->ci->Earning_model->transition(
                $row->id, 'AVAILABLE', 'LOCKED',
                array('payout_request_id' => (int)$payout_id)
            );
            if ($moved) $remaining = bcsub($remaining, $row_amount, 8);
        }

        if (bccomp($remaining, '0', 8) > 0) {
            // Balance moved under us between the check and the lock.
            return $this->err('INSUFFICIENT',
                'Your available balance changed. Refresh and try again.');
        }
        return array('ok' => true);
    }

    /* ------------------------------------------------------------------ */
    /* Staff actions                                                       */
    /* ------------------------------------------------------------------ */

    public function approve($payout, $actor, $note = null) {
        if (!$payout) return $this->err('NO_PAYOUT', 'Unknown payout request.');

        $moved = $this->ci->Payout_request_model->transition(
            $payout->id, self::STATUS_REQUESTED, self::STATUS_APPROVED,
            array('reviewed_by_id' => (int)$actor->id,
                  'reviewed_at' => gmdate('Y-m-d H:i:s'),
                  'review_note' => $note ? mb_substr($note, 0, 500) : null)
        );
        if (!$moved) return $this->err('BAD_STATE', 'That request is no longer awaiting review.');

        $this->audit($actor->id, 'payout.approved', $payout->public_id, array('note' => $note));
        $this->notify($payout->user_id, 'Payout approved',
            'Your payout of '.marvy_money($payout->amount).' was approved and is being processed.');

        return array('ok' => true);
    }

    /** Reject and return the locked earnings to available. */
    public function reject($payout, $actor, $reason = null) {
        if (!$payout) return $this->err('NO_PAYOUT', 'Unknown payout request.');
        if ($payout->status === self::STATUS_PAID) {
            return $this->err('ALREADY_PAID', 'That payout has already been settled.');
        }

        $this->ci->db->trans_start();

        $moved = $this->ci->Payout_request_model->transition(
            $payout->id, $payout->status, self::STATUS_REJECTED,
            array('reviewed_by_id' => (int)$actor->id,
                  'reviewed_at' => gmdate('Y-m-d H:i:s'),
                  'review_note' => $reason ? mb_substr($reason, 0, 500) : null)
        );
        if (!$moved) {
            $this->ci->db->trans_rollback();
            return $this->err('BAD_STATE', 'That request could not be rejected.');
        }

        $this->release($payout->id);

        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return $this->err('REJECT_FAILED', 'Could not reject the request.');
        }

        $this->audit($actor->id, 'payout.rejected', $payout->public_id, array('reason' => $reason));
        $this->notify($payout->user_id, 'Payout rejected',
            'Your payout request was not approved'.($reason ? ': '.$reason : '.')
            .' The amount is available in your earnings again.');

        return array('ok' => true);
    }

    /**
     * Mark an approved payout as settled.
     *
     * Requires the reference of the transfer that actually happened. That is
     * the point: the panel is recording a payment made elsewhere, so without
     * the reference there is nothing tying the ledger to the bank.
     */
    public function mark_paid($payout, $actor, $reference) {
        if (!$payout) return $this->err('NO_PAYOUT', 'Unknown payout request.');

        $reference = trim((string)$reference);
        if ($reference === '') {
            return $this->err('NO_REFERENCE',
                'Record the bank or provider reference for the transfer you sent.');
        }

        $this->ci->db->trans_start();

        $moved = $this->ci->Payout_request_model->transition(
            $payout->id, self::STATUS_APPROVED, self::STATUS_PAID,
            array('payout_reference' => mb_substr($reference, 0, 160),
                  'paid_at' => gmdate('Y-m-d H:i:s'))
        );
        if (!$moved) {
            $this->ci->db->trans_rollback();
            return $this->err('BAD_STATE', 'Only an approved payout can be marked paid.');
        }

        // The locked earnings are now spent.
        foreach ($this->ci->Earning_model->for_payout($payout->id) as $earning) {
            $this->ci->Earning_model->transition($earning->id, 'LOCKED', 'PAID',
                array('paid_out_at' => gmdate('Y-m-d H:i:s')));
        }

        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return $this->err('PAY_FAILED', 'Could not record the payout.');
        }

        $this->audit($actor->id, 'payout.paid', $payout->public_id, array('reference' => $reference));
        $this->notify($payout->user_id, 'Payout sent',
            marvy_money($payout->amount).' has been sent. Reference: '.$reference);

        return array('ok' => true);
    }

    /** A user cancelling their own request before it is reviewed. */
    public function cancel($payout, $user) {
        if (!$payout) return $this->err('NO_PAYOUT', 'Unknown payout request.');
        if ((int)$payout->user_id !== (int)$user->id) return $this->err('FORBIDDEN', 'Not your request.');

        $this->ci->db->trans_start();
        $moved = $this->ci->Payout_request_model->transition(
            $payout->id, self::STATUS_REQUESTED, self::STATUS_CANCELLED
        );
        if (!$moved) {
            $this->ci->db->trans_rollback();
            return $this->err('BAD_STATE', 'That request can no longer be cancelled.');
        }
        $this->release($payout->id);
        $this->ci->db->trans_complete();

        return array('ok' => true);
    }

    /* ------------------------------------------------------------------ */

    /** Return locked earnings to AVAILABLE. */
    private function release($payout_id) {
        foreach ($this->ci->Earning_model->for_payout($payout_id) as $earning) {
            $this->ci->Earning_model->transition($earning->id, 'LOCKED', 'AVAILABLE',
                array('payout_request_id' => null));
        }
    }

    /**
     * Settle a payout as spendable wallet credit.
     *
     * Goes through LedgerService like every other wallet movement, so the
     * double-entry ledger stays balanced and this shows up in the normal
     * transaction history rather than as a mystery balance change.
     */
    private function settle_as_wallet_credit($payout, $user) {
        $this->ci->load->model('Wallet_model');
        $wallet = $this->ci->Wallet_model->for_user($user->id);
        if (!$wallet) return $this->err('NO_WALLET', 'No wallet for this account.');

        $this->ci->db->trans_start();

        $credit = $this->ci->ledgerservice->credit(
            $wallet->id, (string)$payout->amount, 'EARNINGS_CONVERSION',
            'PayoutRequest', $payout->public_id,
            'payout:credit:'.$payout->public_id,
            array('source' => 'earnings')
        );
        if (empty($credit['ok'])) {
            $this->ci->db->trans_rollback();
            return $this->err('CREDIT_FAILED', $credit['error'] ?? 'Could not credit the wallet.');
        }

        $this->ci->Payout_request_model->transition(
            $payout->id, self::STATUS_REQUESTED, self::STATUS_PAID,
            array('payout_reference' => 'WALLET-'.$payout->public_id,
                  'paid_at' => gmdate('Y-m-d H:i:s'))
        );
        foreach ($this->ci->Earning_model->for_payout($payout->id) as $earning) {
            $this->ci->Earning_model->transition($earning->id, 'LOCKED', 'PAID',
                array('paid_out_at' => gmdate('Y-m-d H:i:s')));
        }

        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status() === false) {
            return $this->err('CREDIT_FAILED', 'Could not convert the earnings.');
        }

        $this->notify($user->id, 'Earnings converted',
            marvy_money($payout->amount).' was added to your wallet balance.');

        return array('ok' => true, 'payout' => $this->ci->Payout_request_model->find_by_id($payout->id),
                     'converted' => true);
    }

    private function normalise_amount($amount) {
        if ($amount === null || $amount === '' || !is_numeric($amount)) return null;
        return number_format((float)$amount, 8, '.', '');
    }

    private function err($code, $message) {
        return array('ok' => false, 'code' => $code, 'error' => $message);
    }

    private function audit($actor_id, $action, $entity, array $meta = array()) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id, $action, 'payout_requests', $entity, null, $meta ?: null,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'payout audit failed: '.$e->getMessage());
        }
    }

    private function notify($user_id, $title, $body) {
        try {
            $this->ci->db->insert('notifications', array(
                'public_id'  => marvy_public_id(),
                'user_id'    => (int)$user_id,
                'type'       => 'payout',
                'channel'    => 'IN_APP',
                'title'      => $title,
                'body'       => $body,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        } catch (Throwable $e) {
            log_message('error', 'payout notification failed: '.$e->getMessage());
        }
    }
}
