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

        $this->db_ready = marvy_load_database();

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

        // Maintenance mode (§ settings: maintenance_mode). When enabled, hold
        // non-staff visitors on a branded page while staff keep working.
        $this->enforce_maintenance();

        // Demo mode (feature flag `demo_mode`, seeded off). A public demo
        // deployment needs to stay browsable and stay put — nobody should be
        // able to change a price, delete a listing or drain a demo wallet
        // from the internet. On, every mutating request is refused except
        // the ones needed to actually experience the demo (sign in/out,
        // health checks); reads are untouched.
        $this->enforce_demo_mode();

        $this->capture_referral();
    }

    /**
     * Remember a ?ref= code arriving on any page.
     *
     * Campaign links point wherever the advert wants — the homepage, a service
     * page, a blog post — not just /register. Capturing this only on the
     * registration form meant an advert driving traffic to the homepage lost
     * its attribution entirely, so the campaign looked like it converted
     * nobody.
     *
     * Deliberately cheap and silent: one session read for the overwhelming
     * majority of requests that carry no ?ref=, and any failure is swallowed.
     * Referral bookkeeping must never be able to take a page down.
     */
    protected function capture_referral() {
        if (!isset($this->input) || $this->input->is_cli_request()) return;
        if (strtoupper((string)$this->input->method(true)) !== 'GET') return;

        $code = $this->input->get('ref', true);
        if (!$code) return;

        // An authenticated visitor cannot be referred — they already have an
        // account, and attribution happens once at registration.
        try {
            if ($this->auth && $this->auth->check()) return;
        } catch (Throwable $e) { /* treat as anonymous */ }

        if (!$this->db_ready) return;

        try {
            $this->load->library('ReferralService');
            $this->referralservice->remember_visit($code, $this->uri->uri_string());
        } catch (Throwable $e) {
            log_message('error', 'referral capture failed: '.$e->getMessage());
        }
    }

    /**
     * Maintenance gate.
     *
     * Enabled by config `marvy.maintenance` (bool) or the DB setting
     * `maintenance_mode`. Staff (SUPER_ADMIN/ADMIN/STAFF) pass through so they
     * can keep operating; the login/health/status routes stay reachable so a
     * staff member can authenticate and load balancers keep getting a healthy
     * response. Everything else sees a 503 holding page.
     */
    protected function enforce_maintenance() {
        if (!isset($this->input) || $this->input->is_cli_request()) return;

        $enabled = false;
        $cfg = $this->config->item('marvy');
        if (is_array($cfg) && !empty($cfg['maintenance'])) $enabled = true;

        if (!$enabled && $this->db_ready) {
            try {
                $this->load->model('Setting_model');
                $v = $this->Setting_model->get('maintenance_mode');
                if ($v !== null && $v !== '' && in_array(strtolower(trim((string)$v)), array('1','true','yes','on'), true)) {
                    $enabled = true;
                }
            } catch (Throwable $e) { /* settings unavailable — fail open */ }
        }

        if (!$enabled) return;

        $path = trim((string)$this->uri->uri_string(), '/');
        $exempt = array('login','admin/login','forgot-password','logout','impersonation/stop',
                        'health','health/live','health/ready');
        if (in_array($path, $exempt, true)) return;
        if (strpos($path, 'reset-password/') === 0) return;
        if (strpos($path, 'health') === 0) return;

        $role = null;
        try {
            $u = $this->auth ? $this->auth->user() : null;
            $role = $u ? $u->role : null;
        } catch (Throwable $e) { $role = null; }
        if ($role && in_array($role, array('SUPER_ADMIN','ADMIN','STAFF'), true)) return;

        $this->output->set_status_header(503);
        $this->load->view('errors/html/maintenance');
        exit;
    }

    /**
     * Demo mode: read-only for anonymous/customer traffic, so a public demo
     * deployment can be browsed and never mutated from the internet. Staff
     * are exempt (same as maintenance mode above) — they still need to
     * operate the panel, moderate it, and are the only ones who can turn
     * the flag back off. Only the DB-backed feature flag is checked,
     * deliberately not `env_bool('DEMO_MODE')` — that env flag governs which
     * mock provider adapters load (see config/providers.php) and is a
     * deploy-time decision; this is the runtime switch an operator flips
     * from Admin → Settings → Feature flags without redeploying.
     */
    protected function enforce_demo_mode() {
        if (!isset($this->input) || $this->input->is_cli_request()) return;
        if (!$this->db_ready) return;
        if (!marvy_feature_enabled('demo_mode', false)) return;

        $method = strtoupper((string)$this->input->method(true));
        if (!in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) return;

        $path = trim((string)$this->uri->uri_string(), '/');
        $exempt_prefixes = array('login', 'admin/login', 'logout', 'register', 'csrf');
        foreach ($exempt_prefixes as $prefix) {
            if ($path === $prefix || strpos($path, $prefix.'/') === 0) return;
        }

        // Staff keep working, same exemption as enforce_maintenance() above.
        // Without it, switching demo_mode on would be a one-way door: nobody
        // — not even a SUPER_ADMIN with the password — could POST it back
        // off again without direct database access. The permission boundary
        // that actually matters here (settings.manage, orders.refund, etc.)
        // is still enforced by each controller's own require_perm() call.
        $role = null;
        try {
            $u = $this->auth ? $this->auth->user() : null;
            $role = $u ? $u->role : null;
        } catch (Throwable $e) { $role = null; }
        if ($role && in_array($role, array('SUPER_ADMIN', 'ADMIN', 'STAFF'), true)) return;

        $message = 'This is a read-only demo. Changes are disabled.';
        $wants_json = strpos($path, 'api/') === 0
            || (isset($this->input) && $this->input->is_ajax_request());

        $this->output->set_status_header(403);
        if ($wants_json) {
            $this->output->set_content_type('application/json');
            $this->output->set_output(json_encode(array(
                'success' => false,
                'error' => array('code' => 'DEMO_MODE', 'message' => $message),
            )));
            exit;
        }

        if (isset($this->session)) {
            $this->session->set_flashdata('error', $message);
        }
        $referer = isset($this->input) ? (string)$this->input->server('HTTP_REFERER') : '';
        redirect($referer !== '' ? $referer : '/');
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
     * script-src is nonce-only: no 'unsafe-inline', no 'unsafe-eval'. Every
     * inline <script> in the views prints the nonce via csp_nonce_attr(), and
     * the inline event-handler attributes the admin UI used to rely on
     * (onclick="…showModal()", onsubmit="return confirm(…)", …) were replaced
     * by declarative data-* attributes handled by one delegated listener in
     * assets/js/app.js — attributes a nonce can never cover.
     *
     * Inline *styles* are still allowed. They are used widely for layout in the
     * admin views and cannot execute script, so the tradeoff is different.
     */
    /**
     * `frame-src` for the contact map, or nothing at all.
     *
     * Read from settings rather than hard-coded so the allowance disappears
     * with the feature. Any failure (no database yet, table missing) returns
     * the strict answer: no frames.
     */
    private function map_frame_src() {
        try {
            if (!function_exists('marvy_load_database') || !marvy_load_database()) return null;
            $this->load->model('Setting_model');
            $on = $this->Setting_model->get('contact_map_enabled', false);
            if (!($on === true || $on === 1 || $on === '1' || $on === 'true')) return null;
        } catch (Throwable $e) {
            return null;
        }
        return "frame-src 'self' https://www.openstreetmap.org https://maps.google.com https://www.google.com";
    }

    protected function send_security_headers() {
        $this->csp_nonce = base64_encode(random_bytes(16));
        // Expose it to views via the helper, which does not depend on knowing
        // which controller rendered the page.
        $GLOBALS['__marvy_csp_nonce'] = $this->csp_nonce;

        $csp = array(
            "default-src 'self'",
            "base-uri 'self'",
            // No plugins, and nothing may frame us (the modern X-Frame-Options).
            "object-src 'none'",
            // Development / preview hosts are often embedded in an IDE iframe.
            // Production stays locked to same-origin (and X-Frame-Options below).
            (env_str('APP_ENV') === 'production') ? "frame-ancestors 'self'" : "frame-ancestors *",
            "form-action 'self'",
            // Nonce-only. Inline handler attributes are gone (see the method
            // docblock); anything that needs script prints the nonce.
            "script-src 'self' 'nonce-".$this->csp_nonce."'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "connect-src 'self'",
            // Nothing may be framed by default. The contact page's map is the
            // only embed this panel has, so the two providers it can use are
            // allowed only when an operator has actually switched the map on —
            // an install without a map keeps a policy that forbids every
            // iframe outright.
            $this->map_frame_src(),
        );
        $csp = array_values(array_filter($csp));

        $this->output->set_header('X-Content-Type-Options: nosniff');
        if (env_str('APP_ENV') === 'production') {
            $this->output->set_header('X-Frame-Options: SAMEORIGIN');
        }
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

    /** Render a page inside the global public shell, passing the current user to views. */
    protected function render_public($content_view, $data = array()) {
        $this->load->view('layouts/main', array('content_view' => $content_view, 'data' => $data));
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
            $path = isset($this->uri) ? trim((string)$this->uri->uri_string(), '/') : '';
            if ($path === 'admin' || strpos($path, 'admin/') === 0) {
                redirect('admin/login');
            }
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
        $this->enforce_admin_mfa();
    }

    /**
     * Enforce mandatory MFA for staff when the `admin_mfa_required` setting is
     * on. Staff without MFA are redirected to the security screen to enrol —
     * never hard-blocked, which would strand them outside the back office.
     */
    protected function enforce_admin_mfa() {
        try {
            $this->load->model('Setting_model');
            $required = $this->Setting_model->get('admin_mfa_required', false);
        } catch (Throwable $e) { return; /* settings unavailable — fail open */ }
        if ($required === null || $required === ''
            || !in_array(strtolower(trim((string)$required)), array('1','true','yes','on'), true)) {
            return;
        }

        $user = $this->current_user ?? null;
        if (!$user || !in_array($user->role, array('SUPER_ADMIN','ADMIN','STAFF'), true)) return;
        if (!empty($user->mfa_enabled)) return;

        // Allow the routes a staff member needs to enrol, sign out or stop an
        // impersonation; everything else redirects until MFA is on.
        $path = trim((string)$this->uri->uri_string(), '/');
        if (in_array($path, array('dashboard/security', 'dashboard/profile', 'logout', 'impersonation/stop'), true)) return;
        if (strpos($path, 'auth/mfa') === 0) return;

        $this->session->set_flashdata('warning',
            'Two-factor authentication is required for staff access. Enable it below, then continue.');
        redirect('dashboard/security');
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
