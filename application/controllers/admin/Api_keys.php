<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Admin reseller API-key policy, revocation, and usage evidence. */
class Api_keys extends Admin_Controller {
    private $per_page = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('api.manage');
        $this->load->model('Api_key_model');
        $this->load->library(array('ApiKeyPolicy', 'ApiKeyAdminService', 'DashboardStats'));
    }

    public function index() {
        $filters = $this->filters();
        $page = max(1, (int)$this->input->get('page', true));
        $total = $this->Api_key_model->count_admin($filters);
        $max_page = max(1, (int)ceil($total / $this->per_page));
        $page = min($page, $max_page);
        $rows = $this->Api_key_model->admin_list($filters, $this->per_page, ($page - 1) * $this->per_page);

        $this->render('admin/api_keys/index', array(
            'title'=>'Reseller API keys', 'rows'=>$rows, 'filters'=>$filters,
            'page'=>$page, 'per_page'=>$this->per_page, 'total'=>$total,
            'totals'=>$this->Api_key_model->admin_totals(),
        ));
    }

    public function show($public_id) {
        $key = $this->key_or_404($public_id);
        $this->render('admin/api_keys/detail', array(
            'title'=>'API key · '.(string)$key->name,
            'key'=>$key,
            'scope_catalogue'=>ApiKeyPolicy::scopes(),
            'usage'=>$this->Api_key_model->usage_for_key($key->id, 50),
            'usage_summary'=>$this->Api_key_model->usage_summary($key->id),
            'endpoint_usage'=>$this->Api_key_model->endpoint_usage($key->id, 20),
        ));
    }

    public function update($public_id) {
        $this->require_post();
        $key = $this->key_or_404($public_id);
        $result = $this->apikeyadminservice->update_policy($key, $this->input->post(null, true),
            $this->current_user->id, $this->input->ip_address(), (string)$this->input->user_agent());
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error']);
        } else {
            $this->session->set_flashdata('success', 'API key policy updated. The credential itself was not exposed or rotated.');
        }
        redirect('admin/api-keys/'.$public_id);
    }

    public function revoke($public_id) {
        $this->require_post();
        $key = $this->key_or_404($public_id);
        $result = $this->apikeyadminservice->revoke($key, $this->current_user->id,
            $this->input->ip_address(), (string)$this->input->user_agent());
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error']);
        } elseif (!empty($result['already_revoked'])) {
            $this->session->set_flashdata('success', 'API key was already revoked. Revocation remains permanent.');
        } else {
            $this->session->set_flashdata('success', 'API key revoked permanently. A replacement must be created by the customer.');
        }
        redirect('admin/api-keys/'.$public_id);
    }

    private function filters() {
        $status = strtoupper(trim((string)$this->input->get('status', true)));
        if (!in_array($status, array('', 'ACTIVE', 'EXPIRED', 'REVOKED'), true)) $status = '';
        return array(
            'status'=>$status,
            'search'=>substr(trim((string)$this->input->get('q', true)), 0, 100),
            'user'=>substr(trim((string)$this->input->get('user', true)), 0, 64),
        );
    }

    private function key_or_404($public_id) {
        $key = $this->Api_key_model->safe_admin_detail((string)$public_id);
        if (!$key) show_404();
        return $key;
    }

    private function render($view, array $data) {
        $this->load->view('layouts/app_theme', array_merge(array(
            'nav_active'=>'admin/api-keys',
            'content_view'=>$view,
            'current_user'=>$this->current_user,
            'permissions'=>$this->auth->permissions(),
            'unread'=>$this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }

    private function require_post() {
        if (strtoupper($this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
        }
    }
}
