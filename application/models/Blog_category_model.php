<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Blog_category_model extends MY_Model {
    protected $table = 'blog_categories';

    public function all_with_counts() {
        return $this->db->select('blog_categories.*, COUNT(blog_posts.id) AS post_count')
            ->from('blog_categories')
            ->join('blog_posts', "blog_posts.category_id = blog_categories.id AND blog_posts.status='PUBLISHED'", 'left')
            ->group_by('blog_categories.id')
            ->order_by('blog_categories.name','ASC')
            ->get()->result();
    }

    public function find_by_slug($slug) {
        return $this->db->where('slug', $slug)->get($this->table)->row();
    }
}
