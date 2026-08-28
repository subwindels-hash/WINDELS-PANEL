<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Ticket_message_model extends MY_Model {
    protected $table = 'ticket_messages';

    /**
     * Every message on a ticket, each carrying its own attachments.
     *
     * Attachments are fetched in ONE extra query for the whole thread rather
     * than one per message: a busy ticket is twenty messages, and a per-row
     * lookup in the view is how a support page starts taking a second to load.
     */
    public function for_ticket($ticket_id, $include_internal = false) {
        $this->db->where('ticket_id', $ticket_id);
        if (!$include_internal) $this->db->where('is_internal_note', 0);
        $messages = $this->db->order_by('created_at', 'ASC')->get($this->table)->result();
        if (!$messages) return $messages;

        $ids = array();
        foreach ($messages as $m) { $m->attachments = array(); $ids[(int)$m->id] = $m; }
        $rows = $this->db->where_in('ticket_message_id', array_keys($ids))
            ->order_by('id', 'ASC')->get('ticket_attachments')->result();
        foreach ($rows as $row) {
            $id = (int)$row->ticket_message_id;
            if (isset($ids[$id])) $ids[$id]->attachments[] = $row;
        }
        return $messages;
    }

    public function create(array $data) {
        $this->db->insert($this->table, $data);
        return $this->db->where('id', $this->db->insert_id())->get($this->table)->row();
    }
}
