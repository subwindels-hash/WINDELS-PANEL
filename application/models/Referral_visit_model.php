<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_visit_model — click attribution.
 *
 * Stores a salted hash of IP+user-agent, never the raw IP: the fraud signal
 * ("several signups from one machine") works on a hash, so tracking clicks does
 * not create a second database of everyone's IP addresses.
 */
class Referral_visit_model extends MY_Model {

    protected $table = 'referral_visits';

    public function record(array $data) {
        $data['created_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    /** Link the most recent matching click to the account it produced. */
    public function mark_converted($visitor_hash, $user_id) {
        $row = $this->db->where('visitor_hash', $visitor_hash)
                        ->where('converted_user_id IS NULL', null, false)
                        ->order_by('id', 'DESC')->limit(1)
                        ->get($this->table)->row();
        if (!$row) return false;
        return $this->db->where('id', $row->id)
                        ->update($this->table, array('converted_user_id' => (int)$user_id));
    }

    public function count_for_code($code, $since = null) {
        $this->db->where('code', strtoupper((string)$code));
        if ($since) $this->db->where('created_at >=', $since);
        return (int)$this->db->count_all_results($this->table);
    }
}
