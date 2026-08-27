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
