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

    /* -------------------------- staff queue -------------------------- */

    /**
     * Staff ticket queue. Unscoped by design — only reachable behind
     * `tickets.view`.
     *
     * @param array $f status|priority|department|assigned_to_id|unassigned|search
     */
    public function admin_search(array $f, $limit = 25, $offset = 0){
        $this->admin_filters($f);
        return $this->db
            ->select('tickets.*, users.username, users.email,
                      assignee.username AS assignee_username', false)
            ->join('users', 'users.id = tickets.user_id', 'left')
            ->join('users AS assignee', 'assignee.id = tickets.assigned_to_id', 'left')
            ->order_by("FIELD(tickets.status,'OPEN','PENDING','ANSWERED','CLOSED')", '', false)
            ->order_by("FIELD(tickets.priority,'URGENT','HIGH','MEDIUM','LOW')", '', false)
            ->order_by('tickets.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $f){
        $this->admin_filters($f);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status']))     $this->db->where('tickets.status', $f['status']);
        if (!empty($f['priority']))   $this->db->where('tickets.priority', $f['priority']);
        if (!empty($f['department'])) $this->db->where('tickets.department', $f['department']);
        if (!empty($f['assigned_to_id'])) $this->db->where('tickets.assigned_to_id', (int)$f['assigned_to_id']);
        if (!empty($f['unassigned']))     $this->db->where('tickets.assigned_to_id IS NULL', null, false);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('tickets.subject', $term)
                ->or_like('tickets.public_id', $term)
                ->or_like('users.email', $term)
                ->group_end();
        }
    }

    /** One ticket with its requester, for the staff view (no user scoping). */
    public function admin_find($public_id){
        return $this->db
            ->select('tickets.*, users.username, users.email,
                      assignee.username AS assignee_username', false)
            ->from($this->table)
            ->join('users', 'users.id = tickets.user_id', 'left')
            ->join('users AS assignee', 'assignee.id = tickets.assigned_to_id', 'left')
            ->where('tickets.public_id', $public_id)
            ->get()->row();
    }

    /** Counts per status for the queue header. */
    public function status_counts(){
        $rows = $this->db->select('status, COUNT(*) AS c', false)
            ->group_by('status')->get($this->table)->result();
        $out = array();
        foreach ($rows as $r) $out[$r->status] = (int)$r->c;
        return $out;
    }

    public function assign($id, $staff_id){
        return $this->db->where('id', $id)->update($this->table, array(
            'assigned_to_id' => $staff_id ?: null,
            'updated_at'     => $this->now_utc(),
        ));
    }

    public function set_status($id, $status){
        $data = array('status' => $status, 'updated_at' => $this->now_utc());
        $data['closed_at'] = $status === 'CLOSED' ? $this->now_utc() : null;
        return $this->db->where('id', $id)->update($this->table, $data);
    }

    public function set_priority($id, $priority){
        return $this->db->where('id', $id)->update($this->table, array(
            'priority'   => $priority,
            'updated_at' => $this->now_utc(),
        ));
    }
}
