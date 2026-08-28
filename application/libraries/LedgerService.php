<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * LedgerService — only writer to wallets/wallet_transactions/ledger_entries.
 * Uses DECIMAL(20,8) + bcmath + SELECT ... FOR UPDATE.
 *
 * ## The currency boundary (module 37)
 *
 * Every amount passed to charge()/credit()/refund() is denominated in the
 * BASE currency — the same convention every caller has always used, because
 * every price in the panel is a base-currency price. When the wallet itself
 * holds a foreign currency, the conversion happens HERE, at the only
 * boundary all money movement already passes through, which is why every
 * purchase domain (SMM orders, VTU, numbers, identity, gift cards, the
 * marketplace shop, deposits, commissions, payouts-as-credit) supports a
 * foreign wallet without a single engine change:
 *
 *   - the wallet side is converted at the currency's current rate and the
 *     rate is PINNED on the row (`fx_rate` + `base_amount`, migration 035);
 *   - a REFUND converts at the rate pinned on the charge it reverses —
 *     never the day's rate — so FX drift can never make a refund create or
 *     destroy money. That is the refund-rate policy, and it is enforced
 *     here rather than trusted to each caller;
 *   - the double entry gains a translation leg: the wallet movement writes
 *     `wallet:{id}` and `fx:{CODE}` in the wallet's currency (those books
 *     balance) and `fx:{CODE}` plus the account the movement always used
 *     (`revenue` / `liability` / …) in the base currency (those books
 *     balance too). The `fx:{CODE}` account is the platform's currency
 *     position: its foreign sub-balance is the currency taken in, its base
 *     sub-balance the value handed out, and their difference at today's
 *     rate is the unrealised FX position an operator can actually audit.
 *
 * adjust() is deliberately different: a staff correction is typed by a human
 * looking at a balance on screen, so its amount is in the WALLET'S currency
 * and no conversion happens. Its ledger entries stay a plain two-legged
 * pair in the wallet's currency.
 *
 * A base-currency wallet takes exactly the path it always took: two ledger
 * entries, no fx columns written. Nothing about existing money changes.
 */
class LedgerService {
    private $ci;
    public function __construct(){ $this->ci =& get_instance(); }

    /**
     * Debit the wallet for a base-currency amount (an order/service charge).
     * A foreign wallet is charged the converted amount at the pinned rate.
     */
    public function charge($wallet_id, $amount, $reference_type, $reference_id, $idempotency_key=null, $metadata=null){
        return $this->move($wallet_id, $amount, 'DEBIT', 'ORDER_CHARGE', $reference_type, $reference_id, $idempotency_key, $metadata);
    }

    /**
     * Credit the wallet for a base-currency amount (a deposit, a commission,
     * a payout settled as wallet credit). A foreign wallet is credited the
     * converted amount at the current rate.
     */
    public function credit($wallet_id, $amount, $type, $reference_type, $reference_id, $idempotency_key=null, $metadata=null){
        return $this->move($wallet_id, $amount, 'CREDIT', $type, $reference_type, $reference_id, $idempotency_key, $metadata);
    }

    /**
     * Refund a base-currency amount back to the wallet it came from.
     *
     * The conversion — if any — replays the rate PINNED on the charge being
     * reversed, looked up by reference; `$fx_rate` lets a caller that
     * already holds the original movement (a same-request rollback) pass it
     * explicitly instead of relying on the lookup.
     */
    public function refund($wallet_id, $amount, $reference_type, $reference_id, $idempotency_key=null, $fx_rate=null){
        return $this->move($wallet_id, $amount, 'CREDIT', 'REFUND', $reference_type, $reference_id,
            $idempotency_key, null, array('fx_rate' => $fx_rate));
    }

    /**
     * A manual balance correction made by a member of staff.
     *
     * This is the only entry point that writes `actor_id` and `note`, the two
     * columns wallet_transactions carries specifically for "an admin forced
     * this". A goodwill credit and a clawback are the same operation in
     * opposite directions, so they share one method and one audit shape.
     *
     * It deliberately reuses move(): an adjustment is not exempt from the
     * balance floor, the row lock or the idempotency guard just because a
     * human typed it. A DEBIT larger than the balance fails here exactly as a
     * purchase would, which is what stops a clawback driving a wallet
     * negative. And unlike charge/credit/refund, the amount is typed in the
     * wallet's OWN currency — the number on the screen the staff member is
     * looking at — so no conversion happens.
     */
    public function adjust($wallet_id, $amount, $direction, $reference_type, $reference_id,
                           $idempotency_key=null, $metadata=null, $actor_id=null, $note=null){
        $direction = strtoupper($direction) === 'DEBIT' ? 'DEBIT' : 'CREDIT';
        return $this->move($wallet_id, $amount, $direction, 'ADJUSTMENT', $reference_type, $reference_id,
            $idempotency_key, $metadata, array('actor_id' => $actor_id, 'note' => $note, 'wallet_currency_amount' => true));
    }

    /**
     * Does this wallet's balance cover a base-currency amount?
     *
     * The engines use this for their friendly pre-check; the authoritative
     * judgement is still the FOR UPDATE comparison inside move(). A foreign
     * balance is valued at the current rate — the same rate the charge would
     * convert at — with a one-unit-of-scale tolerance so a balance that is
     * exactly enough is never refused here and then accepted by the ledger.
     */
    public function covers($wallet, $base_amount) {
        if (!$wallet) return false;
        $base = marvy_base_currency();
        $wcur = strtoupper((string)$wallet->currency);
        $amount = (string)$base_amount;
        if ($wcur === $base) {
            return bccomp((string)$wallet->balance, $amount, 8) >= 0;
        }
        $row = $this->ci->db->where('code', $wcur)->get('currencies')->row();
        if (!$row || bccomp((string)$row->exchange_rate, '0', 8) <= 0) return false;
        $base_equivalent = bcdiv((string)$wallet->balance, (string)$row->exchange_rate, 8);
        return bccomp($base_equivalent, bcsub($amount, '0.00000001', 8), 8) >= 0;
    }

    private function move($wallet_id, $amount, $direction, $tx_type, $ref_type, $ref_id, $idem, $metadata, $extra=array(), $counter_account=null){
        $this->ci->db->trans_start();
        // idempotency guard
        if ($idem) {
            $existing = $this->ci->db->where('idempotency_key',$idem)->get('wallet_transactions')->row();
            if ($existing) { $this->ci->db->trans_complete(); return array('ok'=>TRUE,'duplicate'=>TRUE,'tx'=>$existing); }
        }
        $wallet = $this->ci->db->query('SELECT * FROM wallets WHERE id=? FOR UPDATE', array($wallet_id))->row();
        if (!$wallet) { $this->ci->db->trans_rollback(); return array('ok'=>FALSE,'error'=>'Wallet not found'); }

        // ---- The conversion, if this wallet holds a foreign currency ------
        // charge()/credit()/refund() speak base currency; adjust() speaks the
        // wallet's currency and never converts. The rate is pinned on the row
        // either way, so a refund can always replay it.
        $base = marvy_base_currency();
        $wcur = strtoupper((string)$wallet->currency);
        $amt_base = number_format((float)$amount, 8, '.', '');
        $fx_rate = null;           // NULL until a conversion actually happens
        $amt = $amt_base;          // the amount the wallet itself moves by
        $converted = ($wcur !== $base);
        if ($converted) {
            if (!empty($extra['wallet_currency_amount'])) {
                // A staff adjustment: typed in wallet currency, moved as typed.
                $amt = $amt_base;
            } else {
                $fx_rate = (isset($extra['fx_rate']) && $extra['fx_rate'] !== null)
                    ? (string)$extra['fx_rate']
                    : $this->resolve_rate($wallet_id, $tx_type, $ref_type, $ref_id, $wcur);
                if ($fx_rate === null) {
                    $this->ci->db->trans_rollback();
                    return array('ok'=>FALSE,'error'=>'CURRENCY_UNAVAILABLE',
                        'error_detail'=>'The wallet currency '.$wcur.' has no exchange rate configured.');
                }
                $amt = bcmul($amt_base, $fx_rate, 8);
            }
        } else {
            $amt = $amt_base;
        }
        $amt = number_format((float)$amt, 8, '.', '');
        // What write_entries needs to know: only a CONVERTED movement (a base
        // amount re-expressed in the wallet's currency) gets the four-legged
        // translation shape. A wallet-currency adjustment is a plain pair.
        $converted_movement = $converted && empty($extra['wallet_currency_amount']);

        $bal_before = $wallet->balance;
        if ($direction==='DEBIT') {
            if (bccomp($bal_before, $amt, 8) < 0) { $this->ci->db->trans_rollback(); return array('ok'=>FALSE,'error'=>'INSUFFICIENT_BALANCE'); }
            $bal_after = bcsub($bal_before, $amt, 8);
        } else {
            $bal_after = bcadd($bal_before, $amt, 8);
        }
        $public_id = marvy_public_id();
        $row = array(
            'public_id'=>$public_id,
            'wallet_id'=>$wallet_id,
            'type'=>$tx_type,
            'direction'=>$direction,
            'amount'=>$amt,
            'balance_before'=>$bal_before,
            'balance_after'=>$bal_after,
            'currency'=>$wcur,
            'reference_type'=>$ref_type,
            'reference_id'=>$ref_id,
            'idempotency_key'=>$idem,
            'metadata'=> $metadata ? json_encode($metadata) : null,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        );
        // The conversion record: what rate was pinned, and what the movement
        // was worth in base at that rate. NULL when nothing was converted —
        // a base wallet, or an adjustment typed in the wallet's currency.
        if ($converted_movement) {
            $row['fx_rate']     = number_format((float)$fx_rate, 8, '.', '');
            $row['base_amount'] = $amt_base;
        }
        // Only a staff adjustment supplies these; every other movement leaves
        // them NULL so "a human did this" stays a searchable fact.
        if (!empty($extra['actor_id'])) $row['actor_id'] = (int)$extra['actor_id'];
        if (!empty($extra['note']))     $row['note']     = mb_substr((string)$extra['note'], 0, 255);
        $this->ci->db->insert('wallet_transactions', $row);
        $wt_id = $this->ci->db->insert_id();
        // double-entry ledger
        $this->write_entries($wt_id, $wallet_id, $direction, $amt, $wcur,
            $converted_movement ? $amt_base : null, $base, $tx_type, $counter_account);
        // The lifetime counters move with the balance, in the same locked
        // transaction. They had existed since migration 002 and NOTHING had
        // ever written them, so "Total spent" on every admin customer screen
        // read ₦0.00 for every customer who had ever bought anything, and the
        // platform wallets summary said the same. Kept here rather than
        // recomputed per page because the admin customer list would otherwise
        // need an aggregate over the whole movement history per row.
        $counters = array('balance' => $bal_after, 'updated_at' => gmdate('Y-m-d H:i:s'));
        if ($direction === 'DEBIT') {
            $counters['total_spent'] = bcadd((string)($wallet->total_spent ?? '0'), $amt, 8);
        } elseif ($tx_type === 'DEPOSIT') {
            $counters['total_deposited'] = bcadd((string)($wallet->total_deposited ?? '0'), $amt, 8);
        } elseif ($tx_type === 'REFUND') {
            // Money handed back was never spent. Floored at zero: a refund of
            // a charge taken before this counter existed must not drive it
            // negative and make the column look broken all over again.
            $spent = bcsub((string)($wallet->total_spent ?? '0'), $amt, 8);
            $counters['total_spent'] = bccomp($spent, '0', 8) < 0 ? '0.00000000' : $spent;
        }
        $this->ci->db->where('id',$wallet_id)->update('wallets', $counters);
        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status()===FALSE) return array('ok'=>FALSE,'error'=>'Transaction failed');
        return array('ok'=>TRUE,'public_id'=>$public_id,'balance_after'=>$bal_after,
            'currency'=>$wcur, 'wallet_amount'=>$amt, 'fx_rate'=>$row['fx_rate'] ?? null,
            'base_amount'=>$row['base_amount'] ?? null);
    }

    /**
     * The ledger legs for one movement.
     *
     * Base wallet (or a wallet-currency adjustment): the two entries this
     * ledger has always written — the wallet account against its counter
     * (revenue for a charge, liability for a credit), in the wallet's
     * currency.
     *
     * Converted movement: those two entries are in different currencies and
     * must not be paired directly, so the translation account `fx:{CODE}`
     * carries the conversion as its own pair. Per currency the books balance:
     *
     *   charge $0.63 for a ₦980 service:
     *     wallet:7  DEBIT   0.63  USD   |  fx:USD  CREDIT 0.63  USD
     *     fx:USD   DEBIT  980.00  NGN   |  revenue CREDIT 980  NGN
     *
     * The counter account keeps the semantics it always had (revenue on
     * charge, liability on credit/refund); only the pairing changes.
     */
    private function write_entries($wt_id, $wallet_id, $direction, $amt, $wcur, $amt_base, $base, $tx_type, $counter_account=null) {
        $counter = $counter_account ?: ($direction==='DEBIT' ? 'revenue' : 'liability');
        $opposite = $direction==='DEBIT' ? 'CREDIT' : 'DEBIT';
        $now = gmdate('Y-m-d H:i:s');

        if ($amt_base === null) {
            // No conversion: the classic pair, in the wallet's currency.
            $this->ci->db->insert('ledger_entries', array(
                'wallet_transaction_id'=>$wt_id, 'account'=>'wallet:'.$wallet_id,
                'direction'=>$direction, 'amount'=>$amt, 'currency'=>$wcur, 'created_at'=>$now));
            $this->ci->db->insert('ledger_entries', array(
                'wallet_transaction_id'=>$wt_id, 'account'=>$counter,
                'direction'=>$opposite, 'amount'=>$amt, 'currency'=>$wcur, 'created_at'=>$now));
            return;
        }

        // Converted movement: the translation account bridges the currencies.
        $fx = 'fx:'.$wcur;
        $this->ci->db->insert('ledger_entries', array(
            'wallet_transaction_id'=>$wt_id, 'account'=>'wallet:'.$wallet_id,
            'direction'=>$direction, 'amount'=>$amt, 'currency'=>$wcur, 'created_at'=>$now));
        $this->ci->db->insert('ledger_entries', array(
            'wallet_transaction_id'=>$wt_id, 'account'=>$fx,
            'direction'=>$opposite, 'amount'=>$amt, 'currency'=>$wcur, 'created_at'=>$now));
        $this->ci->db->insert('ledger_entries', array(
            'wallet_transaction_id'=>$wt_id, 'account'=>$fx,
            'direction'=>$direction, 'amount'=>$amt_base, 'currency'=>$base, 'created_at'=>$now));
        $this->ci->db->insert('ledger_entries', array(
            'wallet_transaction_id'=>$wt_id, 'account'=>$counter,
            'direction'=>$opposite, 'amount'=>$amt_base, 'currency'=>$base, 'created_at'=>$now));
    }

    /**
     * Which rate should this converted movement use?
     *
     * A REFUND replays the rate PINNED on the charge it reverses — the most
     * recent converted DEBIT on this wallet with the same reference.
     * Engines that charge and refund against the same order/transaction id
     * (TransactionEngine, a stamped order charge) resolve exactly. When
     * nothing matches (a goodwill refund with no prior charge, or a
     * same-request rollback whose reference was never stamped) the current
     * rate is used and pinned on the refund row itself, so the answer is
     * always recorded, never guessed twice.
     *
     * Every other movement converts at the currency's current rate. Returns
     * null when the wallet's currency has no usable row in `currencies` — a
     * foreign wallet must never be moved at an invented rate.
     */
    private function resolve_rate($wallet_id, $tx_type, $ref_type, $ref_id, $wcur) {
        if ($tx_type === 'REFUND' && $ref_type !== null && $ref_id !== null && $ref_id !== '') {
            $orig = $this->ci->db
                ->where('wallet_id', $wallet_id)
                ->where('reference_type', $ref_type)
                ->where('reference_id', $ref_id)
                ->where('direction', 'DEBIT')
                ->order_by('id', 'DESC')
                ->limit(1)->get('wallet_transactions')->row();
            if ($orig && $orig->fx_rate !== null && bccomp((string)$orig->fx_rate, '0', 8) > 0) {
                return (string)$orig->fx_rate;
            }
        }
        $row = $this->ci->db->where('code', $wcur)->get('currencies')->row();
        if (!$row || bccomp((string)$row->exchange_rate, '0', 8) <= 0) return null;
        return (string)$row->exchange_rate;
    }
}
