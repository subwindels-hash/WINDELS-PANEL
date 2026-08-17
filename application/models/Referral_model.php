<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_model — the referrer → referred edge (Session 14).
 *
 * `referred_id` is UNIQUE in the schema, so a user can only ever be attributed
 * to one referrer; attribution is therefore first-touch and permanent.
 */
class Referral_model extends MY_Model {
    protected $table = 'referrals';

    public function for_referred($referred_id) {
        return $this->db->where('referred_id', $referred_id)->get($this->table)->row();
    }

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return $this->find_by_id($this->db->insert_id());
    }

    public function count_for_referrer($referrer_id) {
        return (int)$this->db->where('referrer_id', $referrer_id)->count_all_results($this->table);
    }

    /** Referred users with their signup date and lifetime commission earned. */
    public function for_referrer($referrer_id, $limit = 25, $offset = 0) {
        return $this->db->select(
                'r.id, r.created_at, u.username, u.public_id AS user_public_id, u.created_at AS joined_at,'.
                ' COALESCE(SUM(rc.amount),0) AS earned,'.
                " COALESCE(SUM(CASE WHEN rc.status = 'PENDING' THEN rc.amount ELSE 0 END),0) AS pending", false)
            ->from('referrals r')
            ->join('users u', 'u.id = r.referred_id', 'inner')
            ->join('referral_commissions rc', 'rc.referral_id = r.id', 'left')
            ->where('r.referrer_id', $referrer_id)
            ->group_by('r.id, r.created_at, u.username, u.public_id, u.created_at')
            ->order_by('r.created_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }
}
