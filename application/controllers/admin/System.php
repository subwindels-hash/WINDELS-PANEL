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
        // The heartbeat state, for the "is anything actually running?" answer:
        // on a host with no crontab, auto-run is what is doing the work.
        if (!class_exists('CronScheduler', false)) {
            require_once APPPATH.'libraries/CronScheduler.php';
        }
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
            'autorun' => CronScheduler::state(),
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

    /**
     * POST /admin/cron/run — run one job right now, from the browser.
     *
     * This is the "did the crontab even install?" answer that used to require
     * SSH. It runs the *same* code the crontab would have run — CronRegistry
     * resolves the worker, JobRunner takes the exclusive lock and records the
     * run in job_runs — so a manual run can never overlap a scheduled tick, can
     * never double-apply anything the CLI run applies, and shows up in the run
     * history like any other tick. What it must never be is a second,
     * weaker implementation of the job.
     */
    public function cron_run() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');

        $this->load->library(array('JobRunner', 'CronRegistry', 'CronControlService'));
        $job = (string)$this->input->post('job', true);

        if (!$this->cronregistry->has($job)) {
            $this->session->set_flashdata('error', 'Unknown cron job.');
            return redirect('admin/cron');
        }

        // A paused job is idle on purpose: a manual run would work around an
        // incident switch, so it is refused — and the refusal is recorded as a
        // SKIPPED tick, exactly as the crontab's own attempt would be.
        if ($this->croncontrolservice->is_paused($job)) {
            $state = $this->croncontrolservice->state($job);
            $reason = $state && $state->reason !== '' ? $state->reason : 'paused by an operator';
            $this->jobrunner->record_skip($job, 'manual run refused: paused ('.$reason.')');
            $this->session->set_flashdata('warning',
                "{$job} is paused ({$reason}). Resume it first, then run it.");
            return redirect('admin/cron');
        }

        $worker = $this->cronregistry->worker($job);

        // A manual run must mirror what the crontab gets in production:
        // db_debug off, so a failing query is a FAILED run recorded in
        // job_runs — not a dead admin page halfway through the job.
        if (isset($this->db) && is_object($this->db)) {
            $this->db->db_debug = false;
        }

        // A big catalogue sync or a long reconciliation can outlive a default
        // PHP-FPM request timeout; ask for the room, ignore the refusal on
        // hosts that cap it (the harness still contains the failure).
        if (function_exists('set_time_limit')) @set_time_limit(0);
        @ignore_user_abort(true);

        $result = $this->jobrunner->run($job, $worker);
        $seconds = $this->jobrunner->elapsed();

        $this->audit('cron.run', 'job_runs', $job, null, array(
            'status'    => !empty($result['ok']) ? (empty($result['skipped']) ? 'SUCCESS' : 'SKIPPED') : 'FAILED',
            'message'   => (string)($result['message'] ?? ($result['error'] ?? '')),
            'seconds'   => round((float)$seconds, 3),
            'triggered' => 'admin',
        ));

        if (!empty($result['skipped'])) {
            $this->session->set_flashdata('warning',
                "{$job}: skipped — it is already running. The tick in progress owns the lock.");
        } elseif (empty($result['ok'])) {
            $this->session->set_flashdata('error',
                "{$job}: FAILED — ".($result['error'] ?? 'unknown error'));
        } else {
            $this->session->set_flashdata('success',
                "{$job}: ok — ".($result['message'] ?? 'done').sprintf(' (%.3fs)', $seconds));
        }
        redirect('admin/cron');
    }

    /**
     * POST /admin/cron/catchup — run every overdue job, in one click.
     *
     * The crontab is still how the panel is kept running; no browser button can
     * replace the schedule. But when a crontab has been missing or its PHP path
     * was wrong, the panel knows exactly which jobs fell behind (the same
     * `late` / `never` verdict shown on the screen) and an operator should not
     * have to click "Run now" twenty times while they fix the crontab. This runs
     * each of those jobs through the exact harness a scheduled tick uses —
     * CronRegistry worker, JobRunner exclusive lock, job_runs record, audit
     * entry — so it can never overlap a real tick or double-apply anything.
     */
    public function cron_catchup() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');

        $this->load->library(array('JobRunner', 'CronRegistry', 'CronControlService'));
        $schedules = (array)$this->config->item('cron');
        $runs = $this->db->order_by('started_at', 'DESC')->limit(500)->get('job_runs')->result();
        $controls = $this->croncontrolservice->all();

        $latest = array();
        foreach ($runs as $row) {
            if (!isset($latest[$row->job])) $latest[$row->job] = $row;
        }

        $due = array();
        foreach ($schedules as $job => $schedule) {
            $control = $controls[$job] ?? null;
            if ($control && (int)$control->is_paused === 1) continue;
            if (!$this->cronregistry->has($job)) continue;

            $last = $latest[$job] ?? null;
            $age = $last ? max(0, (int)round((time() - strtotime($last->started_at.' UTC')) / 60)) : null;
            $state = SystemAdminService::job_state((string)$schedule, $last, $age);
            if ($state === 'late' || $state === 'never') $due[] = $job;
        }

        if (!$due) {
            $this->session->set_flashdata('info', 'No overdue jobs to catch up — the schedule is healthy.');
            return redirect('admin/cron');
        }

        $ok = $skipped = $failed = 0;
        $details = array();
        foreach ($due as $job) {
            // Re-check at the last moment; a pause landed while the list was
            // being built must not be worked around.
            if ($this->croncontrolservice->is_paused($job)) {
                $this->jobrunner->record_skip($job, 'catch-up refused: paused');
                $skipped++;
                continue;
            }
            $worker = $this->cronregistry->worker($job);
            if ($worker === null) {
                $failed++;
                continue;
            }

            if (isset($this->db) && is_object($this->db)) {
                $this->db->db_debug = false;
            }
            if (function_exists('set_time_limit')) @set_time_limit(0);
            @ignore_user_abort(true);

            $result = $this->jobrunner->run($job, $worker);
            $this->audit('cron.catchup', 'job_runs', $job, null, array(
                'status'    => !empty($result['ok']) ? (empty($result['skipped']) ? 'SUCCESS' : 'SKIPPED') : 'FAILED',
                'message'   => (string)($result['message'] ?? ($result['error'] ?? '')),
                'triggered' => 'admin-catchup',
            ));

            if (!empty($result['skipped'])) {
                $skipped++;
                $details[] = $job.' skipped (already running)';
            } elseif (empty($result['ok'])) {
                $failed++;
                $details[] = $job.' FAILED — '.($result['error'] ?? 'unknown error');
            } else {
                $ok++;
                $details[] = $job.' ok — '.($result['message'] ?? 'done');
            }
        }

        $this->session->set_flashdata(
            $failed ? 'warning' : 'success',
            sprintf('Catch-up finished: %d ok, %d skipped, %d failed. %s',
                $ok, $skipped, $failed, implode('; ', $details))
        );
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
