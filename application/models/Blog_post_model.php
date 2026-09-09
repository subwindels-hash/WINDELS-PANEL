<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Blog_post_model extends MY_Model {
    protected $table = 'blog_posts';

    public function published($category_slug = null, $limit = 20, $offset = 0) {
        // Resolve the category BEFORE the post conditions are staged: the query
        // builder keeps pending where() clauses until the next get(), so looking
        // the slug up mid-build ran `status = 'PUBLISHED'` against
        // blog_categories and blew up with "Unknown column 'status'".
        $category_id = $this->category_id_for_slug($category_slug);
        if ($category_slug !== null && $category_slug !== '' && $category_id === null) {
            return array();
        }

        $this->db->where('status', 'PUBLISHED')->where('published_at <=', gmdate('Y-m-d H:i:s'));
        if ($category_id !== null) $this->db->where('category_id', $category_id);
        return $this->db->order_by('published_at', 'DESC')->limit($limit, $offset)->get($this->table)->result();
    }

    public function count_published($category_slug = null) {
        $category_id = $this->category_id_for_slug($category_slug);
        if ($category_slug !== null && $category_slug !== '' && $category_id === null) {
            return 0;
        }

        $this->db->where('status', 'PUBLISHED')->where('published_at <=', gmdate('Y-m-d H:i:s'));
        if ($category_id !== null) $this->db->where('category_id', $category_id);
        return (int)$this->db->count_all_results($this->table);
    }

    /** Category id for a slug, or null when the slug is empty/unknown. */
    private function category_id_for_slug($slug) {
        if ($slug === null || $slug === '') return null;
        $row = $this->db->where('slug', $slug)->get('blog_categories')->row();
        return $row ? (int)$row->id : null;
    }

    public function find_published($slug) {
        return $this->db->where('slug', $slug)->where('status','PUBLISHED')
            ->where('published_at <=', gmdate('Y-m-d H:i:s'))->get($this->table)->row();
    }

    public function increment_views($id) {
        $this->db->set('views', 'views+1', false)->where('id', $id)->update($this->table);
    }

    /* ---------------------------- admin editor --------------------------- */

    /**
     * One page of the admin post list — every status, not just published.
     *
     * The body is deliberately not selected: a listing of fifty posts would
     * otherwise pull fifty MEDIUMTEXT blobs to render fifty titles.
     */
    public function admin_search(array $filters, $limit = 25, $offset = 0){
        $this->admin_filters($filters);
        return $this->db
            ->select('blog_posts.id, blog_posts.public_id, blog_posts.title, blog_posts.slug,
                      blog_posts.excerpt, blog_posts.status, blog_posts.views,
                      blog_posts.published_at, blog_posts.created_at, blog_posts.category_id,
                      blog_categories.name AS category_name,
                      users.username AS author_name', false)
            ->join('blog_categories', 'blog_categories.id = blog_posts.category_id', 'left')
            ->join('users', 'users.id = blog_posts.author_id', 'left')
            ->order_by('blog_posts.created_at', 'DESC')
            ->limit($limit, $offset)
            ->get()->result();
    }

    public function admin_count(array $filters){
        $this->admin_filters($filters);
        return (int)$this->db->count_all_results();
    }

    private function admin_filters(array $f){
        $this->db->from($this->table);
        if (!empty($f['status']))      $this->db->where('blog_posts.status', strtoupper($f['status']));
        if (!empty($f['category_id'])) $this->db->where('blog_posts.category_id', (int)$f['category_id']);
        if (!empty($f['search'])) {
            $term = trim((string)$f['search']);
            $this->db->group_start()
                ->like('blog_posts.title', $term)
                ->or_like('blog_posts.slug', $term)
                ->group_end();
        }
    }

    public function status_counts(){
        $out = array();
        foreach (array('DRAFT','PUBLISHED','ARCHIVED') as $s) {
            $this->admin_filters(array('status' => $s));
            $out[$s] = (int)$this->db->count_all_results();
        }
        return $out;
    }

    /** Is this slug already used by a different post? */
    public function slug_taken($slug, $except_id = null){
        $this->db->where('slug', $slug);
        if ($except_id !== null) $this->db->where('id !=', (int)$except_id);
        return (bool)$this->db->from($this->table)->count_all_results();
    }
}
