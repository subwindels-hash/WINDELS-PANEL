<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ApiRateLimiter — fixed-window per-key and per-IP rate limiting (Session 12).
 *
 * Uses a file-based counter so it works without Redis in development; in
 * production the same interface can be backed by Redis without changing the
 * controller. A request beyond the limit returns a 429 with Retry-After.
 */
class ApiRateLimiter {

    private $ci;
    private $dir;

    public function __construct() {
        $this->ci =& get_instance();
        $this->dir = rtrim(sys_get_temp_dir(), '/').'/marvy_ratelimit';
        if (!is_dir($this->dir)) @mkdir($this->dir, 0700, true);
    }

    /**
     * @param string $bucket   e.g. "key:42" or "ip:1.2.3.4"
     * @param int    $limit    max requests per window
     * @param int    $window   window length in seconds
     * @return array{allowed:bool,remaining:int,retry_after:int,limit:int}
     */
    public function check($bucket, $limit, $window = 60) {
        $limit = max(1, (int)$limit);
        $now = time();
        $file = $this->dir.'/'.md5($bucket).'.json';
        $fp = fopen($file, 'c+');
        if (!$fp) return array('allowed'=>true,'remaining'=>$limit,'retry_after'=>0,'limit'=>$limit);
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = $raw ? json_decode($raw, true) : null;
        if (!$data || ($now - (int)($data['window_start'] ?? 0)) >= $window) {
            $data = array('window_start' => $now, 'count' => 0);
        }
        $data['count']++;
        $allowed = $data['count'] <= $limit;
        $remaining = max(0, $limit - $data['count']);
        $retry_after = $allowed ? 0 : max(1, $window - ($now - (int)$data['window_start']));
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp); flock($fp, LOCK_UN); fclose($fp);

        return array(
            'allowed' => $allowed,
            'remaining' => $remaining,
            'retry_after' => $retry_after,
            'limit' => $limit,
        );
    }
}
