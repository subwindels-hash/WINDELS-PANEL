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
}
