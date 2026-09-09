<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Deploy — CLI-only deployment checks (§20, §66: nothing web-triggered).
 *
 *   php index.php deploy              # same as check
 *   php index.php deploy check        # preflight; exits 1 if anything FAILs
 *   php index.php deploy storage      # create the runtime directories
 *
 * `check` is meant for the container entrypoint and for CI: it exits non-zero
 * when the deployment is unsafe, so a bad release stops before it serves
 * traffic rather than after.
 */
class Deploy extends Cron_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('Preflight');
    }

    public function index() { $this->check(); }

    public function check() {
        $report = $this->preflight->run();

        $this->line('');
        $this->line('  Preflight — environment: '.$report['environment']);
        $this->line('');

        foreach ($report['results'] as $r) {
            $mark = $r['status'] === Preflight::OK ? 'ok  '
                  : ($r['status'] === Preflight::WARN ? 'warn' : 'FAIL');
            $this->line(sprintf('  [%s] %-22s %s', $mark, $r['name'], $r['detail']));
            if ($r['hint'] && $r['status'] !== Preflight::OK) {
                $this->line(sprintf('         %s', $r['hint']));
            }
        }

        $this->line('');
        if ($report['ok']) {
            $this->line(sprintf('  PASS — %d warning(s).', $report['warned']));
            $this->line('');
            return;
        }
        $this->line(sprintf('  FAILED — %d problem(s), %d warning(s).',
            $report['failed'], $report['warned']));
        $this->line('');
        exit(1);
    }

    /**
     * Create the runtime directories.
     *
     * No longer a deployment step: the directories are committed (only their
     * contents are ignored), they ship inside application-deployment.zip, and
     * Env::ensure_writable_paths() recreates any that are missing on the first
     * request. This command remains for the case that motivates it — a host
     * where the web user may not create directories, so someone with a shell
     * has to — and for anyone who wants to see the resolved paths.
     */
    public function storage() {
        require_once APPPATH.'core/Env.php';
        Env::ensure_writable_paths();
        foreach (Env::writable_report() as $name => $info) {
            $state = !$info['exists'] ? 'FAILED  ' : ($info['writable'] ? 'ok      ' : 'READONLY');
            $this->line(sprintf('  %s %-10s %s', $state, $name, $info['path']));
        }
        $this->line('');
    }

    private function line($msg) { echo $msg."\n"; }
}
