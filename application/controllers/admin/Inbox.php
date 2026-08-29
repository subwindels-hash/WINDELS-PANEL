<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Inbox — mail that reached the configured mailbox.
 *
 * The receiving half of email: a cron worker (inbox_poll, every two minutes)
 * pulls messages out of the cPanel account the SMTP settings point at, and
 * InboxService routes each one — mail addressed to the admin inbox address
 * (or anything it cannot attribute) lands here, on this screen, shared by
 * every staff member with settings.manage; mail addressed to a registered
 * customer lands in that customer's dashboard instead (dashboard/inbox).
 *
 * What this controller deliberately cannot do:
 *   - **See a customer's inbox.** Staff inboxes are shared and read-only
 *     between staff; each customer's mail is visible only to that customer.
 *   - **Render stored HTML as HTML.** Message bodies are text; the raw HTML
 *     half is shown escaped, reference only, so a hostile sender cannot
 *     plant script in the back office.
 *   - **Change the mail pipeline.** Enabling/disabling the inbox, and the
 *     mailbox credentials themselves (VP_INBOX_* in .env), live in Settings.
 */
class Inbox extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        // The inbox is the receiving side of the mail system, so it shares
        // the mail configuration permission: whoever runs the mail queue
        // reads the inbox.
        $this->require_perm('settings.manage');
        $this->load->library(array('InboxService', 'MailService'));
        $this->load->model('Audit_log_model');
    }

    /** GET /admin/inbox — the staff inbox list, newest first. */
    public function index() {
        $status = $this->input->get('status') === 'UNREAD' ? 'UNREAD' : '';
        $page   = max(1, (int) $this->input->get('page'));
        $limit  = self::PER_PAGE;

        $rows  = $this->inboxservice->for_admin($status, $limit, ($page - 1) * $limit);
        $total = $this->inboxservice->count_admin($status);

        $this->render('Inbox', 'admin/inbox/index', array(
            'rows'         => $rows,
            'total'        => $total,
            'unread'       => $this->inboxservice->count_admin('UNREAD'),
            'status'       => $status,
            'page'         => $page,
            'total_pages'  => max(1, (int) ceil($total / $limit)),
            'admin_email'  => $this->inboxservice->admin_address(),
        ));
    }

    /** GET /admin/inbox/:id — one message; marks it read on open. */
    public function detail($public_id) {
        $msg = $this->inboxservice->find_admin($public_id);
        if (!$msg) show_404();
        if (!(int) $msg->is_read) {
            $this->inboxservice->mark_read('ADMIN', null, $public_id);
            $msg->is_read = 1;
        }
        $this->render('Inbox — '.$this->clip((string) $msg->subject), 'admin/inbox/detail', array(
            'msg'    => $msg,
            'reply'  => $this->input->get('reply') === '1',
        ));
    }

    /**
     * POST /admin/inbox/read — mark one message (public_id) or everything
     * read. Both land on the same screen the staff came from.
     */
    public function mark_read() {
        if ($this->input->method(true) !== 'POST') show_404();
        $public_id = $this->input->post('public_id');
        if ($public_id) {
            $this->inboxservice->mark_read('ADMIN', null, $public_id);
        } else {
            $this->inboxservice->mark_read('ADMIN', null, null);
        }
        $this->session->set_flashdata('success', 'Marked as read.');
        redirect($this->input->post('back') ?: 'admin/inbox');
    }

    /**
     * POST /admin/inbox/:id/delete — remove a stored message.
     *
     * Deletion is a manual housekeeping act (spam, duplicates), so it is
     * POST-only and audited like every other admin mutation; the audit row
     * records the subject and sender, never the body.
     */
    public function delete($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $msg = $this->inboxservice->find_admin($public_id);
        if (!$msg) show_404();
        $this->inboxservice->delete('ADMIN', null, $public_id);
        $this->Audit_log_model->record(
            $this->current_user->id, 'inbox.message_deleted', 'inbox_messages', $public_id,
            array('subject' => (string) $msg->subject, 'from' => (string) $msg->from_email),
            null,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
        $this->session->set_flashdata('success', 'Message deleted.');
        redirect('admin/inbox');
    }

    /**
     * POST /admin/inbox/:id/reply — answer through the normal mail queue.
     *
     * The reply goes to the sender's address via MailService::enqueue_raw
     * (the same queue every other outgoing mail uses), so delivery, retries
     * and the mail-queue screen all behave exactly as they do for any other
     * mail. It must be addressed to someone: a stored message without a
     * From address cannot be replied to and says so.
     */
    public function reply($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $msg = $this->inboxservice->find_admin($public_id);
        if (!$msg) show_404();
        if (empty($msg->from_email)) {
            $this->session->set_flashdata('error',
                'This message has no From address, so it cannot be replied to.');
            return redirect('admin/inbox/'.$public_id);
        }

        $body = trim((string) $this->input->post('body'));
        if ($body === '') {
            $this->session->set_flashdata('error', 'Write a reply before sending it.');
            return redirect('admin/inbox/'.$public_id.'?reply=1');
        }

        $subject = 'Re: '.$msg->subject;
        $html = '<p>'.nl2br(htmlspecialchars($body)).'</p>';
        if (!$this->mailservice->enqueue_raw(
                $msg->from_email, $subject, $html, $body, $msg->from_name, 'inbox.reply')) {
            $this->session->set_flashdata('error', 'Could not queue the reply. Try again.');
        } else {
            $this->session->set_flashdata('success',
                'Reply queued to '.$msg->from_email.'. It sends with the next mail-queue run.');
        }
        redirect('admin/inbox/'.$public_id);
    }

    private function clip($s) {
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 57).'…' : $s;
    }

    private function render($title, $view, array $data) {
        $this->load->view('layouts/app_theme', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/inbox',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        ), $data));
    }
}
