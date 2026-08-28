<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ApiRateLimiter — fixed-window counters for the reseller API.
 *
 * Two things this had to get right that the first version did not:
 *
 * **Where the counters live.** They were written to
 * `sys_get_temp_dir()/marvy_ratelimit`. On shared hosting that directory is
 * frequently shared between accounts and swept by the host, so counters could
 * be read, poisoned or silently reset by something outside the panel — and the
 * application already ships a private, web-inaccessible directory for exactly
 * this (`storage/cache/ratelimit`, created and guarded by the deployment).
 * That path is used first; the system temp directory remains a last resort so
 * a misconfigured install degrades to "still limited" rather than "not
 * limited".
 *
 * **More than one web node.** File counters are per-node, so two PHP servers
 * behind a load balancer each allow the full limit. When Redis is configured
 * (the compose stack ships it) counters go there instead and every node shares
 * one window. Redis being unreachable falls back to files rather than failing
 * the request: an outage in the limiter must not take the API down.
 */
class ApiRateLimiter {

    private $ci;
    private $dir;
    private $redis = null;
    private $redis_checked = false;

    public function __construct() {
        $this->ci =& get_instance();
        $this->dir = $this->storage_dir();
    }

    /**
     * @param string $bucket   e.g. "key:42", "ip:1.2.3.4"
     * @param int    $limit    max requests per window
     * @param int    $window   window length in seconds
     * @return array{allowed:bool,remaining:int,retry_after:int,limit:int}
     */
    public function check($bucket, $limit, $window = 60) {
        $limit = max(1, (int)$limit);
        $window = max(1, (int)$window);

        $redis = $this->redis();
        if ($redis !== null) {
            $result = $this->check_redis($redis, $bucket, $limit, $window);
            if ($result !== null) return $result;
        }
        return $this->check_file($bucket, $limit, $window);
    }

    /**
     * Read a bucket without consuming from it.
     *
     * Used where the answer decides whether to do work at all (refusing an
     * unauthenticated flood), so that the refusal itself does not count as
     * another request and extend the lockout for ever.
     */
    public function peek($bucket, $limit, $window = 60) {
        $limit = max(1, (int)$limit);
        $count = 0;
        $started = 0;

        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $count = (int)$redis->get($this->redis_key($bucket, $window));
                $started = time();
            } catch (Throwable $e) { $count = 0; }
        } else {
            $data = $this->read_file($this->file_for($bucket), $window);
            $count = (int)$data['count'];
            $started = (int)$data['window_start'];
        }

        $allowed = $count < $limit;
        return array(
            'allowed'     => $allowed,
            'remaining'   => max(0, $limit - $count),
            'retry_after' => $allowed ? 0 : max(1, $window - (time() - $started)),
            'limit'       => $limit,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Backends                                                            */
    /* ------------------------------------------------------------------ */

    private function check_redis($redis, $bucket, $limit, $window) {
        try {
            $key = $this->redis_key($bucket, $window);
            $count = (int)$redis->incr($key);
            if ($count === 1) $redis->expire($key, $window);
            $ttl = (int)$redis->ttl($key);
            if ($ttl < 0) $ttl = $window;

            $allowed = $count <= $limit;
            return array(
                'allowed'     => $allowed,
                'remaining'   => max(0, $limit - $count),
                'retry_after' => $allowed ? 0 : max(1, $ttl),
                'limit'       => $limit,
            );
        } catch (Throwable $e) {
            log_message('error', 'rate limiter: redis unavailable, falling back to files: '.$e->getMessage());
            $this->redis = null;
            return null;
        }
    }

    private function check_file($bucket, $limit, $window) {
        $file = $this->file_for($bucket);
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            // Never fail the request because the counter could not be written;
            // log it so the operator can fix the permissions.
            log_message('error', 'rate limiter: cannot open counter file '.$file);
            return array('allowed' => true, 'remaining' => $limit, 'retry_after' => 0, 'limit' => $limit);
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = $raw ? json_decode($raw, true) : null;
        $now = time();
        if (!is_array($data) || ($now - (int)($data['window_start'] ?? 0)) >= $window) {
            $data = array('window_start' => $now, 'count' => 0);
        }
        $data['count']++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $allowed = $data['count'] <= $limit;
        return array(
            'allowed'     => $allowed,
            'remaining'   => max(0, $limit - $data['count']),
            'retry_after' => $allowed ? 0 : max(1, $window - ($now - (int)$data['window_start'])),
            'limit'       => $limit,
        );
    }

    private function read_file($file, $window) {
        $raw = @file_get_contents($file);
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || (time() - (int)($data['window_start'] ?? 0)) >= $window) {
            return array('window_start' => time(), 'count' => 0);
        }
        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* Wiring                                                              */
    /* ------------------------------------------------------------------ */

    /** The panel's own private counter directory, or the system temp dir. */
    private function storage_dir() {
        $dir = null;
        try {
            require_once APPPATH.'core/Env.php';
            $paths = Env::writable_paths();
            if (!empty($paths['ratelimit'])) $dir = rtrim($paths['ratelimit'], '/');
        } catch (Throwable $e) { /* fall through */ }

        if ($dir === null) $dir = rtrim(sys_get_temp_dir(), '/').'/marvy_ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (!is_writable($dir)) {
            $dir = rtrim(sys_get_temp_dir(), '/').'/marvy_ratelimit';
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private function file_for($bucket) {
        return $this->dir.'/'.md5((string)$bucket).'.json';
    }

    private function redis_key($bucket, $window) {
        // The window is part of the key so a changed limit window cannot
        // inherit a stale counter.
        return 'marvy:rl:'.$window.':'.md5((string)$bucket);
    }

    /** A connected Redis client, or null when Redis is not in use. */
    private function redis() {
        if ($this->redis_checked) return $this->redis;
        $this->redis_checked = true;

        try {
            if (!isset($this->ci->config)) return $this->redis = null;
            $cfg = $this->ci->config->item('redis');
            if (!is_array($cfg) || empty($cfg['enabled'])) return $this->redis = null;
            if (!class_exists('Predis\\Client')) return $this->redis = null;

            $client = new Predis\Client(array(
                'scheme'   => 'tcp',
                'host'     => $cfg['host'] ?? '127.0.0.1',
                'port'     => (int)($cfg['port'] ?? 6379),
                'password' => $cfg['password'] ?? null,
                'database' => (int)($cfg['database'] ?? 0),
                'timeout'  => 1.0,
            ));
            $client->ping();
            return $this->redis = $client;
        } catch (Throwable $e) {
            log_message('error', 'rate limiter: redis not usable ('.$e->getMessage().'), using files');
            return $this->redis = null;
        }
    }
}
