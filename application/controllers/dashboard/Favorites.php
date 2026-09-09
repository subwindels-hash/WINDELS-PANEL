<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Favorites — toggle a user's saved services (Session 07).
 *
 * The route `/dashboard/favorites` is handled by Dashboard/Services::favorites
 * (which lists them); the toggle endpoints live here so they are CSRF-protected
 * POST actions and never reachable via GET.
 */
class Favorites extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model('Service_model');
        $this->load->library('DashboardStats');
    }

    /** POST /dashboard/favorites/add/:public_id */
    public function add($public_id = null) {
        $service = $this->Service_model->find_by_public_id($public_id);
        if (!$service) show_404();

        // INSERT IGNORE-equivalent: only insert when not already favorited.
        $exists = (bool)$this->db->where(array(
            'user_id' => $this->current_user->id,
            'service_id' => $service->id,
        ))->count_all_results('service_favorites');

        if (!$exists) {
            $this->db->insert('service_favorites', array(
                'user_id'    => $this->current_user->id,
                'service_id' => $service->id,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
        }

        if ($this->input->is_ajax_request()) {
            $this->json_success(array('favorited' => true));
        }
        $this->session->set_flashdata('success', 'Service added to favorites.');
        redirect($this->agent_referrer('dashboard/services'));
    }

    /** POST /dashboard/favorites/remove/:public_id */
    public function remove($public_id = null) {
        $service = $this->Service_model->find_by_public_id($public_id);
        if (!$service) show_404();

        $this->db->where(array(
            'user_id' => $this->current_user->id,
            'service_id' => $service->id,
        ))->delete('service_favorites');

        if ($this->input->is_ajax_request()) {
            $this->json_success(array('favorited' => false));
        }
        $this->session->set_flashdata('success', 'Service removed from favorites.');
        redirect($this->agent_referrer('dashboard/favorites'));
    }

    private function agent_referrer($fallback) {
        $ref = $this->input->server('HTTP_REFERER');
        if (!$ref) return $fallback;
        // Only honour local referrers (no open redirect).
        $host = $this->input->server('HTTP_HOST');
        if ($host && parse_url($ref, PHP_URL_HOST) === $host) {
            return $ref;
        }
        return $fallback;
    }
}
