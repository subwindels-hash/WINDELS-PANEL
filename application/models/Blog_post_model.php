<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Blog_post_model extends MY_Model {
    protected $table = 'blog_posts';

    public function published($category_slug = null, $limit = 20, $offset = 0) {
        $this->db->where('status', 'PUBLISHED')->where('published_at <=', gmdate('Y-m-d H:i:s'));
        if ($category_slug) {
            $cat = $this->db->where('slug', $category_slug)->get('blog_categories')->row();
            if ($cat) $this->db->where('category_id', $cat->id);
        }
        return $this->db->order_by('published_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function count_published($category_slug = null) {
        $this->db->where('status', 'PUBLISHED')->where('published_at <=', gmdate('Y-m-d H:i:s'));
        if ($category_slug) {
            $cat = $this->db->where('slug', $category_slug)->get('blog_categories')->row();
            if ($cat) $this->db->where('category_id', $cat->id);
        }
        return (int)$this->db->count_all_results($this->table);
    }

    public function find_published($slug) {
        return $this->db->where('slug', $slug)->where('status','PUBLISHED')
            ->where('published_at <=', gmdate('Y-m-d H:i:s'))->get($this->table)->row();
    }

    public function increment_views($id) {
        $this->db->set('views', 'views+1', false)->where('id', $id)->update($this->table);
    }
}
