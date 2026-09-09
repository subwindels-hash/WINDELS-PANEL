<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Exceptions — 404s that leave a trail.
 *
 * A 404 is normally silent: CodeIgniter renders the page and the only record
 * is the webserver's access line, which says *that* a URL was requested but
 * not *why* nothing handled it. When an operator reports "entering the API
 * key returns 404", the first question is which URL was actually requested —
 * a stale form posting to a route that no longer exists looks identical to a
 * missing record until the URL is known. This override logs that URL (path
 * and query, never the body, so a pasted API key cannot reach the log) with
 * the method and referer, then defers to the stock renderer.
 */
class MY_Exceptions extends CI_Exceptions {

    /**
     * Log the full request line before rendering the stock 404 page.
     *
     * @param string $page      page URI
     * @param bool   $log_error whether to log the error
     */
    public function show_404($page = '', $log_error = TRUE) {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? $page);
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        log_message('error', '404: '.$method.' '.$uri
            .($referer !== '' ? ' (referer: '.$referer.')' : ''));

        return parent::show_404($page, $log_error);
    }
}
