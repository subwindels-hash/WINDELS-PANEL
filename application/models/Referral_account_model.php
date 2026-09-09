<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_account_model — one row per referrer (Session 14).
 *
 * `code` mirrors users.referral_code so a link stays stable, and the three
 * DECIMAL(20,8) totals (referred / earned / paid) are denormalised counters
 * maintained by AffiliateService — never by a controller.
 */
class Referral_account_model extends MY_Model {
    protected $table = 'referral_accounts';

    public function for_user($user_id) {
        return $this->db->where('user_id', $user_id)->get($this->table)->row();
    }

    public function find_by_code($code) {
        return $this->db->where('code', $code)->get($this->table)->row();
    }

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return $this->find_by_id($this->db->insert_id());
    }

    /** Bump the denormalised counters. Values are DECIMAL strings. */
    public function add_totals($id, $referred = 0, $earned = '0', $paid = '0') {
        if ((int)$referred !== 0) $this->db->set('total_referred', 'total_referred + '.(int)$referred, false);
        if (bccomp((string)$earned, '0', 8) !== 0) $this->db->set('total_earned', 'total_earned + '.$this->num($earned), false);
        if (bccomp((string)$paid, '0', 8) !== 0)   $this->db->set('total_paid', 'total_paid + '.$this->num($paid), false);
        $this->db->set('updated_at', $this->now_utc());
        return $this->db->where('id', $id)->update($this->table);
    }

    public function set_percent($id, $percent) {
        return $this->db->where('id', $id)->update($this->table, array(
            'commission_percent' => $this->num($percent, 4),
            'updated_at'         => $this->now_utc(),
        ));
    }

    /** Leaderboard for the admin affiliate screen. */
    public function paginated($limit = 25, $offset = 0) {
        return $this->db->select('ra.*, u.username, u.email, u.public_id AS user_public_id')
            ->from('referral_accounts ra')
            ->join('users u', 'u.id = ra.user_id', 'inner')
            ->order_by('ra.total_earned', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function count_all_accounts() {
        return (int)$this->db->count_all_results($this->table);
    }

    /** Never interpolate raw user input into SQL — clamp to a decimal literal. */
    private function num($value, $scale = 8) {
        $v = (string)$value;
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) $v = '0';
        return bcadd($v, '0', $scale);
    }
}
