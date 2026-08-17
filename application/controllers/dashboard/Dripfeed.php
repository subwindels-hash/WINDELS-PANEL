<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Dripfeed — drip-feed schedules (Session 10).
 */
class Dripfeed extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Dripfeed_order_model', 'Dripfeed_run_model', 'Service_model'));
        $this->load->library(array('DripfeedService', 'DashboardStats', 'form_validation'));
    }

    public function index() {
        $page = max(1, (int)$this->input->get('page'));
        $limit = 15;
        $rows = $this->Dripfeed_order_model->for_user($this->current_user->id, $limit, ($page-1)*$limit);
        $total = $this->Dripfeed_order_model->count_for_user($this->current_user->id);

        $this->load->view('layouts/app', array(
            'title' => 'Drip-feed',
            'nav_active' => 'dashboard/drip-feed',
            'content_view' => 'dashboard/dripfeed/index',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'dripfeeds' => $rows,
            'services' => $this->Service_model->active(),
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function create() {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->dripfeedservice->create($this->current_user, $this->input->post());
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not create schedule');
            redirect('dashboard/drip-feed');
        }
        $this->session->set_flashdata('success', 'Drip-feed schedule created.');
        redirect('dashboard/drip-feed/'.$res['dripfeed']->public_id);
    }

    public function detail($public_id) {
        $drip = $this->Dripfeed_order_model->find_public_for_user($public_id, $this->current_user->id);
        if (!$drip) show_404();
        $service = $this->Service_model->find_by_id($drip->service_id);
        $runs = $this->Dripfeed_run_model->for_dripfeed($drip->id);
        $this->load->view('layouts/app', array(
            'title' => 'Drip-feed #'.$public_id,
            'nav_active' => 'dashboard/drip-feed',
            'content_view' => 'dashboard/dripfeed/detail',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'dripfeed' => $drip, 'service' => $service, 'runs' => $runs,
        ));
    }

    public function pause($public_id) { $this->action('pause', $public_id); }
    public function resume($public_id) { $this->action('resume', $public_id); }
    public function cancel($public_id) { $this->action('cancel', $public_id); }

    private function action($method, $public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->dripfeedservice->$method($public_id, $this->current_user);
        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            $res['error'] ?? ucfirst($method).'d.');
        redirect('dashboard/drip-feed/'.$public_id);
    }
}
