<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TransactionEngine — the one transaction lifecycle every service domain uses (§18).
 *
 * The spec is explicit that VTU, numbers, gift cards, identity and education
 * must not each grow their own copy of the order engine. This class owns the
 * money-critical sequence once:
 *
 *   validate → price → check wallet → create record → debit → call provider
 *   → update record → finalise ledger → notify
 *
 * A domain service supplies the parts that actually differ, via execute():
 * what it costs, and what calling the provider means. Everything about charging,
 * refunding, status history and idempotency happens here.
 *
 * Money rules inherited from Session 09, deliberately unchanged:
 *   - LedgerService is the only writer of wallet tables.
 *   - Amounts are DECIMAL-as-string, compared and arithmetic'd with bcmath.
 *   - A failed or rejected provider call refunds in full, exactly once.
 *   - An idempotency_key makes a retry resolve to the original transaction
 *     rather than charging twice.
 */
class TransactionEngine {

    /** Terminal states in which the customer must get their money back. */
    private static $refunding_states = array('FAILED', 'CANCELLED', 'REFUNDED');

    /**
     * States that end the transaction's life. Note SUCCESSFUL is NOT here:
     * a completed purchase must still be refundable by an admin (§25), and
     * treating it as closed would make goodwill refunds impossible. What must
     * never happen twice is the *refund*, which refunded_amount guards.
     */
    private static $terminal_states = array('FAILED', 'CANCELLED', 'REFUNDED');

    /** States that may still be transitioned out of. */
    private static $settled_states = array('SUCCESSFUL');

    const REFERENCE_TYPE = 'ServiceTransaction';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Service_transaction_model', 'Service_transaction_status_history_model',
            'Wallet_model',
        ));
        $this->ci->load->library(array('LedgerService'));
    }

    /**
     * Run one service purchase end to end.
     *
     * @param object|int $user
     * @param array $spec {
     *   service_domain: string   VTU|NUMBER|GIFTCARD|...
     *   service_type:   string   AIRTIME|DATA|...
     *   amount:         string   what the customer pays
     *   provider_cost:  ?string  frozen for margin reporting
     *   service_id:     ?int
     *   provider_id:    ?int
     *   idempotency_key:?string
     *   source:         ?string
     *   metadata:       ?array
     *   detail:         ?callable(int $service_transaction_id): void
     *                   writes the domain's own row (vtu_transactions, ...)
     *   dispatch:       callable(object $tx): array
     *                   calls the provider. Returns:
     *                     ok:        bool
     *                     reference: ?string  provider reference
     *                     status:    ?string  SUCCESSFUL|PROCESSING (default SUCCESSFUL)
     *                     error:     ?string
     *                     cost:      ?string  actual provider cost, if known now
     *                     detail:    ?array   domain columns to merge (token, units...)
     * }
     * @return array{ok:bool,transaction?:object,error?:string,code?:string}
     */
    public function execute($user, array $spec) {
        $user_id = is_object($user) ? (int)$user->id : (int)$user;
        if ($user_id <= 0) {
            return $this->fail_result('Unknown user', 'NO_USER');
        }

        foreach (array('service_domain', 'service_type', 'amount', 'dispatch') as $required) {
            if (!isset($spec[$required])) {
                return $this->fail_result('Missing '.$required, 'BAD_REQUEST');
            }
        }
        if (!is_callable($spec['dispatch'])) {
            return $this->fail_result('dispatch must be callable', 'BAD_REQUEST');
        }

        $amount = $this->money($spec['amount']);
        if (bccomp($amount, '0', 8) <= 0) {
            return $this->fail_result('Amount must be greater than zero', 'BAD_AMOUNT');
        }

        // 0. Idempotency: a retry must resolve to the original, not a new charge.
        $idem = isset($spec['idempotency_key']) ? $spec['idempotency_key'] : null;
        if ($idem) {
            $existing = $this->ci->Service_transaction_model->find_by_idempotency_key($idem);
            if ($existing) {
                return array('ok' => true, 'transaction' => $existing, 'duplicate' => true);
            }
        }

        $wallet = $this->ci->Wallet_model->for_user($user_id);
        if (!$wallet) {
            return $this->fail_result('Wallet not found', 'NO_WALLET');
        }
        // Cheap pre-check for a clear error message; LedgerService re-checks
        // under FOR UPDATE, which is the authoritative one.
        if (bccomp($this->money($wallet->balance), $amount, 8) < 0) {
            return $this->fail_result('Insufficient wallet balance', 'INSUFFICIENT_BALANCE');
        }

        // 1. Create the record first, so a provider call can never happen
        //    without something durable to attribute it to.
        $now = gmdate('Y-m-d H:i:s');
        $tx_id = $this->ci->Service_transaction_model->create(array(
            'public_id'       => windels_public_id(),
            'user_id'         => $user_id,
            'service_domain'  => $spec['service_domain'],
            'service_type'    => $spec['service_type'],
            'service_id'      => isset($spec['service_id']) ? $spec['service_id'] : null,
            'provider_id'     => isset($spec['provider_id']) ? $spec['provider_id'] : null,
            'status'          => 'PENDING',
            'amount'          => $amount,
            'provider_cost'   => isset($spec['provider_cost']) && $spec['provider_cost'] !== null
                                    ? $this->money($spec['provider_cost']) : null,
            'currency'        => isset($wallet->currency) ? $wallet->currency : windels_base_currency(),
            'idempotency_key' => $idem,
            'source'          => isset($spec['source']) ? $spec['source'] : 'WEB',
            'metadata'        => isset($spec['metadata']) ? json_encode($spec['metadata']) : null,
            'created_at'      => $now,
        ));
        if (!$tx_id) {
            return $this->fail_result('Could not create transaction', 'CREATE_FAILED');
        }
        $this->record_status($tx_id, null, 'PENDING', 'SYSTEM');

        // 2. Domain-specific detail row (vtu_transactions, number_orders, ...).
        if (isset($spec['detail']) && is_callable($spec['detail'])) {
            call_user_func($spec['detail'], $tx_id);
        }

        // 3. Charge. LedgerService is idempotent and holds the row lock.
        $charge = $this->ci->ledgerservice->charge(
            $wallet->id, $amount, self::REFERENCE_TYPE, $tx_id,
            $idem ? $idem.':charge' : null,
            array('domain' => $spec['service_domain'], 'type' => $spec['service_type'])
        );
        if (empty($charge['ok'])) {
            $reason = isset($charge['error']) ? $charge['error'] : 'Charge failed';
            $this->transition($tx_id, 'FAILED', 'SYSTEM', $reason, array('refund' => false));
            return $this->fail_result($reason,
                $reason === 'INSUFFICIENT_BALANCE' ? 'INSUFFICIENT_BALANCE' : 'CHARGE_FAILED');
        }
        // LedgerService returns the wallet transaction's public_id on a fresh
        // charge, and the row itself when it recognises a duplicate. Resolve
        // either into the id, so the debit is always traceable from here.
        $wallet_tx_id = $this->resolve_wallet_tx_id($charge);
        if ($wallet_tx_id) {
            $this->ci->Service_transaction_model->update_fields($tx_id, array(
                'wallet_transaction_id' => $wallet_tx_id,
            ));
        }
        $this->transition($tx_id, 'PROCESSING', 'SYSTEM', null, array('refund' => false));

        // 4. Provider call. Anything thrown here is a failed purchase the
        //    customer has already paid for, so it must refund like a rejection.
        $tx = $this->ci->Service_transaction_model->find_by_id($tx_id);
        try {
            $result = call_user_func($spec['dispatch'], $tx);
        } catch (Exception $e) {
            log_message('error', 'TransactionEngine dispatch threw: '.$e->getMessage());
            $this->transition($tx_id, 'FAILED', 'PROVIDER', 'Provider error');
            return $this->fail_result('The provider could not be reached', 'PROVIDER_ERROR');
        }
        if (!is_array($result)) {
            $this->transition($tx_id, 'FAILED', 'PROVIDER', 'Malformed provider response');
            return $this->fail_result('The provider returned an unusable response', 'PROVIDER_ERROR');
        }

        $fields = array();
        if (!empty($result['reference'])) $fields['provider_reference'] = $result['reference'];
        if (isset($result['cost']) && $result['cost'] !== null) {
            $fields['provider_cost'] = $this->money($result['cost']);
        }
        if ($fields) $this->ci->Service_transaction_model->update_fields($tx_id, $fields);

        if (empty($result['ok'])) {
            $reason = isset($result['error']) ? $result['error'] : 'Provider rejected the request';
            $this->transition($tx_id, 'FAILED', 'PROVIDER', $reason);
            return $this->fail_result($reason, 'PROVIDER_REJECTED');
        }

        // 5. Success — or still in flight, for providers that settle async.
        $final = isset($result['status']) ? strtoupper($result['status']) : 'SUCCESSFUL';
        if (!in_array($final, array('SUCCESSFUL', 'PROCESSING'), true)) {
            $final = 'SUCCESSFUL';
        }
        if ($final === 'SUCCESSFUL') {
            $this->transition($tx_id, 'SUCCESSFUL', 'PROVIDER');
        }

        return array('ok' => true, 'transaction' => $this->ci->Service_transaction_model->find_by_id($tx_id));
    }

    /**
     * Move a transaction to a new status, refunding on the way into a
     * refunding terminal state.
     *
     * Refund exactly once: guarded by refunded_amount, and by refusing to
     * leave a terminal state.
     *
     * @param array $opts refund: bool (default true), amount: ?string partial
     */
    public function transition($tx_id, $new_status, $source = 'SYSTEM', $reason = null, array $opts = array()) {
        $tx = $this->ci->Service_transaction_model->find_by_id($tx_id);
        if (!$tx) return array('ok' => false, 'error' => 'Transaction not found', 'code' => 'NOT_FOUND');

        $from = $tx->status;
        // Terminal check first. Re-requesting REFUNDED on an already-refunded
        // transaction must be an explicit rejection, not a quiet "unchanged"
        // success — a caller that treats ok=true as "the refund happened" would
        // otherwise report a second refund that never occurred.
        if (in_array($from, self::$terminal_states, true)) {
            return array('ok' => false, 'error' => 'Transaction is already '.$from, 'code' => 'TERMINAL');
        }
        if ($from === $new_status) {
            return array('ok' => true, 'transaction' => $tx, 'unchanged' => true);
        }
        // Anything else already settled has had its money moved.
        // A settled purchase may only move to a refunding state — an admin
        // refund or cancellation. It must not go back to PROCESSING.
        if (in_array($from, self::$settled_states, true)
            && !in_array($new_status, self::$refunding_states, true)) {
            return array('ok' => false,
                'error' => 'A '.$from.' transaction can only be refunded or cancelled',
                'code' => 'NOT_ALLOWED');
        }

        $fields = array('status' => $new_status);
        if (in_array($new_status, self::$terminal_states, true)
            || in_array($new_status, self::$settled_states, true)) {
            $fields['completed_at'] = gmdate('Y-m-d H:i:s');
        }
        if ($reason !== null) $fields['failure_reason'] = substr($reason, 0, 255);

        $wants_refund = !array_key_exists('refund', $opts) || $opts['refund'];
        $refund_amount = null;
        if ($wants_refund && in_array($new_status, self::$refunding_states, true)) {
            $already = $this->money(isset($tx->refunded_amount) ? $tx->refunded_amount : '0');
            $charged = $this->money($tx->amount);
            $target  = isset($opts['amount']) ? $this->money($opts['amount']) : $charged;
            // Never refund more than was charged, in total.
            $remaining = bcsub($charged, $already, 8);
            if (bccomp($target, $remaining, 8) > 0) $target = $remaining;
            if (bccomp($target, '0', 8) > 0 && $tx->wallet_transaction_id) {
                $refund_amount = $target;
            }
        }

        if ($refund_amount !== null) {
            $wallet = $this->ci->Wallet_model->for_user($tx->user_id);
            if ($wallet) {
                $res = $this->ci->ledgerservice->refund(
                    $wallet->id, $refund_amount, self::REFERENCE_TYPE, $tx->id,
                    'stx:'.$tx->id.':refund:'.$new_status
                );
                if (!empty($res['ok'])) {
                    $fields['refunded_amount'] = bcadd(
                        $this->money($tx->refunded_amount), $refund_amount, 8);
                }
            }
        }

        $this->ci->Service_transaction_model->update_fields($tx->id, $fields);
        $this->record_status($tx->id, $from, $new_status, $source, $reason);

        return array(
            'ok' => true,
            'transaction' => $this->ci->Service_transaction_model->find_by_id($tx->id),
            'refunded' => $refund_amount,
        );
    }

    /* -------------------------------------------------------------------- */

    /** The wallet_transactions.id behind a LedgerService result. */
    private function resolve_wallet_tx_id(array $charge) {
        if (!empty($charge['tx']) && isset($charge['tx']->id)) {
            return (int)$charge['tx']->id;
        }
        if (!empty($charge['public_id'])) {
            $row = $this->ci->db->where('public_id', $charge['public_id'])
                                ->get('wallet_transactions')->row();
            if ($row) return (int)$row->id;
        }
        return null;
    }

    private function record_status($tx_id, $from, $to, $source, $reason = null) {
        $this->ci->Service_transaction_status_history_model->record($tx_id, $from, $to, $source, $reason);
    }

    private function money($v) {
        return number_format((float)$v, 8, '.', '');
    }

    private function fail_result($error, $code) {
        return array('ok' => false, 'error' => $error, 'code' => $code);
    }
}
