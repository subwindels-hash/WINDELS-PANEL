<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Services — browseable service catalog (placeholder).
 * Full catalog with pricing tiers/favorites ships in Session 07.
 */
class Services extends Auth_Controller {
    public function __construct() {
        parent::__construct();
        $this->load->model(array('Service_model', 'Service_category_model'));
        $this->load->library('DashboardStats');
    }

    public function index() {
        $category = (int)$this->input->get('category');
        $services = $this->Service_model->active($category ?: null);
        $categories = $this->Service_category_model->active();

        $this->load->view('layouts/app', array(
            'title'        => 'Services',
            'nav_active'   => 'dashboard/services',
            'content_view' => 'dashboard/services/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'services'     => $services,
            'categories'   => $categories,
            'active_cat'   => $category,
        ));
    }

    /** Favorites — full implementation in Session 07. */
    public function favorites() { redirect('dashboard/services'); }
}
