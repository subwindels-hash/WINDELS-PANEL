<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Inbox — this customer's mail.
 *
 * The receiving half of email for customers: the inbox_poll cron worker
 * pulls mail out of the configured mailbox, and InboxService routes anything
 * addressed to a registered customer to that customer's rows (owner_type
 * USER). Every view here is hard-scoped to the signed-in customer — the
 * public_id lookup filters on owner_id, so one customer cannot open, mark or
 * delete another customer's message by guessing its id.
 *
 * What this controller deliberately cannot do:
 *   - **See the staff inbox or another customer's mail.** Scope is enforced
 *     in the queries, not the links.
 *   - **Render stored HTML as HTML.** Bodies are text; the raw HTML half is
 *     shown escaped, reference only.
 *   - **Reply.** Customers answer by hitting Reply in their own mail client
 *     (which is also why their address is in the To header); this screen
 *     offers mark-read and delete, and only the staff side can reply.
 */
class Inbox extends Auth_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->load->library('InboxService');
        $this->load->library('DashboardStats');
    }

    /** GET /dashboard/inbox — my mail, newest first. */
    public function index() {
        $status = $this->input->get('status') === 'UNREAD' ? 'UNREAD' : '';
        $page   = max(1, (int) $this->input->get('page'));
        $limit  = self::PER_PAGE;

        $rows  = $this->inboxservice->for_user($this->current_user->id, $status, $limit, ($page - 1) * $limit);
        $total = $this->inboxservice->count_user($this->current_user->id, $status);

        $this->load->view('layouts/app', array(
            'title'        => 'Inbox',
            'nav_active'   => 'dashboard/inbox',
            'content_view' => 'dashboard/inbox/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'rows'         => $rows,
            'inbox_unread' => $this->inboxservice->count_user($this->current_user->id, 'UNREAD'),
            'total'        => $total,
            'status'       => $status,
            'page'         => $page,
            'total_pages'  => max(1, (int) ceil($total / $limit)),
        ));
    }

    /** GET /dashboard/inbox/:id — my one message; marks it read on open. */
    public function detail($public_id) {
        $msg = $this->inboxservice->find_for_user($public_id, $this->current_user->id);
        if (!$msg) show_404();
        if (!(int) $msg->is_read) {
            $this->inboxservice->mark_read('USER', $this->current_user->id, $public_id);
            $msg->is_read = 1;
        }
        $this->load->view('layouts/app', array(
            'title'        => 'Inbox — '.$this->clip((string) $msg->subject),
            'nav_active'   => 'dashboard/inbox',
            'content_view' => 'dashboard/inbox/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'msg'          => $msg,
        ));
    }

    /** POST /dashboard/inbox/read — mark one (public_id) or everything read. */
    public function mark_read() {
        $id = $this->input->post('public_id');
        $this->inboxservice->mark_read('USER', $this->current_user->id, $id ?: null);
        $back = $this->input->post('back');
        redirect(preg_match('#^dashboard/inbox#', (string) $back) ? $back : 'dashboard/inbox');
    }

    /** POST /dashboard/inbox/:id/delete — remove one of my stored messages. */
    public function delete($public_id) {
        $msg = $this->inboxservice->find_for_user($public_id, $this->current_user->id);
        if (!$msg) show_404();
        $this->inboxservice->delete('USER', $this->current_user->id, $public_id);
        $this->session->set_flashdata('success', 'Message deleted.');
        redirect('dashboard/inbox');
    }

    private function clip($s) {
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 57).'…' : $s;
    }
}
