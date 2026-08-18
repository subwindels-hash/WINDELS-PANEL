<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/System — service categories, the blacklist and the audit trail.
 *
 * The last three routed-but-missing screens: `admin/categories`,
 * `admin/blacklist` and `admin/audit-logs` all 404'd, leaving
 * `categories.manage`, `blacklist.manage` and `audit.view` gating nothing.
 *
 * Each area keeps its own permission — reading the audit trail and editing
 * the blacklist are unrelated jobs — so the constructor admits anyone holding
 * one of the three and every action re-checks the specific one.
 *
 * Two deliberate absences:
 *
 *   - **The audit trail has no write path.** No edit, no delete, no bulk
 *     purge. An append-only trail an admin can edit is not a trail, and this
 *     is the screen most likely to be opened by someone covering their
 *     tracks. It reads and it exports; that is all it can do.
 *   - **Categories cannot be deleted while in use.** The FK is ON DELETE SET
 *     NULL, so a delete would succeed and silently orphan every service in it.
 *
 * The blacklist's sharp edge — staff-supplied regular expressions that run on
 * every registration — is handled in SystemAdminService::validate_pattern().
 */
class System extends Admin_Controller {

    const PER_PAGE = 50;

    public function __construct() {
        parent::__construct();
        if (!$this->auth->can('categories.manage')
            && !$this->auth->can('blacklist.manage')
            && !$this->auth->can('audit.view')) {
            $this->require_perm('audit.view');
        }
        $this->load->library(array('SystemAdminService', 'DashboardStats'));
        $this->load->model(array(
            'Service_category_model', 'Blacklist_model', 'Audit_log_model',
            'User_model', 'Service_model',
        ));
    }

    /* ---------------------------- categories ---------------------------- */

    /** GET /admin/categories */
    public function categories() {
        $this->require_perm('categories.manage');

        $this->render('Categories', 'admin/system/categories', 'categories', array(
            'categories' => $this->systemadminservice->categories(),
        ));
    }

    /** POST /admin/categories/save — create or update. */
    public function save_category($public_id = null) {
        $this->guard('categories.manage');

        $existing = $public_id ? $this->systemadminservice->find_category($public_id) : null;
        if ($public_id && !$existing) show_404();

        $res = $this->systemadminservice->save_category($existing, $this->input->post(null, true));
        if (empty($res['ok'])) return $this->fail('admin/categories', $res['error']);

        $this->audit($res['created'] ? 'category.created' : 'category.updated',
            'service_categories', $res['category']->id,
            $res['before'], get_object_vars($res['category']));
        $this->session->set_flashdata('success', 'Category saved.');
        if (!empty($res['warnings'])) {
            $this->session->set_flashdata('warning', implode(' ', $res['warnings']));
        }
        redirect('admin/categories');
    }

    /** POST /admin/categories/:id/delete */
    public function delete_category($public_id) {
        $this->guard('categories.manage');
        $category = $this->systemadminservice->find_category($public_id);
        if (!$category) show_404();

        $res = $this->systemadminservice->delete_category($category);
        if (empty($res['ok'])) return $this->fail('admin/categories', $res['error']);

        $this->audit('category.deleted', 'service_categories', $category->id,
            get_object_vars($category), null);
        $this->session->set_flashdata('success', 'Category deleted.');
        redirect('admin/categories');
    }

    /* ----------------------------- blacklist ---------------------------- */

    /** GET /admin/blacklist */
    public function blacklist() {
        $this->require_perm('blacklist.manage');

        $entries = array();
        foreach (array_keys(SystemAdminService::lists()) as $kind) {
            $entries[$kind] = $this->systemadminservice->list_entries($kind, self::PER_PAGE);
        }

        $this->render('Blacklist', 'admin/system/blacklist', 'blacklist', array(
            'lists'   => SystemAdminService::lists(),
            'entries' => $entries,
        ));
    }

    /** POST /admin/blacklist/:kind/add */
    public function blacklist_add($kind) {
        $this->guard('blacklist.manage');
        if (!array_key_exists($kind, SystemAdminService::lists())) show_404();

        $res = $this->systemadminservice->blacklist_add($kind,
            $this->input->post('value', true), $this->input->post('reason', true));
        if (empty($res['ok'])) return $this->fail('admin/blacklist', $res['error']);

        $this->audit('blacklist.added', $res['table'], $res['entry']->id,
            null, get_object_vars($res['entry']));
        $this->session->set_flashdata('success', 'Entry blocked.');
        redirect('admin/blacklist');
    }

    /** POST /admin/blacklist/:kind/:id/remove */
    public function blacklist_remove($kind, $id) {
        $this->guard('blacklist.manage');
        if (!array_key_exists($kind, SystemAdminService::lists())) show_404();

        $res = $this->systemadminservice->blacklist_remove($kind, $id);
        if (empty($res['ok'])) return $this->fail('admin/blacklist', $res['error']);

        $this->audit('blacklist.removed', $res['table'], $id,
            get_object_vars($res['entry']), null);
        $this->session->set_flashdata('success', 'Entry unblocked.');
        redirect('admin/blacklist');
    }

    /* ---------------------------- audit trail --------------------------- */

    /**
     * GET /admin/audit-logs — read-only, by construction.
     *
     * There is no companion write action anywhere in this controller, and
     * that is the feature.
     */
    public function audit_logs() {
        $this->require_perm('audit.view');

        $filters = array(
            'resource' => $this->input->get('resource', true),
            'actor_id' => (int)$this->input->get('actor'),
            'search'   => $this->input->get('q', true),
            'from'     => $this->input->get('from', true),
            'to'       => $this->input->get('to', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $total = $this->systemadminservice->audit_count($filters);

        $this->render('Audit log', 'admin/system/audit', 'audit', array(
            'entries'     => $this->systemadminservice->audit_search($filters, self::PER_PAGE,
                                ($page - 1) * self::PER_PAGE),
            'resources'   => $this->systemadminservice->audit_resources(),
            'staff'       => $this->User_model->staff_members(),
            'filters'     => $filters,
            'page'        => $page,
            'total'       => (int)$total,
            'total_pages' => max(1, (int)ceil($total / self::PER_PAGE)),
        ));
    }

    /* ------------------------------ helpers ----------------------------- */

    private function render($title, $view, $area, array $data) {
        $tabs = array(
            'categories' => array('Categories', 'admin/categories', $this->auth->can('categories.manage')),
            'blacklist'  => array('Blacklist',  'admin/blacklist',  $this->auth->can('blacklist.manage')),
            'audit'      => array('Audit log',  'admin/audit-logs', $this->auth->can('audit.view')),
        );
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/categories',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'area'         => $area,
            'tabs'         => $tabs,
        ), $data));
    }

    private function guard($perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
    }

    private function fail($url, $message) {
        $this->session->set_flashdata('error', $message);
        redirect($url);
    }

    private function audit($action, $table, $id, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, $table, (string)$id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
