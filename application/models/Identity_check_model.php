<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One identity lookup (§22).
 *
 * Money lives on service_transactions; this row holds the answer and the
 * evidence trail around it. Two habits are enforced here rather than left to
 * callers:
 *
 *  - **Nothing in this model accepts a raw NIN or BVN.** Lookups are by blind
 *    index (see EncryptionService::blind_index), so there is no method that
 *    could put the identifier into a query, a log or a slow-query report.
 *  - **purge() empties the payload but keeps the row.** Retention applies to
 *    the personal data, not to the fact that a paid transaction happened.
 */
class Identity_check_model extends MY_Model {
    protected $table = 'identity_checks';

    public function create(array $data){
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_transaction($tx_id){
        return $this->db->where('service_transaction_id',$tx_id)->get($this->table)->row();
    }

    /**
     * Checks for many transactions at once, keyed by transaction id.
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
     * Earlier checks of the same identifier.
     *
     * Takes the blind index, never the number. Support uses this to answer
     * "have we already looked this one up?" by hashing what the customer
     * quotes and comparing, which works without the panel ever holding it.
     */
    public function by_identifier_hash($hash, $limit = 20){
        return $this->db->where('identifier_hash',$hash)
                        ->order_by('created_at','DESC')->limit($limit)
                        ->get($this->table)->result();
    }

    /** Record that a human opened the decrypted result (§22). */
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

    /**
     * Results old enough to scrub.
     *
     * Only rows that still hold a payload are returned, so a second sweep over
     * the same window does no work and purged_at keeps its original timestamp.
     */
    public function purgeable($cutoff, $limit = 500){
        return $this->db->where('purged_at IS NULL', null, false)
                        ->where('result_encrypted IS NOT NULL', null, false)
                        ->where('created_at <=', $cutoff)
                        ->order_by('created_at','ASC')->limit($limit)
                        ->get($this->table)->result();
    }

    /** Drop the payload, keep the evidence that the check happened. */
    public function purge($id){
        return $this->db->where('id',$id)->update($this->table, array(
            'result_encrypted' => null,
            'purged_at'        => $this->now_utc(),
            'updated_at'       => $this->now_utc(),
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
