<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Announcement_model extends MY_Model {
    protected $table = 'announcements';

    /** Active announcements visible to an audience at this moment. */
    public function visible($audience = 'all') {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->where('is_active', 1)
            ->group_start()
                ->where('starts_at IS NULL', null, false)->or_where('starts_at <=', $now)
            ->group_end()
            ->group_start()
                ->where('ends_at IS NULL', null, false)->or_where('ends_at >=', $now)
            ->group_end()
            ->group_start()
                ->where('audience', 'all')->or_where('audience', $audience)
            ->group_end();
        return $this->db->order_by('severity', 'DESC')->order_by('created_at','DESC')->get($this->table)->result();
    }

    /* ---------------------------- admin editor --------------------------- */

    /** Every announcement, including expired and switched-off ones. */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->order_by('announcements.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['severity'])) $this->db->where('announcements.severity', strtoupper($f['severity']));
        if (!empty($f['audience'])) $this->db->where('announcements.audience', strtolower($f['audience']));
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
            $this->db->where('announcements.is_active', $f['status'] === 'active' ? 1 : 0);
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('announcements.title', $term)
                ->or_like('announcements.content', $term)
                ->group_end();
        }
    }
}
