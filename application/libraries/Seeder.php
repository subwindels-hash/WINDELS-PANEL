<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Seeder — tiny base class for CI3 seed classes.
 *
 * Seeds are idempotent: every insert goes through insert_once()/upsert(), so
 * running `php index.php seed core` twice never duplicates rows.
 */
abstract class Seeder {

    /** @var CI_Controller */
    protected $ci;
    protected $verbose = TRUE;
    protected $counts = array();

    public function __construct($params = array()) {
        $ci = get_instance();
        $this->ci = $ci;
        if (isset($params['verbose'])) $this->verbose = (bool)$params['verbose'];
    }

    /** Run the seed. Implementations must be idempotent. */
    abstract public function run();

    /** Human name used in CLI output. */
    public function name() { return get_class($this); }

    protected function out($msg) {
        if ($this->verbose) { echo $msg."\n"; }
        log_message('info', '[seed] '.$msg);
    }

    protected function now() { return gmdate('Y-m-d H:i:s'); }

    protected function pid() { return marvy_public_id(); }

    /** Insert only when no row matches $unique. Returns the row id. */
    protected function insert_once($table, array $unique, array $data = array()) {
        $existing = $this->ci->db->where($unique)->get($table)->row();
        if ($existing) {
            $this->bump($table, 'skipped');
            return isset($existing->id) ? (int)$existing->id : TRUE;
        }
        $this->ci->db->insert($table, array_merge($unique, $data));
        $this->bump($table, 'inserted');
        return (int)$this->ci->db->insert_id();
    }

    /** Insert or update the row matching $unique. Returns the row id. */
    protected function upsert($table, array $unique, array $data = array()) {
        $existing = $this->ci->db->where($unique)->get($table)->row();
        if ($existing) {
            if ($data) $this->ci->db->where($unique)->update($table, $data);
            $this->bump($table, 'updated');
            return isset($existing->id) ? (int)$existing->id : TRUE;
        }
        $this->ci->db->insert($table, array_merge($unique, $data));
        $this->bump($table, 'inserted');
        return (int)$this->ci->db->insert_id();
    }

    protected function bump($table, $what) {
        if (!isset($this->counts[$table])) $this->counts[$table] = array('inserted'=>0,'updated'=>0,'skipped'=>0);
        $this->counts[$table][$what]++;
    }

    public function summary() {
        $lines = array();
        foreach ($this->counts as $table => $c) {
            $lines[] = sprintf('  %-26s +%d ~%d =%d', $table, $c['inserted'], $c['updated'], $c['skipped']);
        }
        return implode("\n", $lines);
    }

    /** Hash a password with the strongest algorithm available. */
    protected function hash_password($plain) {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID);
        }
        return password_hash($plain, PASSWORD_BCRYPT, array('cost' => 12));
    }
}
