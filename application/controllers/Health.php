<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Health — liveness and readiness probes (§20).
 *
 *   GET /health/live   is the process up?            (never touches deps)
 *   GET /health/ready  can it serve real traffic?    (checks deps)
 *
 * The split matters to an orchestrator: a failing liveness probe means restart
 * me, a failing readiness probe means stop sending traffic but leave me alone.
 * Conflating them turns a brief database blip into a restart loop.
 *
 * Readiness answers 200 or 503 and is deliberately terse — it is polled every
 * few seconds by things that only look at the status code, and it is
 * unauthenticated, so it must not leak configuration. Error strings from
 * exceptions are logged, never returned.
 */
class Health extends MY_Controller {

    public function index() { $this->live(); }

    /** Liveness: PHP is executing. No database, no Redis, no disk. */
    public function live() {
        // Skip the parent DB probe: liveness must stay green while MySQL is
        // still being imported on a fresh cPanel account.
        $this->json_success(array('status' => 'ok', 'time' => gmdate('c')));
    }

    /** Readiness: every dependency needed to serve a request. */
    public function ready() {
        $checks = array(
            'database' => $this->check_database(),
            'schema'   => $this->check_schema(),
            'storage'  => $this->check_storage(),
            'redis'    => $this->check_redis(),
        );

        // Redis is optional (sessions and cache can run on files/database), so
        // a 'skip' must not take the instance out of the load balancer.
        $ok = TRUE;
        foreach ($checks as $state) {
            if ($state === 'fail') $ok = FALSE;
        }

        $this->json(array(
            'success' => $ok,
            'data'    => array('status' => $ok ? 'ready' : 'unready', 'checks' => $checks),
        ), $ok ? 200 : 503);
    }

    /* ------------------------------------------------------------------ */

    private function check_database() {
        try {
            if (!windels_load_database()) return 'fail';
            $this->db->query('SELECT 1');
            return 'ok';
        } catch (Exception $e) {
            log_message('error', 'health: database check failed: '.$e->getMessage());
            return 'fail';
        }
    }

    /**
     * Serving requests against a schema the code does not expect corrupts
     * data quietly, so an un-migrated instance is not ready.
     */
    private function check_schema() {
        try {
            // migration.php is not part of the auto-loaded config set, so its
            // keys are absent from the registry until it is explicitly loaded.
            // Without this the expected version reads NULL and readiness can
            // never succeed, keeping every healthy deploy out of the pool.
            $this->config->load('migration', TRUE, TRUE);
            $cfg = $this->config->item('migration');
            $cfg = is_array($cfg) ? $cfg : array();

            $table = isset($cfg['migration_table']) ? $cfg['migration_table'] : 'migrations';
            if (!$this->db->table_exists($table)) return 'fail';
            $row = $this->db->get($table)->row();
            $current  = $row ? (int)$row->version : 0;
            $expected = isset($cfg['migration_version']) ? (int)$cfg['migration_version'] : 0;
            if ($expected === 0) return 'fail';
            return $current === $expected ? 'ok' : 'fail';
        } catch (Exception $e) {
            log_message('error', 'health: schema check failed: '.$e->getMessage());
            return 'fail';
        }
    }

    /** A read-only log directory loses the audit trail silently. */
    private function check_storage() {
        $path = rtrim((string)$this->config->item('log_path'), '/');
        if ($path === '') return 'skip';
        return (is_dir($path) && is_writable($path)) ? 'ok' : 'fail';
    }

    /**
     * Actually connect. The previous version reported 'ok' when the config
     * file merely loaded, so a dead Redis still looked healthy.
     */
    private function check_redis() {
        if (!class_exists('Redis')) return 'skip';
        try {
            $this->config->load('redis', TRUE);
            $cfg = $this->config->item('redis');
            if (!is_array($cfg)) return 'skip';

            $redis = new Redis();
            $connected = @$redis->connect(
                $cfg['host'] ?? '127.0.0.1',
                (int)($cfg['port'] ?? 6379),
                1.0 // seconds; a probe must not hang
            );
            if (!$connected) return 'fail';
            if (!empty($cfg['password'])) @$redis->auth($cfg['password']);
            $pong = @$redis->ping();
            @$redis->close();
            return $pong ? 'ok' : 'fail';
        } catch (Exception $e) {
            log_message('error', 'health: redis check failed: '.$e->getMessage());
            return 'fail';
        }
    }
}
