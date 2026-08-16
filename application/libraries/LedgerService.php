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

    private function move($wallet_id, $amount, $direction, $tx_type, $ref_type, $ref_id, $idem, $metadata){
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
        $this->ci->db->insert('wallet_transactions', array(
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
        ));
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
