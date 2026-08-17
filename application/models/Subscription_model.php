<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Subscription_model extends MY_Model {
    protected $table = 'subscriptions';

    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db->where('user_id', $user_id)
            ->order_by('created_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function count_for_user($user_id) {
        return (int)$this->db->where('user_id', $user_id)->count_all_results($this->table);
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->where('public_id', $public_id)->where('user_id', $user_id)->get($this->table)->row();
    }

    public function find_by_id($id) {
        return $this->db->where('id', $id)->get($this->table)->row();
    }

    public function due($limit = 100) {
        return $this->db->where('status', 'ACTIVE')
            ->where('next_execution_at <=', gmdate('Y-m-d H:i:s'))
            ->order_by('next_execution_at', 'ASC')->limit($limit)->get($this->table)->result();
    }
}
