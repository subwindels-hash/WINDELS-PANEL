<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Shipping_address_model extends MY_Model {
    protected $table = 'shipping_addresses';

    public function for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)
            ->order_by('is_default', 'DESC')->order_by('id', 'DESC')
            ->get($this->table)->result();
    }

    public function default_for_user($user_id) {
        return $this->db->where('user_id', (int)$user_id)->where('is_default', 1)
            ->get($this->table)->row();
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->where('public_id', $public_id)->where('user_id', (int)$user_id)
            ->get($this->table)->row();
    }

    public function create(array $data) {
        $data['public_id'] = $this->new_public_id();
        $data['created_at'] = $this->now_utc();
        $data['updated_at'] = $this->now_utc();
        if (!empty($data['is_default'])) {
            $this->db->where('user_id', (int)$data['user_id'])->update($this->table, array('is_default' => 0));
        }
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }
}
