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
    /**
     * Find the account a sign-in identifier refers to.
     *
     * Accepts an email address, a username, or the six-digit account number.
     * The numeric branch is only added when the input actually looks like a
     * code, so a username of "123456" still resolves by username first and a
     * normal username can never accidentally match a stranger's code.
     */
    public function find_by_identifier($identifier) {
        $identifier = trim((string) $identifier);
        if ($identifier === '') return null;

        $this->db->group_start()
                     ->where('email', $identifier)
                     ->or_where('username', $identifier);
        if (preg_match('/^\d{6}$/', $identifier)) {
            $this->db->or_where('user_code', $identifier);
        }
        $this->db->group_end();

        return $this->db->get($this->table)->row();
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

    /* -------------------------- admin directory -------------------------- */

    /**
     * One page of the admin user directory.
     *
     * Deliberately not user-scoped — this reads across every account, which is
     * safe only because the controller gates it behind `users.view`. The
     * wallet is joined in because a directory without balances sends the
     * operator to a second screen for the one number they came to see.
     *
     * password_hash and mfa_secret are never selected. A screen cannot leak a
     * column it does not read.
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('users.id, users.public_id, users.username, users.email,
                      users.first_name, users.last_name, users.phone, users.status,
                      users.role, users.price_group_id, users.email_verified_at,
                      users.mfa_enabled, users.last_login_at, users.created_at,
                      wallets.balance, wallets.currency, wallets.total_spent,
                      wallets.total_deposited, price_groups.name AS price_group_name', false)
            ->join('wallets', 'wallets.user_id = users.id', 'left')
            ->join('price_groups', 'price_groups.id = users.price_group_id', 'left')
            ->order_by('users.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status'])) $this->db->where('users.status', strtoupper($f['status']));
        if (!empty($f['role']))   $this->db->where('users.role', strtoupper($f['role']));
        if (!empty($f['price_group_id'])) {
            $this->db->where('users.price_group_id', (int)$f['price_group_id']);
        }
        // The staff screen wants only staff; the customer screen wants only
        // customers. Same query, one flag apart.
        if (!empty($f['staff_only'])) {
            $this->db->where_in('users.role', array('SUPER_ADMIN','ADMIN','STAFF'));
        }
        if (!empty($f['customers_only'])) {
            $this->db->where('users.role', 'CUSTOMER');
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('users.username', $term)
                ->or_like('users.email', $term)
                ->or_like('users.public_id', $term)
                ->or_like('users.phone', $term)
                ->group_end();
        }
    }

    /** Counts by status, for the filter chips. */
    public function status_counts(array $base = array()){
        $out = array();
        foreach (array('ACTIVE','SUSPENDED','BANNED','PENDING') as $s) {
            $this->admin_filters(array_merge($base, array('status' => $s)));
            $out[$s] = (int)$this->db->count_all_results();
        }
        return $out;
    }

    /**
     * Active super admins other than $except_id.
     *
     * Used to answer "is this the last one?" before a demotion or a suspension
     * can brick the panel. Counting is the point — a cached flag would drift.
     */
    public function count_active_super_admins($except_id = null){
        $this->db->from($this->table)
                 ->where('role', 'SUPER_ADMIN')
                 ->where('status', 'ACTIVE');
        if ($except_id !== null) $this->db->where('id !=', (int)$except_id);
        return (int)$this->db->count_all_results();
    }

    public function set_status($user_id, $status){
        return $this->db->where('id', $user_id)->update($this->table, array(
            'status' => $status, 'updated_at' => $this->now_utc(),
        ));
    }

    public function set_role($user_id, $role){
        return $this->db->where('id', $user_id)->update($this->table, array(
            'role' => $role, 'updated_at' => $this->now_utc(),
        ));
    }

    public function set_price_group($user_id, $price_group_id){
        return $this->db->where('id', $user_id)->update($this->table, array(
            'price_group_id' => $price_group_id, 'updated_at' => $this->now_utc(),
        ));
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
