<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MailService — renders email_templates and enqueues the result into email_queue.
 *
 * Actual delivery is performed by a cron worker (Session 16) — the web/auth path
 * must never block on SMTP. In non-production environments the queued payload is
 * also written to the log when MAIL_LOG=1, which is how local/dev reads the
 * verify/reset links.
 */
class MailService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Setting_model');
    }

    /**
     * Enqueue a templated email.
     *
     * @param string $to
     * @param string $template_key  e.g. "auth.verify_email"
     * @param array  $variables     merged into {{placeholders}}
     * @param string $to_name
     * @return bool
     */
    public function enqueue_template($to, $template_key, array $variables = array(), $to_name = null) {
        $row = $this->ci->db->where('template_key', $template_key)
            ->where('is_active', 1)->get('email_templates')->row();
        if (!$row) {
            log_message('error', "mail: missing template {$template_key}");
            return false;
        }

        $variables = array_merge($this->global_variables(), $variables);
        $subject = $this->interpolate($row->subject, $variables);
        $html    = $this->interpolate($row->body_html, $variables);
        $text    = $row->body_text ? $this->interpolate($row->body_text, $variables) : trim(strip_tags($html));

        return $this->enqueue_raw($to, $subject, $html, $text, $to_name, $template_key);
    }

    /**
     * Enqueue a raw (already-rendered) email.
     */
    public function enqueue_raw($to, $subject, $body_html, $body_text = null,
                                 $to_name = null, $template_key = null) {
        $ok = (bool) $this->ci->db->insert('email_queue', array(
            'to_email'     => strtolower(trim($to)),
            'to_name'      => $to_name,
            'subject'      => $subject,
            'body_html'    => $body_html,
            'body_text'    => $body_text,
            'template_key' => $template_key,
            'status'       => 'QUEUED',
            'attempts'     => 0,
            'scheduled_at' => gmdate('Y-m-d H:i:s'),
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ));

        if ($ok && env_bool('MAIL_LOG')) {
            log_message('info', "mail queued to {$to} <{$subject}>\n".strip_tags($body_html));
        }
        return $ok;
    }

    /**
     * Deliver one queued message (called by the email_queue cron worker).
     *
     * Transport is chosen from settings, falling back to VP_MAIL_DRIVER in
     * .env so a fresh deployment sends mail without an admin first visiting a
     * settings screen: `mail` hands off to PHP's mail() (what a cPanel account
     * has working out of the box), `smtp` uses CI3's email library against the
     * configured server, `log` writes the payload to the log (the default in
     * dev, and what the verify/reset links are read from locally). The queue
     * row itself is managed by the worker — this method only attempts delivery
     * and reports the outcome.
     *
     * @return array{ok:bool, transport?:string, error?:string}
     */
    public function deliver($mail) {
        if (!$mail || empty($mail->to_email)) {
            return array('ok'=>false, 'error'=>'missing recipient');
        }
        $transport = $this->transport();

        if ($transport === 'log' || env_bool('MAIL_LOG')) {
            log_message('info', sprintf(
                "mail[log] to=%s subject=%s\n%s",
                $mail->to_email, $mail->subject, strip_tags((string)$mail->body_html)
            ));
            if ($transport === 'log') return array('ok'=>true, 'transport'=>'log');
        }

        if ($transport !== 'smtp' && $transport !== 'mail') {
            return array('ok'=>false, 'error'=>"unknown mail transport '{$transport}'");
        }

        try {
            $this->ci->load->library('email');
            $this->ci->email->clear(true);
            // DB settings win (admin-editable); .env supplies the initial
            // defaults (VP_MAIL_FROM_ADDRESS / VP_MAIL_FROM_NAME).
            $from_email = class_exists('Env') ? (string) Env::get('MAIL_FROM_ADDRESS', '') : '';
            $from_name  = class_exists('Env') ? (string) Env::get('MAIL_FROM_NAME', '') : '';
            $this->ci->email->from(
                $this->ci->Setting_model->get('mail_from_email', $from_email !== '' ? $from_email : 'no-reply@marvy.local'),
                $this->ci->Setting_model->get('mail_from_name',  $from_name  !== '' ? $from_name  : 'MarvySocials')
            );
            $this->ci->email->to($mail->to_email);
            $this->ci->email->subject($mail->subject);
            $this->ci->email->message($mail->body_html);
            if (!empty($mail->body_text)) $this->ci->email->set_alt_message($mail->body_text);

            if (!$this->ci->email->send(false)) {
                // CI3's SMTP client records the whole conversation in its
                // debug buffer — including the server's greeting banner, which
                // is always the FIRST entry. Reporting "the first line" (the
                // old behaviour) told the operator the mail server said hello,
                // and nothing about why the send failed. Read the buffer back
                // and surface the actual failure plus a cPanel-oriented hint.
                $summary = $this->smtp_failure_summary();
                return array(
                    'ok'        => false,
                    'transport' => $transport,
                    'error'     => $summary['reason'],
                    'hint'      => $summary['hint'],
                );
            }
            return array('ok'=>true, 'transport'=>$transport);
        } catch (Exception $e) {
            return array('ok'=>false, 'transport'=>$transport, 'error'=>$e->getMessage());
        }
    }

    /**
     * Turn CI3's debug buffer into an operator-actionable answer.
     *
     * Every SMTP exchange is appended to the buffer, so on failure it reads
     * something like:
     *
     *   220-server315.web-hosting.com ESMTP Exim 4.99.5 #2 ...   ← banner
     *   hello: 250-server315.web-hosting.com ...                 ← command echo
     *   Failed to authenticate password. Response: 535 5.7.8 ... ← the failure
     *
     * The banner is NOT the error — it proves the connection opened. The real
     * reason is the last entry that is not a 2xx/3xx exchange (banner, echoed
     * command, or a DATA reply the server accepted).
     *
     * @return array{reason:string, hint:string}
     */
    public function smtp_failure_summary() {
        $raw = (string) $this->ci->email->print_debugger(array());
        $raw = str_ireplace(array('<br />', '<br>'), "\n", $raw);
        $raw = strip_tags($raw);
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        $reason = '';
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '' || $this->is_smtp_exchange($line)) continue;
            $reason = $line; // keep the last meaningful line
        }
        if ($reason === '') {
            $reason = 'The SMTP server rejected the message without a usable explanation.';
        }

        // What the operator should actually check, mapped from the failure.
        $lower = function_exists('mb_strtolower') ? mb_strtolower($reason) : strtolower($reason);
        $hint  = '';
        if (strpos($lower, 'authenticate password') !== false
            || strpos($lower, 'smtp_auth_pw') !== false
            || preg_match('/\b535\b/', $reason)) {
            $hint = 'Authentication failed. Check VP_MAIL_USER / VP_MAIL_PASS in .env — on cPanel the username is the full '
                .'email address, and if the account has two-step login on, generate an App Password '
                .'(cPanel → Email Accounts → App Passwords) and use that instead of the account password.';
        } elseif (strpos($lower, 'failed to send auth login') !== false
            || strpos($lower, 'smtp_auth_un') !== false) {
            $hint = 'The server rejected the AUTH LOGIN handshake. Try VP_MAIL_CRYPTO=ssl with VP_MAIL_PORT=465 '
                .'(or tls with 587) in .env — some cPanel hosts only accept one of the two.';
        } elseif (strpos($lower, 'starttls') !== false
            || strpos($lower, 'unable to connect') !== false
            || strpos($lower, 'error: #(') !== false
            || strpos($lower, 'no_socket') !== false) {
            $hint = 'Could not open a usable connection. Check VP_MAIL_HOST, VP_MAIL_PORT and VP_MAIL_CRYPTO in .env: '
                .'cPanel usually offers 465/ssl or 587/tls on the mail host (the host in your cPanel banner, e.g. '
                .'server315.web-hosting.com, or mail.yourdomain.com).';
        } elseif (strpos($lower, 'hostname') !== false && strpos($lower, 'smtp') !== false) {
            $hint = 'No SMTP host is configured — set VP_MAIL_HOST in .env (cPanel → Email Accounts shows the hostname).';
        } elseif (preg_match('/\b[45]\d{2}\b/', $reason)
            || strpos($lower, 'relay') !== false
            || strpos($lower, 'sender') !== false
            || strpos($lower, 'domain') !== false
            || strpos($lower, 'not local') !== false) {
            $hint = 'The server refused the message — usually because the From address (Settings → From address) is not '
                .'on the same domain as the SMTP account. cPanel accounts can normally only relay mail for their own '
                .'domain; use a From address on that domain or ask the host to allow the sender.';
        }
        return array('reason' => $reason, 'hint' => $hint);
    }

    /**
     * True when a debug-buffer line is a successful exchange (server banner or
     * a per-command echo such as "hello: 250-..."), not a failure. 4xx/5xx
     * replies are failures even though they look like the same "220 ..." shape.
     */
    private function is_smtp_exchange($line) {
        if (preg_match('/^(\d{3})[- ]/', $line, $m)) {
            return (int) $m[1] < 400;
        }
        if (preg_match('/^[a-z_]+:\s+(\d{3})[- ]/i', $line, $m)) {
            return (int) $m[1] < 400;
        }
        return false;
    }

    /**
     * The transport delivery will use: settings first, then VP_MAIL_DRIVER,
     * defaulting to `log` so a fresh install never silently drops mail into a
     * misconfigured SMTP server.
     *
     * Public because the admin mail-queue screen has to tell the operator
     * which transport their test will actually go through — "it worked" means
     * nothing if it worked by writing to a log file.
     */
    public function transport() {
        require_once APPPATH.'core/Env.php';
        $default = strtolower((string)Env::get('MAIL_DRIVER', 'log'));
        if (!in_array($default, array('log', 'smtp', 'mail'), true)) $default = 'log';

        $configured = strtolower((string)$this->ci->Setting_model->get('mail_transport', $default));
        return in_array($configured, array('log', 'smtp', 'mail'), true) ? $configured : $default;
    }

    /**
     * Send a test message immediately, bypassing the queue.
     *
     * The operator is waiting for an answer: queueing would report success for
     * a message the SMTP server has not seen yet, which is exactly the thing
     * they are trying to find out.
     */
    public function send_test($to) {
        $site = $this->ci->Setting_model->get('site_name', 'MarvySocials');
        $mail = (object)array(
            'to_email'  => strtolower(trim((string)$to)),
            'to_name'   => null,
            'subject'   => $site.' — test message',
            'body_html' => '<p>This is a test message from '.htmlspecialchars($site).'.</p>'
                          .'<p>If you are reading it, outbound email works. Sent '
                          .gmdate('Y-m-d H:i:s').' UTC via the '.$this->transport().' transport.</p>',
            'body_text' => 'Test message from '.$site.'. Sent '.gmdate('Y-m-d H:i:s').' UTC.',
        );

        $res = $this->deliver($mail);
        $res['transport'] = $res['transport'] ?? $this->transport();
        return $res;
    }

    /* -------------------------------------------------------------- */

    private function global_variables() {
        return array(
            'site_name'     => $this->ci->Setting_model->get('site_name', 'MarvySocials'),
            'site_url'      => rtrim(base_url(), '/'),
            'support_email' => $this->ci->Setting_model->get('support_email', 'support@marvy.local'),
            'year'          => gmdate('Y'),
        );
    }

    private function interpolate($template, array $vars) {
        $out = (string)$template;
        foreach ($vars as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out = str_replace('{{' . $key . '}}', (string)$value, $out);
            }
        }
        // Strip any unresolved placeholders rather than leaking them.
        return preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '', $out);
    }
}
