<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_commission_model — one row per commission-earning event (Session 14).
 *
 * Rows start PENDING and move to PAID exactly once, at which point
 * `wallet_transaction_id` points at the LedgerService credit. `paid_at` is set
 * in the same UPDATE so a crashed payout can never double-credit.
 */
class Referral_commission_model extends MY_Model {
    protected $table = 'referral_commissions';

    const STATUS_PENDING  = 'PENDING';
    const STATUS_PAID     = 'PAID';
    const STATUS_REVERSED = 'REVERSED';
    const STATUS_REJECTED = 'REJECTED';

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return $this->find_by_id($this->db->insert_id());
    }

    public function find_for_order($referral_id, $order_id) {
        return $this->db->where('referral_id', $referral_id)->where('order_id', $order_id)
                        ->get($this->table)->row();
    }

    /** Commission rows belonging to a referrer, newest first. */
    public function for_referrer($referrer_id, $limit = 25, $offset = 0, $status = null) {
        $this->db->select('rc.*, u.username AS referred_username, o.public_id AS order_public_id', false)
            ->from('referral_commissions rc')
            ->join('referrals r', 'r.id = rc.referral_id', 'inner')
            ->join('users u', 'u.id = r.referred_id', 'inner')
            ->join('orders o', 'o.id = rc.order_id', 'left')
            ->where('r.referrer_id', $referrer_id);
        if ($status) $this->db->where('rc.status', $status);
        return $this->db->order_by('rc.created_at', 'DESC')->limit($limit, $offset)->get()->result();
    }

    public function count_for_referrer($referrer_id, $status = null) {
        $this->db->from('referral_commissions rc')
            ->join('referrals r', 'r.id = rc.referral_id', 'inner')
            ->where('r.referrer_id', $referrer_id);
        if ($status) $this->db->where('rc.status', $status);
        return (int)$this->db->count_all_results();
    }

    /** Sum of commissions for a referrer in a given status (DECIMAL string). */
    public function sum_for_referrer($referrer_id, $status = null) {
        $this->db->select('COALESCE(SUM(rc.amount),0) AS total', false)
            ->from('referral_commissions rc')
            ->join('referrals r', 'r.id = rc.referral_id', 'inner')
            ->where('r.referrer_id', $referrer_id);
        if ($status) $this->db->where('rc.status', $status);
        $row = $this->db->get()->row();
        return $row ? (string)$row->total : '0.00000000';
    }

    /** PENDING rows old enough to clear the hold window, oldest first. */
    public function payable($not_after, $limit = 200) {
        return $this->db->select('rc.*, r.referrer_id, r.referred_id', false)
            ->from('referral_commissions rc')
            ->join('referrals r', 'r.id = rc.referral_id', 'inner')
            ->where('rc.status', self::STATUS_PENDING)
            ->where('rc.created_at <=', $not_after)
            ->order_by('rc.created_at', 'ASC')
            ->limit($limit)->get()->result();
    }

    /**
     * Claim a PENDING row for payout. The WHERE on status makes this a
     * compare-and-set: only one worker can win the race.
     *
     * @return bool true when this caller claimed the row
     */
    public function mark_paid($id, $wallet_transaction_id) {
        $this->db->where('id', $id)->where('status', self::STATUS_PENDING)
            ->update($this->table, array(
                'status'                => self::STATUS_PAID,
                'wallet_transaction_id' => $wallet_transaction_id,
                'paid_at'               => $this->now_utc(),
            ));
        return $this->db->affected_rows() > 0;
    }

    /** Reverse an unpaid commission (order refunded/canceled before payout). */
    public function reverse($id) {
        $this->db->where('id', $id)->where('status', self::STATUS_PENDING)
            ->update($this->table, array('status' => self::STATUS_REVERSED));
        return $this->db->affected_rows() > 0;
    }
}
