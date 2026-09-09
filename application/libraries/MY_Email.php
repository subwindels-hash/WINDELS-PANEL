<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Email — a hardened SMTP greeting for shared hosting.
 *
 * CI3 loads this automatically (subclass_prefix = MY_) whenever a caller
 * does `$this->load->library('email')`. It changes two things about the SMTP
 * handshake, both driven by a production failure in which the panel's mail
 * queue logged:
 *
 *     from: 503 HELO or EHLO required
 *     The following SMTP error was encountered: 503 HELO or EHLO required
 *     quit: 221 server315.web-hosting.com closing connection
 *
 * i.e. the server processed MAIL FROM while believing no EHLO/HELO had been
 * sent. Two stock-CI3 behaviours produce that class of failure:
 *
 *   1. **A junk greeting name under cron.** CI3's `_get_hostname()` returns
 *      `$_SERVER['SERVER_NAME']`, which does not exist in a cron/CLI request —
 *      so the panel greeted the server with `EHLO localhost.localdomain`, a
 *      name cPanel Exim setups with strict HELO checks can refuse or log as a
 *      protocol error. `helo_host` lets MailService pin the greeting to the
 *      panel's real domain (VP_MAIL_HELO, then the base URL's host).
 *
 *   2. **No RFC 5321 fallback.** RFC 5321 §4.1.4: when a server rejects EHLO,
 *      a client that does not require extensions MUST retry the greeting with
 *      HELO. Stock CI3 treats a refused EHLO as a failed connection and
 *      surfaces the refusal — and on hosts whose Exim answers EHLO harshly the
 *      conversation never reaches the greeting the server would have accepted.
 *      The fallback applies only when authentication is not configured (AUTH
 *      requires the EHLO-advertised capabilities).
 */
class MY_Email extends CI_Email {

    /**
     * The name used for the EHLO/HELO greeting. Empty = stock behaviour.
     * Set by MailService before send; public so the caller can pin it.
     */
    public $helo_host = '';

    /** @var bool tracks that the connection passed a greeting (see fallback). */
    private $_greeted = false;

    /**
     * A real, configured FQDN for the greeting; the stock hostname otherwise.
     */
    protected function _get_hostname() {
        if (is_string($this->helo_host) && trim($this->helo_host) !== '') {
            return trim($this->helo_host);
        }
        return parent::_get_hostname();
    }

    /**
     * CI3's SMTP connect, with the RFC 5321 §4.1.4 EHLO→HELO fallback.
     *
     * Identical to the parent except that a refused EHLO is retried as HELO
     * (when no authentication is configured) before the connect is reported
     * as failed. `_greeted` is exposed so MailService's failure summary can
     * tell a "503 HELO or EHLO required" apart from a real greeting failure.
     */
    protected function _smtp_connect() {
        $this->_greeted = false;

        if (is_resource($this->_smtp_connect)) {
            return TRUE;
        }

        $ssl = ($this->smtp_crypto === 'ssl') ? 'ssl://' : '';

        $this->_smtp_connect = $this->_open_smtp_socket($ssl);

        if ( ! is_resource($this->_smtp_connect)) {
            $this->_set_error_message('lang:email_smtp_error', 'connection failed');
            return FALSE;
        }

        stream_set_timeout($this->_smtp_connect, $this->smtp_timeout);
        $this->_set_error_message($this->_get_smtp_data());

        if ($this->smtp_crypto === 'tls') {
            if ( ! $this->_hello()) {
                return FALSE;
            }
            $this->_send_command('starttls');

            /**
             * STREAM_CRYPTO_METHOD_TLS_CLIENT is quite the mess ...
             *
             * - On PHP <5.6 it doesn't even mean TLS, but SSL 2.0, and there's no option to use actual TLS
             * - On PHP 5.6.0-5.6.6, >=7.2 it means negotiation with any of TLS 1.0, 1.1, 1.2
             * - On PHP 5.6.7-7.1.* it means only TLS 1.0
             *
             * We want the negotiation, so we'll force it below ...
             */
            $method = is_php('5.6')
                ? STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                : STREAM_CRYPTO_METHOD_TLS_CLIENT;
            $crypto = stream_socket_enable_crypto($this->_smtp_connect, TRUE, $method);

            if ($crypto !== TRUE) {
                $this->_set_error_message('lang:email_smtp_error', $this->_get_smtp_data());
                return FALSE;
            }
        }

        return $this->_hello();
    }

    /**
     * Open the TCP socket to the SMTP server. Isolated as a seam so tests
     * can point the rest of the (real) connect/greeting logic at a scripted
     * endpoint without touching the protocol code.
     */
    protected function _open_smtp_socket($ssl) {
        return fsockopen($ssl.$this->smtp_host,
                         $this->smtp_port,
                         $errno,
                         $errstr,
                         $this->smtp_timeout);
    }

    /**
     * The greeting exchange: EHLO (or HELO when extensions are not needed),
     * then — only when that was refused and no authentication is required —
     * the RFC 5321 §4.1.4 HELO fallback.
     */
    protected function _hello() {
        if ($this->_smtp_auth OR $this->_get_encoding() === '8bit') {
            $ok = $this->_greet('EHLO');
        } else {
            $ok = $this->_greet('HELO');
        }

        if ($ok) {
            $this->_greeted = true;
            return TRUE;
        }

        // A refused EHLO is not necessarily a dead connection: the server may
        // only accept the plain greeting. Try it once, exactly as the RFC
        // instructs. With authentication configured there is no point — AUTH
        // is an ESMTP extension and requires the EHLO the server just refused.
        if ($this->_smtp_auth OR $this->_get_encoding() === '8bit') {
            $this->_smtp_auth = FALSE; // plain greeting: no AUTH on this session
            if ($this->_greet('HELO')) {
                $this->_greeted = true;
                return TRUE;
            }
        }
        return FALSE;
    }

    /** Send one greeting command and read its reply. */
    private function _greet($word) {
        $this->_send_data($word.' '.$this->_get_hostname());
        $reply = $this->_get_smtp_data();
        $this->_debug_msg[] = '<pre>hello: '.$reply.'</pre>';
        return ((int) self::substr($reply, 0, 3) === 250);
    }

    /** Whether this connection passed a greeting (EHLO or HELO accepted). */
    public function greeted() {
        return $this->_greeted;
    }
}
