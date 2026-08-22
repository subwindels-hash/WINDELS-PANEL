<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Setup — the browser stand-in for the installer command that no longer exists.
 *
 * A cPanel deployment is: upload files, create the database, import
 * `database/production.sql`, edit `.env`. That already produces a working
 * panel with a SUPER_ADMIN whose password is printed in the SQL file's header,
 * so this page is not required to deploy. It exists for the two jobs that
 * would otherwise send an operator back to a terminal:
 *
 *   1. **Setting the first administrator's own credentials** instead of
 *      logging in once with a password that is documented in a public file.
 *   2. **Telling them what is wrong** when the panel does not come up — which
 *      directory is not writable, whether the database credentials in `.env`
 *      actually connect, whether the import ran, whether the encryption key is
 *      usable. All of that used to be `php index.php deploy check` output.
 *
 * ## Why it is safe to ship enabled-by-default-off
 *
 * The page answers nothing at all unless `VP_SETUP_TOKEN` is set in `.env` and
 * the request carries the same value. No token in `.env` (the shipped default)
 * means a plain 404 — not a login form, not a hint that the route exists —
 * so a deployment that never touches it has no extra attack surface. The token
 * is compared with hash_equals(), attempts are rate limited through the same
 * table the login screen uses, and every successful change is written to
 * `audit_logs`.
 *
 * The operator is told to delete the token line when they are done, and the
 * page says so on every screen while it is live.
 */
class Setup extends CI_Controller {

    /** Shortest administrator password this page will accept. */
    const MIN_PASSWORD = 12;

    /** Rate-limit bucket, namespaced so it cannot collide with an email. */
    const BUCKET = 'setup';

    public function __construct() {
        parent::__construct();
        date_default_timezone_set('UTC');
        require_once APPPATH.'core/Env.php';
        $this->load->helper(array('form', 'url', 'windels'));
        // RateLimiter writes login_attempts — only load it when MySQL is up.
        if (windels_load_database()) {
            $this->load->library('RateLimiter');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Screens                                                             */
    /* ------------------------------------------------------------------ */

    public function index() {
        $token = $this->gate();
        $this->render($token, array('checks' => $this->checks()));
    }

    /**
     * Set the administrator's username, email and password.
     *
     * Updates the existing SUPER_ADMIN when there is one (the row that
     * production.sql imported) and creates one otherwise, so this also
     * recovers a deployment whose only admin account was lost.
     */
    public function admin() {
        $token = $this->gate();

        if ($this->input->method(TRUE) !== 'POST') {
            redirect('setup?token='.rawurlencode($token));
            return;
        }

        $ip = $this->input->ip_address();
        $bucket = RateLimiter::scope(self::BUCKET, 'admin');
        if (isset($this->ratelimiter) && $this->ratelimiter->too_many_failures($ip, $bucket, 5, 900)) {
            $this->render($token, array(
                'checks' => $this->checks(),
                'error'  => 'Too many attempts. Try again in '
                            .ceil($this->ratelimiter->retry_after($ip, $bucket, 900, 5) / 60).' minutes.',
            ));
            return;
        }

        $username = trim((string)$this->input->post('username'));
        $email    = trim((string)$this->input->post('email'));
        $password = (string)$this->input->post('password');
        $confirm  = (string)$this->input->post('password_confirm');

        $error = $this->validate($username, $email, $password, $confirm);
        if ($error === null && !$this->database_ready()) {
            $error = 'The database is not reachable yet — fix the VP_DB_* values in .env first.';
        }
        if ($error !== null) {
            $this->note_attempt($bucket, $ip, false, 'SETUP_ADMIN_INVALID');
            $this->render($token, array(
                'checks' => $this->checks(),
                'error'  => $error,
                'form'   => array('username' => $username, 'email' => $email),
            ));
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $existing = $this->db->where('role', 'SUPER_ADMIN')->order_by('id', 'ASC')->limit(1)->get('users')->row();
        if ($existing) {
            $this->db->where('id', $existing->id)->update('users', array(
                'username'          => $username,
                'email'             => $email,
                'password_hash'     => $hash,
                'status'            => 'ACTIVE',
                'email_verified_at' => $existing->email_verified_at ?: $now,
                'updated_at'        => $now,
            ));
            $user_id = (int)$existing->id;
        } else {
            $group = $this->db->where('is_default', 1)->limit(1)->get('price_groups')->row();
            $this->db->insert('users', array(
                'public_id'         => windels_public_id(),
                'username'          => $username,
                'email'             => $email,
                'password_hash'     => $hash,
                'status'            => 'ACTIVE',
                'role'              => 'SUPER_ADMIN',
                'price_group_id'    => $group ? $group->id : null,
                'referral_code'     => strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)),
                'timezone'          => 'UTC',
                'locale'            => 'en',
                'email_verified_at' => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ));
            $user_id = (int)$this->db->insert_id();

            // Every account carries a wallet in the base currency, including
            // this one — the admin screens join against it.
            $this->db->insert('wallets', array(
                'public_id'  => windels_public_id(),
                'user_id'    => $user_id,
                'balance'    => '0.00000000',
                'currency'   => windels_base_currency(),
                'created_at' => $now,
                'updated_at' => $now,
            ));
        }

        $this->record_completion($user_id);
        $this->note_attempt($bucket, $ip, true, 'SETUP_ADMIN_OK');

        $this->render($token, array(
            'checks'  => $this->checks(),
            'success' => 'Administrator saved. Sign in at '.site_url('login').' with “'.html_escape($username).'”, '
                       .'then remove the VP_SETUP_TOKEN line from .env to close this page.',
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Gate                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Return the presented token, or 404 the request.
     *
     * Deliberately indistinguishable from a route that does not exist: with no
     * VP_SETUP_TOKEN in .env there is nothing here to find, and with the wrong
     * token there is nothing to brute force against a visible target.
     */
    private function gate() {
        $expected = (string)Env::get('SETUP_TOKEN', '');
        $presented = (string)$this->input->get_post('token');

        if ($expected === '' || strlen($expected) < 16) {
            if ($expected !== '') {
                log_message('error', 'setup: VP_SETUP_TOKEN is shorter than 16 characters — refusing to enable the setup page.');
            }
            show_404();
        }
        if ($presented === '' || !hash_equals($expected, $presented)) {
            $ip = $this->input->ip_address();
            $this->note_attempt(RateLimiter::scope(self::BUCKET, 'token'), $ip, false, 'SETUP_BAD_TOKEN');
            show_404();
        }
        return $expected;
    }

    /* ------------------------------------------------------------------ */
    /* Checks                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Everything a deployment can get wrong, in the order it goes wrong.
     *
     * Each entry is [status, label, detail, hint] where status is ok|warn|fail.
     */
    private function checks() {
        $out = array();

        $out[] = $this->check(
            version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'fail',
            'PHP version', PHP_VERSION,
            'Select PHP 8.1 or newer in cPanel → Select PHP Version.'
        );

        $missing = array();
        foreach (array('mysqli', 'mbstring', 'openssl', 'curl', 'json', 'bcmath') as $ext) {
            if (!extension_loaded($ext)) $missing[] = $ext;
        }
        $out[] = $this->check(
            $missing ? 'fail' : 'ok',
            'PHP extensions',
            $missing ? 'missing: '.implode(', ', $missing) : 'mysqli, mbstring, openssl, curl, json, bcmath',
            'Enable them in cPanel → Select PHP Version → Extensions.'
        );

        foreach (Env::writable_report() as $name => $info) {
            $out[] = $this->check(
                $info['writable'] ? 'ok' : 'fail',
                'Writable: '.$name,
                $this->relative($info['path']).($info['exists'] ? '' : ' (missing)'),
                'cPanel → File Manager → select the folder → Permissions → 755 (or 775).'
            );
        }

        $base = (string)Env::get('APP_URL', '');
        $out[] = $this->check(
            $base === '' ? 'warn' : (stripos($base, 'https://') === 0 ? 'ok' : 'warn'),
            'Base URL', $base === '' ? 'not set (guessed from the request)' : $base,
            'Set VP_BASE_URL in .env to the https:// address of this panel.'
        );

        $key_problem = EncryptionService::key_problem(Env::get('ENCRYPTION_KEY'));
        $out[] = $this->check(
            $key_problem === null ? 'ok' : 'fail',
            'Encryption key', $key_problem === null ? 'set' : $key_problem,
            'Set VP_ENCRYPTION_KEY in .env — and when migrating a panel, carry the old value across unchanged.'
        );

        $out[] = $this->check(
            (string)Env::get('AUTH_SECRET', Env::get('APP_KEY', '')) === '' ? 'warn' : 'ok',
            'Auth secret',
            (string)Env::get('AUTH_SECRET', Env::get('APP_KEY', '')) === '' ? 'falling back to the encryption key' : 'set',
            'Set VP_AUTH_SECRET in .env to isolate session/token signing from at-rest encryption.'
        );

        $db_ok = $this->database_ready();
        $out[] = $this->check(
            $db_ok ? 'ok' : 'fail',
            'Database connection',
            $db_ok ? Env::get('DB_NAME').' on '.Env::get('DB_HOST') : 'cannot connect with the .env credentials',
            'cPanel → MySQL Databases: check the database name, user, password, and that the user is assigned to the database.'
        );

        if ($db_ok) {
            $version = $this->schema_version();
            $out[] = $this->check(
                $version > 0 ? 'ok' : 'fail',
                'Database import',
                $version > 0 ? 'schema version '.$version : 'no tables found',
                'cPanel → phpMyAdmin → select the database → Import → database/production.sql.'
            );

            $admins = $version > 0 ? (int)$this->db->where('role', 'SUPER_ADMIN')->where('status', 'ACTIVE')->count_all_results('users') : 0;
            $out[] = $this->check(
                $admins > 0 ? 'ok' : 'warn',
                'Administrator', $admins > 0 ? $admins.' active' : 'none — create one below',
                'Use the form on this page.'
            );
        }

        return $out;
    }

    private function check($status, $label, $detail, $hint) {
        return array('status' => $status, 'label' => $label, 'detail' => $detail, 'hint' => $hint);
    }

    private function database_ready() {
        try {
            if (!windels_load_database()) {
                return false;
            }
            $this->db->query('SELECT 1');
            return true;
        } catch (Exception $e) {
            log_message('error', 'setup: database check failed: '.$e->getMessage());
            return false;
        } catch (Throwable $e) {
            log_message('error', 'setup: database check failed: '.$e->getMessage());
            return false;
        }
    }

    /** Applied schema version, or 0 when the import has not happened. */
    private function schema_version() {
        try {
            if (!$this->db->table_exists('migrations')) return 0;
            $row = $this->db->select_max('version')->get('migrations')->row();
            return $row ? (int)$row->version : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    private function relative($path) {
        $root = Env::root();
        return strpos($path, $root) === 0 ? ltrim(substr($path, strlen($root)), '/') : $path;
    }

    /* ------------------------------------------------------------------ */

    private function validate($username, $email, $password, $confirm) {
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $username)) {
            return 'Pick a username of 3–64 letters, digits, dots, dashes or underscores.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'That email address does not look valid.';
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            return 'The password must be at least '.self::MIN_PASSWORD.' characters.';
        }
        if (!hash_equals($password, $confirm)) {
            return 'The two passwords do not match.';
        }
        return null;
    }

    /** Leave a trail: settings row + audit log, both visible in the admin UI. */
    private function record_completion($user_id) {
        $now = gmdate('Y-m-d H:i:s');
        try {
            $exists = $this->db->where('setting_key', 'setup_completed_at')->count_all_results('settings') > 0;
            $payload = json_encode(array('value' => $now));
            if ($exists) {
                $this->db->where('setting_key', 'setup_completed_at')->update('settings', array('setting_value' => $payload));
            } else {
                $this->db->insert('settings', array(
                    'setting_key'   => 'setup_completed_at',
                    'setting_value' => $payload,
                    'category'      => 'system',
                    'is_public'     => 0,
                ));
            }
        } catch (Exception $e) {
            log_message('error', 'setup: could not record completion: '.$e->getMessage());
        }

        try {
            $this->db->insert('audit_logs', array(
                'actor_id'    => $user_id,
                'action'      => 'setup.admin_configured',
                'resource'    => 'user',
                'resource_id' => (string)$user_id,
                'ip'          => $this->input->ip_address(),
                'user_agent'  => (string)$this->input->user_agent(),
                'created_at'  => $now,
            ));
        } catch (Exception $e) {
            log_message('error', 'setup: could not write audit log: '.$e->getMessage());
        }
    }

    private function render($token, array $data) {
        $data['token'] = $token;
        $this->output->set_header('X-Robots-Tag: noindex, nofollow');
        $this->load->view('setup/index', $data);
    }
}
