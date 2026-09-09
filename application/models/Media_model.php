<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Media_model extends MY_Model {
    protected $table = 'media';

    /** One page of the media library, newest first. */
    public function admin_search(array $filters, $limit = 40, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('media.*, users.username AS uploader_name', false)
            ->join('users', 'users.id = media.uploader_id', 'left')
            ->order_by('media.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['purpose'])) $this->db->where('media.purpose', $f['purpose']);
        if (!empty($f['search'])) {
            $this->db->like('media.file_name', trim((string)$f['search']));
        }
    }

    /** Images only, for the pickers that expect one (branding, featured). */
    public function images($limit = 40){
        return $this->db->where('mime_type !=', 'application/pdf')
            ->order_by('created_at', 'DESC')->limit($limit)->get($this->table)->result();
    }
}
