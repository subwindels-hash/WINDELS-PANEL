<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ticket_message_model extends MY_Model {
    protected $table = 'ticket_messages';

    public function for_ticket($ticket_id, $include_internal = false) {
        $this->db->where('ticket_id', $ticket_id);
        if (!$include_internal) $this->db->where('is_internal_note', 0);
        return $this->db->order_by('created_at', 'ASC')->get($this->table)->result();
    }

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return $this->db->where('id', $this->db->insert_id())->get($this->table)->row();
    }
}
