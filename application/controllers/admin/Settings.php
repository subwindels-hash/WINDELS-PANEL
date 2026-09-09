<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Settings — the panel's configuration.
 *
 * `admin/settings` has been the last entry in the admin sidebar since Session
 * 15 and 404'd for every operator, so `settings.manage` gated nothing and
 * every value seeded in Session 02 could only be changed with SQL.
 *
 * The schema, validation and the list of deliberately-omitted keys live in
 * SettingsService. Two things are worth knowing before reading further:
 *
 *   - Settings that no code reads are **not** rendered as controls. The screen
 *     lists them instead, with what each would need to work. A switch that
 *     saves and does nothing is worse than no switch.
 *   - `base_currency` is shown read-only. It moves by migration, never by
 *     form — see the class comment on SettingsService.
 */
class Settings extends Admin_Controller {

    public function __construct() {
        parent::__construct();
        $this->require_perm('settings.manage');
        $this->load->library(array('SettingsService', 'DashboardStats'));
        $this->load->model(array('Setting_model', 'Audit_log_model'));
    }

    public function index() {
        $this->load->view('layouts/app_theme', array(
            'title'        => 'Settings',
            'nav_active'   => 'admin/settings',
            'content_view' => 'admin/settings/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'grouped'      => $this->settingsservice->grouped(),
            'values'       => $this->settingsservice->current(),
            'unwired'      => SettingsService::unwired(),
            'readonly'     => SettingsService::readonly_settings(),
            'base_currency'=> marvy_base_currency(),
            'page_description' => 'Panel-wide configuration. Every change is recorded in the audit log.',
        ));
    }

    /** POST /admin/settings/save — validate and persist. */
    /** GET|POST /admin/settings/flags */
    public function flags() {
        if ($this->input->method(true) === 'POST') {
            return $this->save_flags();
        }
        $this->load->model('Feature_flag_model');
        $this->load->view('layouts/app', array(
            'title'        => 'Feature flags',
            'nav_active'   => 'admin/settings',
            'content_view' => 'admin/settings/flags',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'flags'        => $this->Feature_flag_model->all_rows(),
            'page_description' => 'Turn product modules on or off without a deploy.',
        ));
    }

    /** POST /admin/settings/flags */
    public function save_flags() {
        $this->guard();
        $this->load->model('Feature_flag_model');
        $posted = $this->input->post('flags');
        $posted = is_array($posted) ? $posted : array();
        foreach ($this->Feature_flag_model->all_rows() as $row) {
            $on = !empty($posted[$row->flag_key]);
            $this->Feature_flag_model->set_enabled($row->flag_key, $on);
        }
        $this->audit('settings.feature_flags', array('flags' => array('before' => null, 'after' => $posted)));
        $this->session->set_flashdata('success', 'Feature flags saved.');
        redirect('admin/settings/flags');
    }

    public function save() {
        $this->guard();

        $res = $this->settingsservice->save($this->input->post(null, true));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            redirect('admin/settings');
        }

        if (empty($res['changed'])) {
            $this->session->set_flashdata('warning', 'Nothing changed.');
            redirect('admin/settings');
        }

        // One audit entry carrying every before/after pair: settings change
        // rarely and matter a lot, so "who raised the commission" must stay
        // answerable months later.
        $this->audit('settings.updated', $res['changed']);
        $this->session->set_flashdata('success',
            count($res['changed']).' setting'.(count($res['changed']) === 1 ? '' : 's').' updated.');
        redirect('admin/settings');
    }

    /* ----------------------------- helpers ----------------------------- */

    private function guard() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');
    }

    private function audit($action, array $changed) {
        $before = array();
        $after  = array();
        foreach ($changed as $key => $pair) {
            $before[$key] = $pair['before'];
            $after[$key]  = $pair['after'];
        }
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'settings', null,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
