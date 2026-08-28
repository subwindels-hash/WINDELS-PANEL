<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Staff — who works here, and what each role may do.
 *
 * `admin/staff` was routed and `staff.manage` seeded in Session 15, but no
 * controller existed: the role matrix could only be changed by editing
 * Core_seeder and re-running the seed, which is not something anyone should
 * do against a live panel.
 *
 * Two screens, both behind `staff.manage`:
 *   - the staff directory, reusing the same model queries as Admin →
 *     Customers with a role filter, so there is one definition of "a user
 *     row" rather than two that drift;
 *   - the permission matrix, one grid per role.
 *
 * The dangerous half is the matrix, and every guard for it lives in
 * RbacService so it cannot hold on one path and not another. Read its class
 * comment before changing anything here — in particular, why SUPER_ADMIN's
 * grid is deliberately not editable.
 *
 * Role *assignment* (making someone an ADMIN) is not here: it lives on the
 * user's own file in Admin → Customers, next to their account status, because
 * that is where an operator is looking when they need it.
 */
class Staff extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('staff.manage');
        $this->load->library(array('RbacService', 'UserAdminService', 'DashboardStats'));
        $this->load->model(array('User_model', 'Role_model', 'Permission_model', 'Audit_log_model'));
    }

    /** GET /admin/administrators — SUPER_ADMIN and ADMIN only. */
    public function administrators() {
        $filters = array(
            'search' => $this->input->get('q', true),
            'role'   => $this->input->get('role', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $this->db->from('users')->where_in('role', array('SUPER_ADMIN','ADMIN'));
        if (!empty($filters['role'])) $this->db->where('role', $filters['role']);
        if (!empty($filters['search'])) {
            $q = $filters['search'];
            $this->db->group_start()->like('username', $q)->or_like('email', $q)->group_end();
        }
        $total = (int)$this->db->count_all_results();
        $this->db->from('users')->where_in('role', array('SUPER_ADMIN','ADMIN'));
        if (!empty($filters['role'])) $this->db->where('role', $filters['role']);
        if (!empty($filters['search'])) {
            $q = $filters['search'];
            $this->db->group_start()->like('username', $q)->or_like('email', $q)->group_end();
        }
        $rows = $this->db->order_by('role', 'ASC')->order_by('username', 'ASC')
            ->limit($limit, ($page - 1) * $limit)->get()->result();

        $this->render('Administrators', 'admin/staff/administrators', array(
            'staff'       => $rows,
            'filters'     => $filters,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
            'page_description' => 'Accounts that can reach the admin area as SUPER_ADMIN or ADMIN.',
        ));
    }

    /**
     * POST /admin/administrators/create — mint another administrator.
     *
     * Creating is additive, so the last-super-admin lockout guards do not
     * apply; the privilege rule does: only a SUPER_ADMIN may create another
     * SUPER_ADMIN, and that check lives in UserAdminService where the
     * set_role() copy already lives, so the two cannot drift apart.
     */
    public function create() {
        $this->guard();
        $res = $this->useradminservice->create_admin($this->current_user, array(
            'username' => $this->input->post('username', true),
            'email'    => $this->input->post('email', true),
            'password' => (string)$this->input->post('password'),
            'role'     => $this->input->post('role', true),
        ));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('admin/administrators');
        }

        $this->session->set_flashdata('success', sprintf(
            '%s account created for %s. Hand the password over privately — they should change it from Account → Security after signing in.',
            $res['user']->role, $res['user']->username
        ));
        redirect('admin/administrators');
    }

    /** GET /admin/staff — the staff directory. */
    public function index() {
        $filters = array(
            'search'     => $this->input->get('q', true),
            'role'       => $this->input->get('role', true),
            'staff_only' => true,
        );
        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $grid  = $this->useradminservice->grid($filters, $limit, ($page - 1) * $limit);
        $total = (int)$grid['total'];

        $this->render('Staff', 'admin/staff/index', array(
            'staff'       => $grid['rows'],
            'roles'       => $this->rbacservice->roles(),
            'filters'     => $filters,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** GET /admin/staff/permissions — the role/permission matrix. */
    public function permissions() {
        $this->render('Roles and permissions', 'admin/staff/permissions', array(
            'roles'      => $this->rbacservice->roles(),
            'catalogue'  => $this->rbacservice->catalogue(),
            'matrix'     => $this->rbacservice->matrix(),
            'unenforced' => $this->rbacservice->unenforced(),
            'keystone'   => RbacService::KEYSTONE,
        ));
    }

    /** POST /admin/staff/permissions/:role — replace one role's grid. */
    public function save_permissions($role_name = null) {
        $this->guard();

        $keys = $this->input->post('permissions');
        $keys = is_array($keys) ? array_values(array_filter(array_map('strval', $keys))) : array();

        $res = $this->rbacservice->set_permissions($this->current_user, (string)$role_name, $keys);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            redirect('admin/staff/permissions');
        }

        if (empty($res['added']) && empty($res['removed'])) {
            $this->session->set_flashdata('warning', 'Nothing changed for '.$role_name.'.');
            redirect('admin/staff/permissions');
        }

        $this->audit('rbac.permissions_changed', $role_name, $res['before'], $res['after']);
        $this->session->set_flashdata('success', sprintf(
            '%s updated — %d granted, %d revoked.',
            $role_name, count($res['added']), count($res['removed'])
        ));
        redirect('admin/staff/permissions');
    }

    /* ----------------------------- helpers ----------------------------- */

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/staff',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }

    private function guard() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('staff.manage');
    }

    private function audit($action, $role_name, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'role_permissions', (string)$role_name,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
