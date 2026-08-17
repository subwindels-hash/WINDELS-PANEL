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
     * Create the runtime directories. They are gitignored (correctly — their
     * contents are not source), which means a fresh clone has no storage/logs
     * at all and CI3 silently drops every log line it tries to write.
     */
    public function storage() {
        $root = rtrim(realpath(APPPATH.'..'), '/');
        foreach (Preflight::WRITABLE_PATHS as $rel) {
            $path = $root.'/'.$rel;
            if (is_dir($path)) {
                $this->line('  exists   '.$rel);
                continue;
            }
            if (@mkdir($path, 0775, TRUE)) {
                $this->line('  created  '.$rel);
            } else {
                $this->line('  FAILED   '.$rel);
            }
        }
        $this->line('');
    }

    private function line($msg) { echo $msg."\n"; }
}
