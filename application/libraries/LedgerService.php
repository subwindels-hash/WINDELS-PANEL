<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * LedgerService — only writer to wallets/wallet_transactions/ledger_entries.
 * Uses DECIMAL(20,8) + bcmath + SELECT ... FOR UPDATE.
 */
class LedgerService {
    private $ci;
    public function __construct(){ $this->ci =& get_instance(); }

    public function charge($wallet_id, $amount, $reference_type, $reference_id, $idempotency_key=null, $metadata=null){
        return $this->move($wallet_id, $amount, 'DEBIT', 'ORDER_CHARGE', $reference_type, $reference_id, $idempotency_key, $metadata);
    }
    public function credit($wallet_id, $amount, $type, $reference_type, $reference_id, $idempotency_key=null, $metadata=null){
        return $this->move($wallet_id, $amount, 'CREDIT', $type, $reference_type, $reference_id, $idempotency_key, $metadata);
    }
    public function refund($wallet_id, $amount, $reference_type, $reference_id, $idempotency_key=null){
        return $this->credit($wallet_id, $amount, 'REFUND', $reference_type, $reference_id, $idempotency_key);
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
     * negative.
     */
    public function adjust($wallet_id, $amount, $direction, $reference_type, $reference_id,
                           $idempotency_key=null, $metadata=null, $actor_id=null, $note=null){
        $direction = strtoupper($direction) === 'DEBIT' ? 'DEBIT' : 'CREDIT';
        return $this->move($wallet_id, $amount, $direction, 'ADJUSTMENT', $reference_type, $reference_id,
            $idempotency_key, $metadata, array('actor_id' => $actor_id, 'note' => $note));
    }

    private function move($wallet_id, $amount, $direction, $tx_type, $ref_type, $ref_id, $idem, $metadata, $extra=array()){
        $this->ci->db->trans_start();
        // idempotency guard
        if ($idem) {
            $existing = $this->ci->db->where('idempotency_key',$idem)->get('wallet_transactions')->row();
            if ($existing) { $this->ci->db->trans_complete(); return array('ok'=>TRUE,'duplicate'=>TRUE,'tx'=>$existing); }
        }
        $wallet = $this->ci->db->query('SELECT * FROM wallets WHERE id=? FOR UPDATE', array($wallet_id))->row();
        if (!$wallet) { $this->ci->db->trans_rollback(); return array('ok'=>FALSE,'error'=>'Wallet not found'); }
        $bal_before = $wallet->balance;
        $amt = number_format((float)$amount, 8, '.', '');
        if ($direction==='DEBIT') {
            if (bccomp($bal_before, $amt, 8) < 0) { $this->ci->db->trans_rollback(); return array('ok'=>FALSE,'error'=>'INSUFFICIENT_BALANCE'); }
            $bal_after = bcsub($bal_before, $amt, 8);
        } else {
            $bal_after = bcadd($bal_before, $amt, 8);
        }
        $public_id = windels_public_id();
        $row = array(
            'public_id'=>$public_id,
            'wallet_id'=>$wallet_id,
            'type'=>$tx_type,
            'direction'=>$direction,
            'amount'=>$amt,
            'balance_before'=>$bal_before,
            'balance_after'=>$bal_after,
            'reference_type'=>$ref_type,
            'reference_id'=>$ref_id,
            'idempotency_key'=>$idem,
            'metadata'=> $metadata ? json_encode($metadata) : null,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        );
        // Only a staff adjustment supplies these; every other movement leaves
        // them NULL so "a human did this" stays a searchable fact.
        if (!empty($extra['actor_id'])) $row['actor_id'] = (int)$extra['actor_id'];
        if (!empty($extra['note']))     $row['note']     = mb_substr((string)$extra['note'], 0, 255);
        $this->ci->db->insert('wallet_transactions', $row);
        $wt_id = $this->ci->db->insert_id();
        // double-entry ledger
        $this->ci->db->insert('ledger_entries', array(
            'wallet_transaction_id'=>$wt_id,
            'account'=> 'wallet:'.$wallet_id,
            'direction'=>$direction,
            'amount'=>$amt,
            'currency'=>$wallet->currency,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ));
        $counter = $direction==='DEBIT' ? 'revenue' : 'liability';
        $this->ci->db->insert('ledger_entries', array(
            'wallet_transaction_id'=>$wt_id,
            'account'=>$counter,
            'direction'=> $direction==='DEBIT' ? 'CREDIT' : 'DEBIT',
            'amount'=>$amt,
            'currency'=>$wallet->currency,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ));
        $this->ci->db->where('id',$wallet_id)->update('wallets', array('balance'=>$bal_after, 'updated_at'=>gmdate('Y-m-d H:i:s')));
        $this->ci->db->trans_complete();
        if ($this->ci->db->trans_status()===FALSE) return array('ok'=>FALSE,'error'=>'Transaction failed');
        return array('ok'=>TRUE,'public_id'=>$public_id,'balance_after'=>$bal_after);
    }
}
