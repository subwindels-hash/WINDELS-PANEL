<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The reservation itself (§10). Money lives on service_transactions; this
 * table holds where the *number* is, which is not the same question.
 */
class Virtual_number_model extends MY_Model {
    protected $table = 'virtual_numbers';

    /** Reservation states in which the vendor still holds the number for us. */
    public static function live_states(){ return array('RESERVED', 'RECEIVED'); }

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_transaction($tx_id){
        return $this->db->where('service_transaction_id',$tx_id)->get($this->table)->row();
    }

    /**
     * Reservations for many transactions at once, keyed by transaction id.
     *
     * The history and "your live numbers" lists both need the number beside
     * each transaction. Fetching one row per transaction is an N+1 that grows
     * with the page size, so the list pages ask for them in one query.
     */
    public function for_transactions(array $tx_ids){
        $tx_ids = array_values(array_unique(array_map('intval', $tx_ids)));
        if (!$tx_ids) return array();

        $out = array();
        foreach ($this->db->where_in('service_transaction_id',$tx_ids)->get($this->table)->result() as $row) {
            $out[(int)$row->service_transaction_id] = $row;
        }
        return $out;
    }

    public function update_for_transaction($tx_id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('service_transaction_id',$tx_id)->update($this->table, $fields);
    }

    public function update_fields($id, array $fields){
        $fields['updated_at'] = $this->now_utc();
        return $this->db->where('id',$id)->update($this->table, $fields);
    }

    /**
     * Reservations whose deadline has passed but which are still marked live.
     *
     * This is the query the expiry sweep exists for. It is deliberately driven
     * by expires_at and the *reservation* status, not by the transaction: a
     * transaction can be PROCESSING for many reasons, but only a number has a
     * deadline, and only a number that passed it without a code owes a refund.
     */
    public function expired($now = null, $limit = 200){
        $now = $now ?: $this->now_utc();
        return $this->db->where('status','RESERVED')
                        ->where('expires_at IS NOT NULL', null, false)
                        ->where('expires_at <=', $now)
                        ->order_by('expires_at','ASC')->limit($limit)
                        ->get($this->table)->result();
    }

    /**
     * Live reservations a poller should check for new SMS.
     *
     * RECEIVED numbers are included on purpose: a second code often follows
     * the first, and the customer is still holding the number until it is
     * finished or expires.
     */
    public function awaiting_sms($limit = 200){
        return $this->db->where_in('status', self::live_states())
                        ->where('provider_order_id IS NOT NULL', null, false)
                        ->order_by('reserved_at','ASC')->limit($limit)
                        ->get($this->table)->result();
    }
}
