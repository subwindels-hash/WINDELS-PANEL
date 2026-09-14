<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Email — a hardened SMTP greeting for shared hosting.
 *
 * CI3 loads this automatically (subclass_prefix = MY_) whenever a caller
 * does `$this->load->library('email')`. It changes three things about the
 * SMTP handshake, all driven by production mail-queue failures:
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
 *
 *   3. **An undiagnosed silent drop, behind the classic report:**
 *
 *          hello: hello: Unable to send email using PHP SMTP. Your server
 *          might not be configured to send mail using this method.
 *
 *      Two greeting attempts with EMPTY replies, then CI3's generic tail.
 *      An empty reply to EHLO on a socket that also produced no banner means
 *      the port never spoke SMTP at the byte level — the textbook cause is a
 *      port/encryption mismatch: the operator pointed plain/STARTTLS config
 *      at an implicit-SSL port (465), so the server was waiting for a TLS
 *      ClientHello while the client sent `EHLO` in cleartext, and every
 *      greeting vanished into an ignored or reset connection. Stock CI3
 *      never says any of that; each handshake stage below records a
 *      machine-readable `handshake_failure` plus a `mail: <code> — …` error
 *      line that MailService turns into a retry and an operator hint.
 */
class MY_Email extends CI_Email {

    /**
     * The name used for the EHLO/HELO greeting. Empty = stock behaviour.
     * Set by MailService before send; public so the caller can pin it.
     */
    public $helo_host = '';

    /**
     * Machine-readable handshake diagnosis, set when the connection dies
     * before/during the greeting or TLS setup:
     *
     *   ''                       no handshake failure observed
     *   'no-smtp-banner'         TCP connected but the server never sent an
     *                            SMTP banner (port speaks another protocol)
     *   'silent-greeting'        the server closed the connection in reply
     *                            to EHLO/HELO without saying anything
     *   'starttls-refused'       the server answered STARTTLS with an error
     *   'tls-negotiation-failed' stream_socket_enable_crypto() failed
     *
     * MailService keys the crypto-swap retry and the operator hint on this;
     * public so the caller can read it after send() returns FALSE.
     *
     * @var string
     */
    public $handshake_failure = '';

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
     * Drop the current connection so a retry starts from a clean socket.
     *
     * No QUIT is sent: reset_connection() is only called after a handshake
     * failure, i.e. on a socket whose peer stopped talking (the silent-drop
     * case would block on the reply read for the full smtp_timeout). The
     * HELO fallback may have disabled `_smtp_auth` for the old session —
     * recompute it from the configured credentials so the retry can
     * authenticate again. Message state (from/to/subject/body) is NOT
     * touched; callers re-prime via clear() + the usual setters.
     */
    public function reset_connection() {
        if (is_resource($this->_smtp_connect)) {
            @fclose($this->_smtp_connect);
        }
        $this->_smtp_connect = '';
        $this->_greeted = false;
        $this->handshake_failure = '';
        $this->_smtp_auth = isset($this->smtp_user[0], $this->smtp_pass[0]);
    }

    /**
     * CI3's SMTP connect, with the RFC 5321 §4.1.4 EHLO→HELO fallback and a
     * diagnosis for every way the handshake can die.
     *
     * Same shape as the parent except: a refused EHLO is retried as HELO
     * before the connect is reported as failed, an empty banner aborts the
     * attempt immediately (two greeting timeouts would only repeat what the
     * missing banner already proved — the port is not plain SMTP), a refused
     * STARTTLS is checked (stock CI3 ignores the reply and lets the crypto
     * call fail), and each failure records `handshake_failure` + a
     * `mail: <code>` debug line for MailService's summary.
     */
    protected function _smtp_connect() {
        $this->_greeted = false;
        $this->handshake_failure = '';

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

        $banner = $this->_get_smtp_data();
        $this->_set_error_message($banner);

        if (trim($banner) === '') {
            // The server accepted TCP but never spoke SMTP. Named for the
            // operator's log, this is the "hello: hello: Unable to send
            // email using PHP SMTP" report: usually an implicit-SSL port
            // (465) reached with plain/STARTTLS settings — the server sat
            // waiting for a TLS ClientHello the client never sent. Bailing
            // now skips two pointless greeting timeouts per queued message.
            $this->handshake_failure = 'no-smtp-banner';
            $this->_set_error_message(
                'mail: no-smtp-banner — the server accepted the connection but never sent an SMTP '
                .'banner. This port is not speaking plain SMTP: pair port 465 with '
                .'VP_MAIL_CRYPTO=ssl (implicit TLS from the first byte), or port 587 with '
                .'VP_MAIL_CRYPTO=tls (plain banner + STARTTLS).'
            );
            return FALSE;
        }

        if ($this->smtp_crypto === 'tls') {
            if ( ! $this->_hello()) {
                return FALSE;
            }

            if ( ! $this->_send_command('starttls')) {
                $this->handshake_failure = 'starttls-refused';
                $this->_set_error_message(
                    'mail: starttls-refused — the server refused STARTTLS on this port. This host '
                    .'likely expects implicit TLS instead: set VP_MAIL_CRYPTO=ssl with '
                    .'VP_MAIL_PORT=465 (the pairing cPanel → Email Accounts → Connect Devices lists).'
                );
                return FALSE;
            }

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
            $crypto = @stream_socket_enable_crypto($this->_smtp_connect, TRUE, $method);

            if ($crypto !== TRUE) {
                $this->handshake_failure = 'tls-negotiation-failed';
                $this->_set_error_message(
                    'mail: tls-negotiation-failed — TLS negotiation failed after the server accepted '
                    .'STARTTLS. Check VP_MAIL_HOST / VP_MAIL_PORT / VP_MAIL_CRYPTO against cPanel → '
                    .'Email Accounts → Connect Devices; if this is port 465, use VP_MAIL_CRYPTO=ssl '
                    .'(implicit TLS, no STARTTLS).'
                );
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

    /**
     * Send one greeting command and read its reply.
     *
     * An EMPTY reply is the silent-drop signature — the server closed the
     * connection without answering, which is what the classic
     * "hello: hello: Unable to send email using PHP SMTP" debug tail shows
     * (two empty hello lines). Record it as such; a port that swallows
     * greetings is a port speaking a different protocol (implicit SSL vs
     * STARTTLS), not a server that "is not configured to send mail".
     */
    private function _greet($word) {
        $this->_send_data($word.' '.$this->_get_hostname());
        $reply = $this->_get_smtp_data();
        $this->_debug_msg[] = '<pre>hello: '.$reply.'</pre>';

        if (trim($reply) === '' && $this->handshake_failure === '') {
            $this->handshake_failure = 'silent-greeting';
            $this->_set_error_message(
                'mail: silent-greeting — the server closed the connection without answering the '
                .'SMTP greeting (empty reply to '.$word.'). The port is speaking a different '
                .'protocol: pair port 465 with VP_MAIL_CRYPTO=ssl, or port 587 with '
                .'VP_MAIL_CRYPTO=tls.'
            );
        }
        return ((int) self::substr($reply, 0, 3) === 250);
    }

    /** Whether this connection passed a greeting (EHLO or HELO accepted). */
    public function greeted() {
        return $this->_greeted;
    }
}
