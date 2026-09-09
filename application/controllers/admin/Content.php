<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Content — blog posts, FAQ entries and announcements.
 *
 * All three were routed and permissioned in Session 15 (`blog.manage`,
 * `faq.manage`, `announcements.manage`) with no controller behind them, so
 * `admin/blog`, `admin/faq` and `admin/announcements` all 404'd and the public
 * blog, help page and announcement banner could only be filled by SQL.
 *
 * One controller over three domains, like Admin → Catalogue over four: the
 * operator's job is the same in each, and the rules that differ live in
 * ContentService rather than in three near-copies that drift apart.
 *
 * Each domain is gated on its own permission — `blog.manage` should not imply
 * the ability to raise a CRITICAL banner on every customer's dashboard — so
 * the constructor gates on holding *any* of the three and each action
 * re-checks the specific one.
 *
 * The security-relevant part is in ContentService::sanitize_html(): the public
 * blog renders post bodies unescaped, so this screen is the one path by which
 * a staff session could put script on a page seen by every visitor. Content is
 * sanitised on the way in, by allow-list.
 */
class Content extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        // Reading the content area needs at least one of the three.
        if (!$this->auth->can('blog.manage')
            && !$this->auth->can('faq.manage')
            && !$this->auth->can('announcements.manage')) {
            $this->require_perm('blog.manage');
        }
        $this->load->library(array('ContentService', 'DashboardStats'));
        $this->load->model(array(
            'Blog_post_model', 'Blog_category_model', 'Faq_model',
            'Announcement_model', 'Audit_log_model', 'Managed_page_model',
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Managed pages (Terms, Privacy, Refund, Acceptable use, About)       */
    /* ------------------------------------------------------------------ */

    /**
     * GET /admin/pages — the list of overridable public pages.
     *
     * These are the pages an operator must be able to change without a
     * developer: policy text moves for legal reasons, not release reasons.
     */
    public function pages() {
        $this->require_perm('content.pages');

        $this->render('pages', 'admin/content/pages', 'Website pages', array(
            'catalogue' => Managed_page_model::catalogue(),
            'overrides' => $this->Managed_page_model->all_by_key(),
        ));
    }

    /** GET /admin/pages/:key — edit one page. */
    public function page_edit($key) {
        $this->require_perm('content.pages');
        if (!Managed_page_model::is_page($key)) show_404();

        $this->render('pages', 'admin/content/page_form',
            'Edit '.Managed_page_model::label($key), array(
                'page_key'  => $key,
                'page_label'=> Managed_page_model::label($key),
                'override'  => $this->Managed_page_model->find($key),
            ));
    }

    /** POST /admin/pages/:key — save the override. */
    public function page_save($key) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('content.pages');
        if (!Managed_page_model::is_page($key)) show_404();

        $title = trim((string)$this->input->post('title', true));
        // post(null-escaped) would mangle the markup, so the body is read raw
        // and then sanitised — the allowlist is the security boundary, not the
        // input filter.
        $body  = $this->contentservice->sanitize_html((string)$this->input->post('body_html', false));
        $meta  = trim((string)$this->input->post('meta_description', true));

        if ($title === '') {
            $this->session->set_flashdata('error', 'A page title is required.');
            return redirect('admin/pages/'.$key);
        }
        if (trim(strip_tags($body)) === '') {
            $this->session->set_flashdata('error',
                'The page body cannot be empty. To restore the bundled text, use “Reset to default”.');
            return redirect('admin/pages/'.$key);
        }

        $before = $this->Managed_page_model->find($key);
        $this->Managed_page_model->store($key, array(
            'title'            => mb_substr($title, 0, 160),
            'body_html'        => $body,
            'meta_description' => $meta !== '' ? mb_substr($meta, 0, 320) : null,
            'is_published'     => $this->input->post('is_published') ? 1 : 0,
        ), $this->current_user->id);

        $this->Audit_log_model->record(
            $this->current_user->id, 'content.page_saved', 'managed_pages', $key,
            $before ? array('title' => $before->title, 'bytes' => strlen($before->body_html)) : null,
            array('title' => $title, 'bytes' => strlen($body)),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata('success',
            Managed_page_model::label($key).' updated. The change is live on the website now.');
        redirect('admin/pages/'.$key);
    }

    /** POST /admin/pages/:key/reset — drop the override. */
    public function page_reset($key) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('content.pages');
        if (!Managed_page_model::is_page($key)) show_404();

        $this->Managed_page_model->clear($key);
        $this->Audit_log_model->record(
            $this->current_user->id, 'content.page_reset', 'managed_pages', $key,
            null, null,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata('success',
            Managed_page_model::label($key).' restored to the text that ships with the panel.');
        redirect('admin/pages');
    }

    /** GET /admin/email-templates */
    public function email_templates() {
        $this->require_perm('settings.manage');
        $rows = $this->db->order_by('template_key', 'ASC')->get('email_templates')->result();
        $this->render('email', 'admin/content/email_templates', 'Email templates', array(
            'rows' => $rows,
            'page_description' => 'Subjects and bodies used by MailService. Variables like {{username}} stay in the text.',
        ));
    }

    /**
     * GET /admin/mail-queue — what the panel has tried to send.
     *
     * Delivery is asynchronous, so without this screen a failed email is
     * invisible: staff cannot see that a customer never received their
     * password reset, why it failed, or retry it.
     */
    public function mail_queue() {
        $this->require_perm('settings.manage');

        $status = strtoupper((string)$this->input->get('status', true));
        $allowed = array('QUEUED', 'SENDING', 'SENT', 'FAILED');
        if (!in_array($status, $allowed, true)) $status = '';

        if ($status !== '') $this->db->where('status', $status);
        $rows = $this->db->order_by('id', 'DESC')->limit(100)->get('email_queue')->result();

        $counts = array();
        foreach ($allowed as $s) {
            $counts[$s] = (int)$this->db->where('status', $s)->count_all_results('email_queue');
        }

        $this->load->library('MailService');
        $this->render('email', 'admin/content/mail_queue', 'Mail queue', array(
            'rows'      => $rows,
            'counts'    => $counts,
            'status'    => $status,
            'transport' => $this->mailservice->transport(),
            'page_description' => 'Every message the panel has queued, with the delivery error when there '
                                  .'is one. Retry puts a failed message back in the queue for the next cron run.',
        ));
    }

    /** POST /admin/mail-queue/:id/retry — put a failed message back in the queue. */
    public function retry_mail($id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');

        $row = $this->db->where('id', (int)$id)->get('email_queue')->row();
        if (!$row) show_404();
        if ($row->status === 'SENT') {
            $this->session->set_flashdata('error', 'That message was already delivered.');
            return redirect('admin/mail-queue');
        }

        // attempts is reset so the worker's backoff starts again from zero;
        // leaving it at the cap would re-fail the message immediately.
        $this->db->where('id', $row->id)->update('email_queue', array(
            'status'       => 'QUEUED',
            'attempts'     => 0,
            'scheduled_at' => gmdate('Y-m-d H:i:s'),
            'last_error'   => null,
        ));
        $this->Audit_log_model->record(
            $this->current_user->id, 'mail.requeued', 'email_queue', (string)$row->id,
            array('status' => $row->status, 'attempts' => (int)$row->attempts), array('status' => 'QUEUED'),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
        $this->session->set_flashdata('success', 'Message re-queued. The next cron run will try again.');
        redirect('admin/mail-queue');
    }

    /**
     * POST /admin/mail-queue/test — prove the mail transport works.
     *
     * Sends immediately rather than queueing: the operator is standing in
     * front of the screen waiting for an answer, and "queued" would tell them
     * nothing about whether SMTP accepts the message.
     */
    public function test_mail() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');

        $to = trim((string)$this->input->post('to', true));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->session->set_flashdata('error', 'Enter a valid email address to send the test to.');
            return redirect('admin/mail-queue');
        }

        $this->load->library('MailService');
        $res = $this->mailservice->send_test($to);

        $this->Audit_log_model->record(
            $this->current_user->id, 'mail.test_sent', 'email_queue', null, null,
            array('to' => $to, 'ok' => !empty($res['ok']), 'transport' => $res['transport'] ?? null),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        if (empty($res['ok'])) {
            // MailService::smtp_failure_summary() already pulled the real
            // failure out of CI3's debug buffer (the banner is a success, not
            // the error) and paired it with a hint — append it, it is what
            // turns "535" into a cPanel menu the operator can click through.
            $reason = trim(strtok((string)($res['error'] ?? 'unknown error'), "\r\n"));
            $flash  = 'Test failed via '.($res['transport'] ?? 'unknown')
                .': '.mb_substr($reason, 0, 300);
            if (!empty($res['hint'])) $flash .= ' — '.(string)$res['hint'];
            $this->session->set_flashdata('error', $flash);
        } else {
            $this->session->set_flashdata('success', 'Test message accepted by the '
                .$res['transport'].' transport. Check '.$to.'.');
        }
        redirect('admin/mail-queue');
    }

    /** POST /admin/email-templates/:id */
    /**
     * POST /admin/email-templates/create — add a template.
     *
     * The screen used to be edit-only: the operator could change the six
     * seeded templates but never add one, which left the contact-inbox reply
     * picker ("start from a template") frozen at whatever shipped. Keys are
     * operator-chosen so the picker can grow with the business; the reply
     * picker lists any active key beginning "contact.reply".
     */
    public function create_email_template() {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');

        $key = strtolower(trim((string)$this->input->post('template_key', true)));
        if (!preg_match('/^[a-z0-9][a-z0-9_.]{2,126}$/', $key)) {
            $this->session->set_flashdata('error',
                'The key must be 3–127 characters of lowercase letters, numbers, dots or underscores (e.g. contact.reply_promo).');
            return redirect('admin/email-templates');
        }
        $subject = trim((string)$this->input->post('subject', true));
        if ($subject === '' || mb_strlen($subject) > 255) {
            $this->session->set_flashdata('error', 'A subject is required (max 255 characters).');
            return redirect('admin/email-templates');
        }
        if ($this->db->where('template_key', $key)->get('email_templates')->row()) {
            $this->session->set_flashdata('error', 'A template called '.$key.' already exists.');
            return redirect('admin/email-templates');
        }

        $this->db->insert('email_templates', array(
            'template_key' => $key,
            'subject'      => $subject,
            'body_html'    => (string)$this->input->post('body_html', false),
            'body_text'    => (string)$this->input->post('body_text', true),
            'is_active'    => $this->input->post('is_active') ? 1 : 0,
        ));

        $this->Audit_log_model->record(
            $this->current_user->id, 'email_template.created', 'email_templates',
            (string)$this->db->insert_id(),
            null, array('template_key' => $key, 'subject' => $subject),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );

        $this->session->set_flashdata('success',
            'Template '.$key.' created'.(strpos($key, 'contact.reply') === 0 ? ' — it now appears in the contact reply picker.' : '.'));
        redirect('admin/email-templates');
    }

    public function save_email_template($id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm('settings.manage');
        $row = $this->db->where('id', (int)$id)->get('email_templates')->row();
        if (!$row) show_404();
        $this->db->where('id', $row->id)->update('email_templates', array(
            'subject'   => mb_substr(trim((string)$this->input->post('subject', true)), 0, 255),
            'body_html' => (string)$this->input->post('body_html', false),
            'body_text' => (string)$this->input->post('body_text', true),
            'is_active' => $this->input->post('is_active') ? 1 : 0,
        ));
        $this->Audit_log_model->record(
            $this->current_user->id, 'email_template.updated', 'email_templates', (string)$row->id,
            array('subject' => $row->subject), array('subject' => $this->input->post('subject', true)),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
        $this->session->set_flashdata('success', 'Template '.$row->template_key.' saved.');
        redirect('admin/email-templates');
    }

    public function index() {
        redirect('admin/blog');
    }

    /** GET /admin/{blog,faq,announcements} — the list for one domain. */
    public function domain($domain) {
        if (!ContentService::is_domain($domain)) show_404();
        $this->require_perm(ContentService::permission($domain));

        $filters = $this->filters($domain);
        $page    = max(1, (int)$this->input->get('page'));
        $limit   = self::PER_PAGE;
        $grid    = $this->contentservice->grid($domain, $filters, $limit, ($page - 1) * $limit);
        $total   = (int)$grid['total'];

        $this->render($domain, 'admin/content/index', ContentService::label($domain), array(
            'rows'        => $grid['rows'],
            'filters'     => $filters,
            'categories'  => $domain === 'blog' ? $this->contentservice->categories()
                                                : $this->Faq_model->all_categories(),
            'counts'      => $domain === 'blog' ? $this->Blog_post_model->status_counts() : array(),
            'page'        => $page,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ));
    }

    /** GET /admin/{domain}/new — an empty editor. */
    public function create_form($domain) {
        if (!ContentService::is_domain($domain)) show_404();
        $this->require_perm(ContentService::permission($domain));

        $this->render($domain, 'admin/content/edit', 'New '.ContentService::label($domain), array(
            'row'        => null,
            'categories' => $domain === 'blog' ? $this->contentservice->categories()
                                               : $this->Faq_model->all_categories(),
        ));
    }

    /** GET /admin/{domain}/:handle — the editor for one item. */
    public function edit($domain, $handle) {
        if (!ContentService::is_domain($domain)) show_404();
        $this->require_perm(ContentService::permission($domain));

        $row = $this->contentservice->find($domain, $handle);
        if (!$row) show_404();

        $this->render($domain, 'admin/content/edit',
            $domain === 'faq' ? 'Edit FAQ' : ($row->title ?? 'Edit'), array(
                'row'        => $row,
                'categories' => $domain === 'blog' ? $this->contentservice->categories()
                                                   : $this->Faq_model->all_categories(),
            ));
    }

    /* ------------------------------ actions ----------------------------- */

    /** POST /admin/{domain}/create */
    public function create($domain) {
        $this->guard($domain);

        $res = $this->contentservice->save($domain, null, $this->input->post(null, true),
            $this->current_user->id);
        if (empty($res['ok'])) return $this->fail($domain, $res['error']);

        $this->audit($domain.'.created', $domain, $res['row'], null, $this->row($res['row']));
        $this->flash($res, ContentService::label($domain).' item created.');
        redirect('admin/'.$domain.'/'.$this->handle($domain, $res['row']));
    }

    /** POST /admin/{domain}/:handle/update */
    public function update($domain, $handle) {
        $row = $this->guard($domain, $handle);

        $res = $this->contentservice->save($domain, $row, $this->input->post(null, true),
            $this->current_user->id);
        if (empty($res['ok'])) return $this->fail($domain, $res['error'], $handle);

        $this->audit($domain.'.updated', $domain, $res['row'], $res['before'], $this->row($res['row']));
        $this->flash($res, 'Saved.');
        redirect('admin/'.$domain.'/'.$this->handle($domain, $res['row']));
    }

    /** POST /admin/{domain}/:handle/status — the on/off switch. */
    public function status($domain, $handle) {
        $row    = $this->guard($domain, $handle);
        $active = $this->input->post('is_active') === '1';

        $res = $this->contentservice->set_active($domain, $row, $active);
        if (empty($res['ok'])) return $this->fail($domain, $res['error'], $handle);

        $this->audit($domain.'.'.($active ? 'shown' : 'hidden'), $domain, $row,
            $res['before'], $res['after']);
        $this->session->set_flashdata('success', $active ? 'Now visible.' : 'Hidden from customers.');
        redirect('admin/'.$domain);
    }

    /** POST /admin/{domain}/:handle/delete */
    public function delete($domain, $handle) {
        $row = $this->guard($domain, $handle);

        $res = $this->contentservice->delete($domain, $row);
        if (empty($res['ok'])) return $this->fail($domain, $res['error'], $handle);

        $this->audit($domain.'.deleted', $domain, $row, $this->row($row), null);
        $this->session->set_flashdata('success', 'Deleted.');
        redirect('admin/'.$domain);
    }

    /* ------------------------------ helpers ----------------------------- */

    private function filters($domain) {
        $f = array(
            'status' => $this->input->get('status', true),
            'search' => $this->input->get('q', true),
        );
        if ($domain === 'blog') {
            $f['category_id'] = (int)$this->input->get('category');
        } elseif ($domain === 'faq') {
            $f['category'] = $this->input->get('category', true);
        } elseif ($domain === 'announcements') {
            $f['severity'] = $this->input->get('severity', true);
            $f['audience'] = $this->input->get('audience', true);
        }
        return $f;
    }

    /** Blog and announcements are addressed by public_id; FAQs by id. */
    private function handle($domain, $row) {
        return $domain === 'faq' ? $row->id : $row->public_id;
    }

    private function render($domain, $view, $title, array $data) {
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/blog',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'domain'       => $domain,
            'domains'      => ContentService::domains(),
        ), $data));
    }

    /** POST-only + permission + existence, shared by every mutation. */
    private function guard($domain, $handle = null) {
        if ($this->input->method(true) !== 'POST') show_404();
        if (!ContentService::is_domain($domain)) show_404();
        $this->require_perm(ContentService::permission($domain));
        if ($handle === null) return null;

        $row = $this->contentservice->find($domain, $handle);
        if (!$row) show_404();
        return $row;
    }

    private function fail($domain, $message, $handle = null) {
        $this->session->set_flashdata('error', $message);
        redirect('admin/'.$domain.($handle !== null ? '/'.$handle : ''));
    }

    private function flash(array $res, $message) {
        $this->session->set_flashdata('success', $message);
        if (!empty($res['warnings'])) {
            $this->session->set_flashdata('warning', implode(' ', $res['warnings']));
        }
    }

    private function row($row) {
        if (!$row) return null;
        $out = get_object_vars($row);
        // The body can be tens of kilobytes; an audit entry does not need it.
        foreach (array('content', 'answer') as $big) {
            if (isset($out[$big])) $out[$big] = '['.strlen((string)$out[$big]).' bytes]';
        }
        return $out;
    }

    private function audit($action, $domain, $row, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, $this->contentservice->table($domain),
            $row ? (string)$row->id : null,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
