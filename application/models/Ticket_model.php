<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ticket_model extends MY_Model {
    protected $table = 'tickets';

    public function for_user($user_id, $status = null, $limit = 25, $offset = 0) {
        $this->db->where('user_id', $user_id);
        if ($status) $this->db->where('status', $status);
        return $this->db->order_by('updated_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function count_for_user($user_id, $status = null) {
        $this->db->where('user_id', $user_id);
        if ($status) $this->db->where('status', $status);
        return (int)$this->db->count_all_results($this->table);
    }

    public function find_public_for_user($public_id, $user_id) {
        return $this->db->where('public_id', $public_id)->where('user_id', $user_id)->get($this->table)->row();
    }

    public function find_by_id($id) {
        return $this->db->where('id', $id)->get($this->table)->row();
    }

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return $this->find_by_id($this->db->insert_id());
    }

    public function touch($id, array $extra = array()) {
        $data = array_merge(array('last_reply_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')), $extra);
        return $this->db->where('id', $id)->update($this->table, $data);
    }

    public function close($id) {
        return $this->db->where('id', $id)->update($this->table, array(
            'status' => 'CLOSED', 'closed_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ));
    }
}
