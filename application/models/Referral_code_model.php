<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Referral_code_model — named/vanity referral codes.
 *
 * Codes are stored and compared uppercase so JOHN8K24, john8k24 and John8k24
 * are one code. Anything else means a customer sharing their code in lowercase
 * silently loses their referrals.
 */
class Referral_code_model extends MY_Model {

    protected $table = 'referral_codes';

    public function by_code($code) {
        return $this->db->where('code', strtoupper(trim((string)$code)))->get($this->table)->row();
    }

    public function primary_for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)->where('is_active', 1)
                        ->order_by('id', 'ASC')->limit(1)->get($this->table)->row();
    }

    public function for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)->order_by('id', 'ASC')
                        ->get($this->table)->result();
    }

    public function create(array $data) {
        $data['public_id']  = $this->new_public_id();
        $data['code']       = strtoupper(trim((string)$data['code']));
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function set_active($id, $active) {
        return $this->db->where('id', (int)$id)->update($this->table, array(
            'is_active'  => $active ? 1 : 0,
            'updated_at' => $this->now_utc(),
        ));
    }

    /**
     * Increment a counter in the database rather than read-modify-write.
     *
     * Two concurrent clicks on the same link must count as two: reading the
     * value in PHP and writing back value+1 loses one of them.
     */
    public function bump($id, $column) {
        $allowed = array('total_visits', 'total_signups', 'total_qualified');
        if (!in_array($column, $allowed, true)) return false;
        $this->db->set($column, $column.' + 1', false)
                 ->where('id', (int)$id)->update($this->table);
        return true;
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        if (!empty($filters['search'])) $this->db->like('code', strtoupper($filters['search']));
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $this->db->where('is_active', (int)$filters['is_active']);
        }
        return $this->db->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }
}
