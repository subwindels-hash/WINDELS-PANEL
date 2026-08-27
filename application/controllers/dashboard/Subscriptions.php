<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Subscriptions — recurring auto-order plans (Session 10).
 */
class Subscriptions extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Subscription_model','Service_model'));
        $this->load->library(array('SubscriptionService','DashboardStats','form_validation'));
    }

    private function guard_enabled() {
        if (!marvy_feature_enabled('subscriptions', true)) show_404();
    }

    public function index() {
        $this->guard_enabled();
        $rows = $this->Subscription_model->for_user($this->current_user->id, 25);
        $this->load->view('layouts/app', array(
            'title' => 'Subscriptions',
            'nav_active' => 'dashboard/subscriptions',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/subscriptions/index',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'subscriptions' => $rows,
            'services' => $this->Service_model->active_for_picker(),
        ));
    }

    public function create() {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->subscriptionservice->create($this->current_user, $this->input->post());
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not create subscription');
            redirect('dashboard/subscriptions');
        }
        $this->session->set_flashdata('success', 'Subscription created.');
        redirect('dashboard/subscriptions');
    }

    public function pause($id)  { $this->action('pause', $id); }
    public function resume($id) { $this->action('resume', $id); }
    public function cancel($id) { $this->action('cancel', $id); }

    private function action($method, $id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->subscriptionservice->$method($id, $this->current_user);
        $this->session->set_flashdata(empty($res['ok'])?'error':'success',
            $res['error'] ?? ucfirst($method).'d.');
        redirect('dashboard/subscriptions');
    }
}
