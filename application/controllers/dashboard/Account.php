<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Account — profile, security settings and API keys.
 */
class Account extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('form_validation', 'AuthService', 'ApiKeyPolicy'));
        $this->load->model(array('Api_key_model', 'Audit_log_model', 'User_model'));
        $this->load->library('DashboardStats');
    }

    /* ----------------------------- profile ----------------------------- */

    public function profile() {
        if ($this->input->method(true) === 'POST') {
            return $this->profile_update();
        }
        $this->load->library('NotificationService');
        $prefs = array();
        foreach (array_keys(NotificationService::EVENTS) as $type) {
            $prefs[$type] = $this->notificationservice->preferences($this->current_user->id, $type);
        }
        $this->render('Profile', 'dashboard/account/profile', 'dashboard/profile', array(
            'notification_events' => NotificationService::EVENTS,
            'notification_prefs'  => $prefs,
        ));
    }

    private function profile_update() {
        if ($this->input->post('action', true) === 'avatar') {
            return $this->avatar_update();
        }
        if ($this->input->post('action', true) === 'avatar_remove') {
            return $this->avatar_remove();
        }
        if ($this->input->post('action', true) === 'notifications') {
            return $this->notification_prefs_update();
        }

        $this->form_validation->set_rules('username', 'Username', 'trim|required|min_length[3]|max_length[64]|alpha_dash');
        $this->form_validation->set_rules('email', 'Email', 'trim|required|valid_email|max_length[255]');
        $this->form_validation->set_rules('first_name', 'First name', 'trim|max_length[100]');
        $this->form_validation->set_rules('last_name', 'Last name', 'trim|max_length[100]');
        $this->form_validation->set_rules('phone', 'Phone', 'trim|max_length[32]');
        $this->form_validation->set_rules('timezone', 'Timezone', 'trim|max_length[64]');
        $this->form_validation->set_rules('locale', 'Locale', 'trim|max_length[8]');

        if (!$this->form_validation->run()) {
            $this->session->set_flashdata('error', strip_tags(validation_errors()));
            return redirect('dashboard/profile');
        }

        $username = trim((string)$this->input->post('username', true));
        $email    = strtolower(trim((string)$this->input->post('email', true)));

        // A username or email that already belongs to somebody else is a
        // unique-key violation waiting to happen: refuse it with a readable
        // message instead of a database error page.
        $by_username = $this->User_model->find_by_username($username);
        if ($by_username && (int)$by_username->id !== (int)$this->current_user->id) {
            $this->session->set_flashdata('error', 'That username is already taken.');
            return redirect('dashboard/profile');
        }
        $by_email = $this->User_model->find_by_email($email);
        if ($by_email && (int)$by_email->id !== (int)$this->current_user->id) {
            $this->session->set_flashdata('error', 'That email address is already in use.');
            return redirect('dashboard/profile');
        }

        $email_changed = strtolower((string)$this->current_user->email) !== $email;

        $data = array(
            'username'   => $username,
            'email'      => $email,
            'first_name' => $this->input->post('first_name', true),
            'last_name'  => $this->input->post('last_name', true),
            'phone'      => $this->input->post('phone', true),
            'timezone'   => $this->input->post('timezone', true) ?: 'UTC',
            'locale'     => $this->input->post('locale', true) ?: 'en',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );
        // A new address is unproven until it is confirmed; keeping the old
        // verification would let anyone move notices to an address they do not
        // control and still look verified.
        if ($email_changed) $data['email_verified_at'] = null;

        $this->db->where('id', $this->current_user->id)->update('users', $data);
        $this->Audit_log_model->record($this->current_user->id, 'profile.update', 'users',
            $this->current_user->public_id,
            array('username' => $this->current_user->username, 'email' => $this->current_user->email),
            $data, $this->input->ip_address(), $this->input->user_agent(), $this->request_id);

        $message = 'Profile updated.';
        if ($email_changed) {
            $message .= ' Your email address changed, so it needs verifying again.';
            try {
                $this->load->library('MailService');
                $fresh = $this->User_model->find_by_id($this->current_user->id);
                $token = $this->auth->issue_verification_token($fresh);
                $this->mailservice->enqueue_template($email, 'auth.verify_email', array(
                    'username'   => $fresh->username,
                    'verify_url' => site_url('verify-email/'.$token),
                ), trim(($fresh->first_name ?? '').' '.($fresh->last_name ?? '')) ?: $fresh->username);
            } catch (Throwable $e) {
                log_message('error', 'verification email after profile change failed: '.$e->getMessage());
            }
        }
        $this->session->set_flashdata('success', $message);
        redirect('dashboard/profile');
    }

    /**
     * Save which events reach the inbox and which reach the mailbox.
     *
     * A missing row means "both on", so a row is only written when the
     * customer turns something OFF — and deleted again when they turn it back
     * on. That keeps notification_preferences small and makes the default
     * unambiguous.
     */
    private function notification_prefs_update() {
        $this->load->library('NotificationService');
        // Field names carry the event type with its dot replaced: CodeIgniter's
        // input sanitiser rejects any array key outside [a-z0-9:_/|-], so a
        // field named notify[order.completed][email] arrives with its key
        // mangled and the preference is silently never saved.
        $posted   = (array)$this->input->post('notify', true);
        $rendered = (array)$this->input->post('notify_rendered', true);
        if (!$rendered) return redirect('dashboard/profile');

        foreach (array_keys(NotificationService::EVENTS) as $type) {
            $field = str_replace('.', '__', $type);
            if (!isset($rendered[$field])) continue;
            $in_app = !empty($posted[$field]['in_app']);
            $email  = !empty($posted[$field]['email']);
            $where  = array('user_id' => $this->current_user->id, 'type' => $type);

            if ($in_app && $email) {
                $this->db->where($where)->delete('notification_preferences');
                continue;
            }
            $exists = $this->db->where($where)->get('notification_preferences')->row();
            $values = array('in_app' => $in_app ? 1 : 0, 'email' => $email ? 1 : 0);
            if ($exists) $this->db->where($where)->update('notification_preferences', $values);
            else         $this->db->insert('notification_preferences', array_merge($where, $values));
        }

        $this->Audit_log_model->record($this->current_user->id, 'profile.notification_prefs', 'users',
            $this->current_user->public_id, null, $posted,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id);
        $this->session->set_flashdata('success', 'Notification preferences saved.');
        redirect('dashboard/profile');
    }

    /** Profile picture upload — same validated pipeline as the media library. */
    private function avatar_update() {
        $this->load->library('MediaService');
        if (empty($_FILES['avatar']['name'])) {
            $this->session->set_flashdata('error', 'Choose an image first.');
            return redirect('dashboard/profile');
        }

        $res = $this->mediaservice->store($_FILES['avatar'], 'avatar', $this->current_user->id);
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error']);
            return redirect('dashboard/profile');
        }
        if (strpos((string)$res['media']->mime_type, 'image/') !== 0) {
            $this->mediaservice->delete($res['media']);
            $this->session->set_flashdata('error', 'A profile picture must be an image.');
            return redirect('dashboard/profile');
        }

        $this->db->where('id', $this->current_user->id)
            ->update('users', array('avatar_url' => $res['media']->url, 'updated_at' => gmdate('Y-m-d H:i:s')));
        $this->Audit_log_model->record($this->current_user->id, 'profile.avatar', 'users',
            $this->current_user->public_id, array('avatar_url' => $this->current_user->avatar_url),
            array('avatar_url' => $res['media']->url),
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id);
        $this->session->set_flashdata('success', 'Profile picture updated.');
        redirect('dashboard/profile');
    }

    private function avatar_remove() {
        $this->db->where('id', $this->current_user->id)
            ->update('users', array('avatar_url' => null, 'updated_at' => gmdate('Y-m-d H:i:s')));
        $this->Audit_log_model->record($this->current_user->id, 'profile.avatar_removed', 'users',
            $this->current_user->public_id, array('avatar_url' => $this->current_user->avatar_url), null,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id);
        $this->session->set_flashdata('success', 'Profile picture removed.');
        redirect('dashboard/profile');
    }

    /* ----------------------------- security ---------------------------- */

    public function security() {
        if ($this->input->method(true) === 'POST') {
            return $this->security_update();
        }
        // Safe projection keeps the stored credential verifier out of views.
        $keys = $this->Api_key_model->for_user_safe($this->current_user->id);

        $this->load->library('PinService');

        $this->render('Security', 'dashboard/account/security', 'dashboard/security', array(
            'keys'       => $keys,
            'mfa'        => $this->current_user->mfa_enabled,
            // Whether a PIN exists, never the PIN itself — there is no code
            // path that can recover it from the stored hash.
            'pin_set'    => $this->pinservice->is_set($this->current_user),
            'pin_locked' => $this->pinservice->locked_for($this->current_user),
            // Automatic rotation: when the current PIN will next be replaced,
            // and whether the scheduled worker is even turned on.
            'pin_rotation_enabled' => $this->pinservice->rotation_enabled(),
            'pin_rotation_hours'   => $this->pinservice->rotation_hours(),
            'pin_rotates_in'       => $this->pinservice->rotates_in($this->current_user),
        ));
    }

    private function security_update() {
        $action = $this->input->post('action', true);
        if ($action === 'change_password') {
            $this->form_validation->set_rules('current_password', 'Current password', 'required');
            $this->form_validation->set_rules('new_password', 'New password', 'required|min_length[8]');
            $this->form_validation->set_rules('confirm_password', 'Confirm password', 'required|matches[new_password]');
            if (!$this->form_validation->run()) {
                $this->session->set_flashdata('error', validation_errors());
                return redirect('dashboard/security');
            }
            $res = $this->auth->change_password(
                $this->current_user,
                $this->input->post('current_password'),
                $this->input->post('new_password')
            );
            if (!$res['ok']) {
                $this->session->set_flashdata('error', 'Your current password was incorrect.');
                return redirect('dashboard/security');
            }
            $this->session->set_flashdata('success', 'Password changed.');
            return redirect('dashboard/security');
        }

        if ($action === 'set_pin') {
            $this->load->library('PinService');
            $has_pin = $this->pinservice->is_set($this->current_user);

            $this->form_validation->set_rules('new_pin', 'New PIN', 'required|exact_length[4]|numeric');
            $this->form_validation->set_rules('confirm_pin', 'Confirm PIN', 'required|matches[new_pin]');
            if ($has_pin) {
                $this->form_validation->set_rules('current_pin', 'Current PIN', 'required|exact_length[4]|numeric');
            }
            if (!$this->form_validation->run()) {
                $this->session->set_flashdata('error', validation_errors());
                return redirect('dashboard/security');
            }

            $res = $this->pinservice->set(
                $this->current_user,
                $this->input->post('new_pin'),
                $has_pin ? $this->input->post('current_pin') : null
            );
            if (empty($res['ok'])) {
                $this->session->set_flashdata('error', $res['error']);
                return redirect('dashboard/security');
            }

            $this->session->set_flashdata('success',
                $has_pin ? 'Your transaction PIN was updated.' : 'Your transaction PIN is now set.');
            return redirect('dashboard/security');
        }

        show_404();
    }

    /* ----------------------------- API keys ---------------------------- */

    public function api_keys() {
        $new_key = null;
        if ($this->input->method(true) === 'POST') {
            $new_key = $this->create_api_key();
        }
        $keys = $this->Api_key_model->for_user_safe($this->current_user->id);

        $this->render('API Keys', 'dashboard/account/api_keys', 'dashboard/api', array(
            'scope_catalogue' => ApiKeyPolicy::scopes(),
            'keys'    => $keys,
            'new_key' => $new_key,
        ));
    }

    private function create_api_key() {
        $this->form_validation->set_rules('name', 'Key name', 'trim|required|max_length[64]');
        if (!$this->form_validation->run()) {
            $this->session->set_flashdata('error', validation_errors());
            return null;
        }
        $parsed = $this->apikeypolicy->parse_ip_whitelist($this->input->post('ip_whitelist'));
        if (empty($parsed['ok'])) {
            $this->session->set_flashdata('error', $parsed['error']);
            return null;
        }
        $opts = $parsed['value'] ? array('ip_whitelist'=>$parsed['value']) : array();

        // Scoped keys existed in the policy and were enforced by the API, but
        // the only screen that could set them was the admin's — a customer
        // creating their own key always got full access. A read-only key for a
        // dashboard or a price scraper is the common case, so the choice
        // belongs here.
        if ($this->input->post('access_mode', true) === 'scoped') {
            $catalogue = ApiKeyPolicy::scopes();
            $chosen = array_values(array_intersect(
                array_map('strval', (array)$this->input->post('scopes', true)),
                array_keys($catalogue)
            ));
            if (!$chosen) {
                $this->session->set_flashdata('error', 'Choose at least one scope, or select full access.');
                return null;
            }
            $opts['scopes'] = $chosen;
        }

        $result = $this->auth->create_api_key(
            $this->current_user->id,
            $this->input->post('name', true),
            $opts
        );
        $this->session->set_flashdata('success', 'API key created. Copy it now — it will not be shown again.');
        return $result;
    }

    public function revoke_api_key($public_id = null) {
        if (strtoupper($this->input->method()) !== 'POST') show_error('Method Not Allowed', 405);
        if (!$public_id) show_404();
        $key = $this->Api_key_model->safe_for_user($public_id, $this->current_user->id);
        if (!$key) show_404();
        if (!empty($key->revoked_at)) {
            $this->session->set_flashdata('success', 'API key was already revoked.');
            return redirect('dashboard/api');
        }
        $revoked_at = gmdate('Y-m-d H:i:s');
        $this->db->where('id', $key->id)->where('revoked_at IS NULL', null, false)
            ->update('api_keys', array('revoked_at'=>$revoked_at));
        if ($this->db->affected_rows() > 0) {
            $this->Audit_log_model->record($this->current_user->id, 'api_key.revoked', 'api_keys',
                $key->public_id, array('revoked_at'=>null), array('revoked_at'=>$revoked_at),
                $this->input->ip_address(), $this->input->user_agent(), $this->request_id);
            $this->session->set_flashdata('success', 'API key revoked permanently.');
        } else {
            $this->session->set_flashdata('error', 'API key changed before it could be revoked. Reload and try again.');
        }
        redirect('dashboard/api');
    }

    /* ------------------------------ helper ----------------------------- */

    private function render($title, $view, $nav, $extra) {
        $data = array(
            'title'        => $title,
            'nav_active'   => $nav,
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
        );
        $this->load->view('layouts/app', array_merge($data, $extra));
    }
}
