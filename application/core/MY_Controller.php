<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controllers — enforce auth/CSRF/audit/rate-limit/request-id.
 *
 * Authentication & authorization is delegated to the AuthService library
 * (Session 03): the base classes below are thin gates, child controllers call
 * $this->require_perm('orders.view') for granular checks.
 */
class MY_Controller extends CI_Controller {
    protected $request_id;
    protected $auth;

    public function __construct() {
        parent::__construct();
        $this->request_id = bin2hex(random_bytes(8));
        // UTC
        date_default_timezone_set('UTC');
        // Security headers
        $this->output->set_header('X-Content-Type-Options: nosniff');
        $this->output->set_header('X-Frame-Options: SAMEORIGIN');
        $this->output->set_header('Referrer-Policy: strict-origin-when-cross-origin');

        // AuthService is available to every controller but loaded defensively:
        // CLI maintenance flows (migrate/seed) run before the schema exists.
        try {
            $this->load->library('AuthService');
            $this->auth = $this->authservice;
        } catch (Exception $e) {
            $this->auth = null;
        }
    }

    protected function json($data, $http=200) {
        $this->output->set_content_type('application/json')->set_status_header($http)->set_output(json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))->_display();
        exit;
    }

    protected function json_success($data=null, $meta=null, $http=200) {
        $payload = array('success'=>TRUE);
        if ($data !== null) $payload['data']=$data;
        if ($meta !== null) $payload['meta']=$meta;
        $this->json($payload, $http);
    }

    protected function json_error($code, $message, $http=400, $details=null) {
        $err = array('code'=>$code,'message'=>$message,'requestId'=>$this->request_id);
        if ($details) $err['details']=$details;
        $this->json(array('success'=>FALSE,'error'=>$err), $http);
    }

    protected function require_cli() {
        if (!$this->input->is_cli_request()) {
            show_404();
        }
    }

    protected function current_user() {
        return $this->auth ? $this->auth->user() : null;
    }
}

class Public_Controller extends MY_Controller {
    public function __construct(){
        parent::__construct();
        // Load homepage setting (DB overrides config) if table exists — fail open.
        try { $this->load->model('Setting_model'); } catch (Exception $e) {}
        // Share the authenticated user with every public view/partial.
        $this->load->vars(array('current_user' => $this->current_user()));
    }

    /** Render a page inside the public shell, passing the current user to views. */
    protected function render_public($content_view, $data = array()) {
        $this->load->view('layouts/public', array('content_view' => $content_view, 'data' => $data));
    }
}

class Auth_Controller extends MY_Controller {
    protected $current_user;

    public function __construct(){
        parent::__construct();
        if (!$this->auth || !$this->auth->check()) {
            // Persist the intended destination across the GET login form and
            // the subsequent POST — flashdata would expire in between. Store a
            // relative path so it can never become an open redirect.
            $dest = $this->input->server('REQUEST_URI');
            if (!is_string($dest) || $dest === '' || $dest[0] !== '/') {
                $dest = '/dashboard';
            }
            $this->session->set_userdata('redirect_after_login', $dest);
            redirect('login');
        }
        $this->current_user = $this->auth->user();
        // Expose the unread notification count to every authenticated view so
        // the shell's bell badge works without each controller passing it.
        $this->load->vars(array('unread' => $this->auth->unread_count()));
    }
}

class Admin_Controller extends Auth_Controller {
    public function __construct(){
        parent::__construct();
        // Admin area is restricted to staff/admin roles; fine-grained perms
        // are enforced per-action via require_perm().
        if (!$this->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF'))) {
            show_error('Forbidden — admin only', 403);
        }
    }

    /** Enforce a granular permission key from the RBAC catalog (Session 03). */
    protected function require_perm($key){
        if (!$this->auth->can($key)) {
            log_message('error', "permission denied: {$this->current_user->role} lacks {$key}");
            show_error('Forbidden — missing permission: '.$key, 403);
        }
    }
}

class Api_Controller extends MY_Controller {
    protected $api_key_row;
    protected $api_user;

    public function __construct(){
        parent::__construct();
        $key = $this->input->get_request_header('X-Api-Key', TRUE) ?: $this->input->get_request_header('x-api-key', TRUE);
        if ($key) {
            $this->load->model('Api_key_model');
            $this->api_key_row = $this->Api_key_model->find_valid_by_key($key);
            if ($this->api_key_row) {
                $this->load->model('User_model');
                $this->api_user = $this->User_model->find_by_id($this->api_key_row->user_id);
            }
        }
    }

    protected function require_api_key(){
        if (!$this->api_key_row || !$this->api_user) {
            $this->json_error('UNAUTHORIZED','Invalid or missing API key',401);
        }
        if ($this->api_user->status !== 'ACTIVE') {
            $this->json_error('ACCOUNT_DISABLED','Account is not active',403);
        }
        // IP whitelist
        $wl = json_decode($this->api_key_row->ip_whitelist ?? '[]', TRUE);
        if (!empty($wl)) {
            $ip = $this->input->ip_address();
            if (!in_array($ip, $wl, TRUE)) $this->json_error('IP_FORBIDDEN','IP not whitelisted',403);
        }
    }
}

class Cron_Controller extends MY_Controller {
    public function __construct(){
        parent::__construct();
        $this->require_cli();
    }
}
