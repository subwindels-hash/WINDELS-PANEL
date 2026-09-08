<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Notifications — messages from the team, written by a super admin.
 *
 * Two recipients, one screen: every user at once (a maintenance window, a new
 * payment method, a price change) or one user by email ("your withdrawal is
 * being looked at"). Both are in-app notifications — the bell in the topbar
 * and the Notifications page — because that is the surface every signed-in
 * customer already reads. Email stays out of the path: a broadcast that
 * respects nobody's inbox rules and lands directly where the customer looks
 * is the thing a "notify everyone" button is for.
 *
 * The permission is deliberately its own key (notifications.send) and is
 * seeded into NO staff role: SUPER_ADMIN passes by bypass, so by default this
 * screen is the super admin's alone, and an operator can delegate it through
 * the RBAC matrix if they ever want to.
 */
class Notifications extends Admin_Controller {

    const PER_PAGE = 50;

    public function __construct() {
        parent::__construct();
        $this->require_perm('notifications.send');
        $this->load->library(array('NotificationService', 'DashboardStats'));
        $this->load->model(array('User_model', 'Audit_log_model'));
    }

    /** GET /admin/notifications — the compose form and recent broadcasts. */
    public function index() {
        $total_users = 0;
        try {
            $total_users = (int) $this->db->count_all('users');
        } catch (Throwable $e) { /* the form still renders */ }

        $this->render('Notifications', 'admin/notifications/index', array(
            'rows'        => $this->notificationservice->recent_broadcasts(self::PER_PAGE),
            'total_users' => $total_users,
        ));
    }

    /**
     * POST /admin/notifications/send — deliver to all users, or to one.
     *
     * Audited like every other admin write: who sent what to how many
     * people, when. The body is deliberately NOT in the audit row — the
     * notifications table already holds it, and the audit trail should say
     * what happened, not duplicate content.
     */
    public function send() {
        if ($this->input->method(true) !== 'POST') show_404();

        $title = trim((string) $this->input->post('title'));
        $body  = trim((string) $this->input->post('body'));
        $mode  = $this->input->post('mode') === 'one' ? 'one' : 'all';

        if ($title === '' || $body === '') {
            $this->session->set_flashdata('error', 'Write a title and a message before sending.');
            return redirect('admin/notifications');
        }

        if ($mode === 'one') {
            $email = trim((string) $this->input->post('recipient_email'));
            $user = $this->User_model->find_by_email($email);
            if (!$user) {
                $this->session->set_flashdata('error', 'No account found for '.$email.'.');
                return redirect('admin/notifications');
            }
            $count = $this->notificationservice->broadcast(array($user->id), $title, $body);
            $this->audit($mode, $email, $count);
            $this->session->set_flashdata('success',
                $count > 0 ? 'Notification delivered to '.$user->email.'.'
                           : 'The notification could not be delivered. Try again.');
            return redirect('admin/notifications');
        }

        $ids = array();
        foreach ($this->db->select('id')->get('users')->result() as $row) {
            $ids[] = (int) $row->id;
        }
        if (!$ids) {
            $this->session->set_flashdata('error', 'There are no users to send to yet.');
            return redirect('admin/notifications');
        }
        $count = $this->notificationservice->broadcast($ids, $title, $body);
        $this->audit($mode, null, $count);
        $this->session->set_flashdata('success',
            'Notification delivered to '.$count.' user'.($count === 1 ? '' : 's').'.');
        redirect('admin/notifications');
    }

    /** One audit row per send: who, to whom, how many. */
    private function audit($mode, $email, $count) {
        $this->Audit_log_model->record(
            $this->current_user->id, 'notifications.broadcast', 'notifications', null,
            array('target' => $mode === 'one' ? (string) $email : 'all users', 'delivered' => (int) $count),
            null,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app_theme', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/notifications',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }
}
