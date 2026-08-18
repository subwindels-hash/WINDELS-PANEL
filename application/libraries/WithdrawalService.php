<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * WithdrawalService — reserve wallet funds, review a payout, and settle or
 * return the reservation exactly once.
 *
 * There is intentionally no direct wallet update here. A request immediately
 * debits the customer's available balance through LedgerService and balances
 * that debit against withdrawal_payable. Rejection/cancellation reverses the
 * same payable; marking a request paid moves no wallet money a second time.
 */
class WithdrawalService {
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_PAID = 'PAID';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_CANCELLED = 'CANCELLED';

    const DEFAULT_MIN = '1000.00000000';
    const DEFAULT_MAX = '1000000.00000000';
    const DEFAULT_FEE_PERCENT = '1.0000';
    const DEFAULT_FEE_FIXED = '0.00000000';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Withdrawal_model', 'Wallet_model', 'Wallet_transaction_model',
            'Setting_model', 'Identity_check_model',
        ));
        $this->ci->load->library(array('LedgerService', 'EncryptionService'));
    }

    /** Open a payout request and reserve the gross amount from the wallet. */
    public function request($user, array $input) {
        if (!$user || empty($user->id)) return $this->error('Customer not found', 'NO_USER');

        $idempotency = trim((string)($input['idempotency_key'] ?? ''));
        if ($idempotency === '' || strlen($idempotency) > 128) {
            return $this->error('Please reload the withdrawal form and try again', 'BAD_IDEMPOTENCY_KEY');
        }
        $existing = $this->ci->Withdrawal_model->find_by_idempotency($idempotency);
        if ($existing) {
            if ((int)$existing->user_id !== (int)$user->id) {
                return $this->error('That request key is already in use', 'IDEMPOTENCY_CONFLICT');
            }
            return array('ok' => true, 'duplicate' => true,
                'withdrawal' => $this->ci->Withdrawal_model->find_owned($existing->public_id, $user->id));
        }

        if ($this->identity_required() && !$this->identity_verified($user->id)) {
            return $this->error('Complete a successful identity check before requesting a withdrawal', 'IDENTITY_REQUIRED');
        }

        $amount = $this->money($input['amount'] ?? null);
        if ($amount === null) return $this->error('Enter a valid withdrawal amount', 'BAD_AMOUNT');
        $min = $this->minimum();
        $max = $this->maximum();
        if (bccomp($amount, $min, 8) < 0) {
            return $this->error('The minimum withdrawal is '.windels_money($min), 'AMOUNT_TOO_LOW');
        }
        if (bccomp($amount, $max, 8) > 0) {
            return $this->error('The maximum withdrawal is '.windels_money($max), 'AMOUNT_TOO_HIGH');
        }

        $destination = $this->destination($input);
        if (empty($destination['ok'])) return $destination;

        $fee = $this->fee_for($amount);
        if (bccomp($fee, $amount, 8) >= 0) {
            return $this->error('Withdrawal fees leave no amount to pay out', 'FEE_TOO_HIGH');
        }
        $payout = bcsub($amount, $fee, 8);
        $wallet = $this->ci->Wallet_model->for_user($user->id);
        if (!$wallet) return $this->error('Wallet not found', 'NO_WALLET');

        $public_id = windels_public_id();
        $hold_key = 'withdrawal:'.$public_id.':reserve';
        $now = gmdate('Y-m-d H:i:s');

        if (!$this->ci->db->trans_begin()) return $this->error('Could not start withdrawal', 'DB_ERROR');
        try {
            $hold = $this->ci->ledgerservice->reserve_withdrawal(
                $wallet->id, $amount, $public_id, $hold_key,
                array('withdrawal_id' => $public_id, 'fee_amount' => $fee, 'payout_amount' => $payout)
            );
            if (empty($hold['ok'])) {
                $this->ci->db->trans_rollback();
                $code = ($hold['error'] ?? '') === 'INSUFFICIENT_BALANCE'
                    ? 'INSUFFICIENT_BALANCE' : 'RESERVATION_FAILED';
                return $this->error(
                    $code === 'INSUFFICIENT_BALANCE' ? 'Insufficient wallet balance' : 'Could not reserve wallet funds',
                    $code
                );
            }
            $wallet_tx = $this->ci->Wallet_transaction_model->find_by_idempotency_key($hold_key);
            if (!$wallet_tx) {
                $this->ci->db->trans_rollback();
                return $this->error('Could not trace the wallet reservation', 'RESERVATION_FAILED');
            }

            $withdrawal = $this->ci->Withdrawal_model->create(array(
                'public_id' => $public_id,
                'user_id' => (int)$user->id,
                'wallet_transaction_id' => (int)$wallet_tx->id,
                'amount' => $amount,
                'fee_amount' => $fee,
                'payout_amount' => $payout,
                'currency' => $wallet->currency ?: windels_base_currency(),
                'status' => self::STATUS_PENDING,
                'destination_label' => $destination['label'],
                'destination_encrypted' => $this->ci->encryptionservice->encrypt(
                    json_encode($destination['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ),
                'idempotency_key' => $idempotency,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $this->ci->Withdrawal_model->event(
                $withdrawal->id, $user->id, 'REQUESTED', null, self::STATUS_PENDING,
                'Wallet funds reserved'
            );

            if ($this->ci->db->trans_status() === false || !$this->ci->db->trans_commit()) {
                $this->ci->db->trans_rollback();
                return $this->error('Withdrawal could not be saved', 'DB_ERROR');
            }
            return array('ok' => true,
                'withdrawal' => $this->ci->Withdrawal_model->find_owned($public_id, $user->id));
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'Withdrawal request failed: '.$e->getMessage());
            // A concurrent request with the same key may have committed while
            // this one was waiting on the unique wallet/withdrawal keys.
            $existing = $this->ci->Withdrawal_model->find_by_idempotency($idempotency);
            if ($existing && (int)$existing->user_id === (int)$user->id) {
                return array('ok' => true, 'duplicate' => true,
                    'withdrawal' => $this->ci->Withdrawal_model->find_owned($existing->public_id, $user->id));
            }
            return $this->error('Withdrawal could not be saved', 'DB_ERROR');
        }
    }

    /** Customer cancellation is only valid before an operator approves it. */
    public function cancel($public_id, $user_id) {
        $owned = $this->ci->Withdrawal_model->find_owned($public_id, $user_id);
        if (!$owned) return $this->error('Withdrawal not found', 'NOT_FOUND');
        return $this->change($public_id, self::STATUS_CANCELLED, $user_id, 'Cancelled by customer');
    }

    public function approve($public_id, $actor_id, $note = null) {
        return $this->change($public_id, self::STATUS_APPROVED, $actor_id, $note);
    }

    public function reject($public_id, $actor_id, $reason) {
        $reason = trim((string)$reason);
        if (mb_strlen($reason) < 3) return $this->error('Give a rejection reason', 'REASON_REQUIRED');
        return $this->change($public_id, self::STATUS_REJECTED, $actor_id, $reason);
    }

    public function mark_paid($public_id, $actor_id, $payout_reference, $note = null) {
        $reference = trim((string)$payout_reference);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 128) {
            return $this->error('Enter the bank or processor transfer reference', 'REFERENCE_REQUIRED');
        }
        return $this->change($public_id, self::STATUS_PAID, $actor_id, $note, $reference);
    }

    /** Decrypt a destination only through an explicit, recorded access. */
    public function reveal($public_id, $actor_id) {
        if (!$this->ci->db->trans_begin()) return $this->error('Could not record destination access', 'DB_ERROR');
        try {
            // Lock before decrypting and incrementing so concurrent reveals
            // cannot lose an audit count, and plaintext is never returned when
            // its access event could not be committed.
            $withdrawal = $this->ci->Withdrawal_model->find_for_update($public_id);
            if (!$withdrawal) {
                $this->ci->db->trans_rollback();
                return $this->error('Withdrawal not found', 'NOT_FOUND');
            }
            $plain = $this->ci->encryptionservice->open($withdrawal->destination_encrypted);
            $destination = $plain === null ? null : json_decode($plain, true);
            if (!is_array($destination) || empty($destination['account_number'])) {
                $this->ci->db->trans_rollback();
                return $this->error('Payout destination is unavailable', 'DESTINATION_UNAVAILABLE');
            }
            $this->ci->Withdrawal_model->record_reveal($withdrawal->id, $actor_id);
            $this->ci->Withdrawal_model->event(
                $withdrawal->id, $actor_id, 'DESTINATION_REVEALED',
                $withdrawal->status, $withdrawal->status, 'Payout destination opened'
            );
            if ($this->ci->db->trans_status() === false || !$this->ci->db->trans_commit()) {
                $this->ci->db->trans_rollback();
                return $this->error('Could not record destination access', 'DB_ERROR');
            }
            return array('ok' => true, 'destination' => $destination);
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'Withdrawal destination reveal failed: '.$e->getMessage());
            return $this->error('Could not record destination access', 'DB_ERROR');
        }
    }

    public function minimum() { return $this->setting_money('withdrawal_min_amount', self::DEFAULT_MIN); }
    public function maximum() { return $this->setting_money('withdrawal_max_amount', self::DEFAULT_MAX); }
    public function identity_required() {
        $value = $this->setting('withdrawal_require_verified_identity', false);
        return is_bool($value) ? $value
            : in_array(strtolower(trim((string)$value)), array('1', 'true', 'yes', 'on'), true);
    }
    public function identity_verified($user_id) {
        return $this->ci->Identity_check_model->has_verified_for_user($user_id);
    }

    public function fee_percent() {
        return $this->setting_money('withdrawal_fee_percent', self::DEFAULT_FEE_PERCENT, 4);
    }
    public function fixed_fee() {
        return $this->setting_money('withdrawal_fee_fixed', self::DEFAULT_FEE_FIXED);
    }
    public function fee_for($amount) {
        $amount = $this->money($amount);
        if ($amount === null) return '0.00000000';
        return bcadd(bcdiv(bcmul($amount, $this->fee_percent(), 8), '100', 8),
            $this->fixed_fee(), 8);
    }

    /* ------------------------------------------------------------------ */

    private function change($public_id, $target, $actor_id, $note = null, $payout_reference = null) {
        $allowed = array(
            self::STATUS_PENDING => array(self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED),
            self::STATUS_APPROVED => array(self::STATUS_PAID, self::STATUS_REJECTED),
        );
        if (!$this->ci->db->trans_begin()) return $this->error('Could not update withdrawal', 'DB_ERROR');
        try {
            $withdrawal = $this->ci->Withdrawal_model->find_for_update($public_id);
            if (!$withdrawal) {
                $this->ci->db->trans_rollback();
                return $this->error('Withdrawal not found', 'NOT_FOUND');
            }
            $from = $withdrawal->status;
            if ($from === $target) {
                $this->ci->db->trans_commit();
                return array('ok' => true, 'duplicate' => true,
                    'withdrawal' => $this->ci->Withdrawal_model->find_owned($public_id, $withdrawal->user_id));
            }
            if (!isset($allowed[$from]) || !in_array($target, $allowed[$from], true)) {
                $this->ci->db->trans_rollback();
                return $this->error('A '.$from.' withdrawal cannot move to '.$target, 'BAD_STATE');
            }

            $now = gmdate('Y-m-d H:i:s');
            $fields = array('status' => $target);
            if ($note !== null && trim((string)$note) !== '') {
                $fields['admin_note'] = mb_substr(trim((string)$note), 0, 500);
            }
            if ($target === self::STATUS_APPROVED) {
                $fields['approved_at'] = $now;
                $fields['approved_by'] = (int)$actor_id;
            } elseif ($target === self::STATUS_PAID) {
                $fields['paid_at'] = $now;
                $fields['paid_by'] = (int)$actor_id;
                $fields['resolved_at'] = $now;
                $fields['payout_reference'] = $payout_reference;
            } elseif (in_array($target, array(self::STATUS_REJECTED, self::STATUS_CANCELLED), true)) {
                $wallet = $this->ci->Wallet_model->for_user($withdrawal->user_id);
                $refund_key = 'withdrawal:'.$withdrawal->id.':refund';
                $refund = $this->ci->ledgerservice->refund_withdrawal(
                    $wallet->id, $withdrawal->amount, $withdrawal->public_id, $refund_key
                );
                if (empty($refund['ok'])) {
                    $this->ci->db->trans_rollback();
                    return $this->error('Could not return reserved wallet funds', 'REFUND_FAILED');
                }
                $wallet_tx = $this->ci->Wallet_transaction_model->find_by_idempotency_key($refund_key);
                if (!$wallet_tx) {
                    $this->ci->db->trans_rollback();
                    return $this->error('Could not trace the wallet refund', 'REFUND_FAILED');
                }
                $fields['refund_wallet_transaction_id'] = (int)$wallet_tx->id;
                $fields['resolved_at'] = $now;
            }

            if (!$this->ci->Withdrawal_model->transition($withdrawal->id, $from, $fields)) {
                $this->ci->db->trans_rollback();
                return $this->error('Withdrawal status changed concurrently', 'CONFLICT');
            }
            $this->ci->Withdrawal_model->event(
                $withdrawal->id, $actor_id, $target, $from, $target,
                $note === null ? null : mb_substr(trim((string)$note), 0, 500)
            );
            if ($this->ci->db->trans_status() === false || !$this->ci->db->trans_commit()) {
                $this->ci->db->trans_rollback();
                return $this->error('Could not update withdrawal', 'DB_ERROR');
            }
            return array('ok' => true,
                'withdrawal' => $this->ci->Withdrawal_model->find_owned($public_id, $withdrawal->user_id));
        } catch (Throwable $e) {
            $this->ci->db->trans_rollback();
            log_message('error', 'Withdrawal transition failed: '.$e->getMessage());
            return $this->error('Could not update withdrawal', 'DB_ERROR');
        }
    }

    private function destination(array $input) {
        $bank = trim((string)($input['bank_name'] ?? ''));
        $code = strtoupper(trim((string)($input['bank_code'] ?? '')));
        $account = preg_replace('/\s+/', '', (string)($input['account_number'] ?? ''));
        $name = trim((string)($input['account_name'] ?? ''));

        if (mb_strlen($bank) < 2 || mb_strlen($bank) > 80) {
            return $this->error('Enter a valid bank or payout provider name', 'BAD_DESTINATION');
        }
        if ($code !== '' && !preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
            return $this->error('Enter a valid bank code', 'BAD_DESTINATION');
        }
        if (!preg_match('/^[0-9]{6,20}$/', $account)) {
            return $this->error('Enter a valid account number', 'BAD_DESTINATION');
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $this->error('Enter the account holder name', 'BAD_DESTINATION');
        }

        $safe_bank = preg_replace('/[\x00-\x1F\x7F]/u', '', $bank);
        $safe_name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        return array(
            'ok' => true,
            'label' => mb_substr($safe_bank.' ••••'.substr($account, -4), 0, 120),
            'value' => array(
                'bank_name' => $safe_bank,
                'bank_code' => $code,
                'account_number' => $account,
                'account_name' => $safe_name,
            ),
        );
    }

    private function money($value, $scale = 8) {
        $raw = trim((string)$value);
        if ($raw === '' || !preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,8})?$/', $raw)) return null;
        if (bccomp($raw, '0', $scale) <= 0) return null;
        return bcadd($raw, '0', $scale);
    }

    private function setting_money($key, $default, $scale = 8) {
        $value = $this->setting($key, $default);
        $raw = trim((string)$value);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,8})?$/', $raw)) $raw = (string)$default;
        return bcadd($raw, '0', $scale);
    }

    private function setting($key, $default) {
        try { return $this->ci->Setting_model->get($key, $default); }
        catch (Throwable $e) { return $default; }
    }

    private function error($message, $code) {
        return array('ok' => false, 'error' => $message, 'code' => $code);
    }
}
