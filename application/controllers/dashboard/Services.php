<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Services — catalog browse + saved favorites (Session 07).
 */
class Services extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Service_model', 'Service_category_model'));
        $this->load->library(array('PricingService', 'DashboardStats'));
    }

    public function index() {
        $category = (int)$this->input->get('category');
        $services = $this->Service_model->active($category ?: null);
        $categories = $this->Service_category_model->active();

        // Resolve the user's price group / per-user rates in one pass.
        $fav_ids = $this->favorite_ids();

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
            'favorites'    => $fav_ids,
            'favorites_only' => false,
        ));
    }

    /** Favorites list — the `/dashboard/favorites` route target. */
    public function favorites() {
        $rows = $this->db->select('services.*, sc.name AS category_name, sc.slug AS category_slug')
            ->from('service_favorites sf')
            ->join('services', 'services.id = sf.service_id', 'inner')
            ->join('service_categories sc', 'sc.id = services.category_id', 'left')
            ->where('sf.user_id', $this->current_user->id)
            ->where('services.status', 'ACTIVE')
            ->order_by('sf.created_at', 'DESC')
            ->get()->result();

        $this->load->view('layouts/app', array(
            'title'        => 'Favorites',
            'nav_active'   => 'dashboard/favorites',
            'content_view' => 'dashboard/services/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'services'     => $rows,
            'categories'   => $this->Service_category_model->active(),
            'active_cat'   => 0,
            'favorites'    => $this->favorite_ids(),
            'favorites_only' => true,
        ));
    }

    private function favorite_ids() {
        $rows = $this->db->where('user_id', $this->current_user->id)
            ->get('service_favorites')->result();
        $ids = array();
        foreach ($rows as $r) $ids[(int)$r->service_id] = true;
        return $ids;
    }
}
