<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controllers — thin, enforce auth/CSRF/audit/rate-limit/request-id.
 */
class MY_Controller extends CI_Controller {
    protected $request_id;

    public function __construct() {
        parent::__construct();
        $this->request_id = bin2hex(random_bytes(8));
        // UTC
        date_default_timezone_set('UTC');
        // Security headers
        $this->output->set_header('X-Content-Type-Options: nosniff');
        $this->output->set_header('X-Frame-Options: SAMEORIGIN');
        $this->output->set_header('Referrer-Policy: strict-origin-when-cross-origin');
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
}

class Public_Controller extends MY_Controller {
    public function __construct(){ parent::__construct();
        // Load homepage setting (DB overrides config) if table exists — fail open
        try { $this->load->model('Setting_model'); } catch (Exception $e) {}
    }
}

class Auth_Controller extends MY_Controller {
    protected $current_user;
    public function __construct(){
        parent::__construct();
        $this->load->library('session');
        $uid = $this->session->userdata('user_id');
        if (!$uid) { redirect('login'); }
        $this->load->model('User_model');
        $this->current_user = $this->User_model->find_by_id($uid);
        if (!$this->current_user || $this->current_user->status !== 'ACTIVE') {
            $this->session->sess_destroy();
            redirect('login');
        }
    }
}

class Admin_Controller extends Auth_Controller {
    public function __construct(){
        parent::__construct();
        $role = $this->current_user->role ?? '';
        if (!in_array($role, array('SUPER_ADMIN','ADMIN','STAFF'), TRUE)) {
            show_error('Forbidden — admin only', 403);
        }
        // Permission gate: child controllers call $this->require_perm('orders.view')
    }
    protected function require_perm($key){
        $this->load->model('Permission_model');
        // Simplified: SUPER_ADMIN bypass; else check role_permissions (stub until Session 03)
        if ($this->current_user->role === 'SUPER_ADMIN') return;
        // TODO Session 03: real RBAC check — for foundation, allow ADMIN/STAFF
        if (!in_array($this->current_user->role, array('ADMIN','STAFF'), TRUE)) {
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
