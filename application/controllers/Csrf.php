<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Csrf — hands the current CSRF token to JavaScript.
 *
 * A page that posts more than once (a reply box, a support/chat widget, any
 * fetch() call) needs a way to obtain a valid token without reloading. Without
 * one, the only options are to embed the token at render time and hope it is
 * still current, or to reload the whole page between messages.
 *
 * GET only, no side effects, and it exposes nothing an attacker could not
 * already get: the token is bound to the caller's own cookie, so a cross-origin
 * page cannot read this response (no CORS headers are sent) and a token
 * fetched by some other visitor is useless against this session.
 */
class Csrf extends MY_Controller {

    public function index() {
        if ($this->input->method(TRUE) !== 'GET') {
            show_404();
        }

        // Never cached: a proxy or the browser holding this response would
        // hand a retired token to the next request and reintroduce the very
        // failure this endpoint exists to prevent.
        $this->output
            ->set_header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0')
            ->set_header('Pragma: no-cache');

        $this->json_success(array(
            'name'      => $this->security->get_csrf_token_name(),
            'hash'      => $this->security->get_csrf_hash(),
            'header'    => MY_Security::TOKEN_HEADER,
            'expiresIn' => (int)$this->config->item('csrf_expire'),
        ));
    }
}
