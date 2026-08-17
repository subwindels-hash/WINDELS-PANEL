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
}
