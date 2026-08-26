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
        require_once APPPATH.'core/Env.php';
        $default_transport = strtolower((string)Env::get('MAIL_DRIVER', 'log'));
        if (!in_array($default_transport, array('log', 'smtp', 'mail'), TRUE)) {
            $default_transport = 'log';
        }
        $transport = strtolower((string)$this->ci->Setting_model->get('mail_transport', $default_transport));

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
                // print_debugger() is the only way CI3 surfaces the SMTP error.
                return array('ok'=>false, 'error'=>strip_tags((string)$this->ci->email->print_debugger(array('headers'))));
            }
            return array('ok'=>true, 'transport'=>$transport);
        } catch (Exception $e) {
            return array('ok'=>false, 'error'=>$e->getMessage());
        }
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
