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

        if ($ok && getenv('MAIL_LOG')) {
            log_message('info', "mail queued to {$to} <{$subject}>\n".strip_tags($body_html));
        }
        return $ok;
    }

    /* -------------------------------------------------------------- */

    private function global_variables() {
        return array(
            'site_name'     => $this->ci->Setting_model->get('site_name', 'WINDELS PANEL'),
            'site_url'      => rtrim(base_url(), '/'),
            'support_email' => $this->ci->Setting_model->get('support_email', 'support@windels.local'),
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
