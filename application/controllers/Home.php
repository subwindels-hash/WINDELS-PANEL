<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends Public_Controller {
    public function index(){
        $preview = $this->input->get('preview');
        $allowed = array('AURORA','NEXUS','PULSE');
        // Preview override — admin only, not persisted
        if ($preview && in_array(strtoupper($preview), $allowed, TRUE)) {
            $is_admin = $this->session->userdata('role') && in_array($this->session->userdata('role'), array('SUPER_ADMIN','ADMIN','STAFF'), TRUE);
            if ($is_admin) { $active = strtoupper($preview); } else { $active = $this->active_homepage(); }
        } else {
            $active = $this->active_homepage();
        }
        $data = array('active_homepage'=>$active, 'title'=>'WINDELS PANEL — SMM Reseller Platform');
        // Single switch — no Node
        $view = 'homepages/'.strtolower($active).'/index';
        // Fallback if template missing. CI_Loader has no view-exists helper, so
        // check the filesystem directly (AURORA is the guaranteed default).
        if (!is_file(VIEWPATH.$view.'.php')) $view = 'homepages/aurora/index';
        $this->load->view('layouts/public', array('content_view'=>$view,'data'=>$data));
    }
    private function active_homepage(){
        try {
            $this->load->model('Setting_model');
            $v = $this->Setting_model->get('active_homepage');
            if ($v) return $v;
        } catch(Exception $e){}
        $cfg = $this->config->item('windels');
        return $cfg['active_homepage'] ?? 'AURORA';
    }
    public function pricing(){ $this->load->view('layouts/public', array('content_view'=>'public/pricing','data'=>array('title'=>'Pricing'))); }
    public function about(){ $this->load->view('layouts/public', array('content_view'=>'public/about','data'=>array('title'=>'About'))); }
    public function faq(){
        $this->load->model('Faq_model');
        $this->load->view('layouts/public', array('content_view'=>'public/faq','data'=>array(
            'title'=>'FAQ',
            'faqs'=>$this->Faq_model->active(),
            'categories'=>$this->Faq_model->categories(),
        )));
    }
    public function blog(){ $this->load->view('layouts/public', array('content_view'=>'public/blog_list','data'=>array('title'=>'Blog'))); }
    public function blog_detail($slug){ $this->load->view('layouts/public', array('content_view'=>'public/blog_detail','data'=>array('title'=>$slug))); }
    public function contact(){ $this->load->view('layouts/public', array('content_view'=>'public/contact','data'=>array('title'=>'Contact'))); }
    public function terms(){ $this->load->view('layouts/public', array('content_view'=>'public/terms','data'=>array('title'=>'Terms'))); }
    public function privacy(){ $this->load->view('layouts/public', array('content_view'=>'public/privacy','data'=>array('title'=>'Privacy'))); }
    public function refund_policy(){ $this->load->view('layouts/public', array('content_view'=>'public/refund_policy','data'=>array('title'=>'Refund Policy'))); }
    public function acceptable_use(){ $this->load->view('layouts/public', array('content_view'=>'public/acceptable_use','data'=>array('title'=>'Acceptable Use'))); }

    /**
     * Living design-system guide (Session 04). Public so designers/reviewers can
     * see the token/component inventory; renders inside the public shell.
     */
    public function styleguide(){
        $this->load->view('layouts/public', array(
            'content_view' => 'public/styleguide',
            'data' => array(
                'title' => 'Design System',
                'meta_description' => 'WINDELS PANEL design tokens and component inventory.',
                'active_homepage' => $this->active_homepage(),
            ),
        ));
    }
    public function sitemap(){ $this->output->set_content_type('text/xml')->set_output('<?xml version="1.0"?><urlset></urlset>'); }
    public function robots(){ $this->output->set_content_type('text/plain')->set_output("User-agent: *\nAllow: /\nSitemap: ".base_url('sitemap.xml')); }
}
