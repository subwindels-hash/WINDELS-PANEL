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

    /**
     * Correlation id for this request, for logs and the audit trail.
     * Public accessor because collaborators (AuthService, services) need it
     * but must not reach into a protected property to get it.
     */
    public function request_id() { return $this->request_id; }

    /** Per-request CSP nonce; views use csp_nonce() to whitelist inline JS. */
    protected $csp_nonce;

    /** Whether MySQL answered this request. Public pages degrade; auth does not. */
    protected $db_ready = false;

    public function __construct() {
        parent::__construct();
        $this->request_id = bin2hex(random_bytes(8));
        // Configurable (VP_TIMEZONE / APP_TIMEZONE), UTC by default. Storage
        // columns stay UTC DATETIME either way — services write gmdate().
        $tz = class_exists('Env') ? Env::get('APP_TIMEZONE', 'UTC') : 'UTC';
        @date_default_timezone_set(($tz === null || $tz === '') ? 'UTC' : $tz);
        $this->send_security_headers();

        $this->db_ready = windels_load_database();

        // AuthService is available to every controller but loaded defensively:
        // CLI maintenance flows (migrate/seed) run before the schema exists.
        try {
            $this->load->library('AuthService');
            $this->auth = $this->authservice;
        } catch (Exception $e) {
            $this->auth = null;
        }

        // A customer impersonation is a read-only support lens, not a second
        // customer login. Keep its request boundary in the common base so an
        // operator cannot bypass it by posting to a public/auth controller
        // instead of a dashboard controller. The one exception is the
        // dedicated POST endpoint that restores the original staff identity.
        $this->enforce_impersonation_request_boundary();
    }

    private function enforce_impersonation_request_boundary() {
        if (!isset($this->session)) return;
        $context = $this->session->userdata('customer_impersonation');
        if ($context === null) return;

        $path = isset($this->uri) ? trim((string)$this->uri->uri_string(), '/') : '';
        if ($path === '' && isset($this->input)) {
            $request_uri = (string)$this->input->server('REQUEST_URI');
            $path = trim((string)parse_url($request_uri, PHP_URL_PATH), '/');
        }

        // Never let the ordinary logout action discard the only copy of the
        // original staff identity. Operators must use the signed stop form.
        if ($path === 'logout') {
            $this->session->set_flashdata('warning',
                'End customer impersonation before signing out of your staff account.');
            redirect('dashboard');
        }
        $method = isset($this->input) ? strtoupper((string)$this->input->method(true)) : '';
        if ($path === 'impersonation/stop' && $method === 'POST') return;
        $dashboard_read = ($method === 'GET' || $method === 'HEAD')
            && ($path === 'dashboard' || strpos($path, 'dashboard/') === 0);
        if (!$dashboard_read) {
            show_error('Customer impersonation is read-only. End it before making changes.', 403);
        }
    }

    /**
     * Security response headers (§61).
     *
     * The CSP is nonce-based rather than 'unsafe-inline': a handful of views
     * ship inline <script> blocks, and allowing unsafe-inline to accommodate
     * them would forfeit most of the protection the policy exists to provide.
     * Views emit their inline scripts as <script <?=csp_nonce_attr()?>>.
     *
     * Inline *styles* are still allowed. They are used widely for layout in the
     * admin views and cannot execute script, so the tradeoff is different.
     */
    protected function send_security_headers() {
        $this->csp_nonce = base64_encode(random_bytes(16));
        // Expose it to views via the helper, which does not depend on knowing
        // which controller rendered the page.
        $GLOBALS['__windels_csp_nonce'] = $this->csp_nonce;

        $csp = array(
            "default-src 'self'",
            "base-uri 'self'",
            // No plugins, and nothing may frame us (the modern X-Frame-Options).
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$this->csp_nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "connect-src 'self'",
        );

        $this->output->set_header('X-Content-Type-Options: nosniff');
        $this->output->set_header('X-Frame-Options: SAMEORIGIN');
        $this->output->set_header('Referrer-Policy: strict-origin-when-cross-origin');
        $this->output->set_header('Content-Security-Policy: '.implode('; ', $csp));
        // Browsers ignore the legacy per-feature opt-outs; deny the powerful
        // ones a panel never needs.
        $this->output->set_header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

        // HSTS only over TLS: sending it on a plaintext dev host would pin
        // developers into https://localhost for six months.
        if (getenv('APP_ENV') === 'production' && $this->is_https()) {
            $this->output->set_header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        }
    }

    private function is_https() {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        // Behind Nginx/a load balancer the origin request is plaintext.
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return strtolower($proto) === 'https';
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
        if ($this->db_ready) {
            try { $this->load->model('Setting_model'); } catch (Exception $e) {}
        }
        // Share the authenticated user with every public view/partial.
        $user = null;
        try { $user = $this->current_user(); } catch (Throwable $e) { $user = null; }
        $this->load->vars(array('current_user' => $user, 'db_ready' => $this->db_ready));
    }

    /** Render a page inside the public shell, passing the current user to views. */
    protected function render_public($content_view, $data = array()) {
        $this->load->view('layouts/public', array('content_view' => $content_view, 'data' => $data));
    }
}

class Auth_Controller extends MY_Controller {
    protected $current_user;
    protected $impersonation = array('active' => false);

    public function __construct(){
        parent::__construct();

        // Revalidate both identities, the permission and the hard expiry on
        // every impersonated dashboard request. This runs before check() so a
        // target suspended mid-session restores the staff account instead of
        // stranding the browser at the customer login screen.
        if ($this->session->userdata('customer_impersonation') !== null) {
            $this->load->library('ImpersonationService');
            $this->impersonation = $this->impersonationservice->enforce(
                $this->input->ip_address(),
                $this->input->user_agent(),
                $this->request_id
            );
            if (!empty($this->impersonation['ended'])) {
                $this->session->set_flashdata('warning',
                    'The customer impersonation session ended: '
                    .strtolower(str_replace('_', ' ', $this->impersonation['reason'] ?? 'invalid session')).'.');
                redirect(!empty($this->impersonation['actor_restored']) ? 'admin' : 'login');
            }
        }

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

        if (!empty($this->impersonation['active'])) {
            $path = trim((string)$this->uri->uri_string(), '/');
            $logged = $this->impersonationservice->record_access(
                $this->impersonation,
                $this->input->method(true),
                $path,
                $this->input->ip_address(),
                $this->input->user_agent(),
                $this->request_id
            );
            if (!$logged) {
                $this->impersonationservice->end('AUDIT_UNAVAILABLE');
                show_error('The impersonation audit trail is unavailable. The session has been ended.', 503);
            }
        }

        // Expose the unread count and the persistent impersonation banner to
        // every authenticated layout.
        $this->load->vars(array(
            'unread'        => $this->auth->unread_count(),
            'impersonation' => $this->impersonation,
        ));
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
