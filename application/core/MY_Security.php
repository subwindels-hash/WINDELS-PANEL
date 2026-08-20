<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Security — CSRF that survives a page posting more than once.
 *
 * ## The bug this exists to kill
 *
 * CodeIgniter validates one CSRF token per request and, with
 * `csrf_regenerate` on, issues a **new** one on every POST. That is fine for
 * the classic post-then-redirect form: the redirect re-renders the page with
 * the fresh token. It is fatal for anything that posts twice from the *same*
 * rendered page — an AJAX reply box, a chat/support widget, a second browser
 * tab, or a customer who presses Back and submits again. The first post
 * succeeds and rotates the token; the second arrives holding the token that
 * has just been retired, CI3 rejects it, and whatever posted it reports its
 * own generic failure. The user-visible symptom is exactly *"the first message
 * works, the second one says something went wrong"*.
 *
 * Three changes fix that class of failure without weakening the protection:
 *
 * 1. **The token may travel in the `X-CSRF-TOKEN` header.** Stock CI3 only
 *    looks in the POST body, which is why JSON requests (`Content-Type:
 *    application/json` has no form fields at all) can never satisfy it. A
 *    header is same-origin-only in exactly the way a form field is, so this
 *    adds no cross-origin capability — it just gives non-form clients a place
 *    to put the value.
 *
 * 2. **A rejection is machine-readable, and carries a usable token.** Instead
 *    of CI3's HTML error page, an XHR/JSON caller gets HTTP 419 with
 *    `{ error: { code: "CSRF_EXPIRED" }, csrf: { name, hash } }`, so a widget
 *    can retry immediately rather than telling the customer to contact
 *    support. A browser form gets a readable page with a Reload button.
 *
 * 3. **Rotation is configurable** (`VP_CSRF_REGENERATE`, see config.php) and
 *    off by default, so a stable per-session token is the normal case.
 *
 * Nothing here relaxes the check itself: a request with no token, a wrong
 * token or an expired cookie is still refused.
 */
class MY_Security extends CI_Security {

    /** Header a non-form client may present the token in. */
    const TOKEN_HEADER = 'X-CSRF-TOKEN';

    /** Alternate spelling used by several JS libraries. */
    const TOKEN_HEADER_ALT = 'X-XSRF-TOKEN';

    /**
     * Accept a header-borne token, then defer to CodeIgniter.
     *
     * The header value is copied into `$_POST` under the configured token name
     * before the parent runs, so there is exactly one comparison of one token
     * in one place — the framework's. We never re-implement the compare.
     */
    public function csrf_verify() {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return parent::csrf_verify();
        }

        $name = $this->get_csrf_token_name();
        if ($name !== null && !isset($_POST[$name])) {
            $header = self::header_token();
            if ($header !== null) {
                $_POST[$name] = $header;
            }
        }
        return parent::csrf_verify();
    }

    /**
     * Refuse the request in a form the caller can act on.
     *
     * CI3 calls this from csrf_verify(); overriding it is the supported way to
     * change the response. Both branches hand back a *fresh* token: the whole
     * point is that the next attempt should be able to succeed.
     */
    public function csrf_show_error() {
        $hash = $this->get_csrf_hash();
        $name = $this->get_csrf_token_name();

        log_message('error', 'csrf: rejected '.($_SERVER['REQUEST_URI'] ?? '')
            .' from '.($_SERVER['REMOTE_ADDR'] ?? '?')
            .' (token '.(empty($_POST[$name]) ? 'absent' : 'stale/invalid').')');

        if (self::wants_json()) {
            self::send_status(419);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            echo json_encode(array(
                'success' => false,
                'error'   => array(
                    'code'    => 'CSRF_EXPIRED',
                    'message' => 'Your security token expired. The page has been given a new one — '
                                .'send the message again.',
                ),
                // A client that retries with these gets through without the
                // customer having to reload anything.
                'csrf'    => array('name' => $name, 'hash' => $hash),
            ), JSON_UNESCAPED_SLASHES);
            exit(1);
        }

        self::send_status(419);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><meta charset="utf-8"><title>Session token expired</title>'
            .'<style>body{font:16px/1.6 system-ui,sans-serif;margin:8vh auto;max-width:34rem;padding:0 1.5rem;color:#0f172a}'
            .'h1{font-size:1.35rem}a{display:inline-block;margin-top:1.25rem;background:#4f46e5;color:#fff;'
            .'text-decoration:none;border-radius:8px;padding:.6rem 1.2rem;font-weight:600}</style>'
            .'<h1>Your security token expired</h1>'
            .'<p>This happens when a page has been open for a long time, or when the same form is '
            .'submitted twice from two tabs. Nothing was lost and nothing is wrong with your account.</p>'
            .'<p>Reload the page and send it again.</p>'
            .'<a href="'.htmlspecialchars(self::back_url(), ENT_QUOTES, 'UTF-8').'">Reload the page</a>';
        exit(1);
    }

    /* ------------------------------------------------------------------ */

    /** The CSRF token presented in a request header, or NULL. */
    public static function header_token() {
        foreach (array(self::TOKEN_HEADER, self::TOKEN_HEADER_ALT) as $header) {
            $key = 'HTTP_'.str_replace('-', '_', strtoupper($header));
            if (!empty($_SERVER[$key])) {
                return trim((string)$_SERVER[$key]);
            }
        }
        return null;
    }

    /**
     * Would this caller rather have JSON than a web page?
     *
     * Deliberately generous: a widget that sets none of these headers still
     * gets the HTML page, which is readable, so the worst case of guessing
     * wrong is a slightly odd-looking response rather than a broken one.
     */
    public static function wants_json() {
        $requested_with = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requested_with === 'xmlhttprequest') return true;
        if (self::header_token() !== null) return true;

        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (strpos($accept, 'application/json') !== false) return true;

        $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (strpos($content_type, 'application/json') !== false) return true;

        // fetch() sets this on same-origin XHR-style requests.
        $mode = strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
        return $mode === 'cors' || $mode === 'same-origin';
    }

    private static function send_status($code) {
        if (headers_sent()) return;
        // 419 is not in the HTTP spec but is the de-facto "your CSRF token
        // expired" status (Laravel popularised it), and it is what JS clients
        // in the wild already special-case. Send the reason phrase explicitly
        // because PHP has no default for it.
        header('HTTP/1.1 '.$code.' Authentication Timeout', true, $code);
    }

    /** Somewhere safe to send a browser back to. */
    private static function back_url() {
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($referer !== '' && $host !== '') {
            $parts = parse_url($referer);
            if (!empty($parts['host']) && strcasecmp($parts['host'], preg_replace('/:\d+$/', '', $host)) === 0) {
                return $referer;
            }
        }
        return '/';
    }
}
