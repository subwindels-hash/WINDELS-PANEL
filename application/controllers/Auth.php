<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Auth — registration, login/logout, email verification, password reset and
 * self-service MFA. All security logic lives in libraries/AuthService.php; this
 * controller only handles HTTP: validation, rate-limit/blacklist gates, flash
 * messages and redirects.
 *
 * Routes (config/routes.php §3):
 *   GET|POST /login            login
 *   GET|POST /register         register
 *   GET      /logout           logout
 *   GET|POST /forgot-password  forgot_password
 *   GET|POST /reset-password   reset_password($token)
 *   GET      /verify-email     verify_email($token)
 *   POST     /verify-email/resend
 *   POST     /auth/mfa/verify  mfa_verify
 */
class Auth extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library(array('form_validation', 'AuthService', 'RateLimiter', 'MailService'));
        $this->load->model(array('Setting_model', 'Blacklist_model'));
    }

    /* -------------------------------------------------------------- */
    /* Login                                                          */
    /* -------------------------------------------------------------- */

    public function login() {
        if ($this->auth->check()) {
            redirect($this->default_landing());
        }
        if ($this->no_super_admin()) {
            $this->session->set_flashdata('warning', 'No administrator exists yet. Complete first-time setup.');
            return redirect('setup');
        }

        if ($this->input->method(true) === 'POST') {
            return $this->login_post();
        }

        // MFA challenge after a successful password step.
        if ($this->session->flashdata('mfa_required')) {
            return $this->render_auth('auth/mfa', array(
                'title' => 'Two-factor authentication',
                'email' => $this->session->flashdata('mfa_email'),
            ));
        }

        $this->render_auth('auth/login', array(
            'title' => 'Log in',
            'referral' => $this->input->get('ref'),
        ));
    }

    /**
     * Staff-only login. Same password check as /login, then the role must be
     * SUPER_ADMIN, ADMIN or STAFF. Customer credentials receive the generic
     * invalid-credentials message so this page cannot be used to probe roles.
     */
    public function admin_login() {
        if ($this->no_super_admin()) {
            $this->session->set_flashdata('warning', 'No administrator exists yet. Complete first-time setup.');
            return redirect('setup');
        }
        if ($this->auth->check()) {
            if ($this->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF'))) {
                redirect('admin');
            }
            $this->session->set_flashdata('error', 'That area is for staff. You are signed in as a customer.');
            redirect('dashboard');
        }

        if ($this->input->method(true) === 'POST') {
            return $this->admin_login_post();
        }

        $this->render_auth('auth/admin_login', array(
            'title' => 'Staff sign-in',
            // The staff door gets staff words. The customer pitch on this page
            // was the same sales line the public login shows, which reads as a
            // mistake to the person it is shown to. The bullet list is
            // overridden too, so the staff panel never inherits the customer
            // highlights.
            'auth_visual_title' => 'Staff sign-in.',
            'auth_visual_text'   => 'Orders, payments, refunds and the audit trail. '
                                    .'Customer passwords cannot open the back office.',
            'auth_visual_points' => array(
                'Every order, top-up and refund lands in one auditable ledger.',
                'Refund, adjust and reply to tickets with a full staff trail.',
                'Rate limiting and IP blacklists stand guard at this door.',
            ),
        ));
    }

    private function admin_login_post() {
        $this->form_validation->set_rules('identifier', 'Email or username', 'required|trim');
        $this->form_validation->set_rules('password', 'Password', 'required');
        if (!$this->form_validation->run()) {
            return $this->render_auth('auth/admin_login', array(
            'title' => 'Staff sign-in',
            // The staff door gets staff words — see admin_login() above. The
            // bullet list is overridden too, so the staff panel never
            // inherits the customer highlights.
            'auth_visual_title' => 'Staff sign-in.',
            'auth_visual_text'   => 'Orders, payments, refunds and the audit trail. '
                                    .'Customer passwords cannot open the back office.',
            'auth_visual_points' => array(
                'Every order, top-up and refund lands in one auditable ledger.',
                'Refund, adjust and reply to tickets with a full staff trail.',
                'Rate limiting and IP blacklists stand guard at this door.',
            ),
            ));
        }

        $ip         = $this->input->ip_address();
        $identifier = $this->input->post('identifier', true);
        $password   = $this->input->post('password');
        $ua         = $this->input->user_agent();
        $bucket     = RateLimiter::scope('adminlogin', $identifier);

        if ($this->Blacklist_model->is_ip_blacklisted($ip)) {
            $this->session->set_flashdata('error', 'Your IP address has been blocked.');
            return redirect('admin/login');
        }
        if ($this->ratelimiter->too_many_failures($ip, $bucket, 5, 900)) {
            $retry = $this->ratelimiter->retry_after($ip, $bucket, 900, 5);
            $this->session->set_flashdata('error',
                'Too many failed attempts. Try again in ' . ceil($retry / 60) . ' minute(s).');
            return redirect('admin/login');
        }

        $result = $this->auth->attempt_login($identifier, $password, $ip, $ua);
        if (!$result['ok']) {
            $this->ratelimiter->record($bucket, $ip, false, 'ADMIN_INVALID', $ua);
            $this->session->set_flashdata('error', $this->login_error_message($result['error']));
            return redirect('admin/login');
        }

        if (!empty($result['mfa_required'])) {
            $this->session->set_userdata('admin_login_intent', 1);
            $this->capture_remember();
            $this->session->set_flashdata('mfa_required', true);
            $this->session->set_flashdata('mfa_email', $result['user']->email);
            return redirect('login');
        }

        if (!$this->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF'))) {
            // Do not sess_destroy(): that would drop the flash message and the
            // CSRF cookie on the same request. Drop the auth keys only.
            $this->session->unset_userdata(array('user_id', 'role', 'login_at'));
            $this->ratelimiter->record($bucket, $ip, false, 'ADMIN_NOT_STAFF', $ua);
            $this->session->set_flashdata('error', 'Invalid email/username or password.');
            return redirect('admin/login');
        }

        $this->apply_remember_cookie();
        $this->session->set_flashdata('success', 'Welcome back.');
        $dest = $this->session->userdata('redirect_after_login') ?: 'admin';
        $this->session->unset_userdata('redirect_after_login');
        if (!is_string($dest) || strpos($dest, '/admin') !== 0) {
            $dest = 'admin';
        }
        redirect($dest);
    }

    private function login_post() {
        $this->form_validation->set_rules('identifier', 'Email or username', 'required|trim');
        $this->form_validation->set_rules('password', 'Password', 'required');
        if (!$this->form_validation->run()) {
            return $this->render_auth('auth/login', array('title' => 'Log in'));
        }

        $ip         = $this->input->ip_address();
        $identifier = $this->input->post('identifier', true);
        $password   = $this->input->post('password');
        $ua         = $this->input->user_agent();

        if ($this->Blacklist_model->is_ip_blacklisted($ip)) {
            $this->session->set_flashdata('error', 'Your IP address has been blocked.');
            return redirect('login');
        }
        if ($this->ratelimiter->too_many_failures($ip, $identifier)) {
            $retry = $this->ratelimiter->retry_after($ip, $identifier);
            $this->session->set_flashdata('error',
                'Too many failed attempts. Try again in ' . ceil($retry / 60) . ' minute(s).');
            return redirect('login');
        }

        $result = $this->auth->attempt_login($identifier, $password, $ip, $ua);
        if (!$result['ok']) {
            $this->session->set_flashdata('error', $this->login_error_message($result['error']));
            return redirect('login');
        }

        if (!empty($result['mfa_required'])) {
            $this->capture_remember();
            $this->session->set_flashdata('mfa_required', true);
            $this->session->set_flashdata('mfa_email', $result['user']->email);
            return redirect('login');
        }

        $this->apply_remember_cookie();
        $this->session->set_flashdata('success', 'Welcome back.');
        $dest = $this->session->userdata('redirect_after_login') ?: $this->default_landing();
        $this->session->unset_userdata('redirect_after_login');
        // Only redirect to a local relative path (never an open redirect).
        if (!is_string($dest) || $dest === '' || $dest[0] !== '/'
            || strpos($dest, '//') === 0 || !preg_match('#^/[a-zA-Z0-9/_\-.?&=]*$#', $dest)) {
            $dest = $this->default_landing();
        }
        redirect($dest);
    }

    /** MFA second factor form submission (same URL as login via POST). */
    public function mfa_verify() {
        $ip = $this->input->ip_address();
        // A TOTP code is only 6 digits. Without a limit the second factor is
        // brute-forceable in minutes once the password is known.
        $bucket = RateLimiter::scope('mfa', $this->auth->pending_mfa_identifier());
        if ($this->ratelimiter->too_many_failures($ip, $bucket, 5, 900)) {
            $this->session->set_flashdata('error', 'Too many attempts. Try again later.');
            return redirect('login');
        }
        $code = $this->input->post('code', true);
        $result = $this->auth->verify_mfa($code, $ip, $this->input->user_agent());
        if (!$result['ok']) {
            $this->ratelimiter->record($bucket, $ip, false, 'MFA_FAILED', $this->input->user_agent());
            $this->session->set_flashdata('mfa_required', true);
            $this->session->set_flashdata('error', $this->login_error_message($result['error']));
            return redirect('login');
        }
        if ($this->session->userdata('admin_login_intent') && !$this->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF'))) {
            $this->session->unset_userdata(array('admin_login_intent', 'user_id', 'role', 'login_at'));
            $this->session->set_flashdata('error', 'Invalid email/username or password.');
            return redirect('admin/login');
        }
        $this->session->unset_userdata('admin_login_intent');
        $this->apply_remember_cookie();
        $this->session->set_flashdata('success', 'Welcome back.');
        $dest = $this->session->userdata('redirect_after_login') ?: $this->default_landing();
        $this->session->unset_userdata('redirect_after_login');
        redirect($dest);
    }

    /**
     * Logout. The session belongs to server-side state, so it must never be
     * mutated by a GET request a third party can prime (logout CSRF). POST
     * only; CI's csrf_protection validates the hidden token on every POST.
     * GET requests are redirected to the dashboard instead of logged out.
     */
    public function logout() {
        if ($this->input->method(true) !== 'POST') {
            redirect($this->auth->check() ? $this->default_landing() : 'login');
        }
        $this->auth->logout();
        $this->session->set_flashdata('success', 'You have been logged out.');
        redirect('login');
    }

    /* -------------------------------------------------------------- */
    /* Registration                                                   */
    /* -------------------------------------------------------------- */

    public function register() {
        if ($this->auth->check()) {
            redirect($this->default_landing());
        }
        if (!$this->Setting_model->get('registration_enabled', true)) {
            $this->session->set_flashdata('error', 'Registration is currently disabled.');
            return redirect('login');
        }

        // ?ref= is captured for every page by MY_Controller::capture_referral(),
        // so by the time we get here the code is already held in the session
        // whether the visitor landed on /register or on the homepage.
        $this->load->library('ReferralService');
        $incoming = $this->input->get('ref', true);

        if ($this->input->method(true) === 'POST') {
            return $this->register_post();
        }

        $this->render_auth('auth/register', array(
            'title'    => 'Create your account',
            // Prefer the session over the query string so the field stays
            // filled after a validation error redirects back here.
            'referral' => $this->referralservice->pending_code() ?: $incoming,
        ));
    }

    private function register_post() {
        $this->form_validation->set_rules('username', 'Username',
            'required|alpha_numeric|min_length[3]|max_length[64]|trim');
        $this->form_validation->set_rules('email', 'Email',
            'required|valid_email|trim|strtolower|max_length[255]');
        $this->form_validation->set_rules('password', 'Password',
            'required|min_length[8]|max_length[255]');
        $this->form_validation->set_rules('password_confirm', 'Confirm password',
            'required|matches[password]');
        $this->form_validation->set_rules('first_name', 'First name', 'trim|max_length[100]');
        $this->form_validation->set_rules('last_name', 'Last name', 'trim|max_length[100]');

        if (!$this->form_validation->run()) {
            return $this->render_auth('auth/register', array(
                'title' => 'Create your account',
                'referral' => $this->input->post('ref', true),
            ));
        }

        $ip = $this->input->ip_address();
        if ($this->Blacklist_model->is_ip_blacklisted($ip)) {
            $this->session->set_flashdata('error', 'Your IP address has been blocked.');
            return redirect('register');
        }
        $email = $this->input->post('email', true);
        if ($this->Blacklist_model->is_email_blacklisted($email)) {
            $this->session->set_flashdata('error', 'That email address is not permitted.');
            return redirect('register');
        }
        // Scoped: passing a bare $email counted unrelated *login* failures for
        // that address, so a user who mistyped their password could not then
        // register. Recorded too, otherwise the counter never moved and the
        // limit did nothing.
        $reg_bucket = RateLimiter::scope('register', $email);
        if ($this->ratelimiter->too_many_failures($ip, $reg_bucket, 5, 3600)) {
            $this->session->set_flashdata('error', 'Too many registrations from this network. Try again later.');
            return redirect('register');
        }
        $this->ratelimiter->record($reg_bucket, $ip, false, 'register_attempt', $this->input->user_agent());

        $data = array(
            'username'         => $this->input->post('username', true),
            'email'            => $email,
            'password'         => $this->input->post('password'),
            'first_name'       => $this->input->post('first_name', true),
            'last_name'        => $this->input->post('last_name', true),
            'referred_by_code' => $this->input->post('ref', true),
            'timezone'         => 'UTC',
            'ip'               => $ip,
        );
        $result = $this->auth->register($data);
        if (!$result['ok']) {
            $this->session->set_flashdata('error', $this->register_error_message($result['error']));
            return redirect('register');
        }

        // Attribute the referral before the session is replaced by the login:
        // the pending code lives in this session, and attribute() consumes it.
        // Never fatal — a broken referral must not cost someone their account.
        try {
            $this->load->library('ReferralService');
            $this->referralservice->attribute(
                $result['user'],
                $this->input->post('ref', true) ?: $this->referralservice->pending_code()
            );
        } catch (Throwable $e) {
            log_message('error', 'referral attribution failed at registration: '.$e->getMessage());
        }

        // Auto-login the new customer (email verification gates sensitive
        // actions, not the session itself).
        $this->auth->attempt_login($data['email'], $data['password'], $ip, $this->input->user_agent());

        if ($this->Setting_model->get('email_verification_required', false)) {
            $this->send_verification_email($result['user']);
            $this->session->set_flashdata('success', 'Account created. Please verify your email — we sent a link.');
        } else {
            $this->session->set_flashdata('success', 'Welcome to MarvySocials.');
        }
        redirect('dashboard');
    }

    /* -------------------------------------------------------------- */
    /* Email verification                                            */
    /* -------------------------------------------------------------- */

    /**
     * Tell the referral system an account reached a qualifying milestone.
     *
     * Fired unconditionally from the places these events happen; the service
     * ignores it unless the user was referred and this is the event their code
     * requires.
     */
    private function referral_event($user_id, $event) {
        try {
            $this->load->library('ReferralService');
            $this->referralservice->record_event($user_id, $event);
        } catch (Throwable $e) {
            log_message('error', 'referral event '.$event.' failed: '.$e->getMessage());
        }
    }

    public function verify_email($token = null) {
        if (!$token) {
            // POST /verify-email/resend or direct hit without a token.
            if ($this->input->method(true) === 'POST' && $this->auth->check()) {
                $user = $this->auth->user();
                if ($user && !$user->email_verified_at) {
                    $this->send_verification_email($user);
                }
                $this->session->set_flashdata('success', 'If your email needs verification, a new link is on its way.');
                return redirect('dashboard');
            }
            show_404();
        }

        $result = $this->auth->verify_email($token);
        if (!$result['ok']) {
            $this->session->set_flashdata('error', 'That verification link is invalid or has expired.');
            return redirect('login');
        }
        if (!empty($result['user']) && !empty($result['user']->id)) {
            $this->referral_event($result['user']->id, 'EMAIL_VERIFIED');
        }

        $this->session->set_flashdata('success', 'Your email has been verified.');
        if ($this->auth->check()) {
            return redirect('dashboard');
        }
        redirect('login');
    }

    public function verify_email_resend() {
        return $this->verify_email();
    }

    private function send_verification_email($user) {
        $token = $this->auth->issue_verification_token($user);
        $url = site_url('verify-email/' . $token);
        $this->mailservice->enqueue_template(
            $user->email,
            'auth.verify_email',
            array(
                'username'   => $user->username,
                'verify_url' => $url,
            ),
            trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->username
        );
        // In development/demo the link is surfaced in the flash message so a
        // developer can complete the flow without an inbox.
        if (getenv('APP_ENV') !== 'production') {
            $this->session->set_flashdata('dev_link', $url);
        }
    }

    /* -------------------------------------------------------------- */
    /* Password reset                                                 */
    /* -------------------------------------------------------------- */

    public function forgot_password() {
        if ($this->auth->check()) {
            redirect($this->default_landing());
        }
        if ($this->input->method(true) === 'POST') {
            return $this->forgot_password_post();
        }
        $this->render_auth('auth/forgot_password', array('title' => 'Reset your password'));
    }

    private function forgot_password_post() {
        $this->form_validation->set_rules('identifier', 'Email or username', 'required|trim');
        if (!$this->form_validation->run()) {
            return $this->render_auth('auth/forgot_password', array('title' => 'Reset your password'));
        }
        $ip = $this->input->ip_address();
        // Scoped per account: a bare 'pwreset' identifier would share one
        // counter across every user, so a handful of requests would disable
        // password reset site-wide.
        $bucket = RateLimiter::scope('pwreset', $this->input->post('identifier', true));
        if ($this->ratelimiter->too_many_failures($ip, $bucket, 5, 900)) {
            $this->session->set_flashdata('error', 'Too many requests. Try again later.');
            return redirect('forgot-password');
        }
        // Count every reset request (success or not) so the window actually
        // locks after 5 requests, not just after 5 failures.
        $this->ratelimiter->record($bucket, $ip, false, 'reset_requested', $this->input->user_agent());

        $result = $this->auth->begin_password_reset($this->input->post('identifier', true), $ip);
        if (!empty($result['token'])) {
            $user = $result['user'];
            $url = site_url('reset-password/' . $result['token']);
            $this->mailservice->enqueue_template(
                $user->email, 'auth.password_reset',
                array('username' => $user->username, 'reset_url' => $url),
                $user->username
            );
            if (getenv('APP_ENV') !== 'production') {
                $this->session->set_flashdata('dev_link', $url);
            }
            $this->ratelimiter->record($bucket, $ip, true, null, $this->input->user_agent());
        }
        // Always show the same message to prevent user enumeration.
        $this->session->set_flashdata('success',
            'If an account matches that identifier, we have emailed a reset link.');
        redirect('login');
    }

    public function reset_password($token = null) {
        if (!$token) {
            show_404();
        }
        if ($this->auth->check()) {
            redirect($this->default_landing());
        }
        if ($this->input->method(true) === 'POST') {
            return $this->reset_password_post($token);
        }
        $this->render_auth('auth/reset_password', array(
            'title' => 'Set a new password',
            'token' => $token,
        ));
    }

    private function reset_password_post($token) {
        $this->form_validation->set_rules('password', 'New password', 'required|min_length[8]|max_length[255]');
        $this->form_validation->set_rules('password_confirm', 'Confirm password', 'required|matches[password]');
        if (!$this->form_validation->run()) {
            return $this->render_auth('auth/reset_password', array('title' => 'Set a new password', 'token' => $token));
        }
        $result = $this->auth->reset_password($token, $this->input->post('password'), $this->input->ip_address());
        if (!$result['ok']) {
            $this->session->set_flashdata('error', $this->login_error_message($result['error']));
            return redirect('reset-password/' . $token);
        }
        $this->session->set_flashdata('success', 'Your password has been reset — please log in.');
        redirect('login');
    }

    /* -------------------------------------------------------------- */
    /* Self-service MFA (authenticated)                              */
    /* -------------------------------------------------------------- */

    /**
     * POST /auth/mfa/setup  — begin TOTP enrolment.
     * Expects JSON; returns the provisioning URI + one-time recovery codes.
     * The caller then POSTs /auth/mfa/confirm with the first code.
     */
    public function mfa_setup() {
        if (!$this->auth->check()) {
            $this->json_error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!$this->input->is_ajax_request()) {
            show_404();
        }
        $result = $this->auth->setup_mfa($this->auth->user());
        if (!$result['ok']) {
            return $this->json_error($result['error'], 'Could not start MFA setup', 409);
        }
        $this->json_success(array(
            'secret'         => $result['secret'],
            'otpauth_uri'    => $result['otpauth_uri'],
            'recovery_codes' => $result['recovery_codes'],
        ));
    }

    /**
     * POST /auth/mfa/confirm — verify a TOTP code and enable MFA on the account.
     */
    public function mfa_confirm() {
        if (!$this->auth->check()) {
            $this->json_error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!$this->input->is_ajax_request()) {
            show_404();
        }
        $code = $this->json_code();
        if ($code === '') {
            return $this->json_error('BAD_CODE', 'Enter the 6-digit code from your authenticator app.', 400);
        }
        $result = $this->auth->confirm_mfa($this->auth->user(), $code);
        if (!$result['ok']) {
            return $this->json_error($result['error'], 'That code was not accepted.', 400);
        }
        $this->json_success(array('enabled' => true));
    }

    /**
     * POST /auth/mfa/disable — confirm a current code and disable MFA.
     */
    public function mfa_disable() {
        if (!$this->auth->check()) {
            $this->json_error('UNAUTHORIZED', 'Authentication required', 401);
        }
        if (!$this->input->is_ajax_request()) {
            show_404();
        }
        $code = $this->json_code();
        $result = $this->auth->disable_mfa($this->auth->user(), $code !== '' ? $code : null);
        if (!$result['ok']) {
            return $this->json_error($result['error'], 'Could not disable two-factor authentication.', 400);
        }
        $this->json_success(array('disabled' => true));
    }

    /**
     * Read a submitted verification code from either a JSON body or a form POST.
     */
    private function json_code() {
        $raw = $this->input->raw_input_stream;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['code'])) {
                return trim((string)$decoded['code']);
            }
        }
        return trim((string)$this->input->post('code'));
    }

    /* -------------------------------------------------------------- */
    /* Helpers                                                        */
    /* -------------------------------------------------------------- */

    private function no_super_admin() {
        try {
            if (!$this->db_ready) return false;
            return (int)$this->db->where('role', 'SUPER_ADMIN')->where('status', 'ACTIVE')->count_all_results('users') === 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function default_landing() {
        if (!$this->auth) return 'login';
        return $this->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF')) ? 'admin' : 'dashboard';
    }

    /** Remember the checkbox across an MFA challenge. */
    private function capture_remember() {
        if ($this->input->post('remember')) {
            $this->session->set_userdata('remember_login', 1);
        }
    }

    /**
     * Extend the session cookie when the user asked to be remembered.
     * This does not create a second persistent login token; it only lengthens
     * the existing first-party session cookie on this device.
     */
    private function apply_remember_cookie() {
        $wanted = $this->input->post('remember') || $this->session->userdata('remember_login');
        $this->session->unset_userdata('remember_login');
        if (!$wanted) return;

        $name = $this->config->item('sess_cookie_name') ?: session_name();
        if ($name === '' || !isset($_COOKIE[$name])) return;
        $params = session_get_cookie_params();
        $options = array(
            'expires'  => time() + (30 * 86400),
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?: '',
            'secure'   => !empty($params['secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        setcookie($name, $_COOKIE[$name], $options);
    }

    private function render_auth($view, $data = array()) {
        $data['current_user'] = $this->auth ? $this->auth->user() : null;
        $this->load->view('layouts/auth', $data + array('content_view' => $view));
    }

    private function login_error_message($code) {
        $map = array(
            'INVALID_CREDENTIALS'  => 'Invalid email/username or password.',
            'ACCOUNT_SUSPENDED'    => 'This account has been suspended.',
            'ACCOUNT_BANNED'       => 'This account has been banned.',
            'ACCOUNT_INACTIVE'     => 'This account is not active.',
            'MFA_INVALID_CODE'     => 'Invalid verification code.',
            'MFA_SESSION_EXPIRED'  => 'Your sign-in session expired. Please log in again.',
            'MFA_NOT_ENABLED'      => 'Two-factor authentication is not enabled on this account.',
            'INVALID_OR_EXPIRED_TOKEN' => 'This link is invalid or has expired.',
            'TOKEN_ALREADY_USED'   => 'This reset link has already been used. Request a new one.',
        );
        return $map[$code] ?? 'Unable to complete the request.';
    }

    private function register_error_message($code) {
        $map = array(
            'EMAIL_TAKEN'    => 'An account with that email already exists.',
            'USERNAME_TAKEN' => 'That username is already taken.',
            'REGISTRATION_FAILED' => 'Registration could not be completed. Please try again.',
        );
        return $map[$code] ?? 'Unable to complete registration.';
    }
}
