<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ContentService — blog posts, FAQ entries and announcements.
 *
 * Three small catalogues with one controller between them, for the same
 * reason Admin → Catalogue covers four product domains: the operator's job is
 * identical in each (write it, order it, publish it), and three near-copies
 * would drift.
 *
 * The part worth care is not the CRUD, it is **what happens to the HTML**.
 * `views/public/blog/detail.php` renders a post's body unescaped — it is a
 * rich-text field and has to be — with the comment "stored as trusted HTML by
 * staff". That makes this screen the one place where a staff account with
 * `blog.manage` can put script on a public page seen by every visitor, and
 * "trusted" is doing a lot of work in that sentence: it means a stolen editor
 * session becomes stored XSS against the whole site.
 *
 * So content is sanitised **on the way in**, by allow-list:
 *   - only known-safe tags survive;
 *   - `on*` handlers, `javascript:`/`data:` URLs, `<script>`, `<iframe>`,
 *     `<object>`, `<embed>`, `<form>` and `<style>` are stripped;
 *   - the stored value is the sanitised one, so the public page cannot render
 *     something the editor never saw.
 *
 * The panel's nonce-based CSP is a real second layer here — an injected
 * inline script would be refused by the browser — but a CSP is a mitigation,
 * not a licence to store hostile markup, and it does not protect against a
 * misconfigured deploy or an admin-page render.
 */
class ContentService {

    /** Tags a staff author may use in a post body or an announcement. */
    const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote>'
        .'<h2><h3><h4><a><code><pre><hr><table><thead><tbody><tr><th><td><img><figure><figcaption>';

    /** Anything here is removed with its content, not merely unwrapped. */
    private static $strip_whole = array('script', 'style', 'iframe', 'object', 'embed', 'form', 'noscript');

    public static function domains() {
        return array(
            'blog'          => 'Blog posts',
            'faq'           => 'FAQ',
            'announcements' => 'Announcements',
        );
    }

    public static function is_domain($d) {
        return array_key_exists((string)$d, self::domains());
    }

    public static function label($d) {
        $m = self::domains();
        return isset($m[$d]) ? $m[$d] : ucfirst((string)$d);
    }

    /** The permission that governs each domain. */
    public static function permission($d) {
        $m = array('blog' => 'blog.manage', 'faq' => 'faq.manage',
                   'announcements' => 'announcements.manage');
        return isset($m[$d]) ? $m[$d] : 'blog.manage';
    }

    const BLOG_STATUSES  = array('DRAFT', 'PUBLISHED', 'ARCHIVED');
    const SEVERITIES     = array('INFO', 'SUCCESS', 'WARNING', 'CRITICAL');
    const AUDIENCES      = array('all', 'customers', 'staff');

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array(
            'Blog_post_model', 'Blog_category_model', 'Faq_model', 'Announcement_model',
        ));
    }

    /* ------------------------------ reading ----------------------------- */

    public function model($domain) {
        switch ($domain) {
            case 'blog':          return $this->ci->Blog_post_model;
            case 'faq':           return $this->ci->Faq_model;
            case 'announcements': return $this->ci->Announcement_model;
        }
        throw new InvalidArgumentException('Unknown content domain: '.$domain);
    }

    public function table($domain) {
        $m = array('blog' => 'blog_posts', 'faq' => 'faqs', 'announcements' => 'announcements');
        return isset($m[$domain]) ? $m[$domain] : $domain;
    }

    public function grid($domain, array $filters = array(), $limit = 25, $offset = 0) {
        $model = $this->model($domain);
        return array(
            'rows'  => $model->admin_search($filters, $limit, $offset),
            'total' => $model->admin_count($filters),
        );
    }

    /**
     * One row by its public handle.
     *
     * FAQs have no public_id column — they are ordered list items, not
     * addressable documents — so they are found by id.
     */
    public function find($domain, $handle) {
        if ($domain === 'faq') {
            return ctype_digit((string)$handle) ? $this->ci->Faq_model->find_by_id((int)$handle) : null;
        }
        return $this->model($domain)->find_by_public_id($handle);
    }

    public function categories() {
        return $this->ci->Blog_category_model->all_with_counts();
    }

    /* ------------------------------ writing ----------------------------- */

    /** Create or update, dispatching to the per-domain rules. */
    public function save($domain, $existing, array $input, $author_id = null) {
        switch ($domain) {
            case 'blog':          return $this->save_post($existing, $input, $author_id);
            case 'faq':           return $this->save_faq($existing, $input);
            case 'announcements': return $this->save_announcement($existing, $input);
        }
        return $this->err('NO_DOMAIN', 'Unknown content type.');
    }

    private function save_post($existing, array $in, $author_id) {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') return $this->err('INVALID', 'A title is required.');

        $status = strtoupper(trim((string)($in['status'] ?? 'DRAFT')));
        if (!in_array($status, self::BLOG_STATUSES, true)) {
            return $this->err('INVALID', 'Unknown status "'.$status.'".');
        }

        $body = $this->sanitize_html((string)($in['content'] ?? ''));
        if (trim(strip_tags($body)) === '') {
            return $this->err('INVALID', 'The post body cannot be empty.');
        }

        $slug = $this->slug($in['slug'] ?? $title);
        if ($slug === '') return $this->err('INVALID', 'The slug could not be derived from the title.');
        if ($this->ci->Blog_post_model->slug_taken($slug, $existing ? $existing->id : null)) {
            return $this->err('DUPLICATE', 'Another post already uses the slug "'.$slug.'".');
        }

        $category_id = ($in['category_id'] ?? '') === '' ? null : (int)$in['category_id'];
        if ($category_id && !$this->ci->Blog_category_model->find_by_id($category_id)) {
            return $this->err('INVALID', 'That category no longer exists.');
        }

        $row = array(
            'title'            => mb_substr($title, 0, 255),
            'slug'             => $slug,
            'excerpt'          => $this->plain($in['excerpt'] ?? '', 500),
            'content'          => $body,
            'featured_image'   => $this->url($in['featured_image'] ?? ''),
            'meta_title'       => $this->plain($in['meta_title'] ?? '', 255),
            'meta_description' => $this->plain($in['meta_description'] ?? '', 500),
            'status'           => $status,
            'category_id'      => $category_id,
        );

        // published_at is what the public query filters on, so a post that is
        // PUBLISHED with a NULL date would be invisible and look like a bug.
        // Setting it on first publish, and keeping it afterwards, means
        // re-editing a live post does not silently re-date it.
        if ($status === 'PUBLISHED') {
            $when = trim((string)($in['published_at'] ?? ''));
            if ($when !== '') {
                $ts = strtotime($when);
                if ($ts === false) return $this->err('INVALID', 'The publish date could not be read.');
                $row['published_at'] = gmdate('Y-m-d H:i:s', $ts);
            } elseif (!$existing || empty($existing->published_at)) {
                $row['published_at'] = gmdate('Y-m-d H:i:s');
            }
        }

        $warnings = array();
        if ($status === 'PUBLISHED' && !empty($row['published_at'])
            && $row['published_at'] > gmdate('Y-m-d H:i:s')) {
            $warnings[] = 'This post is dated in the future, so it stays hidden until then.';
        }
        if ($row['excerpt'] === null || $row['excerpt'] === '') {
            $warnings[] = 'Without an excerpt the listing page will show a trimmed slice of the body.';
        }

        return $this->persist('blog', $existing, $row, $author_id, $warnings);
    }

    private function save_faq($existing, array $in) {
        $question = $this->plain($in['question'] ?? '', 1000);
        $answer   = $this->sanitize_html((string)($in['answer'] ?? ''));
        if ($question === null || $question === '') return $this->err('INVALID', 'A question is required.');
        if (trim(strip_tags($answer)) === '')       return $this->err('INVALID', 'An answer is required.');

        $row = array(
            'question'  => $question,
            'answer'    => $answer,
            'category'  => $this->plain($in['category'] ?? '', 64),
            'sorting'   => (int)($in['sorting'] ?? 0),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        );
        return $this->persist('faq', $existing, $row, null, array());
    }

    private function save_announcement($existing, array $in) {
        $title = $this->plain($in['title'] ?? '', 255);
        if ($title === null || $title === '') return $this->err('INVALID', 'A title is required.');

        $body = $this->sanitize_html((string)($in['content'] ?? ''));
        if (trim(strip_tags($body)) === '') return $this->err('INVALID', 'The announcement body cannot be empty.');

        $severity = strtoupper(trim((string)($in['severity'] ?? 'INFO')));
        if (!in_array($severity, self::SEVERITIES, true)) {
            return $this->err('INVALID', 'Unknown severity "'.$severity.'".');
        }
        $audience = strtolower(trim((string)($in['audience'] ?? 'all')));
        if (!in_array($audience, self::AUDIENCES, true)) {
            return $this->err('INVALID', 'Unknown audience "'.$audience.'".');
        }

        $starts = $this->datetime($in['starts_at'] ?? '');
        $ends   = $this->datetime($in['ends_at'] ?? '');
        if ($starts === false || $ends === false) {
            return $this->err('INVALID', 'The start or end time could not be read.');
        }
        // A window that closes before it opens shows the banner to nobody,
        // which reads as a broken feature rather than a typo.
        if ($starts && $ends && $starts > $ends) {
            return $this->err('INVALID', 'The announcement ends before it starts.');
        }

        $row = array(
            'title'     => $title,
            'content'   => $body,
            'severity'  => $severity,
            'audience'  => $audience,
            'starts_at' => $starts ?: null,
            'ends_at'   => $ends ?: null,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        );

        $warnings = array();
        if (!empty($row['is_active']) && $ends && $ends < gmdate('Y-m-d H:i:s')) {
            $warnings[] = 'The end time is in the past, so this announcement is already hidden.';
        }
        return $this->persist('announcements', $existing, $row, null, $warnings);
    }

    /** Switch an FAQ or announcement on or off without reopening the form. */
    public function set_active($domain, $row, $active) {
        if (!in_array($domain, array('faq', 'announcements'), true)) {
            return $this->err('NO_DOMAIN', 'That content type has no on/off switch.');
        }
        $before = array('is_active' => (int)$row->is_active);
        $this->ci->db->where('id', $row->id)->update($this->table($domain),
            array('is_active' => $active ? 1 : 0));
        return array('ok' => true, 'error' => null, 'code' => null, 'warnings' => array(),
                     'before' => $before, 'after' => array('is_active' => $active ? 1 : 0),
                     'row' => $this->reload($domain, $row->id), 'created' => false);
    }

    /**
     * Delete a row.
     *
     * Content is the one area where deletion is right: an unpublished draft or
     * a stale FAQ has no financial history hanging off it, unlike an order or
     * a user. A *published* post is archived instead, so an inbound link does
     * not start 404ing because someone tidied up.
     */
    public function delete($domain, $row) {
        if ($domain === 'blog' && (string)$row->status === 'PUBLISHED') {
            return $this->err('PUBLISHED',
                'Archive this post instead — deleting it would break any link already pointing at it.');
        }
        $this->ci->db->where('id', $row->id)->delete($this->table($domain));
        return array('ok' => true, 'error' => null, 'code' => null, 'warnings' => array(),
                     'before' => null, 'after' => null, 'row' => null, 'created' => false);
    }

    /* ------------------------------ helpers ----------------------------- */

    private function persist($domain, $existing, array $row, $author_id, array $warnings) {
        $table = $this->table($domain);
        $now   = gmdate('Y-m-d H:i:s');
        $before = $existing ? get_object_vars($existing) : null;

        if ($existing) {
            $row['updated_at'] = $now;
            // updated_at exists on blog_posts and faqs but not announcements.
            if ($domain === 'announcements') unset($row['updated_at']);
            $this->ci->db->where('id', $existing->id)->update($table, $row);
            $id = $existing->id;
        } else {
            $row['created_at'] = $now;
            if ($domain !== 'announcements') $row['updated_at'] = $now;
            if ($domain !== 'faq')           $row['public_id']  = $this->public_id();
            if ($domain === 'blog')          $row['author_id']  = $author_id;
            $this->ci->db->insert($table, $row);
            $id = $this->ci->db->insert_id();
        }

        return array('ok' => true, 'error' => null, 'code' => null,
                     'warnings' => $warnings, 'before' => $before,
                     'row' => $this->reload($domain, $id), 'created' => !$existing);
    }

    private function reload($domain, $id) {
        return $this->ci->db->where('id', $id)->get($this->table($domain))->row();
    }

    /**
     * Strip everything outside the allow-list.
     *
     * `strip_tags()` alone is not enough: it removes disallowed *tags* but
     * leaves the text inside `<script>` on the page, and it does nothing about
     * `onclick=` or `href="javascript:"` on tags that are allowed.
     */
    public function sanitize_html($html) {
        $html = (string)$html;
        if (trim($html) === '') return '';

        // 1. Remove dangerous elements together with their contents.
        foreach (self::$strip_whole as $tag) {
            $html = preg_replace('~<'.$tag.'\b[^>]*>.*?</'.$tag.'\s*>~is', '', $html);
            // ...including an unclosed one, which a browser would still honour.
            $html = preg_replace('~<'.$tag.'\b[^>]*/?>~i', '', $html);
        }

        // 2. Reduce to the allowed tag set.
        $html = strip_tags($html, self::ALLOWED_TAGS);

        // 3. Drop event handlers and script-bearing URLs from what survives.
        $html = preg_replace('~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);
        $html = preg_replace('~\s(href|src)\s*=\s*("|\')?\s*(javascript|vbscript|data)\s*:[^"\'>\s]*("|\')?~i',
            ' $1="#"', $html);
        // srcdoc smuggles a whole document into an allowed element.
        $html = preg_replace('~\ssrcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);

        return trim($html);
    }

    /** Plain text: tags removed entirely, never rendered as markup. */
    private function plain($v, $max) {
        $v = trim(strip_tags((string)$v));
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    }

    /** A URL for an image field — same scheme rules as the sanitiser. */
    private function url($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (preg_match('~^\s*(javascript|vbscript|data)\s*:~i', $v)) return null;
        return mb_substr($v, 0, 512);
    }

    /** '' → null, a parseable date → UTC, anything else → false. */
    private function datetime($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts === false ? false : gmdate('Y-m-d H:i:s', $ts);
    }

    /** URL-safe slug, matching the shape the public routes expect. */
    public function slug($v) {
        $v = strtolower(trim((string)$v));
        $v = preg_replace('~[^a-z0-9]+~', '-', $v);
        return trim(preg_replace('~-+~', '-', $v), '-');
    }

    private function public_id() {
        return strtoupper(bin2hex(random_bytes(13)));
    }

    private function err($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code,
                     'warnings' => array(), 'before' => null, 'row' => null, 'created' => false);
    }
}
