<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Refill_model extends MY_Model {
    protected $table = 'refills';

    public function for_user($user_id, $limit = 25, $offset = 0) {
        return $this->db->select('refills.*, orders.public_id AS order_public_id, services.name AS service_name')
            ->from('refills')
            ->join('orders', 'orders.id = refills.order_id', 'inner')
            ->join('services', 'services.id = orders.service_id', 'left')
            ->where('orders.user_id', $user_id)
            ->order_by('refills.requested_at', 'DESC')
            ->limit($limit, $offset)->get()->result();
    }

    public function count_for_user($user_id) {
        return (int)$this->db->from('refills')
            ->join('orders', 'orders.id = refills.order_id', 'inner')
            ->where('orders.user_id', $user_id)->count_all_results();
    }

    public function active_for_order($order_id) {
        return $this->db->where('order_id', $order_id)
            ->where_in('status', array('PENDING','PROCESSING','IN_PROGRESS'))
            ->get()->row();
    }

    public function find_by_id($id) {
        return $this->db->where('id', $id)->get($this->table)->row();
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->select('refills.*')
            ->from('refills')
            ->join('orders', 'orders.id = refills.order_id', 'inner')
            ->where('refills.public_id', $public_id)
            ->where('orders.user_id', $user_id)->get()->row();
    }
}
