<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Fundsvera_virtual_account_model — the customer's standing bank account.
 *
 * One row per user, mirroring the provider's own behaviour: their
 * create-virtual-account endpoint returns the existing account rather than
 * issuing a duplicate, so the UNIQUE constraint on user_id is the same rule
 * expressed locally.
 */
class Fundsvera_virtual_account_model extends MY_Model {

    protected $table = 'fundsvera_virtual_accounts';

    public function for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)->get($this->table)->row();
    }

    public function by_account_number($account_number) {
        return $this->db->where('account_number', $account_number)->get($this->table)->row();
    }

    /**
     * Persist a newly issued account.
     *
     * Uses an upsert on user_id rather than a plain insert: if the provider
     * hands back an account we already stored (their documented behaviour on a
     * repeat call), re-inserting would violate the unique key and turn a
     * successful API call into an error the customer sees.
     */
    public function store($user, array $data) {
        $existing = $this->for_user($user->id);
        $data['updated_at'] = $this->now_utc();

        if ($existing) {
            $this->db->where('id', $existing->id)->update($this->table, $data);
            return (int)$existing->id;
        }

        $data['public_id']  = $this->new_public_id();
        $data['user_id']    = (int)$user->id;
        $data['created_at'] = $this->now_utc();
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    public function admin_search(array $filters = array(), $limit = 25, $offset = 0) {
        if (!empty($filters['search'])) {
            $this->db->group_start()
                     ->like('account_number', $filters['search'])
                     ->or_like('customer_email', $filters['search'])
                     ->group_end();
        }
        return $this->db->order_by('id', 'DESC')
                        ->limit(max(1, min(200, (int)$limit)), max(0, (int)$offset))
                        ->get($this->table)->result();
    }
}
