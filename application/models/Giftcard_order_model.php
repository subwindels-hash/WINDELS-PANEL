<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One gift card purchase (§23).
 *
 * Money lives on service_transactions; this row tracks the vendor order and
 * the delivery of the codes. The one habit enforced here rather than left to
 * callers is that nothing in this model reads or writes a card's plaintext:
 * codes belong to Giftcard_code_model, behind GiftcardService.
 */
class Giftcard_order_model extends MY_Model {
    protected $table = 'giftcard_orders';

    /** Vendor states in which the panel still owes the customer a code. */
    public static $open_states = array('PENDING', 'PLACED');

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_transaction($tx_id){
        return $this->db->where('service_transaction_id',$tx_id)->get($this->table)->row();
    }

    /**
     * Orders for many transactions at once, keyed by transaction id.
     * The history list needs one per row; fetching them individually is the
     * N+1 that PerformanceTest exists to catch.
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
     * Orders the vendor accepted but has not delivered codes for.
     *
     * The retry worker's query. Ordered oldest-first so the customer who has
     * been waiting longest is served first, and bounded by the caller — an
     * unbounded sweep here would call the vendor once per undelivered order in
     * a single tick.
     */
    public function awaiting_codes($limit = 100){
        return $this->db->where('status','PLACED')
                        ->where('provider_order_id IS NOT NULL', null, false)
                        ->order_by('placed_at','ASC')
                        ->limit($limit)
                        ->get($this->table)->result();
    }

    /**
     * Orders that have been waiting too long to be worth retrying.
     *
     * Takes the cutoff rather than computing it, so the worker's give-up
     * window is one number in one place instead of a constant duplicated
     * between the query and the caller that interprets it.
     */
    public function stale($cutoff, $max_attempts, $limit = 100){
        return $this->db->where('status','PLACED')
                        ->where('placed_at <=', $cutoff)
                        ->where('code_attempts >=', (int)$max_attempts)
                        ->order_by('placed_at','ASC')
                        ->limit($limit)
                        ->get($this->table)->result();
    }

    /** Record that a human opened a code on this order (§23). */
    public function record_reveal($id, $actor_id){
        $row = $this->find_by_id($id);
        $count = $row ? (int)$row->reveal_count : 0;
        return $this->db->where('id',$id)->update($this->table, array(
            'reveal_count'     => $count + 1,
            'last_revealed_at' => $this->now_utc(),
            'last_revealed_by' => $actor_id,
            'updated_at'       => $this->now_utc(),
        ));
    }

    /** Count one code-retrieval attempt, whatever its outcome. */
    public function record_attempt($id){
        $row = $this->find_by_id($id);
        $attempts = $row ? (int)$row->code_attempts : 0;
        return $this->db->where('id',$id)->update($this->table, array(
            'code_attempts'   => $attempts + 1,
            'last_attempt_at' => $this->now_utc(),
            'updated_at'      => $this->now_utc(),
        ));
    }

    public function status_counts(){
        $rows = $this->db->select('status, COUNT(*) AS total', false)
                         ->group_by('status')->get($this->table)->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->total;
        return $out;
    }
}
