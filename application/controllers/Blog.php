<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Blog — public, SEO-friendly article listing and detail (Session 13).
 */
class Blog extends Public_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Blog_post_model','Blog_category_model'));
    }

    public function index() {
        $category = $this->input->get('category', true);
        $page = max(1, (int)$this->input->get('page'));
        $limit = 9;
        $posts = $this->Blog_post_model->published($category ?: null, $limit, ($page-1)*$limit);
        $total = $this->Blog_post_model->count_published($category ?: null);
        $this->load->view('layouts/main', array(
            'content_view' => 'public/blog/list',
            'data' => array(
                'title' => 'Blog',
                'posts' => $posts,
                'categories' => $this->Blog_category_model->all_with_counts(),
                'active_category' => $category,
                'page' => $page,
                'total_pages' => max(1, (int)ceil($total / $limit)),
                'meta_description' => 'Guides, product updates and reseller tips from MarvySocials.',
            ),
        ));
    }

    public function post($slug) {
        $post = $this->Blog_post_model->find_published($slug);
        if (!$post) show_404();
        $this->Blog_post_model->increment_views($post->id);
        $related = $this->Blog_post_model->published(null, 3);
        $this->load->view('layouts/main', array(
            'content_view' => 'public/blog/detail',
            'data' => array(
                'title' => $post->meta_title ?: $post->title,
                'post' => $post,
                'related' => array_values(array_filter($related, function($p) use ($post) { return $p->id !== $post->id; })),
                'meta_description' => $post->meta_description ?: $post->excerpt,
                'og_type' => 'article',
            ),
        ));
    }
}
