<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Account — profile, security settings and API keys.
 */
class Account extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('form_validation', 'AuthService', 'ApiKeyPolicy'));
        $this->load->model(array('Api_key_model', 'Audit_log_model'));
        $this->load->library('DashboardStats');
    }

    /* ----------------------------- profile ----------------------------- */

    public function profile() {
        if ($this->input->method(true) === 'POST') {
            return $this->profile_update();
        }
        $this->render('Profile', 'dashboard/account/profile', 'dashboard/profile', array());
    }

    private function profile_update() {
        $this->form_validation->set_rules('first_name', 'First name', 'trim|max_length[100]');
        $this->form_validation->set_rules('last_name', 'Last name', 'trim|max_length[100]');
        $this->form_validation->set_rules('phone', 'Phone', 'trim|max_length[32]');
        $this->form_validation->set_rules('timezone', 'Timezone', 'trim|max_length[64]');
        $this->form_validation->set_rules('locale', 'Locale', 'trim|max_length[8]');

        if (!$this->form_validation->run()) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('dashboard/profile');
        }

        $data = array(
            'first_name' => $this->input->post('first_name', true),
            'last_name'  => $this->input->post('last_name', true),
            'phone'      => $this->input->post('phone', true),
            'timezone'   => $this->input->post('timezone', true) ?: 'UTC',
            'locale'     => $this->input->post('locale', true) ?: 'en',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        $this->db->where('id', $this->current_user->id)->update('users', $data);
        $this->Audit_log_model->record($this->current_user->id, 'profile.update', 'users',
            $this->current_user->public_id, null, $data, $this->input->ip_address(), $this->input->user_agent(), $this->request_id);
        $this->session->set_flashdata('success', 'Profile updated.');
        redirect('dashboard/profile');
    }

    /* ----------------------------- security ---------------------------- */

    public function security() {
        if ($this->input->method(true) === 'POST') {
            return $this->security_update();
        }
        // Safe projection keeps the stored credential verifier out of views.
        $keys = $this->Api_key_model->for_user_safe($this->current_user->id);

        $this->render('Security', 'dashboard/account/security', 'dashboard/security', array(
            'keys'  => $keys,
            'mfa'   => $this->current_user->mfa_enabled,
        ));
    }

    private function security_update() {
        $action = $this->input->post('action', true);
        if ($action === 'change_password') {
            $this->form_validation->set_rules('current_password', 'Current password', 'required');
            $this->form_validation->set_rules('new_password', 'New password', 'required|min_length[8]');
            $this->form_validation->set_rules('confirm_password', 'Confirm password', 'required|matches[new_password]');
            if (!$this->form_validation->run()) {
                $this->session->set_flashdata('error', validation_errors());
                return redirect('dashboard/security');
            }
            $res = $this->auth->change_password(
                $this->current_user,
                $this->input->post('current_password'),
                $this->input->post('new_password')
            );
            if (!$res['ok']) {
                $this->session->set_flashdata('error', 'Your current password was incorrect.');
                return redirect('dashboard/security');
            }
            $this->session->set_flashdata('success', 'Password changed.');
            return redirect('dashboard/security');
        }
        show_404();
    }

    /* ----------------------------- API keys ---------------------------- */

    public function api_keys() {
        $new_key = null;
        if ($this->input->method(true) === 'POST') {
            $new_key = $this->create_api_key();
        }
        $keys = $this->Api_key_model->for_user_safe($this->current_user->id);

        $this->render('API Keys', 'dashboard/account/api_keys', 'dashboard/api', array(
            'keys'    => $keys,
            'new_key' => $new_key,
        ));
    }

    private function create_api_key() {
        $this->form_validation->set_rules('name', 'Key name', 'trim|required|max_length[64]');
        if (!$this->form_validation->run()) {
            $this->session->set_flashdata('error', validation_errors());
            return null;
        }
        $parsed = $this->apikeypolicy->parse_ip_whitelist($this->input->post('ip_whitelist'));
        if (empty($parsed['ok'])) {
            $this->session->set_flashdata('error', $parsed['error']);
            return null;
        }
        $opts = $parsed['value'] ? array('ip_whitelist'=>$parsed['value']) : array();
        $result = $this->auth->create_api_key(
            $this->current_user->id,
            $this->input->post('name', true),
            $opts
        );
        $this->session->set_flashdata('success', 'API key created. Copy it now — it will not be shown again.');
        return $result;
    }

    public function revoke_api_key($public_id = null) {
        if (strtoupper($this->input->method()) !== 'POST') show_error('Method Not Allowed', 405);
        if (!$public_id) show_404();
        $key = $this->Api_key_model->safe_for_user($public_id, $this->current_user->id);
        if (!$key) show_404();
        if (!empty($key->revoked_at)) {
            $this->session->set_flashdata('success', 'API key was already revoked.');
            return redirect('dashboard/api');
        }
        $revoked_at = gmdate('Y-m-d H:i:s');
        $this->db->where('id', $key->id)->where('revoked_at IS NULL', null, false)
            ->update('api_keys', array('revoked_at'=>$revoked_at));
        if ($this->db->affected_rows() > 0) {
            $this->Audit_log_model->record($this->current_user->id, 'api_key.revoked', 'api_keys',
                $key->public_id, array('revoked_at'=>null), array('revoked_at'=>$revoked_at),
                $this->input->ip_address(), $this->input->user_agent(), $this->request_id);
            $this->session->set_flashdata('success', 'API key revoked permanently.');
        } else {
            $this->session->set_flashdata('error', 'API key changed before it could be revoked. Reload and try again.');
        }
        redirect('dashboard/api');
    }

    /* ------------------------------ helper ----------------------------- */

    private function render($title, $view, $nav, $extra) {
        $data = array(
            'title'        => $title,
            'nav_active'   => $nav,
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        );
        $this->load->view('layouts/app', array_merge($data, $extra));
    }
}
