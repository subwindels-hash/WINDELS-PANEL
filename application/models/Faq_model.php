<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Faq_model extends MY_Model {
    protected $table = 'faqs';

    public function active($category = null) {
        $this->db->where('is_active', 1);
        if ($category) $this->db->where('category', $category);
        return $this->db->order_by('sorting', 'ASC')->order_by('id','ASC')->get($this->table)->result();
    }

    public function categories() {
        return $this->db->distinct()->select('category')
            ->where('is_active', 1)->where('category IS NOT NULL', null, false)
            ->order_by('category','ASC')->get($this->table)->result();
    }

    /* ---------------------------- admin editor --------------------------- */

    /** Every entry, active or not, in the order customers would see them. */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->order_by('faqs.sorting', 'ASC')
            ->order_by('faqs.id', 'ASC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['category'])) $this->db->where('faqs.category', $f['category']);
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== null) {
            $this->db->where('faqs.is_active', $f['status'] === 'active' ? 1 : 0);
        }
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('faqs.question', $term)
                ->or_like('faqs.answer', $term)
                ->group_end();
        }
    }

    /** Every category in use, including those whose entries are switched off. */
    public function all_categories(){
        $out = array();
        foreach ($this->db->get($this->table)->result() as $row) {
            if (!empty($row->category)) $out[$row->category] = $row->category;
        }
        ksort($out);
        return array_values($out);
    }
}
