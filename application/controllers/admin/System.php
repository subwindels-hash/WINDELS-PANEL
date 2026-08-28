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
            && !$this->auth->can('audit.view')
            && !$this->auth->can('api.manage')) {
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
    /** GET /admin/logs — last lines of application log files. */
    public function logs() {
        $this->require_perm('audit.view');
        require_once APPPATH.'core/Env.php';
        $dir = rtrim(Env::root(), '/').'/storage/logs';
        $files = array();
        $tail = '';
        if ($dir && is_dir($dir)) {
            foreach (glob($dir.'/*.log') ?: array() as $f) {
                $files[] = basename($f);
            }
            rsort($files);
            $pick = $this->input->get('file', true) ?: (isset($files[0]) ? $files[0] : '');
            if ($pick && in_array($pick, $files, true)) {
                $lines = @file($dir.'/'.$pick);
                if (is_array($lines)) {
                    $tail = implode('', array_slice($lines, -200));
                }
            }
        } else {
            $pick = '';
        }

        $this->render('System logs', 'admin/system/logs', 'logs', array(
            'files' => $files,
            'file'  => $pick ?? '',
            'tail'  => $tail,
            'page_description' => 'Read-only tail of storage/logs. Nothing here can be edited.',
        ));
    }

    /* ------------------------------ cron jobs ---------------------------- */

    /**
     * GET /admin/cron — what is scheduled, and whether it is actually running.
     *
     * The panel depends on background work for things customers notice: order
     * status polling, refill settlement, deposit reconciliation, escrow
     * release, email delivery. All of it was invisible from the browser — the
     * only way to answer "is cron running on this host?" was SSH and
     * `php index.php cron status`. An operator who never installed the crontab
     * had no way to find out except by noticing that nothing ever settled.
     *
     * Read-only, deliberately: this screen reports what the schedule says and
     * what the last runs did. Running a job by hand belongs on the surface
     * that owns it (Refills, Payments, and so on), where the permission and
     * the audit trail already exist.
     */
    public function cron() {
        $this->require_perm('audit.view');

        $this->load->library('CronControlService');
        $schedules = (array)$this->config->item('cron');
        $runs = $this->db->order_by('started_at', 'DESC')->limit(200)->get('job_runs')->result();
        $controls = $this->croncontrolservice->all();

        // Latest run per job, plus a small health verdict per row.
        $latest = array();
        foreach ($runs as $row) {
            if (!isset($latest[$row->job])) $latest[$row->job] = $row;
        }

        $jobs = array();
        foreach ($schedules as $job => $schedule) {
            $last = $latest[$job] ?? null;
            $age_minutes = $last ? max(0, (int)round((time() - strtotime($last->started_at.' UTC')) / 60)) : null;
            $control = $controls[$job] ?? null;
            $paused  = $control && (int)$control->is_paused === 1;
            $jobs[] = array(
                'job'      => $job,
                'schedule' => (string)$schedule,
                'human'    => SystemAdminService::describe_schedule((string)$schedule),
                'last'     => $last,
                'age'      => $age_minutes,
                // A paused job is not "late": it is idle on purpose, and
                // showing it in red would train everyone to ignore red.
                'state'    => $paused ? 'paused'
                    : SystemAdminService::job_state((string)$schedule, $last, $age_minutes),
                'command'  => 'php index.php cron '.$job,
                'control'  => $control,
                'paused'   => $paused,
                'consequence' => CronControlService::consequence($job),
                'money'    => CronControlService::moves_money($job),
            );
        }

        // A job that has run but is not in the schedule is worth surfacing:
        // it means the crontab and the code have drifted apart.
        foreach ($latest as $job => $row) {
            if (isset($schedules[$job])) continue;
            $jobs[] = array(
                'job' => $job, 'schedule' => '', 'human' => 'not scheduled',
                'last' => $row, 'age' => null, 'state' => 'unscheduled',
                'command' => 'php index.php cron '.$job,
            );
        }

        $this->render('Cron jobs', 'admin/system/cron', 'cron', array(
            'jobs'    => $jobs,
            'runs'    => array_slice($runs, 0, 40),
            'can_control' => $this->auth->can('settings.manage'),
            'max_pause_hours' => CronControlService::MAX_HOURS,
            'crontab' => SystemAdminService::crontab_lines($schedules),
            'page_description' => 'What background work is scheduled, when it last ran, and the crontab to install.',
        ));
    }

    /**
     * POST /admin/cron/pause — stop a job for a bounded number of hours.
     *
     * Gated on `settings.manage`, not on the `audit.view` that opens the
     * screen: reading which jobs are healthy is an everyday support task,
     * stopping the sweep that reconciles deposits is not.
     */
    public function cron_pause() {
        $this->require_perm('settings.manage');
        if ($this->input->method(true) !== 'POST') show_404();
        $this->load->library('CronControlService');

        $res = $this->croncontrolservice->pause(
            (string)$this->input->post('job', true),
            (string)$this->input->post('reason', true),
            $this->current_user->id,
            (int)$this->input->post('hours', true)
        );

        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
        } else {
            // The expiry is in the confirmation on purpose: the operator
            // should leave this screen knowing the job comes back by itself.
            $this->session->set_flashdata('success',
                'Paused. It resumes automatically at '.$res['resume_at'].' UTC ('
                .$res['hours'].'h) unless you resume it sooner.');
        }
        redirect('admin/cron');
    }

    /** POST /admin/cron/resume — put a paused job back on its schedule. */
    public function cron_resume() {
        $this->require_perm('settings.manage');
        if ($this->input->method(true) !== 'POST') show_404();
        $this->load->library('CronControlService');

        $res = $this->croncontrolservice->resume(
            (string)$this->input->post('job', true), $this->current_user->id);

        $this->session->set_flashdata(empty($res['ok']) ? 'error' : 'success',
            empty($res['ok']) ? $res['error'] : 'Resumed. The job runs on its next tick.');
        redirect('admin/cron');
    }

    /** GET /admin/api-logs */
    public function api_logs() {
        $this->require_perm('api.manage');
        $page = max(1, (int)$this->input->get('page'));
        $limit = 50;
        $total = 0;
        $rows = array();
        if ($this->db->table_exists('api_usage_logs')) {
            $total = (int)$this->db->count_all('api_usage_logs');
            $rows = $this->db->select('api_usage_logs.*, api_keys.prefix, api_keys.name AS key_name, users.username', false)
                ->from('api_usage_logs')
                ->join('api_keys', 'api_keys.id = api_usage_logs.api_key_id', 'left')
                ->join('users', 'users.id = api_keys.user_id', 'left')
                ->order_by('api_usage_logs.created_at', 'DESC')
                ->limit($limit, ($page - 1) * $limit)
                ->get()->result();
        }
        $this->render('API logs', 'admin/system/api_logs', 'api_logs', array(
            'rows' => $rows,
            'page' => $page,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
            'page_description' => 'Reseller API requests recorded in api_usage_logs.',
        ));
    }

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
            'logs'       => array('System logs','admin/logs',       $this->auth->can('audit.view')),
            'cron'       => array('Cron jobs',  'admin/cron',       $this->auth->can('audit.view')),
            'api_logs'   => array('API logs',   'admin/api-logs',   $this->auth->can('api.manage')),
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
