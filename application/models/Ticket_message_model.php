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

    /**
     * Everything needed to decide whether the person asking for an attachment
     * may have it: which ticket it belongs to, who owns that ticket, and
     * whether it hangs off an internal staff note.
     *
     * Looked up by the stored `file_url` because that is the only link between
     * `media` and `ticket_attachments` — attachments record the URL the media
     * library handed them, not the media id. One query, because this runs on
     * every byte-serving request.
     */
    public function attachment_context($file_url) {
        return $this->db
            ->select('ta.file_name, ta.mime_type, ta.size, tm.is_internal_note,
                      t.id AS ticket_id, t.user_id AS ticket_user_id, tm.author_id', false)
            ->from('ticket_attachments ta')
            ->join('ticket_messages tm', 'tm.id = ta.ticket_message_id', 'inner')
            ->join('tickets t', 't.id = tm.ticket_id', 'inner')
            ->where('ta.file_url', (string)$file_url)
            ->limit(1)
            ->get()->row();
    }
}
