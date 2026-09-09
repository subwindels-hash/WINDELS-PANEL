<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_signup_model — the qualification state machine.
 *
 * `referred_user_id` is UNIQUE in the schema: an account is attributed once and
 * never re-attributed. That constraint, not application logic, is what makes
 * "refer the same person twice" impossible.
 */
class Referral_signup_model extends MY_Model {

    protected $table = 'referral_signups';

    public function create(array $data) {
        $data['public_id']  = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function for_referred($user_id) {
        return $this->db->where('referred_user_id', (int)$user_id)->get($this->table)->row();
    }

    public function for_referrer($user_id, $limit = 25, $offset = 0) {
        return $this->db->where('referrer_user_id', (int)$user_id)
                        ->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    /** Compare-and-set, so a repeated event cannot advance the same row twice. */
    public function transition($id, $from, $to, array $extra = array()) {
        $data = array_merge($extra, array('status' => $to, 'updated_at' => $this->now_utc()));
        $this->db->where('id', (int)$id)->where('status', $from)->update($this->table, $data);
        return $this->db->affected_rows() > 0;
    }

    public function count_for_referrer($user_id) {
        return (int)$this->db->where('referrer_user_id', (int)$user_id)
                             ->where_in('status', array('PENDING', 'QUALIFIED', 'REWARDED'))
                             ->count_all_results($this->table);
    }

    /** Signups from one device in the last N hours — the velocity signal. */
    public function count_by_ip_hash($hash, $hours = 24) {
        $since = gmdate('Y-m-d H:i:s', time() - ((int)$hours * 3600));
        return (int)$this->db->where('signup_ip_hash', $hash)
                             ->where('created_at >=', $since)
                             ->count_all_results($this->table);
    }

    public function counts_for_referrer($user_id) {
        $rows = $this->db->select('status, COUNT(*) AS n', false)
                         ->where('referrer_user_id', (int)$user_id)
                         ->group_by('status')->get($this->table)->result();
        $out = array('PENDING' => 0, 'QUALIFIED' => 0, 'REWARDED' => 0,
                     'REJECTED' => 0, 'FRAUD_REVIEW' => 0);
        foreach ($rows as $row) $out[$row->status] = (int)$row->n;
        return $out;
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        $this->apply_filters($filters);
        return $this->db->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }

    public function admin_count(array $filters = array()) {
        $this->apply_filters($filters);
        return (int)$this->db->count_all_results($this->table);
    }

    private function apply_filters(array $f) {
        if (!empty($f['status'])) $this->db->where('status', $f['status']);
        if (!empty($f['campaign_id'])) $this->db->where('campaign_id', (int)$f['campaign_id']);
        if (!empty($f['referrer_user_id'])) $this->db->where('referrer_user_id', (int)$f['referrer_user_id']);
        if (!empty($f['flagged'])) $this->db->where('fraud_flags IS NOT NULL', null, false);
    }
}
