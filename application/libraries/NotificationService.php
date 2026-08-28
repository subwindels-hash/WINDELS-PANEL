<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * NotificationService — tells a customer something happened.
 *
 * The panel had a notifications table, a notification_preferences table, a
 * bell in the topbar, a Notifications page and six seeded email templates —
 * and nothing that ever wrote a row or sent any of them except the email
 * verification link. Orders completed, deposits credited and support replies
 * arrived in total silence, and the bell was permanently empty.
 *
 * Everything customer-facing now goes through here, so there is exactly one
 * place that decides:
 *
 *   - what is written to the in-app inbox,
 *   - which email template goes with it,
 *   - what the customer's preferences allow,
 *   - and that a mail problem can never break the thing that just happened.
 *
 * That last rule matters most: notifying is always the LAST step of a business
 * action. An order is complete whether or not the mail server answers, so
 * every failure here is caught and logged, never thrown.
 */
class NotificationService {

    /**
     * The events this panel notifies on.
     *
     * Each entry is [in-app title, email template key]. The template key may
     * be null for events that belong in the inbox but not in someone's mailbox.
     */
    const EVENTS = array(
        'order.completed'  => array('Order completed',        'order.completed'),
        'order.partial'    => array('Order partially delivered', 'order.partial'),
        'order.canceled'   => array('Order cancelled',        null),
        'order.refunded'   => array('Order refunded',         null),
        // A refill is the customer's only remedy after a drop, and it costs
        // them nothing — which is exactly why silence about the outcome is so
        // damaging. Both endings are announced.
        'refill.completed' => array('Refill completed',       null),
        'refill.failed'    => array('Refill could not be completed', null),
        'payment.credited' => array('Wallet credited',        'payment.credited'),
        'ticket.replied'   => array('Support replied',        'ticket.replied'),
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Notification_model');
    }

    /**
     * Notify a user of an event.
     *
     * @param int    $user_id
     * @param string $type      one of self::EVENTS
     * @param string $body      the sentence shown in the inbox
     * @param array  $data      link/reference data stored with the notification
     * @param array  $email_vars variables for the email template
     * @return array{in_app:bool, email:bool}
     */
    public function notify($user_id, $type, $body, array $data = array(), array $email_vars = array()) {
        $result = array('in_app' => false, 'email' => false);
        if (!$user_id || !isset(self::EVENTS[$type])) {
            log_message('error', 'notify: unknown notification type '.$type);
            return $result;
        }

        list($title, $template) = self::EVENTS[$type];
        $prefs = $this->preferences($user_id, $type);

        if ($prefs['in_app']) {
            $result['in_app'] = $this->write_in_app($user_id, $type, $title, $body, $data);
        }
        if ($prefs['email'] && $template !== null) {
            $result['email'] = $this->send_email($user_id, $template, $email_vars);
        }
        return $result;
    }

    /* ------------------------------------------------------------------ */

    /** Write the inbox row. Never throws: the caller's work is already done. */
    private function write_in_app($user_id, $type, $title, $body, array $data) {
        try {
            $ok = $this->ci->db->insert('notifications', array(
                'public_id'  => marvy_public_id(),
                'user_id'    => (int)$user_id,
                'type'       => $type,
                'channel'    => 'IN_APP',
                'title'      => mb_substr((string)$title, 0, 255),
                'body'       => $body,
                'data'       => $data ? json_encode($data, JSON_UNESCAPED_SLASHES) : null,
                'is_read'    => 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
            // insert() returns false on a rejected write; reporting success
            // there would hide an empty inbox behind a green result.
            return (bool)$ok;
        } catch (Throwable $e) {
            log_message('error', 'notify: could not write notification: '.$e->getMessage());
            return false;
        }
    }

    /** Queue the matching email. Never throws, for the same reason. */
    private function send_email($user_id, $template_key, array $vars) {
        try {
            if (!$this->emails_enabled()) return false;

            $user = $this->ci->db->select('email, username, first_name, last_name', false)
                ->where('id', (int)$user_id)->get('users')->row();
            if (!$user || empty($user->email)) return false;

            $this->ci->load->library('MailService');
            return (bool)$this->ci->mailservice->enqueue_template(
                $user->email, $template_key,
                array_merge(array('username' => $user->username), $vars),
                trim((string)($user->first_name ?? '').' '.(string)($user->last_name ?? '')) ?: $user->username
            );
        } catch (Throwable $e) {
            log_message('error', 'notify: could not queue '.$template_key.': '.$e->getMessage());
            return false;
        }
    }

    /**
     * What this user wants for this event.
     *
     * A missing row means "both on" — the documented default, and the reason
     * notification_preferences is empty on a fresh install rather than being
     * back-filled for every user × event pair.
     */
    public function preferences($user_id, $type) {
        $defaults = array('in_app' => true, 'email' => true);
        try {
            $row = $this->ci->db->where(array('user_id' => (int)$user_id, 'type' => $type))
                ->get('notification_preferences')->row();
        } catch (Throwable $e) {
            return $defaults;
        }
        if (!$row) return $defaults;
        return array('in_app' => (int)$row->in_app === 1, 'email' => (int)$row->email === 1);
    }

    /** Global kill switch for outbound notification email. */
    private function emails_enabled() {
        try {
            $this->ci->load->model('Setting_model');
            $value = $this->ci->Setting_model->get('notification_emails_enabled', true);
        } catch (Throwable $e) {
            return true;
        }
        if ($value === null || $value === '') return true;
        if (is_bool($value)) return $value;
        return !in_array(strtolower((string)$value), array('0', 'false', 'no', 'off'), true);
    }
}
