<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Administer the customer-facing SMM service catalogue and its price overrides. */
class Services extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('services.view');
        $this->load->library(array('SmmServiceAdminService', 'DashboardStats'));
        $this->load->model(array('Service_model', 'Audit_log_model'));
    }

    /** GET /admin/services */
    public function index() {
        $filters = array(
            'search' => substr(trim((string)$this->input->get('q', true)), 0, 100),
            'status' => substr(strtoupper(trim((string)$this->input->get('status', true))), 0, 16),
            // Public IDs keep database identities out of browser query strings.
            'category_public_id' => substr(trim((string)$this->input->get('category', true)), 0, 26),
            'provider_public_id' => substr(trim((string)$this->input->get('provider', true)), 0, 26),
            'service_type' => substr(strtoupper(trim((string)$this->input->get('type', true))), 0, 32),
        );
        $page = max(1, (int)$this->input->get('page'));
        $grid = $this->smmserviceadminservice->grid(
            $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $this->render('SMM services', 'admin/services/index', array(
            'services' => $grid['rows'],
            'total' => (int)$grid['total'],
            'page' => $page,
            'total_pages' => max(1, (int)ceil($grid['total'] / self::PER_PAGE)),
            'filters' => $filters,
            'options' => $this->smmserviceadminservice->options(),
        ));
    }

    /** GET shows a reviewed draft; POST is the only path that inserts it. */
    public function create() {
        $this->require_perm('services.manage');
        if ($this->input->method(true) === 'POST') {
            $res = $this->smmserviceadminservice->save(null, $this->input->post(null, true));
            if (empty($res['ok'])) return $this->fail('admin/services/create', $res['error']);

            $this->audit('service.created', $res['service'], null, get_object_vars($res['service']));
            $this->flash_result($res, 'Service created.');
            return redirect('admin/services/'.$res['service']->public_id);
        }

        $draft = $this->blank_draft();
        $provider_public_id = trim((string)$this->input->get('provider', true));
        $provider_service_id = trim((string)$this->input->get('provider_service', true));
        if ($provider_public_id !== '' || $provider_service_id !== '') {
            $prefill = $this->smmserviceadminservice->draft_from_provider($provider_public_id, $provider_service_id);
            if (!empty($prefill['ok'])) {
                $draft = $prefill['draft'];
            } else {
                $this->session->set_flashdata('warning', $prefill['error']);
            }
        }

        $this->render('Create SMM service', 'admin/services/form', array(
            'service' => $draft,
            'options' => $this->smmserviceadminservice->options($draft),
            'form_action' => 'admin/services/create',
            'is_create' => true,
        ));
    }

    /** GET /admin/services/:public-id */
    public function edit($public_id) {
        $service = $this->find_or_404($public_id);
        $this->render('Edit service', 'admin/services/form', array(
            'service' => $service,
            'options' => $this->smmserviceadminservice->options($service),
            'form_action' => 'admin/services/'.$service->public_id.'/update',
            'is_create' => false,
            'group_rates' => $this->smmserviceadminservice->group_rates($service->id),
            'user_rates' => $this->smmserviceadminservice->user_rates($service->id),
        ));
    }

    /** POST /admin/services/:public-id/update */
    public function update($public_id) {
        $this->guard('services.manage');
        $service = $this->find_or_404($public_id);
        $res = $this->smmserviceadminservice->save($service, $this->input->post(null, true));
        if (empty($res['ok'])) return $this->fail('admin/services/'.$public_id, $res['error']);

        $this->audit('service.updated', $res['service'], $res['before'], get_object_vars($res['service']));
        $this->flash_result($res, 'Service updated.');
        redirect('admin/services/'.$public_id);
    }

    /** POST /admin/services/:public-id/archive */
    public function archive($public_id) {
        $this->guard('services.manage');
        $service = $this->find_or_404($public_id);
        $res = $this->smmserviceadminservice->archive($service);
        if (empty($res['ok'])) return $this->fail('admin/services/'.$public_id, $res['error']);

        $this->audit('service.archived', $res['service'], $res['before'], get_object_vars($res['service']));
        $this->flash_result($res, 'Service archived. It remains attached to historical orders.');
        redirect('admin/services/'.$public_id);
    }


    /** POST /admin/services/:public-id/delete */
    public function delete($public_id) {
        $this->guard("services.manage");
        $service = $this->find_or_404($public_id);
        $res = $this->smmserviceadminservice->delete($service);
        if (empty($res['ok'])) return $this->fail("admin/services/" . $public_id, $res["error"]);

        $this->audit("service.deleted", $res["service"], $res["before"], null);
        $this->flash_result($res, "Service deleted.");
        redirect("admin/services");
    }

    /** POST /admin/services/:public-id/pricing/group/:group-id */
    public function group_rate($public_id, $group_id) {
        $this->guard('pricing.manage');
        $service = $this->find_or_404($public_id);
        $res = $this->smmserviceadminservice->set_group_rate(
            $service, (int)$group_id, $this->input->post('rate', true));
        if (empty($res['ok'])) return $this->fail('admin/services/'.$public_id.'#pricing', $res['error']);

        $this->audit('service.group_rate_updated', $service, $res['before'], $res['after']);
        $this->flash_result($res, $res['after'] ? 'Group rate saved.' : 'Group override removed.');
        redirect('admin/services/'.$public_id.'#pricing');
    }

    /** POST /admin/services/:public-id/pricing/user */
    public function user_rate($public_id) {
        $this->guard('pricing.manage');
        $service = $this->find_or_404($public_id);
        $res = $this->smmserviceadminservice->set_user_rate(
            $service, $this->input->post('user_public_id', true),
            $this->input->post('rate', true));
        if (empty($res['ok'])) return $this->fail('admin/services/'.$public_id.'#pricing', $res['error']);

        $this->audit('service.user_rate_updated', $service, $res['before'], $res['after']);
        $this->flash_result($res, $res['after'] ? 'Customer rate saved.' : 'Customer override removed.');
        redirect('admin/services/'.$public_id.'#pricing');
    }

    private function blank_draft() {
        return (object)array(
            'id'=>null, 'public_id'=>null, 'name'=>'', 'slug'=>'', 'category_id'=>null,
            'description'=>null, 'service_type'=>'DEFAULT', 'rate'=>'',
            'min_quantity'=>100, 'max_quantity'=>10000, 'increment_step'=>1,
            'average_time'=>null, 'average_time_minutes'=>null,
            'provider_id'=>null, 'provider_public_id'=>null, 'provider_service_id'=>null,
            'provider_rate'=>null, 'provider_source_snapshot'=>null,
            'status'=>'INACTIVE', 'cancel_supported'=>0, 'refill_supported'=>0,
            'refill_days'=>null, 'dripfeed_supported'=>0, 'subscription_supported'=>0,
            'package_supported'=>0, 'custom_comments_supported'=>0, 'sorting'=>0,
            'featured'=>0, 'trending'=>0, 'auto_price_sync'=>0, 'metadata'=>null,
        );
    }

    private function find_or_404($public_id) {
        $service = $this->smmserviceadminservice->find($public_id);
        if (!$service) show_404();
        return $service;
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title' => $title,
            'nav_active' => 'admin/services',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }

    private function guard($permission) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($permission);
    }

    private function fail($url, $message) {
        $this->session->set_flashdata('error', $message);
        redirect($url);
    }

    private function flash_result(array $res, $success) {
        $this->session->set_flashdata('success', $success);
        if (!empty($res['warnings'])) {
            $this->session->set_flashdata('warning', implode(' ', $res['warnings']));
        }
    }

    private function audit($action, $service, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'services', (string)$service->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
