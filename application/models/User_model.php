<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends MY_Model {
    protected $table = 'users';

    public function find_by_email($email) {
        return $this->db->where('email', $email)->get($this->table)->row();
    }

    public function find_by_username($username) {
        return $this->db->where('username', $username)->get($this->table)->row();
    }

    /** Login identifier may be either the email or the username. */
    public function find_by_identifier($identifier) {
        return $this->db->group_start()
                            ->where('email', $identifier)
                            ->or_where('username', $identifier)
                        ->group_end()
                        ->get($this->table)->row();
    }

    public function find_by_referral_code($code) {
        return $this->db->where('referral_code', $code)->get($this->table)->row();
    }

    public function touch_login($user_id, $ip = NULL) {
        return $this->db->where('id', $user_id)->update($this->table, array(
            'last_login_at' => $this->now_utc(),
            'last_login_ip' => $ip,
        ));
    }

    public function is_staff($user) {
        return $user && in_array($user->role, array('SUPER_ADMIN','ADMIN','STAFF'), TRUE);
    }

    /** Staff who can be assigned a ticket (admin UI dropdowns). */
    public function staff_members(){
        return $this->db->select('id, username, email, role', false)
            ->where_in('role', array('SUPER_ADMIN','ADMIN','STAFF'))
            ->where('status', 'ACTIVE')
            ->order_by('username', 'ASC')
            ->get($this->table)->result();
    }
}
